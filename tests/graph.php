<?php
// The citation graph: edges from match results, re-matching keeps reviews, queries. Runs on SQLite with a small $wpdb stand-in.
// Usage: php graph.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'graph' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }

define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function get_post( $id ) { $r = $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id ); return $r ? (object) $r[0] : null; }
function get_permalink( $p ) { return 'https://staging.example/?p=' . $p->ID; }
$GLOBALS['meta'] = array();
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }

class FakeDb {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_status TEXT, post_type TEXT, post_date TEXT, post_modified_gmt TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_key TEXT)' );
		$this->pdo->exec( "CREATE TABLE wp_af_citations (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, quote_ord INT, doc TEXT, locator TEXT, loc_num INT, basis TEXT, status TEXT, cover REAL, words INT, quote_hash TEXT, matched_at TEXT, plugin_version TEXT, confirmed INT, at_uri TEXT)" );
	}
	function prepare( $sql, ...$a ) {
		$a = is_array( $a[0] ?? null ) ? $a[0] : $a;
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
	}
	function get_results( $sql, $o = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	function query( $sql ) { return $this->pdo->exec( $sql ); }
	function insert( $t, $d ) { $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', array_keys( $d ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $d ), '?' ) ) . ')' )->execute( array_values( $d ) ); }
	function update( $t, $d, $w ) { $this->pdo->prepare( "UPDATE $t SET " . implode( ',', array_map( function ( $k ) { return "$k=?"; }, array_keys( $d ) ) ) . ' WHERE ' . implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ) )->execute( array_merge( array_values( $d ), array_values( $w ) ) ); }
	function delete( $t, $w ) { $this->pdo->prepare( "DELETE FROM $t WHERE " . implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ) )->execute( array_values( $w ) ); }
}
$wpdb = new FakeDb();
foreach ( array( array( 1, 'Alpha', '2026-01-01' ), array( 2, 'Beta', '2026-02-01' ), array( 3, 'Gamma', '2026-03-01' ) ) as $p ) {
	$wpdb->pdo->exec( "INSERT INTO wp_posts VALUES ({$p[0]}, '{$p[1]}', 'publish', 'post', '{$p[2]}', '{$p[2]} 00:00:00')" );
}
$wpdb->pdo->exec( "INSERT INTO wp_posts VALUES (4, 'Draft', 'draft', 'post', '2026-04-01', '2026-04-01 00:00:00')" );

function q( $i, $status, $best, $typed = array(), $hash = null ) {
	return array( 'i' => $i, 'status' => $status, 'words' => 40, 'hash' => $hash ?? 'h' . $i, 'best' => $best, 'typed' => $typed );
}
$b = function ( $doc, $locs, $cov = 0.97 ) { return array( 'doc' => $doc, 'locators' => $locs, 'coverage' => $cov ); };

$res1 = array( 'quotes' => array(
	q( 0, 'high', $b( 'catechism', array( 1670 ) ), array( array( 'doc' => 'catechism', 'loc' => array( 1670 ) ) ) ),
	q( 1, 'medium', $b( 'laudato-si', array( 100 ), 0.85 ) ),
	q( 2, 'no_match', null ),
	q( 3, 'too_short', null ),
	q( 4, 'conflict', $b( 'laudato-si', array( 16 ), 0.9 ), array( array( 'doc' => 'laudato-si', 'loc' => array( 20 ) ) ) ),
	q( 5, 'ambiguous', $b( 'catechism', array( 5, 6 ), 0.9 ) ),
) );
$rows = AF_Graph::rows_from_result( $res1 );
$by = function ( $rows, $doc, $loc ) { foreach ( $rows as $r ) { if ( $r['doc'] === $doc && $r['loc_num'] === $loc ) { return $r; } } return null; };
check( 'typed + matching text is one high edge', 1 === count( array_filter( $rows, function ( $r ) { return 'catechism' === $r['doc'] && 1670 === $r['loc_num']; } ) ) );
check( 'high edge basis is typed', 'typed' === $by( $rows, 'catechism', 1670 )['basis'] );
check( 'medium edge is text-match', 'text-match' === $by( $rows, 'laudato-si', 100 )['basis'] );
check( 'no_match and too_short leave no edge', 6 === count( $rows ), (string) count( $rows ) );
check( 'conflict records both the match and the typed cite', null !== $by( $rows, 'laudato-si', 16 ) && null !== $by( $rows, 'laudato-si', 20 ) );
check( 'ambiguous records both paragraphs', null !== $by( $rows, 'catechism', 5 ) && null !== $by( $rows, 'catechism', 6 ) );


$o = AF_Graph::record_post( 1, $res1 );
check( 'first record adds all edges', 6 === $o['added'] && 0 === $o['removed'], json_encode( $o ) );
check( 'post is marked matched', '' !== get_post_meta( 1, AF_Graph::META_KEY ) );

// A reviewer confirms one edge; re-matching keeps it. A removed quote drops its edges. A new quote adds.
$wpdb->pdo->exec( "UPDATE wp_af_citations SET confirmed = 1, at_uri = 'at://x' WHERE post_id = 1 AND doc = 'catechism' AND loc_num = 1670" );
$res1b = $res1;
array_splice( $res1b['quotes'], 4, 1 ); // drop the conflict quote
$res1b['quotes'][] = q( 9, 'high', $b( 'catechism', array( 2000 ) ) );
$o = AF_Graph::record_post( 1, $res1b );
check( 're-match: one added, two removed', 1 === $o['added'] && 2 === $o['removed'], json_encode( $o ) );
$kept = $wpdb->get_results( "SELECT * FROM wp_af_citations WHERE post_id = 1 AND loc_num = 1670" );
check( 're-match keeps review and published address', 1 === (int) $kept[0]['confirmed'] && 'at://x' === $kept[0]['at_uri'] );
check( 'no duplicate rows after re-match', 5 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_af_citations WHERE post_id = 1' ) );

AF_Graph::record_post( 2, array( 'quotes' => array( q( 0, 'high', $b( 'catechism', array( 1670 ) ) ), q( 1, 'low', $b( 'catechism', array( 1670 ) ), array(), 'x1' ) ) ) );
AF_Graph::record_post( 3, array( 'quotes' => array( q( 0, 'high', $b( 'laudato-si', array( 100 ) ) ) ) ) );
AF_Graph::record_post( 4, array( 'quotes' => array( q( 0, 'high', $b( 'catechism', array( 1670 ) ) ) ) ) ); // draft: never counted

$s = AF_Graph::summary( array() );
check( 'summary counts only published posts and high/medium', 3 === $s['posts'], json_encode( $s ) );
$top = AF_Graph::top_paragraphs( array(), 5 );
check( 'most cited paragraph is catechism 1670 (posts 1 and 2)', 'catechism' === $top[0]['doc'] && 1670 === (int) $top[0]['locator'] && 2 === (int) $top[0]['posts'], json_encode( $top ) );
$cit = AF_Graph::citing_posts( array( 'doc' => 'catechism', 'locator' => '1670' ) );
check( 'citing posts, newest first, drafts excluded', 2 === count( $cit ) && 'Beta' === $cit[0]['title'], json_encode( $cit ) );
$cit = AF_Graph::citing_posts( array( 'doc' => 'catechism', 'locator' => '1670', 'status' => array( 'all' ) ) );
check( 'status all includes low', 3 === count( $cit ) || 2 < count( $cit ), (string) count( $cit ) );
$cit = AF_Graph::citing_posts( array( 'confirmed' => 'yes' ) );
check( 'confirmed filter', 1 === count( $cit ) && '1' === (string) $cit[0]['post_id'] );

check( 'fresh after matching', AF_Graph::is_fresh( 1, '2020-01-01 00:00:00' ) );
check( 'stale when post modified later', ! AF_Graph::is_fresh( 1, gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
check( 'stale when never matched', ! AF_Graph::is_fresh( 99, '2020-01-01 00:00:00' ) );

AF_Graph::forget_post( 2 );
check( 'forget removes edges and mark', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_af_citations WHERE post_id = 2' ) && '' === get_post_meta( 2, AF_Graph::META_KEY ) );

// Review: a quotation's edges are grouped, decisions are kept and leave the reports.
$GLOBALS['opts']['af_match_1'] = array( 'quotes' => array( array( 'i' => 4, 'excerpt' => 'Some quoted words' ) ) );
AF_Graph::record_post( 1, $res1 ); // restore the original set for post 1
$rv = AF_Graph::review( array( 'status' => AF_Graph::status_set( 'review' ), 'confirmed' => 'unreviewed' ), 0, 20 );
$conf = array_values( array_filter( $rv['groups'], function ( $g ) { return 1 === $g['post_id'] && 'Some quoted words' === $g['excerpt']; } ) );
check( 'review finds the conflict quotation with its excerpt', 1 === count( $conf ), json_encode( $rv ) );
check( 'a conflict shows the typed and the matched paragraph together', 2 === count( $conf[0]['edges'] ) && 'typed' === $conf[0]['edges'][0]['basis'], json_encode( $conf ) );
$amb = array_values( array_filter( $rv['groups'], function ( $g ) { return 2 === count( $g['edges'] ) && 'ambiguous' === $g['edges'][0]['status']; } ) );
check( 'ambiguous quotation is listed once with both paragraphs', 1 === count( $amb ) && 2 === $rv['total'], json_encode( $rv['total'] ) );
$typed = (int) $conf[0]['edges'][0]['id']; $matched = (int) $conf[0]['edges'][1]['id'];
AF_Graph::set_confirmed( $typed, 0 );
AF_Graph::set_confirmed( $matched, 1 );
$rv2 = AF_Graph::review( array( 'status' => AF_Graph::status_set( 'review' ), 'confirmed' => 'unreviewed' ), 0, 20 );
check( 'a reviewed conflict leaves the to-review list', $rv['total'] - 1 === $rv2['total'], $rv['total'] . ' -> ' . $rv2['total'] );
$rv3 = AF_Graph::review( array( 'status' => AF_Graph::status_set( 'review' ), 'confirmed' => 'any' ), 0, 20 );
check( 'reviewed ones come back when asked', $rv3['total'] === $rv['total'], json_encode( $rv3['groups'] ) );
$cit = AF_Graph::citing_posts( array( 'doc' => 'laudato-si', 'locator' => '16' ) );
check( 'a confirmed conflict edge now counts in the default view', 1 === count( $cit ) );
$cit = AF_Graph::citing_posts( array( 'doc' => 'laudato-si', 'locator' => '20', 'status' => array( 'all' ) ) );
check( 'a rejected edge leaves every view', 0 === count( $cit ), json_encode( $cit ) );
$cit = AF_Graph::citing_posts( array( 'doc' => 'laudato-si', 'locator' => '20', 'status' => array( 'all' ), 'confirmed' => 'no' ) );
check( 'rejected edges can be listed on request', 1 === count( $cit ) );
AF_Graph::record_post( 1, $res1 );
$kept = $wpdb->get_results( "SELECT confirmed FROM wp_af_citations WHERE id = $matched" );
check( 're-matching keeps the decision', 1 === (int) $kept[0]['confirmed'] );
AF_Graph::set_confirmed( $matched, null );
check( 'clear removes the decision', null === $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE id = $matched" ) );
check( 'status names', array( 'conflict', 'ambiguous' ) === AF_Graph::status_set( 'review' ) && array( 'all' ) === AF_Graph::status_set( 'all' ) );

// A cross-reference (the Catechism quotes the source the post cites): both edges are recorded, as cross_reference, and count as confident.
$resX = array( 'quotes' => array( array( 'i' => 1, 'status' => 'cross_reference', 'words' => 30, 'hash' => 'hxref', 'best' => $b( 'catechism', array( 112 ), 1.0 ), 'alt' => array(), 'typed' => array( array( 'doc' => 'dei-verbum', 'loc' => array( 12 ) ) ) ) ) );
$rx = AF_Graph::rows_from_result( $resX );
check( 'cross_reference records the matched and the typed paragraph', 2 === count( $rx ) && null !== $by( $rx, 'catechism', 112 ) && null !== $by( $rx, 'dei-verbum', 12 ) && 'cross_reference' === $by( $rx, 'dei-verbum', 12 )['status'] && 'cross_reference' === $by( $rx, 'catechism', 112 )['status'], json_encode( $rx ) );
check( 'cross_reference is in the default view and not in the review list', in_array( 'cross_reference', AF_Graph::status_set( '' ), true ) && ! in_array( 'cross_reference', AF_Graph::status_set( 'review' ), true ) && in_array( 'cross_reference', AF_Graph::CONFIDENT, true ) );

// A typed cite the words could not confirm: no_index when the cited document has no index here, not_found when it has one and the words are not in it.
$resN = array( 'quotes' => array(
	array( 'i' => 1, 'status' => 'no_match', 'words' => 30, 'hash' => 'hnf', 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => 'catechism', 'loc' => array( 2349 ), 'indexed' => true ) ) ),
	array( 'i' => 2, 'status' => 'out_of_scope', 'words' => 30, 'hash' => 'hni', 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => 'humanae-vitae', 'loc' => array( 12 ), 'indexed' => false ) ) ),
	array( 'i' => 3, 'status' => 'no_match', 'words' => 30, 'hash' => 'hold', 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => 'catechism', 'loc' => array( 7 ) ) ) ),
) );
$rn = AF_Graph::rows_from_result( $resN );
check( 'typed cite, document indexed, words absent -> not_found', 'not_found' === $by( $rn, 'catechism', 2349 )['status'], json_encode( $rn ) );
check( 'typed cite, document not indexed -> no_index', 'no_index' === $by( $rn, 'humanae-vitae', 12 )['status'], json_encode( $rn ) );
check( 'typed cite from an older result (no indexed flag) stays unchecked', 'unchecked' === $by( $rn, 'catechism', 7 )['status'], json_encode( $rn ) );
check( 'the filters: unchecked covers both, fix = conflict + not_found', array( 'not_found' ) === AF_Graph::status_set( 'not_found' ) && array( 'no_index' ) === AF_Graph::status_set( 'no_index' ) && in_array( 'not_found', AF_Graph::status_set( 'unchecked' ), true ) && in_array( 'no_index', AF_Graph::status_set( 'unchecked' ), true ) && array( 'conflict', 'not_found' ) === AF_Graph::status_set( 'fix' ) && ! in_array( 'not_found', AF_Graph::CONFIDENT, true ) );

// 0.12.1: a cite mentioned near the quotation (not attached) is 'mention'; in a translation post it is 'translation'; confirmed ones are unaffected.
$resM = array( 'quotes' => array(
	array( 'i' => 1, 'status' => 'no_match', 'words' => 30, 'hash' => 'hm1', 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => 'lumen-gentium', 'loc' => array( 25 ), 'indexed' => true, 'attached' => false ) ) ),
	array( 'i' => 2, 'status' => 'no_match', 'words' => 30, 'hash' => 'hm2', 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => 'catechism', 'loc' => array( 99 ), 'indexed' => true, 'attached' => true ) ) ),
) );
$rm = AF_Graph::rows_from_result( $resM );
check( '0.12.1 unattached typed cite -> mention', 'mention' === $by( $rm, 'lumen-gentium', 25 )['status'] && 'not_found' === $by( $rm, 'catechism', 99 )['status'], json_encode( $rm ) );
$resM['translation'] = true;
$rt = AF_Graph::rows_from_result( $resM );
check( '0.12.1 translation post -> translation', 'translation' === $by( $rt, 'catechism', 99 )['status'] && 'translation' === $by( $rt, 'lumen-gentium', 25 )['status'], json_encode( $rt ) );
check( '0.12.1 filters include mention and translation under unchecked', array( 'mention' ) === AF_Graph::status_set( 'mention' ) && array( 'translation' ) === AF_Graph::status_set( 'translation' ) && in_array( 'mention', AF_Graph::status_set( 'unchecked' ), true ) && in_array( 'translation', AF_Graph::status_set( 'unchecked' ), true ) && ! in_array( 'mention', AF_Graph::CONFIDENT, true ) );

// An ambiguous quotation between two documents records both candidates; a weak alternative is left out.
$resA = array( 'quotes' => array( array( 'i' => 1, 'status' => 'ambiguous', 'words' => 19, 'hash' => 'hamb', 'best' => $b( 'evangelii-gaudium', array( 48 ), 1.0 ), 'alt' => array( array( 'doc' => 'dilexi-te', 'locators' => array( 36 ), 'coverage' => 1.0 ), array( 'doc' => 'laudato-si', 'locators' => array( 9 ), 'coverage' => 0.3 ) ), 'typed' => array() ) ) );
$ra = AF_Graph::rows_from_result( $resA );
check( 'ambiguous across documents records the equal candidates only', 2 === count( $ra ) && null !== $by( $ra, 'dilexi-te', 36 ) && null !== $by( $ra, 'evangelii-gaudium', 48 ) && null === $by( $ra, 'laudato-si', 9 ), json_encode( array_keys( $ra ) ) );

// A typed number that disagrees with a high match is not an edge.
$resH = array( 'quotes' => array( array( 'i' => 1, 'status' => 'high', 'words' => 30, 'hash' => 'hhigh', 'best' => $b( 'dilexi-te', array( 16 ) ), 'alt' => array(), 'typed' => array( array( 'doc' => 'dilexi-te', 'loc' => array( 21 ) ), array( 'doc' => 'dilexi-te', 'loc' => array( 16 ) ) ) ) ) );
$rh = AF_Graph::rows_from_result( $resH );
check( 'a high quotation records only the paragraph that agrees', 1 === count( $rh ) && null !== $by( $rh, 'dilexi-te', 16 ), json_encode( array_keys( $rh ) ) );

// Snapshots: what a change moved.
function get_the_title( $id ) { return 'Post ' . $id; }
function get_edit_post_link( $id, $c = '' ) { return 'https://staging.example/wp-admin/post.php?post=' . $id; }
$wpdb->pdo->exec( 'DELETE FROM wp_af_citations' );
AF_Graph::record_post( 1, array( 'quotes' => array( q( 0, 'high', $b( 'catechism', array( 10 ) ), array( array( 'doc' => 'catechism', 'loc' => array( 10 ) ) ) ), q( 1, 'medium', $b( 'laudato-si', array( 5 ), 0.85 ) ) ) ) );
AF_Graph::record_post( 2, array( 'quotes' => array( q( 0, 'conflict', $b( 'laudato-si', array( 16 ), 0.9 ), array( array( 'doc' => 'laudato-si', 'loc' => array( 20 ) ) ) ) ) ) );
$sv = AF_Graph::snapshot_save( 'Before 0.15!' );
check( 'a snapshot is saved under a clean name', 'before-0.15' === $sv['label'] && $sv['edges'] >= 3, json_encode( $sv ) );
$d0 = AF_Graph::snapshot_diff( 'before-0.15', 'current' );
check( 'nothing differs from itself', 0 === $d0['total'], json_encode( $d0['transitions'] ) );
// Post 1's high quotation becomes medium, post 2's conflict goes, post 3 gains a quotation.
AF_Graph::record_post( 1, array( 'quotes' => array( q( 0, 'medium', $b( 'catechism', array( 10 ), 0.85 ) ), q( 1, 'medium', $b( 'laudato-si', array( 5 ), 0.85 ) ) ) ) );
AF_Graph::record_post( 2, array( 'quotes' => array() ) );
AF_Graph::record_post( 3, array( 'quotes' => array( q( 0, 'high', $b( 'fratelli-tutti', array( 7 ) ) ) ) ) );
$d1 = AF_Graph::snapshot_diff( 'before-0.15', 'current' );
check( 'the changes are found', 3 <= $d1['total'] && isset( $d1['transitions']['high > medium'] ) || isset( $d1['transitions']['high > medium'] ), json_encode( $d1['transitions'] ) );
check( 'a removed edge is reported as gone', isset( $d1['transitions']['conflict > gone'] ), json_encode( $d1['transitions'] ) );
check( 'a new edge is reported as new', isset( $d1['transitions']['new > high'] ), json_encode( $d1['transitions'] ) );
check( 'an unchanged edge is not reported', 0 === count( array_filter( $d1['changes'], function ( $c ) { return 'laudato-si' === $c['doc'] && '5' === $c['locator']; } ) ) );
check( 'each change names its post', 'Post 1' === ( array_values( array_filter( $d1['changes'], function ( $c ) { return 'high' === $c['from']; } ) )[0]['title'] ?? '' ) );
$d2 = AF_Graph::snapshot_diff( 'before-0.15', 'current', 0, 50, 'new > high' );
check( 'one kind of change can be asked for', 1 === $d2['total'] && 'new' === $d2['changes'][0]['from'], json_encode( $d2['total'] ) );
check( 'an unknown snapshot is an error', is_wp_error( AF_Graph::snapshot_diff( 'nope', 'current' ) ) );
for ( $i = 0; $i < 8; $i++ ) { AF_Graph::snapshot_save( 's' . $i ); }
check( 'only the newest snapshots are kept', count( AF_Graph::snapshot_list() ) <= AF_Graph::SNAP_MAX && is_wp_error( AF_Graph::snapshot_diff( 'before-0.15', 'current' ) ) );

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
