<?php
// The citation graph: edges from match results, re-matching keeps reviews, queries. Runs on SQLite with a small $wpdb stand-in.
// Usage: php graph.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'graph' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }

define( 'ARRAY_A', 'ARRAY_A' );
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

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
