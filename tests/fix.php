<?php
// The Citations to fix screen: diagnoses, decisions, the list. Runs on SQLite with a small $wpdb stand-in.
// Usage: php fix.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'graph' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }
function add_action() {} function register_rest_route() {}
class AF_Service {
	static function registry() { return array( 'laudato-si' => array( 'title' => 'Laudato si’' ), 'catechism' => array( 'title' => 'Catechism' ), 'humanae-vitae' => array( 'title' => 'Humanae vitae' ), 'donum-veritatis' => array( 'title' => 'Donum veritatis' ) ); }
	static function link( $d, $l ) { return array( 'link' => "https://v.example/$d#$l" ); }
}
require AF_DIR . 'includes/class-af-fix.php';

define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function get_post( $id ) { $r = $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id ); return $r ? (object) $r[0] : null; }
function get_edit_post_link( $id, $c = '' ) { return "https://staging.example/wp-admin/post.php?post=$id&action=edit"; }
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



$res1 = array( 'quotes' => array(
	q( 0, 'conflict', $b( 'laudato-si', array( 16 ), 0.9 ), array( array( 'doc' => 'laudato-si', 'loc' => array( 20 ) ) ) ),
	q( 1, 'conflict', $b( 'humanae-vitae', array( 31 ), 1.0 ), array( array( 'doc' => 'humanae-vitae', 'loc' => array( 29 ) ) ) ),
	q( 2, 'conflict', $b( 'humanae-vitae', array( 12, 13 ), 1.0 ), array( array( 'doc' => 'humanae-vitae', 'loc' => array( 11 ) ) ) ),
	q( 3, 'conflict', $b( 'catechism', array( 2267 ), 1.0 ), array( array( 'doc' => 'humanae-vitae', 'loc' => array( 9 ) ) ) ),
	q( 4, 'ambiguous', $b( 'catechism', array( 5, 6 ), 0.9 ) ),
	q( 5, 'no_match', null, array( array( 'doc' => 'catechism', 'loc' => array( 77 ), 'indexed' => true ) ) ),
	q( 6, 'high', $b( 'catechism', array( 1670 ) ), array( array( 'doc' => 'catechism', 'loc' => array( 1670 ) ) ) ),
) );
$GLOBALS['opts']['af_match_1'] = array( 'quotes' => array( array( 'i' => 1, 'excerpt' => 'And this We do relying', 'note' => 'typed humanae-vitae 29, text matches humanae-vitae 31' ) ) );
AF_Graph::record_post( 1, $res1 );
AF_Graph::record_post( 2, array( 'quotes' => array( q( 0, 'conflict', $b( 'donum-veritatis', array( 23, 24 ), 0.99 ), array( array( 'doc' => 'donum-veritatis', 'loc' => array( 28 ) ) ) ) ) ) );
$reg = AF_Service::registry();

// diagnose: the words of the problem
$edges = function ( $post, $ord ) { $r = AF_Graph::review( array( 'status' => array( 'conflict', 'ambiguous', 'not_found' ), 'confirmed' => 'any' ), 0, 50 ); foreach ( $r['groups'] as $g ) { if ( $g['post_id'] === $post && $g['ord'] === $ord ) { return $g['edges']; } } return array(); };
$d = AF_Fix::diagnose( 'conflict', $edges( 1, 1 ), $reg );
check( 'conflict headline names both paragraphs', 'You cited Humanae vitae 29, but its words are in Humanae vitae 31.' === $d['headline'], $d['headline'] );
check( 'a different paragraph of the same document', 'same_document' === $d['hint'], $d['hint'] );
$d = AF_Fix::diagnose( 'conflict', $edges( 1, 2 ), $reg );
check( 'a range is said as a list', 'You cited Humanae vitae 11, but its words are in Humanae vitae 12, 13.' === $d['headline'], $d['headline'] );
check( 'the next paragraph is called out as an off-by-one', 'next_to' === $d['hint'], $d['hint'] );
$d = AF_Fix::diagnose( 'conflict', $edges( 1, 3 ), $reg );
check( 'another document is called out', 'other_document' === $d['hint'] && 'Catechism' === $d['found'][0]['title'], json_encode( $d ) );
$d = AF_Fix::diagnose( 'ambiguous', $edges( 1, 4 ), $reg );
check( 'ambiguous says which paragraphs fit', false !== strpos( $d['headline'], 'Catechism 5, 6' ) && empty( $d['cited'] ), $d['headline'] );
$d = AF_Fix::diagnose( 'not_found', $edges( 1, 5 ), $reg );
check( 'not_found says what was cited', false !== strpos( $d['headline'], 'You cited Catechism 77' ) && empty( $d['found'] ), $d['headline'] );
check( 'diagnose copes with no edges', 'The cite and the words disagree.' === AF_Fix::diagnose( 'conflict', array(), $reg )['headline'] );

// the list
$r = AF_Fix::items( array( 'kind' => 'conflict' ) );
check( 'conflicts listed, not the high one', 5 === $r['shown'], (string) $r['shown'] );
check( 'counts per kind', 5 === $r['counts']['conflict'] && 1 === $r['counts']['ambiguous'] && 1 === $r['counts']['not_found'], json_encode( $r['counts'] ) );
check( 'sorted by post title and carries the edit link', 'Alpha' === $r['items'][0]['title'] && false !== strpos( $r['items'][0]['edit_url'], 'post=1' ) && 'Beta' === $r['items'][4]['title'], json_encode( array_column( $r['items'], 'title' ) ) );
check( 'the excerpt and matcher note come along', 'And this We do relying' === $r['items'][1]['excerpt'] && '' !== $r['items'][1]['note'] );
check( 'paragraph links are filled in', 'https://v.example/humanae-vitae#31' === $r['items'][1]['found'][0]['link'], json_encode( $r['items'][1]['found'] ) );
check( 'all kinds together', 7 === AF_Fix::items( array( 'kind' => 'all' ) )['shown'] );
check( 'filter by document', 3 === AF_Fix::items( array( 'kind' => 'conflict', 'doc' => 'humanae-vitae' ) )['shown'] );
check( 'filter by words in the title', 1 === AF_Fix::items( array( 'kind' => 'conflict', 'q' => 'bet' ) )['shown'] );

// decisions
$it = $r['items'][1];
$x = AF_Fix::set_state( $it['post_id'], $it['hash'], $it['ord'], 'fix' );
check( 'a decision is stored', 'fix' === $x['state'] && 'fix' === AF_Fix::states()[ $x['key'] ], json_encode( $x ) );
check( 'it leaves the to-do list', 4 === AF_Fix::items( array( 'kind' => 'conflict' ) )['shown'] );
$done = AF_Fix::items( array( 'kind' => 'conflict', 'show' => 'done' ) );
check( 'and appears under decided', 1 === $done['shown'] && 'fix' === $done['items'][0]['state'] );
check( 'counts keep the whole', 5 === $done['counts']['conflict'] );
check( 'everything shows both', 5 === AF_Fix::items( array( 'kind' => 'conflict', 'show' => 'all' ) )['shown'] );
check( 'fix rejects the cited paragraph and confirms where the words are', 0 === (int) $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE post_id = 1 AND doc = 'humanae-vitae' AND loc_num = 29" ) && 1 === (int) $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE post_id = 1 AND doc = 'humanae-vitae' AND loc_num = 31" ) );
$x = AF_Fix::set_state( $it['post_id'], $it['hash'], $it['ord'], 'ok' );
check( 'ok confirms the cite instead', 1 === (int) $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE post_id = 1 AND doc = 'humanae-vitae' AND loc_num = 29" ) && 0 === (int) $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE post_id = 1 AND doc = 'humanae-vitae' AND loc_num = 31" ) );
AF_Fix::set_state( $it['post_id'], $it['hash'], $it['ord'], 'skip' );
check( 'ignore leaves nothing decided in the graph', null === $wpdb->get_var( "SELECT confirmed FROM wp_af_citations WHERE post_id = 1 AND doc = 'humanae-vitae' AND loc_num = 29" ) );
AF_Fix::set_state( $it['post_id'], $it['hash'], $it['ord'], '' );
check( 'undo brings it back', 5 === AF_Fix::items( array( 'kind' => 'conflict' ) )['shown'] && array() === AF_Fix::states() );
check( 'an unknown decision is refused', is_wp_error( AF_Fix::set_state( 1, 'x', 0, 'delete' ) ) );
check( 'a quotation that is gone is refused', 'af_fix_gone' === AF_Fix::set_state( 1, 'nope', 99, 'ok' )->get_error_code() );

// a decision survives a re-match of the post
AF_Fix::set_state( $it['post_id'], $it['hash'], $it['ord'], 'fix' );
AF_Graph::record_post( 1, $res1 );
check( 'a re-match keeps the decision', 4 === AF_Fix::items( array( 'kind' => 'conflict' ) )['shown'] );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
