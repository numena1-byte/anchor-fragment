<?php
/**
 * An optional second opinion on citations the words could not confirm.
 *
 * The matcher compares exact runs of words. When an author cites a paragraph and the quoted words are not in it
 * (status not_found, or a post that is itself a translation), the cause is either a wrong cite or a faithful
 * translation, and word runs cannot tell the two apart. A second-opinion service can: it compares meaning.
 *
 * The plugin does nothing here until the site owner enters the address and key of such a service. It then sends
 * only the quotation, the cited document (id, title, address) and the paragraph number, and only when the owner
 * presses the button. The service answers with signals (see below); the plugin applies its own rule to them, so
 * the rule stays the same whichever service answers.
 *
 * Request (POST, JSON, header x-key: the key):
 *   { "version": 1, "quote": "...", "document": { "id", "title", "short", "url" }, "locator": "25", "language": "en" }
 *   { "version": 1, "ping": true }   (a connection test: sends no quotation)
 *   For a document that spans many pages (the Catechism) the plugin reads the cited paragraph itself and adds
 *   "passage": "<its text>"; the service then compares the two texts and does not rank the paragraph among the others (rank 0).
 *   The same for a PDF, using the paragraph text an author added from it on this site; with no text added the paragraph is not asked.
 *
 * Answer:
 *   { "version": 1, "ok": true,
 *     "signals": { "rank": 1, "score": 812, "lead": 120, "judge": "faithful_translation", "reason": "...", "better": null },
 *     "models": { "embedding": "bge-m3", "judge": "llama-3.3-70b" } }
 *   or { "version": 1, "ok": false, "error": "..." }
 *
 *   rank   1 when the cited paragraph is the closest in meaning to the quotation, 2 when one other is closer, and so on; 0 or null when not ranked
 *   score  how close the cited paragraph is, 0 to 1000 (thousandths of a cosine similarity)
 *   lead   how far the closest paragraph is ahead of the next one, 0 to 1000
 *   judge  a model's reading of the two texts: faithful_translation, loose_paraphrase, related_but_different or unrelated
 *   better the number of the closest paragraph when it is not the cited one
 *
 * @package AnchorFragment
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Semantic {

	/** The request and answer format, and the rule below. A stored answer made under another version is asked again. */
	const VERSION = 2;

	const OPT_URL = 'af_semantic_url';
	const OPT_KEY = 'af_semantic_key';

	/**
	 * The rule (0.13.1). A model that calls the cited paragraph a faithful translation, with the paragraph at least this close,
	 * is an agreement whatever its rank: short quotations rank badly because many paragraphs are about as close.
	 */
	const MIN_SCORE = 500;
	/** The cited paragraph first and this close in wording, yet a model sees a difference: another translation is likely, so unclear, not a disagreement. */
	const NEAR_SCORE = 750;
	/** Below this the paragraph is not close to the quotation at all. */
	const LOW_SCORE = 450;
	/** A quotation shorter than this is not sent. */
	const MIN_CHARS = 20;

	const TIMEOUT = 40;

	/** Tests set this to function ( $url, $key, $payload ) returning the decoded answer or a WP_Error. */
	public static $transport = null;

	/* ------------------------------------------------------------------ settings */

	public static function url() {
		return trim( (string) get_option( self::OPT_URL, '' ) );
	}

	private static function key() {
		return trim( (string) get_option( self::OPT_KEY, '' ) );
	}

	public static function configured() {
		return '' !== self::url() && '' !== self::key();
	}

	/** What the settings form shows. The key itself is never sent back to the browser. */
	public static function settings() {
		return array( 'url' => self::url(), 'key_set' => '' !== self::key() );
	}

	/**
	 * @param string      $url An https address; empty turns the second opinion off and forgets the key.
	 * @param string|null $key The key; empty keeps the one already saved.
	 * @return true|WP_Error
	 */
	public static function save_settings( $url, $key = null ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			delete_option( self::OPT_URL );
			delete_option( self::OPT_KEY );
			return true;
		}
		if ( ! preg_match( '#^https://[^\s/]+#i', $url ) || ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) ) {
			return new WP_Error( 'af_semantic_url', 'Enter an https:// address that is reachable from the internet.', array( 'status' => 400 ) );
		}
		update_option( self::OPT_URL, $url, false );
		if ( null !== $key && '' !== trim( (string) $key ) ) {
			update_option( self::OPT_KEY, trim( (string) $key ), false );
		}
		return true;
	}

	/* ------------------------------------------------------------------ talking to the service */

	private static function post( array $payload ) {
		$url = self::url();
		$key = self::key();
		if ( '' === $url || '' === $key ) {
			return new WP_Error( 'af_semantic_off', 'No second-opinion service is set up.', array( 'status' => 400 ) );
		}
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, $url, $key, $payload );
		}
		$res = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'content-type' => 'application/json',
					'x-key'        => $key,
					'user-agent'   => 'AnchorFragment/' . AF_VERSION . '; ' . home_url(),
				),
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'af_semantic_net', 'Could not reach the service: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'af_semantic_http', 'The service answered HTTP ' . $code . ( in_array( $code, array( 401, 403 ), true ) ? ' (is the key right?)' : '' ) . '.', array( 'status' => 502 ) );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) ) {
			return new WP_Error( 'af_semantic_format', 'The service did not answer with JSON.', array( 'status' => 502 ) );
		}
		return $j;
	}

	/** A connection test. Sends no quotation. */
	public static function ping() {
		$j = self::post( array( 'version' => self::VERSION, 'ping' => true ) );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		if ( empty( $j['ok'] ) ) {
			return new WP_Error( 'af_semantic_service', 'The service says: ' . (string) ( $j['error'] ?? 'no reason given' ), array( 'status' => 502 ) );
		}
		return array( 'ok' => true, 'models' => (array) ( $j['models'] ?? array() ) );
	}

	/* ------------------------------------------------------------------ the rule */

	/**
	 * What an answer means for the citation. The plugin applies this, whichever service answered.
	 *
	 * @return array { result: agrees|disagrees|inconclusive|error, reason }
	 */
	public static function classify( array $answer ) {
		if ( empty( $answer['ok'] ) ) {
			return array( 'result' => 'error', 'reason' => (string) ( $answer['error'] ?? 'the service gave no reason' ) );
		}
		$s     = (array) ( $answer['signals'] ?? array() );
		$rank  = (int) ( $s['rank'] ?? 0 ); // 0: the paragraph was compared alone, not ranked among the others
		$score = (int) ( $s['score'] ?? 0 );
		$lead  = (int) ( $s['lead'] ?? 0 ); // kept in the answer; the 0.13.1 rule no longer needs it
		$judge = (string) ( $s['judge'] ?? '' );
		if ( ! isset( $s['score'] ) ) {
			return array( 'result' => 'error', 'reason' => 'the answer had no score' );
		}
		if ( 'faithful_translation' === $judge && $score >= self::MIN_SCORE ) {
			return array( 'result' => 'agrees', 'reason' => $rank <= 1 ? 'the cited paragraph is the closest in meaning and a model reads it as a faithful translation' : 'a model reads the cited paragraph as a faithful translation (another paragraph is closer in meaning, but this one is close enough)' );
		}
		if ( 'unrelated' === $judge ) {
			return array( 'result' => 'disagrees', 'reason' => 'a model reads the paragraph as unrelated' );
		}
		if ( $rank <= 1 && $score >= self::NEAR_SCORE ) {
			return array( 'result' => 'inconclusive', 'reason' => 'the paragraph is very close in wording, but a model sees a difference; it may be another translation' );
		}
		if ( 'related_but_different' === $judge ) {
			return array( 'result' => 'disagrees', 'reason' => 'a model reads the paragraph as related but different' );
		}
		if ( $rank > 1 ) {
			return array( 'result' => 'disagrees', 'reason' => 'another paragraph is closer in meaning' );
		}
		if ( $score < self::LOW_SCORE ) {
			return array( 'result' => 'disagrees', 'reason' => 'the paragraph is not close in meaning' );
		}
		return array( 'result' => 'inconclusive', 'reason' => '' === $judge ? 'no model reading' : 'close, but not clear enough to confirm' );
	}

	/* ------------------------------------------------------------------ what was said, kept per post */

	private static function key_of( $hash, $doc, $loc ) {
		return (string) $hash . '|' . (string) $doc . '|' . (int) $loc;
	}

	/**
	 * The stored answer for one quotation and paragraph, or null. An answer made under the first rule is read again under the
	 * current one from the signals it kept, so nothing is asked twice; one that was "not asked" is asked again, because what
	 * kept it from being asked may have changed.
	 */
	public static function lookup( $post_id, $hash, $doc, $loc ) {
		if ( ! $post_id || '' === (string) $hash ) {
			return null;
		}
		$store = get_option( 'af_sem_' . (int) $post_id );
		$k     = self::key_of( $hash, $doc, $loc );
		if ( ! is_array( $store ) || ! isset( $store[ $k ] ) ) {
			return null;
		}
		$rec  = $store[ $k ];
		$rule = (int) ( $rec['rule'] ?? 0 );
		if ( self::VERSION === $rule ) {
			return $rec;
		}
		if ( 1 === $rule && 'skipped' !== ( $rec['result'] ?? '' ) && isset( $rec['rank'], $rec['score'] ) ) {
			$v = self::classify( array( 'ok' => true, 'signals' => array( 'rank' => $rec['rank'], 'score' => $rec['score'], 'lead' => $rec['lead'] ?? 0, 'judge' => $rec['judge'] ?? '' ) ) );
			if ( 'error' !== $v['result'] ) {
				$rec['result'] = $v['result'];
				$rec['rule']   = self::VERSION;
				return $rec;
			}
		}
		return null;
	}

	public static function save( $post_id, $hash, $doc, $loc, array $rec ) {
		$store = get_option( 'af_sem_' . (int) $post_id );
		$store = is_array( $store ) ? $store : array();
		$store[ self::key_of( $hash, $doc, $loc ) ] = $rec + array( 'rule' => self::VERSION, 'checked_at' => time() );
		update_option( 'af_sem_' . (int) $post_id, $store, false );
	}

	/** One line for the review screen. */
	public static function summary( array $rec ) {
		if ( 'skipped' === $rec['result'] ) {
			return 'not asked: ' . (string) ( $rec['reason'] ?? '' );
		}
		$bits = array();
		if ( isset( $rec['score'] ) ) {
			$bits[] = 'similarity ' . round( (int) $rec['score'] / 10 ) . '%';
		}
		if ( ! empty( $rec['rank'] ) ) {
			$bits[] = 'cited paragraph ranked ' . (int) $rec['rank'] . ( ! empty( $rec['better'] ) ? ' (closer: ' . $rec['better'] . ')' : '' );
		}
		if ( ! empty( $rec['judge'] ) ) {
			$bits[] = str_replace( '_', ' ', $rec['judge'] );
		}
		return $rec['result'] . ( $bits ? ' (' . implode( ', ', $bits ) . ')' : '' ) . ( ! empty( $rec['reason'] ) ? ': ' . $rec['reason'] : '' );
	}

	/** The `method` entry the published record would carry for this answer (see the notebook's lexicon section). */
	public static function method_entry( array $rec ) {
		$m = array( 'name' => 'semantic', 'result' => 'agrees' === $rec['result'] ? 'agrees' : 'disagrees' );
		if ( isset( $rec['score'] ) ) {
			$m['score'] = (int) $rec['score'];
		}
		if ( ! empty( $rec['models']['embedding'] ) ) {
			$m['model'] = (string) $rec['models']['embedding'];
		}
		return $m;
	}

	/* ------------------------------------------------------------------ asking, a few at a time */

	/**
	 * Ask about the next citations the words could not confirm. Call again with the returned 'last' until 'done'.
	 *
	 * @param int   $after  Edge id to continue after.
	 * @param bool  $force  Ask again about ones already answered.
	 * @param float $budget Seconds to spend in this call (a request to the service can take several).
	 * @return array|WP_Error { processed, agrees, disagrees, inconclusive, skipped, errors[], last, remaining, done }
	 */
	public static function step( $after = 0, $force = false, $budget = 20.0 ) {
		global $wpdb;
		if ( ! self::configured() ) {
			return new WP_Error( 'af_semantic_off', 'No second-opinion service is set up.', array( 'status' => 400 ) );
		}
		$t        = AF_Graph::table();
		$statuses = $force ? "'not_found','translation','semantic'" : "'not_found','translation'";
		$limit    = 40;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id AS id, c.post_id AS post_id, c.doc AS doc, c.loc_num AS loc_num, c.quote_hash AS quote_hash, c.quote_ord AS quote_ord FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE p.post_status = 'publish' AND c.basis = 'typed' AND c.status IN ({$statuses}) AND c.confirmed IS NULL AND c.id > %d ORDER BY c.id ASC LIMIT %d",
				(int) $after,
				(int) $limit
			),
			ARRAY_A
		);
		$out      = array( 'processed' => 0, 'agrees' => 0, 'disagrees' => 0, 'inconclusive' => 0, 'skipped' => 0, 'errors' => array(), 'last' => (int) $after, 'remaining' => 0, 'done' => false );
		$t0       = microtime( true );
		$reg      = AF_Service::registry();
		$analysed = array();
		$stopped  = false;
		$bad_docs = array();
		foreach ( (array) $rows as $r ) {
			if ( microtime( true ) - $t0 > $budget && $out['processed'] > 0 ) {
				$stopped = true;
				break;
			}
			$post_id = (int) $r['post_id'];
			$hash    = (string) $r['quote_hash'];
			if ( ! $force && self::lookup( $post_id, $hash, $r['doc'], $r['loc_num'] ) ) {
				$out['last'] = (int) $r['id'];
				continue;
			}
			$info = self::quote_info( $post_id, $hash, (int) $r['quote_ord'] );
			if ( '' === $info['text'] && empty( $analysed[ $post_id ] ) ) {
				// The post was matched before the words were kept; match it again once.
				$analysed[ $post_id ] = true;
				AF_Service::analyze_post( $post_id, false );
				$info = self::quote_info( $post_id, $hash, (int) $r['quote_ord'] );
			}
			$text  = $info['text'];
			$entry = $reg[ $r['doc'] ] ?? null;
			$why   = '';
			if ( 'out_of_scope' === $info['status'] ) {
				$why = 'its words were found in another document' . ( '' !== $info['note'] ? ' (' . $info['note'] . ')' : '' );
			} elseif ( '' === $text ) {
				$why = 'the words of the quotation were not kept; match the post again';
			} elseif ( mb_strlen( $text ) < self::MIN_CHARS ) {
				$why = 'the quotation is too short';
			} elseif ( ! $entry ) {
				$why = 'unknown document';
			} elseif ( ! empty( $bad_docs[ $r['doc'] ] ) ) {
				$out['errors'][] = $entry['title'] . ': the service could not read this document, so it was not asked again in this run';
				$out['last']     = (int) $r['id'];
				continue;
			}
			if ( '' !== $why ) {
				self::save( $post_id, $hash, $r['doc'], $r['loc_num'], array( 'result' => 'skipped', 'reason' => $why ) );
				$out['skipped']++;
				$out['last'] = (int) $r['id'];
				continue;
			}
			$request = array(
				'version'  => self::VERSION,
				'quote'    => $text,
				'document' => array( 'id' => $r['doc'], 'title' => $entry['title'] ?? $r['doc'], 'short' => $entry['short'] ?? '', 'url' => $entry['url'] ),
				'locator'  => (string) (int) $r['loc_num'],
				'language' => $entry['language'] ?? 'en',
			);
			if ( ! empty( $entry['multipage'] ) || false !== stripos( (string) $entry['url'], '.pdf' ) ) {
				// Many pages, or a PDF: this site reads the cited paragraph (a PDF's text is what an author added from it) and the service compares the two texts.
				$p = AF_Service::passage( $r['doc'], (int) $r['loc_num'] );
				if ( is_wp_error( $p ) && 'af_pdf_text' === $p->get_error_code() ) {
					$out['skipped']++; // not saved: it is asked once the text has been added
					$out['last'] = (int) $r['id'];
					continue;
				}
				if ( is_wp_error( $p ) || '' === trim( (string) ( $p['text'] ?? '' ) ) ) {
					$out['errors'][] = '#' . $post_id . ' ' . $r['doc'] . ' ' . $r['loc_num'] . ': the paragraph text could not be read (' . ( is_wp_error( $p ) ? $p->get_error_message() : 'empty' ) . ')';
					$out['last']     = (int) $r['id'];
					continue;
				}
				$request['passage'] = mb_substr( (string) $p['text'], 0, 6000 );
			}
			$answer = self::post( $request );
			if ( is_wp_error( $answer ) ) {
				$out['errors'][] = $answer->get_error_message();
				$stopped         = true; // do not keep asking a service that is not answering
				break;
			}
			$verdict = self::classify( $answer );
			if ( 'error' === $verdict['result'] && false !== stripos( (string) $verdict['reason'], 'could not read numbered paragraphs' ) ) {
				$bad_docs[ $r['doc'] ] = true;
			}
			if ( 'error' === $verdict['result'] ) {
				$out['errors'][] = '#' . $post_id . ' ' . $r['doc'] . ' ' . $r['loc_num'] . ': ' . $verdict['reason'];
				$out['last']     = (int) $r['id'];
				continue;
			}
			$s   = (array) ( $answer['signals'] ?? array() );
			$rec = array(
				'result' => $verdict['result'],
				'reason' => mb_substr( (string) ( $s['reason'] ?? $verdict['reason'] ), 0, 160 ),
				'rank'   => (int) ( $s['rank'] ?? 0 ),
				'score'  => (int) ( $s['score'] ?? 0 ),
				'lead'   => (int) ( $s['lead'] ?? 0 ),
				'judge'  => (string) ( $s['judge'] ?? '' ),
				'better' => isset( $s['better'] ) && null !== $s['better'] ? (string) $s['better'] : '',
				'models' => array_map( 'strval', array_slice( (array) ( $answer['models'] ?? array() ), 0, 4 ) ),
			);
			self::save( $post_id, $hash, $r['doc'], $r['loc_num'], $rec );
			$out['processed']++;
			$out[ $verdict['result'] ]++;
			$out['last'] = (int) $r['id'];
			// Rebuild the post's edges so an agreement shows in the graph.
			$m = get_option( 'af_match_' . $post_id );
			if ( is_array( $m ) ) {
				AF_Graph::record_post( $post_id, $m );
			}
		}
		$out['remaining'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE p.post_status = 'publish' AND c.basis = 'typed' AND c.status IN ({$statuses}) AND c.confirmed IS NULL AND c.id > %d",
				(int) $out['last']
			)
		);
		$out['done'] = ! $stopped && 0 === $out['remaining'];
		return $out;
	}

	/** The words, status and note of a quotation, from the post's stored match result. The words are empty when they were not kept. */
	private static function quote_info( $post_id, $hash, $ord ) {
		$none = array( 'text' => '', 'status' => '', 'note' => '' );
		$m    = get_option( 'af_match_' . (int) $post_id );
		if ( ! is_array( $m ) ) {
			return $none;
		}
		foreach ( (array) ( $m['quotes'] ?? array() ) as $q ) {
			if ( ( '' !== $hash && ( $q['hash'] ?? '' ) === $hash ) || ( '' === $hash && (int) $q['i'] === (int) $ord ) ) {
				return array( 'text' => trim( (string) ( $q['text'] ?? '' ) ), 'status' => (string) ( $q['status'] ?? '' ), 'note' => (string) ( $q['note'] ?? '' ) );
			}
		}
		return $none;
	}
}
