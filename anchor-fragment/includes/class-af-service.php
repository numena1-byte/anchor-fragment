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
		$parsed                 = AF_Parser::parse( $html );

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
		update_option( 'af_idx_' . $id, $idx, false );
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
			'url'      => $reg[ $id ]['url'],
			'fragment' => $p['frag'],
			'words'    => $p['words'],
			'link'     => AF_Fragment::link( $reg[ $id ]['url'], $p['frag'] ),
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

		// Every document needs a current index. This fetches any that are missing (2 seconds apart).
		foreach ( array_keys( self::registry() ) as $id ) {
			$doc = self::get_doc( $id, false );
			if ( ! is_wp_error( $doc ) && ! is_array( get_option( 'af_idx_' . $id ) ) ) {
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
				$i = get_option( 'af_idx_' . $doc_id );
				return is_array( $i ) ? $i : null;
			}
		);

		$res = array(
			'post_id'        => $post->ID,
			'title'          => get_the_title( $post ),
			'url'            => get_permalink( $post ),
			'ran_at'         => time(),
			'plugin_version' => AF_VERSION,
		) + $res;
		update_option( 'af_match_' . $post->ID, $res, false );
		return $res;
	}
}
