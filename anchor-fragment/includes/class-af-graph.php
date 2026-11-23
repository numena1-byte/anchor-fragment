<?php
/**
 * The citation graph: which post cites which paragraph of which document.
 *
 * One row of {prefix}af_citations is one edge: post -> (document, paragraph). The columns follow the draft AT Protocol
 * record, so that a later step can publish them without reshaping:
 *
 *   post_id            -> post (the post's URL, later a strong reference)
 *   doc, locator       -> anchor.document, anchor.locator
 *   basis              -> basis   ("typed": the author cited it and the text agrees; "text-match": found by the words alone)
 *   status             -> how sure the matcher is: high, medium, low, ambiguous, conflict, partial, cross_reference, unchecked, no_index, not_found
 *   quote_hash         -> commentary.contentHash (a fingerprint of the quotation; no text is kept)
 *   matched_at         -> matchedAt
 *   confirmed          -> confirmed (NULL not reviewed, 1 yes, 0 no); kept when a post is matched again
 *   at_uri             -> where the record was published, when it is
 *
 * No quotation text is stored here, only the address of the paragraph and how it was found.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AF_Graph {

	const DB_VERSION = '1';
	const META_KEY   = '_af_matched';
	/** Statuses that count as "the post cites this paragraph" unless a report asks for more or less. */
	const CONFIDENT = array( 'high', 'medium', 'cross_reference' );
	/** An alternative counts as a candidate when it covers the quotation within this much of the best match. */
	const TIE = 0.05;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'af_citations';
	}

	public static function post_types() {
		return (array) apply_filters( 'af_graph_post_types', array( 'post', 'page' ) );
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'af_match_post', array( __CLASS__, 'cron_match' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_post' ) );
	}

	/* ------------------------------------------------------------------ table */

	public static function maybe_install() {
		if ( get_option( 'af_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  quote_ord smallint(5) unsigned NOT NULL DEFAULT 0,
  doc varchar(64) NOT NULL,
  locator varchar(32) NOT NULL,
  loc_num int(10) unsigned NOT NULL DEFAULT 0,
  basis varchar(16) NOT NULL,
  status varchar(16) NOT NULL,
  cover float DEFAULT NULL,
  words smallint(5) unsigned NOT NULL DEFAULT 0,
  quote_hash char(16) NOT NULL DEFAULT '',
  matched_at datetime NOT NULL,
  plugin_version varchar(16) NOT NULL DEFAULT '',
  confirmed tinyint(1) DEFAULT NULL,
  at_uri varchar(255) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY anchor (doc,loc_num),
  KEY status (status)
) {$charset};"
		);
		update_option( 'af_db_version', self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ recording */

	/**
	 * The edges one post's match result describes.
	 *
	 * @param array $res The result of AF_Analyzer::analyze() (with 'quotes').
	 * @return array key => row
	 */
	public static function rows_from_result( array $res ) {
		$rows = array();
		$add  = function ( $q, $doc, $loc, $basis, $status, $cover ) use ( &$rows ) {
			$hash = isset( $q['hash'] ) ? (string) $q['hash'] : '';
			$key  = ( '' !== $hash ? $hash : 'q' . (int) $q['i'] ) . '|' . $doc . '|' . (int) $loc . '|' . $basis;
			if ( isset( $rows[ $key ] ) ) {
				return;
			}
			$rows[ $key ] = array(
				'quote_ord'  => (int) $q['i'],
				'doc'        => (string) $doc,
				'locator'    => (string) (int) $loc,
				'loc_num'    => (int) $loc,
				'basis'      => $basis,
				'status'     => $status,
				'cover'      => $cover,
				'words'      => (int) ( $q['words'] ?? 0 ),
				'quote_hash' => $hash,
			);
		};
		foreach ( (array) ( $res['quotes'] ?? array() ) as $q ) {
			$status = $q['status'];
			if ( ! empty( $q['best'] ) && in_array( $status, array( 'high', 'medium', 'low', 'ambiguous', 'conflict', 'partial', 'cross_reference' ), true ) ) {
				foreach ( $q['best']['locators'] as $loc ) {
					$add( $q, $q['best']['doc'], $loc, 'high' === $status ? 'typed' : 'text-match', $status, round( (float) $q['best']['coverage'], 3 ) );
				}
				// An ambiguous quotation is a choice between candidates, so the ones that fit as well belong in the graph too.
				if ( 'ambiguous' === $status ) {
					foreach ( (array) ( $q['alt'] ?? array() ) as $a ) {
						if ( (float) $a['coverage'] >= (float) $q['best']['coverage'] - self::TIE ) {
							foreach ( $a['locators'] as $loc ) {
								$add( $q, $a['doc'], $loc, 'text-match', 'ambiguous', round( (float) $a['coverage'], 3 ) );
							}
						}
					}
				}
			}
			// A citation the author typed is an edge even where the words could not confirm it.
			foreach ( (array) ( $q['typed'] ?? array() ) as $t ) {
				if ( empty( $t['doc'] ) || empty( $t['loc'] ) ) {
					continue;
				}
				foreach ( $t['loc'] as $loc ) {
					// When the quotation is confirmed by a typed cite, other numbers found near it are not edges.
					if ( 'high' === $status && ! ( ! empty( $q['best'] ) && $t['doc'] === $q['best']['doc'] && in_array( $loc, $q['best']['locators'], true ) ) ) {
						continue;
					}
					if ( 'conflict' === $status || in_array( $status, array( 'high', 'cross_reference' ), true ) ) {
						$typed_status = $status;
					} elseif ( isset( $t['indexed'] ) && ! $t['indexed'] ) {
						$typed_status = 'no_index'; // The cited document has no index here, so nothing could be checked.
					} elseif ( isset( $t['indexed'] ) && in_array( $status, array( 'no_match', 'too_short', 'out_of_scope' ), true ) ) {
						$typed_status = 'not_found'; // The document is indexed and the quoted words are in none of it: a likely misquote or paraphrase.
					} else {
						$typed_status = 'unchecked';
					}
					$add( $q, $t['doc'], $loc, 'typed', $typed_status, null );
				}
			}
		}
		return $rows;
	}

	private static function key_of( $r ) {
		return ( '' !== $r['quote_hash'] ? $r['quote_hash'] : 'q' . (int) $r['quote_ord'] ) . '|' . $r['doc'] . '|' . (int) $r['loc_num'] . '|' . $r['basis'];
	}

	/**
	 * Replace what is recorded for a post with what its match result says. A review (confirmed) survives when the
	 * same quotation still cites the same paragraph.
	 *
	 * @return array { added, updated, removed }
	 */
	public static function record_post( $post_id, array $res ) {
		global $wpdb;
		$t   = self::table();
		$new = self::rows_from_result( $res );
		$now = gmdate( 'Y-m-d H:i:s' );
		$old = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE post_id = %d", (int) $post_id ), ARRAY_A );
		$out = array( 'added' => 0, 'updated' => 0, 'removed' => 0 );
		foreach ( (array) $old as $o ) {
			$k = self::key_of( $o );
			if ( isset( $new[ $k ] ) ) {
				$r = $new[ $k ];
				unset( $new[ $k ] );
				$wpdb->update(
					$t,
					array(
						'quote_ord'      => $r['quote_ord'],
						'status'         => $r['status'],
						'cover'          => $r['cover'],
						'words'          => $r['words'],
						'matched_at'     => $now,
						'plugin_version' => AF_VERSION,
					),
					array( 'id' => (int) $o['id'] )
				);
				$out['updated']++;
			} else {
				$wpdb->delete( $t, array( 'id' => (int) $o['id'] ) );
				$out['removed']++;
			}
		}
		foreach ( $new as $r ) {
			$wpdb->insert( $t, array( 'post_id' => (int) $post_id ) + $r + array( 'matched_at' => $now, 'plugin_version' => AF_VERSION ) );
			$out['added']++;
		}
		update_post_meta( (int) $post_id, self::META_KEY, time() . ':' . AF_VERSION );
		return $out;
	}

	public static function forget_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ) );
		delete_post_meta( (int) $post_id, self::META_KEY );
	}

	/** Matched with this plugin version after its last change? */
	public static function is_fresh( $post_id, $modified_gmt ) {
		$m = (string) get_post_meta( (int) $post_id, self::META_KEY, true );
		if ( '' === $m || false === strpos( $m, ':' ) ) {
			return false;
		}
		list( $ts, $ver ) = explode( ':', $m, 2 );
		return AF_VERSION === $ver && (int) $ts >= (int) strtotime( $modified_gmt . ' UTC' );
	}

	/* ------------------------------------------------------------------ matching posts (batch and on save) */

	/**
	 * Match the next posts after the given ID. Call again with the returned 'last' until 'done'.
	 *
	 * @return array { processed, skipped, errors[], last, remaining, done, citations }
	 */
	public static function rebuild_step( $after = 0, $force = false, $budget = 20, $limit = 25 ) {
		global $wpdb;
		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d",
				array_merge( $types, array( (int) $after, (int) $limit ) )
			),
			ARRAY_A
		);
		$t0   = microtime( true );
		$out  = array( 'processed' => 0, 'skipped' => 0, 'errors' => array(), 'last' => (int) $after, 'citations' => 0 );
		foreach ( (array) $rows as $r ) {
			if ( microtime( true ) - $t0 > $budget ) {
				break;
			}
			$out['last'] = (int) $r['ID'];
			if ( ! $force && self::is_fresh( $r['ID'], $r['post_modified_gmt'] ) ) {
				$out['skipped']++;
				continue;
			}
			$res = AF_Service::analyze_post( (int) $r['ID'], false );
			if ( is_wp_error( $res ) ) {
				$out['errors'][] = $r['ID'] . ': ' . $res->get_error_message();
				continue;
			}
			$out['processed']++;
		}
		$out['remaining'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d",
				array_merge( $types, array( $out['last'] ) )
			)
		);
		$out['done'] = 0 === $out['remaining'];
		return $out;
	}

	public static function on_save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) || ! apply_filters( 'af_auto_match', true, $post_id ) ) {
			return;
		}
		// Matching can take a few seconds, so it runs shortly after the save, not during it.
		if ( ! wp_next_scheduled( 'af_match_post', array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 15, 'af_match_post', array( (int) $post_id ) );
		}
	}

	public static function cron_match( $post_id ) {
		AF_Service::analyze_post( (int) $post_id, false );
	}

	/* ------------------------------------------------------------------ reading the graph */

	/**
	 * @param array $f Filters: doc, locator, status (array; default CONFIDENT), basis, post_id, confirmed ('yes'|'no'|'unreviewed').
	 * @return array [ where-sql, args ]
	 */
	private static function where( array $f ) {
		global $wpdb;
		$w    = array( "p.post_status = 'publish'" );
		$args = array();
		$st   = ! empty( $f['status'] ) ? (array) $f['status'] : self::CONFIDENT;
		if ( 'all' !== $st[0] ) {
			// An edge a reviewer confirmed counts whatever the matcher thought of it.
			$in   = 'c.status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$w[]  = empty( $f['strict'] ) ? "({$in} OR c.confirmed = 1)" : $in; // 'strict': exactly these statuses (the review list)
			$args = array_merge( $args, array_values( $st ) );
		}
		if ( empty( $f['confirmed'] ) || ! in_array( $f['confirmed'], array( 'no', 'any' ), true ) ) {
			$w[] = '(c.confirmed IS NULL OR c.confirmed = 1)'; // rejected edges are left out of every view
		}
		foreach ( array( 'doc', 'basis' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[]    = "c.$k = %s";
				$args[] = $f[ $k ];
			}
		}
		if ( isset( $f['locator'] ) && '' !== $f['locator'] ) {
			$w[]    = 'c.loc_num = %d';
			$args[] = (int) $f['locator'];
		}
		if ( ! empty( $f['post_id'] ) ) {
			$w[]    = 'c.post_id = %d';
			$args[] = (int) $f['post_id'];
		}
		if ( ! empty( $f['confirmed'] ) ) {
			$w[] = array( 'yes' => 'c.confirmed = 1', 'no' => 'c.confirmed = 0', 'unreviewed' => 'c.confirmed IS NULL' )[ $f['confirmed'] ] ?? '1=1';
		}
		return array( implode( ' AND ', $w ), $args );
	}

	private static function run( $sql, array $args, $filters_args = array() ) {
		global $wpdb;
		$all = array_merge( $filters_args, $args );
		return $wpdb->get_results( $all ? $wpdb->prepare( $sql, $all ) : $sql, ARRAY_A );
	}

	/** Counts for the whole graph under the filters: edges, posts, paragraphs, and the split by document and by status. */
	public static function summary( array $f = array() ) {
		global $wpdb;
		$t = self::table();
		list( $w, $a ) = self::where( $f );
		$from = "{$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$w}";
		$tot  = self::run( "SELECT COUNT(*) AS edges, COUNT(DISTINCT c.post_id) AS posts FROM {$from}", array(), $a );
		$par  = self::run( "SELECT COUNT(*) AS n FROM (SELECT DISTINCT c.doc, c.loc_num FROM {$from}) x", array(), $a );
		$by   = self::run( "SELECT c.doc AS doc, COUNT(*) AS edges, COUNT(DISTINCT c.post_id) AS posts, COUNT(DISTINCT c.loc_num) AS paragraphs FROM {$from} GROUP BY c.doc ORDER BY posts DESC, c.doc", array(), $a );
		// By status ignores the status filter: it shows what is there to filter.
		$f2 = $f;
		$f2['status'] = array( 'all' );
		list( $w2, $a2 ) = self::where( $f2 );
		$st = self::run( "SELECT c.status AS status, COUNT(*) AS edges FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$w2} GROUP BY c.status ORDER BY edges DESC", array(), $a2 );
		$pm = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY ) );
		return array(
			'edges'      => (int) $tot[0]['edges'],
			'posts'      => (int) $tot[0]['posts'],
			'paragraphs' => (int) $par[0]['n'],
			'analyzed'   => $pm,
			'by_doc'     => $by,
			'by_status'  => $st,
		);
	}

	/** The most cited paragraphs under the filters. */
	public static function top_paragraphs( array $f = array(), $limit = 15 ) {
		global $wpdb;
		$t = self::table();
		list( $w, $a ) = self::where( $f );
		return self::run(
			"SELECT c.doc AS doc, c.loc_num AS locator, COUNT(DISTINCT c.post_id) AS posts, COUNT(*) AS edges FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$w} GROUP BY c.doc, c.loc_num ORDER BY posts DESC, edges DESC, c.doc, c.loc_num LIMIT " . (int) $limit,
			array(),
			$a
		);
	}

	/** Posts that cite a paragraph (or any paragraph of a document), newest first. */
	public static function citing_posts( array $f, $limit = 100 ) {
		global $wpdb;
		$t = self::table();
		list( $w, $a ) = self::where( $f );
		return self::run(
			"SELECT c.post_id AS post_id, p.post_title AS title, c.doc AS doc, c.locator AS locator, c.basis AS basis, c.status AS status, c.cover AS cover, c.confirmed AS confirmed, p.post_date AS date FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$w} ORDER BY p.post_date DESC, c.post_id DESC, c.doc, c.loc_num LIMIT " . (int) $limit,
			array(),
			$a
		);
	}

	/** Statuses a reviewer picks from the filter, by name. */
	public static function status_set( $name ) {
		$sets = array(
			''          => array( 'high', 'medium', 'cross_reference' ),
			'high'      => array( 'high', 'cross_reference' ),
			'weaker'    => array( 'high', 'medium', 'cross_reference', 'low', 'partial' ),
			'cross_reference' => array( 'cross_reference' ),
			'review'    => array( 'conflict', 'ambiguous' ),
			'conflict'  => array( 'conflict' ),
			'ambiguous' => array( 'ambiguous' ),
			'unchecked' => array( 'unchecked', 'no_index', 'not_found' ),
			'no_index'  => array( 'no_index' ),
			'not_found' => array( 'not_found' ),
			'fix'       => array( 'conflict', 'not_found' ),
			'all'       => array( 'all' ),
		);
		return $sets[ $name ] ?? ( false !== strpos( (string) $name, ',' ) ? array_map( 'sanitize_key', explode( ',', $name ) ) : array( sanitize_key( $name ) ) );
	}

	/**
	 * Edges to review, grouped by quotation: one entry per (post, quotation) with every edge it produced.
	 * A conflict has the typed paragraph and the matched paragraph side by side.
	 *
	 * @param array $f      Filters as for summary().
	 * @param int   $offset Groups to skip.
	 * @param int   $limit  Groups to return.
	 * @return array { groups[], total }
	 */
	public static function review( array $f, $offset = 0, $limit = 20 ) {
		global $wpdb;
		$t = self::table();
		list( $w, $a ) = self::where( $f + array( 'strict' => true ) );
		// A quotation is identified by its post and its fingerprint (or its position when there is none).
		$keysq = "SELECT c.post_id AS post_id, c.quote_hash AS quote_hash, c.quote_ord AS quote_ord FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$w} GROUP BY c.post_id, c.quote_hash, c.quote_ord ORDER BY MAX(p.post_date) DESC, c.post_id DESC, c.quote_ord ASC";
		$total = (int) self::run( "SELECT COUNT(*) AS n FROM ({$keysq}) x", array(), $a )[0]['n'];
		$keys  = self::run( $keysq . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, array(), $a );
		$out   = array();
		foreach ( $keys as $k ) {
			$edges = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, doc, locator, loc_num, basis, status, cover, confirmed FROM {$t} WHERE post_id = %d AND quote_hash = %s AND quote_ord = %d ORDER BY basis DESC, loc_num ASC", (int) $k['post_id'], (string) $k['quote_hash'], (int) $k['quote_ord'] ),
				ARRAY_A
			);
			$m   = get_option( 'af_match_' . (int) $k['post_id'] );
			$exc = '';
			$note = '';
			if ( is_array( $m ) && ! empty( $m['quotes'] ) ) {
				foreach ( $m['quotes'] as $q ) {
					if ( (int) $q['i'] === (int) $k['quote_ord'] ) {
						$exc  = (string) ( $q['excerpt'] ?? '' );
						$note = (string) ( $q['note'] ?? '' );
						break;
					}
				}
			}
			$post  = get_post( (int) $k['post_id'] );
			$out[] = array(
				'post_id' => (int) $k['post_id'],
				'title'   => $post ? $post->post_title : '',
				'url'     => $post ? get_permalink( $post ) : '',
				'excerpt' => $exc,
				'note'    => $note,
				'edges'   => $edges,
			);
		}
		return array( 'groups' => $out, 'total' => $total );
	}

	/** A reviewer's decision on one edge: 1 correct, 0 wrong, null not reviewed. */
	public static function set_confirmed( $id, $value ) {
		global $wpdb;
		$v = ( null === $value || '' === $value ) ? null : ( (int) $value ? 1 : 0 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET confirmed = ' . ( null === $v ? 'NULL' : (int) $v ) . ' WHERE id = %d', (int) $id ) );
		return $v;
	}
}
