<?php
/**
 * Registry, polite server-side fetching, caching, and link building.
 */

defined( 'ABSPATH' ) || exit;

class AF_Service {

	const CACHE_TTL    = 30 * DAY_IN_SECONDS;
	const MIN_INTERVAL = 2.0; // Seconds between requests to vatican.va (its robots.txt asks for Crawl-delay: 2).
	const MAX_BYTES    = 8 * MB_IN_BYTES;
	const MAX_HOPS     = 3;

	/** @return array id => registry entry */
	public static function registry() {
		static $reg = null;
		if ( null === $reg ) {
			$reg  = array();
			$json = json_decode( (string) file_get_contents( AF_DIR . 'registry.json' ), true );
			foreach ( (array) ( $json['documents'] ?? array() ) as $doc ) {
				$reg[ $doc['id'] ] = $doc;
			}
		}
		return $reg;
	}

	/** Only vatican.va over https is ever fetched. */
	public static function host_allowed( $url ) {
		$p = wp_parse_url( $url );
		return isset( $p['scheme'], $p['host'] )
			&& 'https' === strtolower( $p['scheme'] )
			&& in_array( strtolower( $p['host'] ), array( 'vatican.va', 'www.vatican.va' ), true );
	}

	public static function user_agent() {
		$ua = 'WPI-AnchorFragment/' . AF_VERSION . ' (+' . home_url( '/' ) . ')';
		return apply_filters( 'af_user_agent', $ua );
	}

	/** Wait until MIN_INTERVAL has passed since the last request finished. */
	private static function throttle() {
		$last = (float) get_option( 'af_last_fetch', 0 );
		$wait = self::MIN_INTERVAL - ( microtime( true ) - $last );
		if ( $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
	}

	/**
	 * GET with manual redirect handling so every hop is checked against the allowlist.
	 *
	 * @return array|WP_Error
	 */
	private static function http_get( $url ) {
		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			if ( ! self::host_allowed( $url ) ) {
				return new WP_Error( 'af_host', 'Refusing to fetch a URL outside vatican.va: ' . $url );
			}
			self::throttle();
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 25,
					'redirection'         => 0,
					'user-agent'          => self::user_agent(),
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);
			update_option( 'af_last_fetch', microtime( true ), false );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$loc = wp_remote_retrieve_header( $res, 'location' );
				if ( ! $loc ) {
					return new WP_Error( 'af_redirect', 'Redirect without a Location header.' );
				}
				if ( 0 === strpos( $loc, '/' ) ) {
					$p   = wp_parse_url( $url );
					$loc = $p['scheme'] . '://' . $p['host'] . $loc;
				}
				$url = $loc;
				continue;
			}
			return array(
				'code'            => $code,
				'body'            => wp_remote_retrieve_body( $res ),
				'content_type'    => (string) wp_remote_retrieve_header( $res, 'content-type' ),
				'document_policy' => (string) wp_remote_retrieve_header( $res, 'document-policy' ),
				'final_url'       => $url,
			);
		}
		return new WP_Error( 'af_redirect', 'Too many redirects.' );
	}

	/** One page from vatican.va, for the developer commands and the cross-reference probe. @return array|WP_Error */
	public static function fetch_page( $url ) {
		return self::http_get( $url );
	}

	/**
	 * Parsed document, from cache or freshly fetched.
	 * Only short excerpts and fragment words are stored, never the full text.
	 *
	 * @return array|WP_Error { meta: array, paras: locator => array }
	 */
	public static function get_doc( $id, $refresh = false ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ] ) ) {
			return new WP_Error( 'af_unknown_doc', 'Unknown document id: ' . $id, array( 'status' => 404 ) );
		}
		$entry = $reg[ $id ];
		$key   = 'af_doc_' . $id;
		if ( ! empty( $entry['hosted'] ) ) {
			$h = self::get_hosted_doc( $id, $entry );
			if ( ! is_wp_error( $h ) || empty( $entry['multipage'] ) ) {
				return $h;
			}
		}
		if ( ! empty( $entry['multipage'] ) ) {
			return self::get_multipage_doc( $id, $entry );
		}

		$cached = get_option( $key );
		if ( ! $refresh && is_array( $cached )
			&& ( $cached['meta']['parser_version'] ?? 0 ) === AF_Parser::VERSION
			&& ( $cached['meta']['url'] ?? '' ) === $entry['url']
			&& time() - (int) $cached['meta']['fetched_at'] < self::CACHE_TTL ) {
			$cached['meta']['source'] = 'cache';
			return $cached;
		}

		$t0  = microtime( true );
		$res = self::http_get( $entry['url'] );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
		}
		if ( 200 !== $res['code'] ) {
			return new WP_Error( 'af_http', 'vatican.va answered HTTP ' . $res['code'] . ' for ' . $entry['url'], array( 'status' => 502 ) );
		}

		list( $html, $charset ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
		$parsed                 = AF_Parser::parse( $html, (int) ( $entry['max_locator'] ?? 0 ) );

		$paras     = array();
		$no_frag   = array();
		foreach ( $parsed['paragraphs'] as $loc => $p ) {
			$f = AF_Fragment::build( $p['first'], $parsed['page'], $p['lead'] );
			if ( null === $f['frag'] ) {
				$no_frag[ $loc ] = $f['reason'];
			}
			$paras[ $loc ] = array(
				'excerpt'  => mb_substr( $p['first'], 0, 120 ),
				'frag'     => $f['frag'],
				'words'    => $f['words'],
				'reason'   => $f['reason'],
				'inferred' => $p['inferred'],
				'hash'     => sha1( AF_Parser::fold( $p['full'] ) ),
			);
		}

		$doc = array(
			'meta'  => array(
				'id'              => $id,
				'url'             => $entry['url'],
				'final_url'       => $res['final_url'],
				'http'            => $res['code'],
				'bytes'           => strlen( $res['body'] ),
				'charset'         => $charset,
				'content_type'    => $res['content_type'],
				'document_policy' => $res['document_policy'],
				'fetched_at'      => time(),
				'fetch_ms'        => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
				'parser_version'  => AF_Parser::VERSION,
				'source'          => 'fetched',
				'expected_count'  => $entry['expected_count'] ?? null,
				'stats'           => $parsed['stats'] + array( 'no_fragment' => $no_frag ),
			),
			'paras' => $paras,
		);
		// Hashed word-run index for matching quotations. It cannot be turned back into the text.
		$idx = AF_Matcher::build_index( $parsed['paragraphs'] );
		self::save_index( $id, $idx );
		$doc['meta']['shingles'] = count( $idx );

		update_option( $key, $doc, false ); // autoload off: this is never loaded on normal page views.
		return $doc;
	}

	/** @return array|WP_Error */
	public static function link( $id, $locator, $refresh = false ) {
		$doc = self::get_doc( $id, $refresh );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$loc = ltrim( trim( (string) $locator ), '0' );
		if ( ! isset( $doc['paras'][ $loc ] ) ) {
			return new WP_Error( 'af_no_locator', sprintf( 'No paragraph "%s" found in %s (parsed %d paragraphs, %d to %d).', $locator, $id, $doc['meta']['stats']['count'], $doc['meta']['stats']['min'], $doc['meta']['stats']['max'] ), array( 'status' => 404 ) );
		}
		$p = $doc['paras'][ $loc ];
		if ( empty( $p['frag'] ) && ! isset( $p['page'] ) ) {
			return new WP_Error( 'af_no_fragment', 'Paragraph found but no reliable fragment could be built: ' . $p['reason'], array( 'status' => 422 ) );
		}
		$reg = self::registry();
		if ( isset( $p['page'] ) ) { // PDF-only document: link to the page, since PDF viewers do not do text fragments.
			return array(
				'doc'      => $id,
				'title'    => $reg[ $id ]['title'],
				'locator'  => $loc,
				'url'      => $reg[ $id ]['url'],
				'fragment' => null,
				'page'     => (int) $p['page'],
				'words'    => 0,
				'link'     => $reg[ $id ]['url'] . '#page=' . (int) $p['page'],
				'excerpt'  => '',
				'source'   => $doc['meta']['source'],
				'fetch_ms' => $doc['meta']['fetch_ms'],
			);
		}
		return array(
			'doc'      => $id,
			'title'    => $reg[ $id ]['title'],
			'locator'  => $loc,
			'url'      => isset( $p['url'] ) ? $p['url'] : $reg[ $id ]['url'],
			'fragment' => $p['frag'],
			'words'    => $p['words'],
			'link'     => AF_Fragment::link( isset( $p['url'] ) ? $p['url'] : $reg[ $id ]['url'], $p['frag'] ),
			'excerpt'  => $p['excerpt'],
			'source'   => $doc['meta']['source'],
			'fetch_ms' => $doc['meta']['fetch_ms'],
		);
	}

	/**
	 * Find the source paragraph of each quotation in a post, and save the result in the
	 * option af_match_<post id> so it can be read back later.
	 *
	 * @return array|WP_Error
	 */
	public static function analyze_post( $post_id, $check_caps = true ) {
		$post = get_post( $post_id );
		if ( ! $post || ( $check_caps && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return new WP_Error( 'af_no_post', 'No such post: ' . (int) $post_id, array( 'status' => 404 ) );
		}

		// Every single-page document needs a current index; this fetches any that are missing
		// (2 seconds apart). Multi-page documents are never fetched here: they are skipped until
		// they have been fetched from the test page.
		$unindexed = array();
		foreach ( self::registry() as $id => $entry ) {
			if ( ! empty( $entry['hosted'] ) ) {
				if ( ! self::has_index( $id ) ) {
					$imp = self::import_hosted( $id );
					if ( is_wp_error( $imp ) ) {
						$unindexed[] = $id;
					}
				}
				continue;
			}
			if ( ! empty( $entry['multipage'] ) ) {
				if ( ! self::has_index( $id ) ) {
					$unindexed[] = $id;
				}
				continue;
			}
			$doc = self::get_doc( $id, false );
			if ( ! is_wp_error( $doc ) && ! self::has_index( $id ) ) {
				$doc = self::get_doc( $id, true );
			}
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
		}

		$tags  = array();
		$terms = get_the_terms( $post->ID, 'post_tag' );
		if ( is_array( $terms ) ) {
			$tags = wp_list_pluck( $terms, 'name' );
		}

		$analyzer = new AF_Analyzer( self::registry() );
		$res      = $analyzer->analyze(
			wpautop( $post->post_content ),
			$tags,
			function ( $doc_id ) {
				return AF_Service::load_index( $doc_id );
			}
		);

		$res = array(
			'post_id'        => $post->ID,
			'title'          => get_the_title( $post ),
			'url'            => get_permalink( $post ),
			'ran_at'         => time(),
			'plugin_version' => AF_VERSION,
			'unindexed'      => $unindexed,
		) + $res;
		update_option( 'af_match_' . $post->ID, $res, false );
		if ( class_exists( 'AF_Graph' ) ) {
			AF_Graph::record_post( $post->ID, $res );
		}
		return $res;
	}

	/* ------------------------------------------------------------------ index storage */

	const SHARD_SIZE = 60000; // Entries per stored row, so no single option row grows too large.

	/** Save a document's index, splitting a large one over several options. */
	public static function save_index( $id, array $idx ) {
		$old = get_option( 'af_idx_' . $id );
		if ( is_array( $old ) && isset( $old['_shards'] ) ) {
			for ( $k = 0; $k < (int) $old['_shards']; $k++ ) {
				delete_option( 'af_idx_' . $id . '_' . $k );
			}
		}
		if ( count( $idx ) <= self::SHARD_SIZE ) {
			update_option( 'af_idx_' . $id, $idx, false );
			return;
		}
		$n      = (int) ceil( count( $idx ) / self::SHARD_SIZE );
		$shards = array_fill( 0, $n, array() );
		foreach ( $idx as $h => $v ) {
			$shards[ $h % $n ][ $h ] = $v;
		}
		unset( $idx );
		foreach ( $shards as $k => $part ) {
			update_option( 'af_idx_' . $id . '_' . $k, $part, false );
		}
		update_option( 'af_idx_' . $id, array( '_shards' => $n ), false );
	}

	public static function has_index( $id ) {
		return is_array( get_option( 'af_idx_' . $id ) );
	}

	/** @return array|null */
	public static function load_index( $id ) {
		$idx = get_option( 'af_idx_' . $id );
		if ( ! is_array( $idx ) ) {
			return null;
		}
		if ( ! isset( $idx['_shards'] ) ) {
			return $idx;
		}
		$all = array();
		for ( $k = 0; $k < (int) $idx['_shards']; $k++ ) {
			$part = get_option( 'af_idx_' . $id . '_' . $k );
			if ( ! is_array( $part ) ) {
				return null; // Incomplete: treat as not indexed rather than match against part of a document.
			}
			$all += $part; // Shards hold different keys, so a union is a merge.
		}
		return $all;
	}

	/** Remove everything stored for one document. */
	public static function clear_doc( $id ) {
		$idx = get_option( 'af_idx_' . $id );
		if ( is_array( $idx ) && isset( $idx['_shards'] ) ) {
			for ( $k = 0; $k < (int) $idx['_shards']; $k++ ) {
				delete_option( 'af_idx_' . $id . '_' . $k );
			}
		}
		delete_option( 'af_idx_' . $id );
		delete_option( 'af_doc_' . $id );
		$st = get_option( 'af_ing_' . $id );
		if ( is_array( $st ) ) {
			for ( $i = 0; $i < count( $st['pages'] ); $i++ ) {
				delete_option( 'af_ip_' . $id . '_' . $i );
			}
		}
		delete_option( 'af_ing_' . $id );
	}

	/* ------------------------------------------------------------------ hosted derived data */

	const HOSTED_PREFIX = 'https://raw.githubusercontent.com/numena1-byte/anchor-fragment/';
	const HOSTED_MAX    = 6 * MB_IN_BYTES;

	/** A hosted document is only ever read from what an import left behind. */
	private static function get_hosted_doc( $id, array $entry ) {
		$cached = get_option( 'af_doc_' . $id );
		if ( is_array( $cached ) && self::has_index( $id ) && ! empty( $cached['meta']['complete'] ) && empty( $cached['meta']['partial'] )
			&& ( ( $cached['meta']['hosted_sha'] ?? '' ) === $entry['hosted']['sha256']
				|| ( ! empty( $entry['multipage'] ) && ( $cached['meta']['parser_version'] ?? 0 ) === AF_Parser::VERSION ) ) ) {
			$cached['meta']['source'] = 'cache';
			return $cached;
		}
		// A download that just failed is not tried again for ten minutes, so a blocked host is not asked on every request.
		$failed = (int) get_option( 'af_hosted_fail_' . $id, 0 );
		if ( $failed && time() - $failed < 600 ) {
			return new WP_Error( 'af_incomplete', 'The hosted data for ' . $entry['title'] . ' could not be downloaded a moment ago. Try the Import button, or upload the file, on the Anchor Fragment Test page.', array( 'status' => 409 ) );
		}
		$imp = self::import_hosted( $id );
		if ( is_wp_error( $imp ) ) {
			update_option( 'af_hosted_fail_' . $id, time(), false );
			return $imp;
		}
		delete_option( 'af_hosted_fail_' . $id );
		return get_option( 'af_doc_' . $id );
	}

	/**
	 * Download a hosted index file (made outside the server from a PDF), check it, and store it.
	 * It holds hashes and page numbers only, never the document's text.
	 *
	 * @return array|WP_Error { count, min, max, message }
	 */
	public static function import_hosted( $id, $force = false ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ]['hosted'] ) ) {
			return new WP_Error( 'af_not_hosted', 'No hosted data for ' . $id, array( 'status' => 400 ) );
		}
		$entry = $reg[ $id ];
		$h     = $entry['hosted'];
		if ( 0 !== strpos( $h['url'], self::HOSTED_PREFIX ) ) {
			return new WP_Error( 'af_host', 'Hosted data must come from ' . self::HOSTED_PREFIX );
		}
		$old = get_option( 'af_doc_' . $id );
		if ( ! $force && is_array( $old ) && ( $old['meta']['hosted_sha'] ?? '' ) === $h['sha256'] && self::has_index( $id ) ) {
			return array( 'count' => (int) $old['meta']['stats']['count'], 'min' => (int) $old['meta']['stats']['min'], 'max' => (int) $old['meta']['stats']['max'], 'message' => 'Already imported.' );
		}
		$t0  = microtime( true );
		$res = wp_safe_remote_get( $h['url'], array( 'timeout' => 30, 'redirection' => 2, 'limit_response_size' => self::HOSTED_MAX, 'user-agent' => self::user_agent() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'af_http', 'Hosted data answered HTTP ' . wp_remote_retrieve_response_code( $res ), array( 'status' => 502 ) );
		}
		return self::import_hosted_body( $id, wp_remote_retrieve_body( $res ), (int) round( ( microtime( true ) - $t0 ) * 1000 ) );
	}

	/**
	 * Check a hosted index file against the checksum in the registry and store it. The file may have been
	 * downloaded by import_hosted() or uploaded by hand; either way it is accepted only if it is exactly the
	 * file the registry names.
	 *
	 * @return array|WP_Error { count, min, max, message }
	 */
	public static function import_hosted_body( $id, $body, $fetch_ms = 0 ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ]['hosted'] ) ) {
			return new WP_Error( 'af_not_hosted', 'No hosted data for ' . $id, array( 'status' => 400 ) );
		}
		$entry = $reg[ $id ];
		$h     = $entry['hosted'];
		if ( ! hash_equals( $h['sha256'], hash( 'sha256', $body ) ) ) {
			return new WP_Error( 'af_checksum', 'This file does not match the checksum in the registry; not imported.', array( 'status' => 502 ) );
		}
		$d = json_decode( $body, true );
		if ( ! is_array( $d ) || ! in_array( $d['format'] ?? 0, array( 1, 2 ), true ) || ( $d['id'] ?? '' ) !== $id || (int) ( $d['matcher_k'] ?? 0 ) !== AF_Matcher::K || empty( $d['index'] ) ) {
			return new WP_Error( 'af_hosted_format', 'Hosted data is not in a format this plugin version understands.', array( 'status' => 502 ) );
		}
		if ( 2 === (int) $d['format'] && (int) ( $d['parser_version'] ?? 0 ) !== AF_Parser::VERSION ) {
			return new WP_Error( 'af_hosted_version', 'This file was built by a different parser version; a newer one is needed.', array( 'status' => 502 ) );
		}
		$paras = array();
		if ( 1 === (int) $d['format'] ) { // PDF document: a page number per paragraph.
			if ( empty( $d['pages'] ) ) {
				return new WP_Error( 'af_hosted_format', 'Hosted data holds no pages.', array( 'status' => 502 ) );
			}
			foreach ( $d['pages'] as $loc => $page ) {
				$paras[ (int) $loc ] = array(
					'excerpt' => '',
					'frag'    => null,
					'words'   => 0,
					'reason'  => 'PDF document: linked by page',
					'page'    => (int) $page,
					'hash'    => $d['para_hash'][ $loc ] ?? '',
				);
			}
		} else { // Text-fragment links, one per paragraph.
			if ( empty( $d['paras'] ) ) {
				return new WP_Error( 'af_hosted_format', 'Hosted data holds no paragraphs.', array( 'status' => 502 ) );
			}
			foreach ( $d['paras'] as $loc => $p ) {
				$row = array(
					'excerpt' => '',
					'frag'    => isset( $p['f'] ) && '' !== $p['f'] ? (string) $p['f'] : null,
					'words'   => (int) ( $p['w'] ?? 0 ),
					'reason'  => isset( $p['f'] ) && '' !== $p['f'] ? '' : (string) ( $p['r'] ?? 'no fragment' ),
				);
				if ( ! empty( $p['u'] ) ) {
					if ( ! self::host_allowed( $p['u'] ) ) {
						return new WP_Error( 'af_hosted_format', 'Hosted data names a page outside the allowed hosts.', array( 'status' => 502 ) );
					}
					$row['url'] = (string) $p['u'];
				}
				$paras[ (int) $loc ] = $row;
			}
		}
		ksort( $paras );
		$min  = (int) min( array_keys( $paras ) );
		$max  = (int) max( array_keys( $paras ) );
		$gaps = array();
		for ( $n = $min; $n <= $max; $n++ ) {
			if ( ! isset( $paras[ $n ] ) ) {
				$gaps[] = $n;
			}
		}
		$no_frag = array();
		if ( 2 === (int) $d['format'] ) {
			foreach ( $paras as $loc => $p ) {
				if ( empty( $p['frag'] ) ) {
					$no_frag[ $loc ] = $p['reason'];
				}
			}
		}
		$idx = array();
		foreach ( $d['index'] as $k => $v ) {
			$idx[ (int) $k ] = $v;
		}
		self::save_index( $id, $idx );
		$doc = array(
			'meta'  => array(
				'id' => $id, 'url' => $entry['url'], 'final_url' => $entry['url'], 'http' => 200, 'bytes' => strlen( $body ),
				'charset' => 'hosted', 'content_type' => '', 'document_policy' => '',
				'fetched_at' => time(), 'fetch_ms' => $fetch_ms,
				'parser_version' => AF_Parser::VERSION, 'source' => 'hosted', 'complete' => true,
				'hosted_sha' => $h['sha256'], 'expected_count' => $entry['expected_count'] ?? null,
				'shingles' => count( $idx ),
				'stats' => array( 'count' => count( $paras ), 'min' => $min, 'max' => $max, 'gaps' => $gaps, 'rejected' => array(), 'inferred' => array(), 'blocks' => 0, 'duplicates' => array(), 'no_fragment' => $no_frag ),
			),
			'paras' => $paras,
		);
		update_option( 'af_doc_' . $id, $doc, false );
		delete_option( 'af_hosted_fail_' . $id );
		return array(
			'count' => count( $paras ), 'min' => $min, 'max' => $max,
			'message' => sprintf( 'Imported %s: %d paragraphs (%d to %d), %d gap(s), %d index entries.', $entry['title'], count( $paras ), $min, $max, count( $gaps ), count( $idx ) ),
		);
	}

	/**
	 * A crawled document as a hosted file (format 2): the index and one text fragment per paragraph.
	 * It holds no paragraph text, only the few words of each fragment.
	 *
	 * @return array|WP_Error
	 */
	public static function export_hosted( $id ) {
		$reg = self::registry();
		$doc = get_option( 'af_doc_' . $id );
		if ( ! isset( $reg[ $id ] ) || ! is_array( $doc ) || empty( $doc['meta']['complete'] ) || ! empty( $doc['meta']['partial'] ) || ! self::has_index( $id ) ) {
			return new WP_Error( 'af_export', 'Only a completely fetched document can be exported.', array( 'status' => 409 ) );
		}
		$paras = array();
		foreach ( $doc['paras'] as $loc => $p ) {
			$row = array( 'w' => (int) $p['words'] );
			if ( ! empty( $p['frag'] ) ) {
				$row['f'] = $p['frag'];
			} else {
				$row['r'] = (string) ( $p['reason'] ?? 'no fragment' );
			}
			if ( ! empty( $p['url'] ) ) {
				$row['u'] = $p['url'];
			}
			$paras[ (int) $loc ] = $row;
		}
		ksort( $paras );
		$idx = self::load_index( $id );
		if ( ! is_array( $idx ) ) {
			return new WP_Error( 'af_export', 'The index is incomplete.', array( 'status' => 409 ) );
		}
		ksort( $idx );
		return array(
			'format'         => 2,
			'id'             => $id,
			'title'          => $reg[ $id ]['title'],
			'matcher_k'      => AF_Matcher::K,
			'parser_version' => AF_Parser::VERSION,
			'count'          => count( $paras ),
			'min'            => (int) min( array_keys( $paras ) ),
			'max'            => (int) max( array_keys( $paras ) ),
			'link_mode'      => 'fragment',
			'shingles'       => count( $idx ),
			'paras'          => $paras,
			'index'          => $idx,
		);
	}

	/* ------------------------------------------------------------------ passage text */

	/**
	 * The text of one paragraph, fetched from vatican.va when asked for (one page, nothing stored).
	 * The text is checked against the stored index: if the paragraph found here is not the one the index was
	 * built from, 'verified' is false.
	 *
	 * @return array|WP_Error { doc, title, short, locator, text, link, verified, fetch_ms }
	 */
	public static function passage( $id, $locator ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ] ) ) {
			return new WP_Error( 'af_unknown_doc', 'Unknown document id: ' . $id, array( 'status' => 404 ) );
		}
		$entry  = $reg[ $id ];
		$is_pdf = false !== stripos( (string) $entry['url'], '.pdf' );
		$loc    = (int) $locator;
		$doc    = self::get_doc( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( ! isset( $doc['paras'][ $loc ] ) ) {
			return new WP_Error( 'af_no_paragraph', 'No paragraph ' . $loc . ' in ' . $entry['title'] . '.', array( 'status' => 404 ) );
		}
		if ( $is_pdf ) {
			// A PDF cannot be read on the server. The text is whatever an author added from the PDF on this site,
			// each paragraph checked against the index when it was added.
			$stored = self::stored_text( $id );
			if ( ! isset( $stored[ $loc ] ) ) {
				return new WP_Error( 'af_pdf_text', $entry['title'] . ' is a PDF. The text of paragraph ' . $loc . ' has not been added on this site (Tools > Anchor Fragment Test > Add text from a PDF).', array( 'status' => 404 ) );
			}
			$link = self::link( $id, (string) $loc );
			$out  = array(
				'doc'      => $id,
				'title'    => $entry['title'],
				'short'    => $entry['short'] ?? '',
				'locator'  => $loc,
				'text'     => $stored[ $loc ],
				'link'     => is_wp_error( $link ) ? $entry['url'] : $link['link'],
				'verified' => true,
				'source'   => 'stored',
				'fetch_ms' => 0,
			);
			$out['html'] = AF_Quote::render( $out );
			return $out;
		}
		$url = ! empty( $doc['paras'][ $loc ]['url'] ) ? $doc['paras'][ $loc ]['url'] : $entry['url'];
		$t0  = microtime( true );
		$res = self::http_get( $url );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
		}
		if ( 200 !== $res['code'] ) {
			return new WP_Error( 'af_http', 'vatican.va answered HTTP ' . $res['code'] . ' for ' . $url, array( 'status' => 502 ) );
		}
		list( $html ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
		$opts         = array_merge( self::parse_opts( $entry ), array( 'after' => max( 0, $loc - 1 ), 'window' => 1 ) );
		$parsed       = AF_Parser::parse( $html, (int) ( $entry['max_locator'] ?? 0 ), $opts );
		if ( ! isset( $parsed['paragraphs'][ $loc ] ) ) {
			return new WP_Error( 'af_no_paragraph', 'Paragraph ' . $loc . ' was not found on ' . $url . '.', array( 'status' => 502 ) );
		}
		$text     = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/\s*\[\d{1,3}\]/', '', $parsed['paragraphs'][ $loc ]['full'] ) ) );
		$verified = false;
		$idx      = self::load_index( $id );
		if ( is_array( $idx ) ) {
			$sc       = AF_Matcher::score( AF_Matcher::segments( $text ), $idx );
			$verified = $sc['coverage'] >= 0.8 && in_array( $loc, $sc['locators'], true );
		}
		$link = self::link( $id, (string) $loc );
		$out = array(
			'doc'      => $id,
			'title'    => $entry['title'],
			'short'    => $entry['short'] ?? '',
			'locator'  => $loc,
			'text'     => $text,
			'link'     => is_wp_error( $link ) ? $url : $link['link'],
			'verified' => $verified,
			'fetch_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
		);
		$out['html'] = AF_Quote::render( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ text added from a PDF */

	const SOFT_HYPHEN = "\xC2\xAD";
	const TEXT_MAX    = 400;

	/** Paragraph text an author added on this site from a PDF: loc => text. Only text that passed the index check is kept. */
	public static function stored_text( $id ) {
		$t = get_option( 'af_text_' . $id );
		return is_array( $t ) ? $t : array();
	}

	public static function clear_text( $id ) {
		delete_option( 'af_text_' . $id );
	}

	/**
	 * Check paragraphs read from a PDF (in the author's browser) against the index and keep those that agree with it
	 * word for word. A line-end hyphen that may belong to the word arrives marked with a soft hyphen; the form that
	 * the index agrees with is used.
	 *
	 * @param array $paras loc => text
	 * @return array|WP_Error { accepted[], rejected[ { loc, reason } ], stored }
	 */
	public static function accept_text( $id, array $paras ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ] ) ) {
			return new WP_Error( 'af_unknown_doc', 'Unknown document id: ' . $id, array( 'status' => 404 ) );
		}
		if ( count( $paras ) > self::TEXT_MAX ) {
			return new WP_Error( 'af_too_many', 'At most ' . self::TEXT_MAX . ' paragraphs at a time.', array( 'status' => 400 ) );
		}
		$idx = self::load_index( $id );
		if ( ! is_array( $idx ) ) {
			return new WP_Error( 'af_no_index', 'This document has no index yet; import it first.', array( 'status' => 409 ) );
		}
		$locs   = array_map( 'intval', array_keys( $paras ) );
		$counts = AF_Matcher::index_runs( $idx, $locs );
		$stored = self::stored_text( $id );
		$acc    = array();
		$rej    = array();
		foreach ( $paras as $k => $text ) {
			$loc = (int) $k;
			if ( ! is_string( $text ) || strlen( $text ) > 30000 || $loc < 1 ) {
				$rej[] = array( 'loc' => $loc, 'reason' => 'not usable text' );
				continue;
			}
			$text  = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );
			$parts = explode( self::SOFT_HYPHEN, $text );
			if ( count( $parts ) > 40 ) {
				$rej[] = array( 'loc' => $loc, 'reason' => 'too many line-end hyphens' );
				continue;
			}
			// Start with every line-end hyphen removed, then keep a hyphen where that makes more of the words agree with the index.
			$use  = array_fill( 1, max( 0, count( $parts ) - 1 ), false );
			$make = function ( array $use ) use ( $parts ) {
				$t = $parts[0];
				for ( $i = 1; $i < count( $parts ); $i++ ) {
					$t .= ( $use[ $i ] ? '-' : '' ) . $parts[ $i ];
				}
				return trim( preg_replace( '/\s+/u', ' ', $t ) );
			};
			$best = AF_Matcher::check_paragraph( AF_Matcher::tokens( $make( $use ) ), $idx, $loc );
			for ( $i = 1; $i < count( $parts ); $i++ ) {
				$try        = $use;
				$try[ $i ]  = true;
				$r          = AF_Matcher::check_paragraph( AF_Matcher::tokens( $make( $try ) ), $idx, $loc );
				if ( $r['found'] > $best['found'] || ( $r['found'] === $best['found'] && $r['runs'] < $best['runs'] ) ) {
					$use  = $try;
					$best = $r;
				}
			}
			$final = $make( $use );
			if ( $best['runs'] < 1 ) {
				$rej[] = array( 'loc' => $loc, 'reason' => 'too short to check' );
			} elseif ( $best['found'] < $best['runs'] ) {
				$rej[] = array( 'loc' => $loc, 'reason' => ( $best['runs'] - $best['found'] ) . ' of ' . $best['runs'] . ' word runs differ from the index' );
			} elseif ( ! empty( $counts[ $loc ] ) && $best['runs'] < 0.98 * $counts[ $loc ] ) {
				$rej[] = array( 'loc' => $loc, 'reason' => 'incomplete: ' . $best['runs'] . ' of ' . $counts[ $loc ] . ' word runs' );
			} else {
				$stored[ $loc ] = $final;
				$acc[]          = $loc;
			}
		}
		if ( $acc ) {
			ksort( $stored );
			update_option( 'af_text_' . $id, $stored, false );
		}
		return array( 'accepted' => $acc, 'rejected' => $rej, 'stored' => count( $stored ) );
	}

	/** What the "Add text from a PDF" form needs: the PDF address, the page of each paragraph and what is stored. */
	public static function text_info( $id ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $id ] ) ) {
			return new WP_Error( 'af_unknown_doc', 'Unknown document id: ' . $id, array( 'status' => 404 ) );
		}
		$doc = self::get_doc( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$pages = array();
		foreach ( $doc['paras'] as $loc => $p ) {
			if ( ! empty( $p['page'] ) ) {
				$pages[ $loc ] = (int) $p['page'];
			}
		}
		return array(
			'doc'    => $id,
			'title'  => $reg[ $id ]['title'],
			'url'    => $reg[ $id ]['url'],
			'pages'  => $pages,
			'stored' => array_keys( self::stored_text( $id ) ),
		);
	}

	/* ------------------------------------------------------------------ multi-page documents */

	/** A multi-page document is only ever read from what a finished fetch left behind. */
	private static function get_multipage_doc( $id, array $entry ) {
		$cached = get_option( 'af_doc_' . $id );
		if ( is_array( $cached )
			&& ! empty( $cached['meta']['complete'] )
			&& ( $cached['meta']['parser_version'] ?? 0 ) === AF_Parser::VERSION
			&& time() - (int) $cached['meta']['fetched_at'] < self::CACHE_TTL ) {
			$cached['meta']['source'] = 'cache';
			return $cached;
		}
		$st = get_option( 'af_ing_' . $id );
		$msg = is_array( $st )
			? sprintf( '%s is partly fetched (%d of %d pages). Continue from the Anchor Fragment Test page.', $entry['title'], (int) $st['next'], count( $st['order'] ) )
			: sprintf( '%s has not been fetched yet. Fetch it from the Anchor Fragment Test page (it is %s).', $entry['title'], 'many small pages' );
		return new WP_Error( 'af_incomplete', $msg, array( 'status' => 409 ) );
	}

	/** Parse options for a registry entry. */
	private static function parse_opts( array $entry ) {
		return isset( $entry['parse'] ) && is_array( $entry['parse'] ) ? $entry['parse'] : array();
	}

	/**
	 * Fetch the next few pages of a multi-page document, within a time budget, and save progress.
	 * With $sample, only about 36 pages are fetched: the start, three spots in the middle and the end
	 * (five runs of consecutive pages), which is enough to try the parser. Call it again until 'finished' is true. When the last page is done the pieces are joined into
	 * the ordinary cached document and index.
	 *
	 * @return array|WP_Error { done, total, finished, paragraphs, last, errors[], message }
	 */
	public static function ingest_step( $id, $budget = 25.0, $restart = false, $sample = false, $crawl = false ) {
		$reg = self::registry();
		if ( ! $crawl && isset( $reg[ $id ] ) && ! empty( $reg[ $id ]['hosted'] ) ) {
			$msg = self::import_hosted( $id, true );
			if ( is_wp_error( $msg ) ) {
				return $msg;
			}
			return array( 'done' => 1, 'total' => 1, 'finished' => true, 'paragraphs' => $msg['count'], 'last' => $msg['max'], 'errors' => array(), 'message' => $msg['message'] );
		}
		if ( ! isset( $reg[ $id ] ) || empty( $reg[ $id ]['multipage'] ) ) {
			return new WP_Error( 'af_not_multipage', 'Not a multi-page document: ' . $id, array( 'status' => 400 ) );
		}
		$entry = $reg[ $id ];
		$mp    = $entry['multipage'];
		if ( $restart ) {
			self::clear_doc( $id );
		}
		$t0 = microtime( true );
		$st = get_option( 'af_ing_' . $id );

		if ( ! is_array( $st ) ) {
			$res = self::http_get( $mp['index'] );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
			}
			if ( 200 !== $res['code'] ) {
				return new WP_Error( 'af_http', 'vatican.va answered HTTP ' . $res['code'] . ' for ' . $mp['index'], array( 'status' => 502 ) );
			}
			list( $html ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
			$pages        = array();
			if ( preg_match_all( '#href=["\']?(' . $mp['page_pattern'] . ')#i', $html, $m ) ) {
				foreach ( $m[1] as $href ) {
					$pages[ strtoupper( $href ) ] = $href;
				}
			}
			$pages = array_values( $pages );
			if ( ! $pages ) {
				return new WP_Error( 'af_no_pages', 'The index page lists no document pages.', array( 'status' => 502 ) );
			}
			$count = count( $pages );
			$runs  = array( array( 0, $count - 1 ) );
			if ( $sample && $count > 60 ) {
				$runs = array(
					array( 0, 11 ),
					array( (int) ( $count * 0.25 ), (int) ( $count * 0.25 ) + 5 ),
					array( (int) ( $count * 0.50 ), (int) ( $count * 0.50 ) + 5 ),
					array( (int) ( $count * 0.75 ), (int) ( $count * 0.75 ) + 5 ),
					array( $count - 6, $count - 1 ),
				);
			}
			$order = array();
			foreach ( $runs as $r ) {
				for ( $k = $r[0]; $k <= $r[1]; $k++ ) {
					$order[] = $k;
				}
			}
			$st = array(
				'sample'  => count( $runs ) > 1,
				'runs'    => $runs,
				'order'   => $order,
				'pages'   => $pages,
				'next'    => 0,
				'last'    => 0,
				'started' => time(),
				'ms'      => 0,
				'errors'  => array(),
				'fails'   => 0,
			);
		}

		$base   = preg_replace( '#/[^/]*$#', '/', $mp['index'] );
		$total  = count( $st['order'] );
		$opts   = self::parse_opts( $entry );
		$window = isset( $mp['window'] ) ? (int) $mp['window'] : AF_Parser::MAX_SKIP;
		$max    = (int) ( $entry['max_locator'] ?? 0 );

		while ( $st['next'] < $total && ( microtime( true ) - $t0 ) < $budget ) {
			$i   = (int) $st['order'][ (int) $st['next'] ]; // Position in the page list; chunks are stored under it.
			$url = $base . $st['pages'][ $i ];
			$run_start = false;
			foreach ( $st['runs'] as $r ) {
				if ( $i === $r[0] ) {
					$run_start = true; // The first page of a run: the previous page was not its neighbour.
				}
			}
			if ( $run_start && $st['sample'] ) {
				$st['last'] = 0;
			}
			$res = self::http_get( $url );
			if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
				$st['fails']++;
				$st['errors'][] = $st['pages'][ $i ] . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'] );
				if ( $st['fails'] >= 3 ) { // Give up on this page, record it, go on.
					$st['next']++;
					$st['fails'] = 0;
				}
				break;
			}
			$st['fails'] = 0;
			list( $html ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
			$parsed       = AF_Parser::parse( $html, $max, array_merge( $opts, array( 'after' => (int) $st['last'], 'window' => ( $run_start && $st['sample'] ) ? max( $max, 1 ) : $window ) ) );

			$paras = array();
			foreach ( $parsed['paragraphs'] as $loc => $p ) {
				$f             = AF_Fragment::build( $p['first'], $parsed['page'], $p['lead'] );
				$paras[ $loc ] = array(
					'excerpt'  => mb_substr( $p['first'], 0, 120 ),
					'frag'     => $f['frag'],
					'words'    => $f['words'],
					'reason'   => $f['reason'],
					'inferred' => $p['inferred'],
					'hash'     => sha1( AF_Parser::fold( $p['full'] ) ),
					'url'      => $url,
				);
			}
			update_option(
				'af_ip_' . $id . '_' . $i,
				array(
					'paras'  => $paras,
					'idx'    => AF_Matcher::build_index( $parsed['paragraphs'] ),
					'blocks' => $parsed['stats']['blocks'],
				),
				false
			);
			if ( $parsed['paragraphs'] ) {
				$st['last'] = (int) end( $parsed['paragraphs'] )['n'];
			}
			$st['next']++;
			update_option( 'af_ing_' . $id, $st + array(), false ); // Save after every page so a stopped request loses nothing.
		}

		$st['ms'] += (int) round( ( microtime( true ) - $t0 ) * 1000 );
		update_option( 'af_ing_' . $id, $st, false );

		$finished = $st['next'] >= $total;
		$message  = sprintf( 'Fetched %d of %d pages%s; last paragraph number %d.', $st['next'], $total, ! empty( $st['sample'] ) ? ' (sample)' : '', $st['last'] );
		if ( $finished ) {
			$done = self::finish_multipage( $id, $entry, $st );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$message = $done;
		}
		return array(
			'done'     => (int) $st['next'],
			'total'    => $total,
			'finished' => $finished,
			'last'     => (int) $st['last'],
			'errors'   => array_slice( $st['errors'], -5 ),
			'message'  => $message,
		);
	}

	/** Join the saved pages into one cached document and one index, then remove the pieces. */
	private static function finish_multipage( $id, array $entry, array $st ) {
		$paras   = array();
		$idx     = array();
		$dups    = array();
		$blocks  = 0;
		$total   = count( $st['order'] );
		$by_page = array();
		foreach ( $st['order'] as $i ) {
			$chunk = get_option( 'af_ip_' . $id . '_' . $i );
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			$blocks += (int) $chunk['blocks'];
			foreach ( $chunk['paras'] as $loc => $p ) {
				if ( isset( $paras[ $loc ] ) ) {
					$dups[] = (int) $loc;
					continue;
				}
				$paras[ $loc ]    = $p;
				$by_page[ $i ][] = (int) $loc;
			}
			foreach ( $chunk['idx'] as $h => $v ) {
				if ( ! isset( $idx[ $h ] ) ) {
					$idx[ $h ] = $v;
				} else {
					$idx[ $h ] = array_values( array_unique( array_merge( (array) $idx[ $h ], (array) $v ) ) );
				}
			}
		}
		ksort( $paras, SORT_NUMERIC );
		$nums = array_map( 'intval', array_keys( $paras ) );
		$min  = $nums ? min( $nums ) : 0;
		$max  = $nums ? max( $nums ) : 0;

		// Gaps: in a full fetch, anything missing from 1 to the highest number. In a sample, only
		// numbers missing inside a run of consecutive pages (the rest was never fetched).
		$gaps = array();
		foreach ( $st['runs'] as $r ) {
			$rn = array();
			for ( $k = $r[0]; $k <= $r[1]; $k++ ) {
				if ( isset( $by_page[ $k ] ) ) {
					$rn = array_merge( $rn, $by_page[ $k ] );
				}
			}
			if ( ! $rn ) {
				continue;
			}
			$from = $st['sample'] ? min( $rn ) : 1;
			$to   = max( $rn );
			for ( $n = $from; $n <= $to; $n++ ) {
				if ( ! isset( $paras[ (string) $n ] ) ) {
					$gaps[] = $n;
				}
			}
		}
		$no_frag = array();
		foreach ( $paras as $loc => $p ) {
			if ( empty( $p['frag'] ) ) {
				$no_frag[ $loc ] = $p['reason'];
			}
		}

		$doc = array(
			'meta'  => array(
				'id'              => $id,
				'url'             => $entry['url'],
				'final_url'       => $entry['url'],
				'http'            => 200,
				'bytes'           => 0,
				'charset'         => 'multi-page',
				'content_type'    => '',
				'document_policy' => '',
				'fetched_at'      => time(),
				'fetch_ms'        => (int) $st['ms'],
				'parser_version'  => AF_Parser::VERSION,
				'source'          => 'fetched',
				'complete'        => true,
				'partial'         => ! empty( $st['sample'] ),
				'runs'            => $st['runs'],
				'pages'           => $total,
				'page_errors'     => $st['errors'],
				'expected_count'  => $entry['expected_count'] ?? null,
				'shingles'        => count( $idx ),
				'stats'           => array(
					'count'       => count( $paras ),
					'min'         => $min,
					'max'         => $max,
					'gaps'        => $gaps,
					'rejected'    => array(),
					'inferred'    => array(),
					'blocks'      => $blocks,
					'duplicates'  => $dups,
					'no_fragment' => $no_frag,
				),
			),
			'paras' => $paras,
		);
		self::save_index( $id, $idx );
		update_option( 'af_doc_' . $id, $doc, false );
		foreach ( $st['order'] as $i ) {
			delete_option( 'af_ip_' . $id . '_' . $i );
		}
		delete_option( 'af_ing_' . $id );

		return sprintf(
			'Finished%s: %d paragraphs (%d to %d) from %d pages, %d gap(s)%s, %d duplicate number(s), %d page error(s).',
			$st['sample'] ? ' (sample only)' : '',
			count( $paras ), $min, $max, $total, count( $gaps ),
			$gaps ? ' [' . implode( ', ', array_slice( $gaps, 0, 15 ) ) . ( count( $gaps ) > 15 ? ', …' : '' ) . ']' : '',
			count( $dups ), count( $st['errors'] )
		);
	}

	/**
	 * What decides whether this server can fetch the documents: outbound requests, limits, versions.
	 *
	 * @return array[] each { name, status ok|warn|fail, detail }
	 */
	public static function check_server() {
		$rows = array();
		$add  = function ( $name, $status, $detail ) use ( &$rows ) {
			$rows[] = array( 'name' => $name, 'status' => $status, 'detail' => $detail );
		};

		$add( 'PHP version', version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'warn', PHP_VERSION );
		$met = (int) ini_get( 'max_execution_time' );
		$add( 'PHP time limit', ( 0 === $met || $met >= 30 ) ? 'ok' : 'warn', 0 === $met ? 'none' : $met . ' seconds' . ( $met < 30 ? ' (a crawl step may be cut short; it carries on when you press the button again)' : '' ) );
		$mem = ini_get( 'memory_limit' );
		$mb  = (int) wp_convert_hr_to_bytes( $mem ) / MB_IN_BYTES;
		$add( 'PHP memory limit', ( $mb < 0 || $mb >= 128 ) ? 'ok' : 'warn', $mem . ( $mb >= 0 && $mb < 128 ? ' (loading a large index may fail)' : '' ) );
		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$hosts = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) WP_ACCESSIBLE_HOSTS : '';
			$need  = array( 'www.vatican.va', 'raw.githubusercontent.com' );
			$miss  = array();
			foreach ( $need as $n ) {
				if ( false === stripos( $hosts, $n ) && false === strpos( $hosts, '*' ) ) {
					$miss[] = $n;
				}
			}
			$add( 'Outgoing requests', $miss ? 'fail' : 'ok', $miss ? 'WP_HTTP_BLOCK_EXTERNAL is on and WP_ACCESSIBLE_HOSTS does not list: ' . implode( ', ', $miss ) : 'WP_HTTP_BLOCK_EXTERNAL is on; both hosts are allowed' );
		} else {
			$add( 'Outgoing requests', 'ok', 'not blocked by WordPress settings' );
		}
		if ( function_exists( 'curl_version' ) ) {
			$cv = curl_version();
			$add( 'cURL and OpenSSL', 'ok', 'cURL ' . $cv['version'] . ', ' . ( $cv['ssl_version'] ?? 'no SSL' ) );
		}

		$tests = array(
			array( 'vatican.va (robots.txt)', 'https://www.vatican.va/robots.txt' ),
			array( 'GitHub (hosted data)', self::HOSTED_PREFIX . 'main/README.md' ),
		);
		foreach ( $tests as $t ) {
			$t0  = microtime( true );
			$res = wp_safe_remote_get( $t[1], array( 'timeout' => 10, 'redirection' => 2, 'user-agent' => self::user_agent(), 'limit_response_size' => 200000 ) );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( is_wp_error( $res ) ) {
				$add( $t[0], 'fail', $res->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$extra = '';
			if ( false !== strpos( $t[1], 'robots.txt' ) && preg_match( '/crawl-delay:\s*(\d+)/i', wp_remote_retrieve_body( $res ), $m ) ) {
				$extra = '; it asks for a ' . $m[1] . '-second pause between requests';
			}
			$add( $t[0], 200 === $code ? 'ok' : ( 404 === $code && false !== strpos( $t[1], 'github' ) ? 'ok' : 'fail' ), 'HTTP ' . $code . ' in ' . $ms . ' ms' . $extra . ( 404 === $code && false !== strpos( $t[1], 'github' ) ? ' (reachable)' : '' ) );
		}
		return $rows;
	}

	/** One row per registry document, for the test page. */
	public static function status() {
		$rows = array();
		foreach ( self::registry() as $id => $entry ) {
			$doc = get_option( 'af_doc_' . $id );
			$st  = get_option( 'af_ing_' . $id );
			$row = array(
				'id'        => $id,
				'title'     => $entry['title'],
				'multipage' => ! empty( $entry['multipage'] ),
				'hosted'    => ! empty( $entry['hosted'] ),
				'pdf'       => false !== stripos( (string) ( $entry['url'] ?? '' ), '.pdf' ),
				'text'      => count( self::stored_text( $id ) ),
				'expected'  => $entry['expected_count'] ?? null,
				'indexed'   => self::has_index( $id ),
				'count'     => is_array( $doc ) ? (int) $doc['meta']['stats']['count'] : null,
				'gaps'      => is_array( $doc ) ? count( $doc['meta']['stats']['gaps'] ) : null,
				'version'   => is_array( $doc ) ? (int) $doc['meta']['parser_version'] : null,
				'partial'   => is_array( $doc ) && ! empty( $doc['meta']['partial'] ),
				'progress'  => is_array( $st ) ? array( 'done' => (int) $st['next'], 'total' => count( $st['order'] ) ) : null,
			);
			$rows[] = $row;
		}
		return $rows;
	}
}
