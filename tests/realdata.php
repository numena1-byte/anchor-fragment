<?php
// The hosted files in data/ against the real registry. Usage: php realdata.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'fragment', 'matcher', 'analyzer', 'quote', 'service' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }
$root = dirname( __DIR__ ) . '/data/';
foreach ( AF_Service::registry() as $id => $e ) {
	if ( empty( $e['hosted'] ) ) { continue; }
	$file = $root . basename( parse_url( $e['hosted']['url'], PHP_URL_PATH ) );
	check( "$id: the registry's file exists in data/", is_file( $file ), $file );
	if ( ! is_file( $file ) ) { continue; }
	$r = AF_Service::import_hosted_body( $id, file_get_contents( $file ) );
	check( "$id: checksum matches and the file imports", ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	if ( is_wp_error( $r ) ) { continue; }
	check( "$id: " . $e['expected_count'] . ' paragraphs, no gaps', $e['expected_count'] === $r['count'] && 1 === $r['min'] && $r['max'] === $e['expected_count'] && ! AF_Service::get_doc( $id )['meta']['stats']['gaps'], json_encode( $r ) );
	$l = AF_Service::link( $id, (string) ( $id === 'catechism' ? 1670 : 126 ) );
	check( "$id: a link can be built", ! is_wp_error( $l ) && '' !== $l['link'], is_wp_error( $l ) ? $l->get_error_message() : '' );
	echo '       ' . ( is_wp_error( $l ) ? '' : $l['link'] ) . "\n";
}
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
