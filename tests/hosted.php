<?php
// Hosted data round trip: crawl an invented catechism, export it, then import the file on a "new site".
// Usage: php hosted.php <path-to-plugin-dir>
$plugin = realpath( $argv[1] );
$mode   = $argv[2] ?? 'main';

if ( 'main' === $mode ) {
	$self = escapeshellarg( __FILE__ );
	$tmp  = sys_get_temp_dir() . '/af_hosted_' . getmypid();
	mkdir( $tmp );
	// Phase A: crawl and export.
	passthru( PHP_BINARY . " $self " . escapeshellarg( $plugin ) . ' A ' . escapeshellarg( $tmp . '/out.json' ), $rc );
	$body = (string) file_get_contents( $tmp . '/out.json' );
	if ( ! $body ) { echo "  FAIL no export produced\n"; exit( 1 ); }
	// A copy of the plugin whose registry lets the catechism be imported from that file.
	exec( 'cp -r ' . escapeshellarg( $plugin ) . ' ' . escapeshellarg( $tmp . '/plugin' ) );
	$reg = json_decode( file_get_contents( $tmp . '/plugin/registry.json' ), true );
	foreach ( $reg['documents'] as &$d ) {
		if ( 'catechism' === $d['id'] ) {
			$d['hosted'] = array( 'url' => 'https://raw.githubusercontent.com/numena1-byte/anchor-fragment/abc/data/catechism.json', 'sha256' => hash( 'sha256', $body ) );
		}
	}
	unset( $d );
	file_put_contents( $tmp . '/plugin/registry.json', json_encode( $reg ) );
	file_put_contents( $tmp . '/tampered.json', $body . ' ' );
	passthru( PHP_BINARY . " $self " . escapeshellarg( $tmp . '/plugin' ) . ' B ' . escapeshellarg( $tmp ), $rc2 );
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	exit( $rc || $rc2 ? 1 : 0 );
}

$GLOBALS['af_plugin_dir'] = $plugin;
require __DIR__ . '/stubs.php';
$pages = array(
	'_INDEX.HTM' => '<html><body><ul><li><a href=__P1.HTM>PROLOGUE</a></li><li><a href=__P2.HTM>I. One</a></li><li><a href=__P3.HTM>IN BRIEF</a></li></ul></body></html>',
	'__P1.HTM'   => '<html><body><p>PROLOGUE</p><p>1 Alpha begins the story of a long road that crosses many fields and rivers before the sea.</p><p>2 Beta continues with the hunger of the shepherds who read the weather from every mountain bell in thin air.</p></body></html>',
	'__P2.HTM'   => '<html><body><p>CHAPTER ONE</p><p>3 Gamma speaks of bread shared at a long table where the hunger answered is never only of the body alone.</p><p>4 Delta says that generosity is simply arithmetic done with an honest heart and counted among the neighbours.</p></body></html>',
	'__P3.HTM'   => '<html><body><p>IN BRIEF</p><p>5 Epsilon summarises the whole journey of the fields, the table and the bells in one quiet closing paragraph of words.</p></body></html>',
);
function wp_safe_remote_get( $url, $args = array() ) {
	global $pages;
	$name = basename( parse_url( $url, PHP_URL_PATH ) );
	if ( ! isset( $pages[ $name ] ) ) { return array( 'code' => 404, 'body' => '', 'headers' => array() ); }
	return array( 'code' => 200, 'body' => $pages[ $name ], 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ) );
}
function ingest_crawl( $id, $b = 25.0, $r = false, $s = false ) { return AF_Service::ingest_step( $id, $b, $r, $s, true ); }
foreach ( array( 'parser', 'fragment', 'matcher', 'analyzer', 'service' ) as $f ) {
	require AF_DIR . "includes/class-af-$f.php";
}

if ( 'A' === $mode ) {
	$r = null;
	ingest_crawl( 'catechism', 0.0 );
	for ( $k = 0; $k < 6 && ( null === $r || ! $r['finished'] ); $k++ ) { $r = ingest_crawl( 'catechism', 25.0 ); }
	$link4 = AF_Service::link( 'catechism', '4' );
	$data  = AF_Service::export_hosted( 'catechism' );
	check( 'A: the crawl finished and exported', ! is_wp_error( $data ) && 5 === $data['count'] && 2 === $data['format'], is_wp_error( $data ) ? $data->get_error_message() : '' );
	check( 'A: the export holds no paragraph text', false === strpos( json_encode( $data ), 'arithmetic done with an honest heart and counted' ), '' );
	file_put_contents( $argv[3], json_encode( $data ) );
	file_put_contents( $argv[3] . '.link', $link4['link'] );
	echo $fail ? "\n$fail FAILED\n" : '';
	exit( $fail ? 1 : 0 );
}

// Phase B: a fresh site with nothing stored.
$dir  = $argv[3];
$body = file_get_contents( $dir . '/out.json' );
check( 'B: nothing indexed yet', ! AF_Service::has_index( 'catechism' ) );
$bad = AF_Service::import_hosted_body( 'catechism', file_get_contents( $dir . '/tampered.json' ) );
check( 'B: a changed file is refused', is_wp_error( $bad ) && 'af_checksum' === $bad->get_error_code() && ! AF_Service::has_index( 'catechism' ) );
$ok = AF_Service::import_hosted_body( 'catechism', $body );
check( 'B: the real file imports, 5 paragraphs', ! is_wp_error( $ok ) && 5 === $ok['count'], is_wp_error( $ok ) ? $ok->get_error_message() : '' );
check( 'B: index saved', AF_Service::has_index( 'catechism' ) );
$link = AF_Service::link( 'catechism', '4' );
check( 'B: the link is the same as the one made by the crawl', ! is_wp_error( $link ) && $link['link'] === file_get_contents( $dir . '/out.json.link' ), is_wp_error( $link ) ? $link->get_error_message() : $link['link'] . ' vs ' . file_get_contents( $dir . '/out.json.link' ) );
check( 'B: no text is stored', '' === $link['excerpt'] );
$d = AF_Service::get_doc( 'catechism' );
check( 'B: get_doc uses the imported data without fetching', ! is_wp_error( $d ) && 'cache' === $d['meta']['source'] );
$an  = new AF_Analyzer( AF_Service::registry() );
$res = $an->analyze( '<p>The Catechism says:</p><blockquote>Gamma speaks of bread shared at a long table where the hunger answered is never only of the body alone.</blockquote>', array(), function ( $id ) { return AF_Service::load_index( $id ); } );
$q = $res['quotes'][0] ?? array();
check( 'B: a quotation matches paragraph 3 using the imported index', isset( $q['best'] ) && 'catechism' === $q['best']['doc'] && array( 3 ) === $q['best']['locators'], json_encode( $q ) );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
