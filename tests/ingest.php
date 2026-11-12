<?php
// End-to-end test of the multi-page fetch with WordPress faked. Usage: php ingest.php <path-to-plugin-dir>
// Uses an invented three-page "catechism" served from memory.
define( 'ABSPATH', '/' );
$dir = rtrim( realpath( $argv[1] ), '/' );
define( 'AF_DIR', $dir . '/' );
define( 'AF_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['opts'] = array();
$GLOBALS['hits'] = array();
function get_option( $k, $d = false ) { return 'af_last_fetch' === $k ? 0 : ( isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d ); }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://staging.example/' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {
	public $c; public $m;
	function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; }
	function get_error_code() { return $this->c; }
	function get_error_message() { return $this->m; }
}

$pages = array(
	'_INDEX.HTM' => '<html><body><ul><li><a href=__P1.HTM>PROLOGUE</a></li><li><a href=__P2.HTM>I. One</a></li><li><a href=__P3.HTM>IN BRIEF</a></li><li><a href=__P2.HTM>again</a></li></ul></body></html>',
	'__P1.HTM'   => '<html><body><p>PROLOGUE</p><p>1 Alpha begins the story of a long road that crosses many fields and rivers before the sea.<sup><a href="#$A">1</a></sup></p><p>2 Beta continues with the keepers of the lantern who walked at dusk through the market of the old town.</p></body></html>',
	'__P2.HTM'   => '<html><body><p>CHAPTER ONE</p><p>3 Gamma speaks of bread shared at a long table where the hunger answered is never only of the body alone.<sup><a href="#$B">2</a></sup></p><p>A continuing line of the third paragraph about memory and nourishment.</p><p>4 Delta closes the chapter with a harbour pilot who knows the sandbars from years of small corrections made in humility.</p></body></html>',
	'__P3.HTM'   => '<html><body><p>IN BRIEF</p><p>5 Epsilon summarises that generosity is simply arithmetic done with an honest heart and counted among neighbours.</p></body></html>',
);
function wp_safe_remote_get( $url, $args = array() ) {
	global $pages;
	$GLOBALS['hits'][] = $url;
	$name = basename( parse_url( $url, PHP_URL_PATH ) );
	if ( ! isset( $pages[ $name ] ) ) { return array( 'code' => 404, 'body' => '', 'headers' => array() ); }
	return array( 'code' => 200, 'body' => $pages[ $name ], 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ) );
}

foreach ( array( 'parser', 'fragment', 'matcher', 'analyzer', 'service' ) as $f ) {
	require $dir . "/includes/class-af-$f.php";
}

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . ( $cond ? '' : "  -> $detail" ) . "\n";
	if ( ! $cond ) { $fail++; }
}

$real = AF_Service::registry();
check( 'real registry has a multi-page catechism entry', ! empty( $real['catechism']['multipage'] ) && 2865 === $real['catechism']['expected_count'] );
check( 'before fetching: get_doc says incomplete', is_wp_error( AF_Service::get_doc( 'catechism' ) ) && 'af_incomplete' === AF_Service::get_doc( 'catechism' )->get_error_code() );
check( 'before fetching: no index', false === AF_Service::has_index( 'catechism' ) );
check( 'a single-page document is refused by ingest_step', is_wp_error( AF_Service::ingest_step( 'laudato-si' ) ) );

// Step 1 with no time budget reads only the index; progress must survive to the next call.
$r1 = AF_Service::ingest_step( 'catechism', 0.0 );
check( 'step 1: index read, 3 distinct pages found, none fetched yet', 0 === $r1['done'] && 3 === $r1['total'] && false === $r1['finished'], json_encode( $r1 ) );
check( 'step 1: state saved', isset( $GLOBALS['opts']['af_ing_catechism'] ) );
$n_hits = count( $GLOBALS['hits'] );

$r2 = AF_Service::ingest_step( 'catechism', 25.0 );
check( 'step 2: finished, no second index fetch', $r2['finished'] && count( $GLOBALS['hits'] ) === $n_hits + 3, json_encode( $r2 ) . ' hits ' . count( $GLOBALS['hits'] ) );
$doc = AF_Service::get_doc( 'catechism' );
check( 'document complete with 5 paragraphs and no gaps', ! is_wp_error( $doc ) && 5 === $doc['meta']['stats']['count'] && array() === $doc['meta']['stats']['gaps'], is_wp_error( $doc ) ? $doc->get_error_message() : json_encode( $doc['meta']['stats'] ) );
check( 'paragraph 3 belongs to page __P2.HTM', substr( $doc['paras']['3']['url'], -8 ) === '__P2.HTM', $doc['paras']['3']['url'] ?? '' );
check( 'pieces cleaned up', ! isset( $GLOBALS['opts']['af_ing_catechism'] ) && ! isset( $GLOBALS['opts']['af_ip_catechism_0'] ) );
check( 'index saved', AF_Service::has_index( 'catechism' ) );

// A link points at the page that holds the paragraph, with footnote marks gone from the words.
$lk = AF_Service::link( 'catechism', '4' );
check( 'link for 4 uses page 2 and a text fragment', ! is_wp_error( $lk ) && false !== strpos( $lk['link'], '__P2.HTM#:~:text=Delta' ), is_wp_error( $lk ) ? $lk->get_error_message() : $lk['link'] );
$lk1 = AF_Service::link( 'catechism', '1' );
check( 'link for 1 uses page 1; the footnote digit is not in the excerpt', ! is_wp_error( $lk1 ) && false !== strpos( $lk1['link'], '__P1.HTM' ) && false === strpos( $lk1['excerpt'], 'sea.1' ), is_wp_error( $lk1 ) ? $lk1->get_error_message() : $lk1['excerpt'] );

// Matching against the stored index.
$an = new AF_Analyzer( $real );
$res = $an->analyze( '<p>See CCC 3.</p><blockquote>Gamma speaks of bread shared at a long table where the hunger answered is never only of the body alone.</blockquote>', array(), function ( $id ) { return AF_Service::load_index( $id ); } );
$q = $res['quotes'][0];
check( 'quote of paragraph 3 matches catechism 3 with the typed cite', 'catechism' === $q['best']['doc'] && array( 3 ) === $q['best']['locators'] && array( 3 ) === $q['typed'][0]['loc'], json_encode( $q ) );

// A large index is stored in several rows and comes back whole.
$big = array();
for ( $i = 1; $i <= 130000; $i++ ) { $big[ $i * 7919 ] = $i % 2999; }
AF_Service::save_index( 'bigtest', $big );
$rows = 0; foreach ( array_keys( $GLOBALS['opts'] ) as $k ) { if ( 0 === strpos( $k, 'af_idx_bigtest' ) ) { $rows++; } }
check( 'large index split over rows', $rows >= 4, (string) $rows );
check( 'large index loads back identical', AF_Service::load_index( 'bigtest' ) == $big );
AF_Service::save_index( 'bigtest', array( 1 => 1 ) );
$rows = 0; foreach ( array_keys( $GLOBALS['opts'] ) as $k ) { if ( 0 === strpos( $k, 'af_idx_bigtest' ) ) { $rows++; } }
check( 'saving a smaller index removes the old rows', 1 === $rows, (string) $rows );

// A page that keeps failing is skipped after three tries and reported.
$GLOBALS['opts'] = array();
unset( $GLOBALS['pages_backup'] );
unset( $pages['__P2.HTM'] );
AF_Service::ingest_step( 'catechism', 0.0 );
$done = null;
for ( $k = 0; $k < 6 && ( null === $done || ! $done['finished'] ); $k++ ) { $done = AF_Service::ingest_step( 'catechism', 25.0 ); }
$doc = AF_Service::get_doc( 'catechism' );
check( 'missing page: finished, paragraphs 3 and 4 reported as gaps', ! is_wp_error( $doc ) && array( 3, 4 ) === $doc['meta']['stats']['gaps'], is_wp_error( $doc ) ? $doc->get_error_message() : json_encode( $doc['meta']['stats']['gaps'] ) );
check( 'missing page: the failure is recorded', ! is_wp_error( $doc ) && 3 === count( $doc['meta']['page_errors'] ) && false !== strpos( $doc['meta']['page_errors'][0], '__P2.HTM' ), is_wp_error( $doc ) ? '' : json_encode( $doc['meta']['page_errors'] ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
