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
		if ( empty( $p['frag'] ) ) {
			return new WP_Error( 'af_no_fragment', 'Paragraph found but no reliable fragment could be built: ' . $p['reason'], array( 'status' => 422 ) );
		}
		$reg = self::registry();
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
	public static function analyze_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'af_no_post', 'No such post: ' . (int) $post_id, array( 'status' => 404 ) );
		}

		// Every single-page document needs a current index; this fetches any that are missing
		// (2 seconds apart). Multi-page documents are never fetched here: they are skipped until
		// they have been fetched from the test page.
		$unindexed = array();
		foreach ( self::registry() as $id => $entry ) {
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
	public static function ingest_step( $id, $budget = 25.0, $restart = false, $sample = false ) {
		$reg = self::registry();
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
