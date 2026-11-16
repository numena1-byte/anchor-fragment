<?php
// Build a hosted derived-data file from extracted paragraphs. Usage: php tools-build-hosted.php paras.json out.json id title
define( 'AF_TEST', true );
require __DIR__ . '/anchor-fragment/includes/class-af-parser.php';
require __DIR__ . '/anchor-fragment/includes/class-af-matcher.php';
$in = json_decode( file_get_contents( $argv[1] ), true );
$paras = array(); $pages = array(); $hash = array();
foreach ( $in['paras'] as $n => $t ) {
	$paras[ (int) $n ] = array( 'full' => $t );
	$pages[ (int) $n ] = (int) $in['pg'][ $n ];
	$hash[ (int) $n ]  = substr( sha1( AF_Parser::fold( $t ) ), 0, 16 );
}
ksort( $paras ); ksort( $pages ); ksort( $hash );
$idx = AF_Matcher::build_index( $paras );
$doc = array(
	'format' => 1, 'id' => $argv[3], 'title' => $argv[4],
	'matcher_k' => AF_Matcher::K, 'parser_version' => AF_Parser::VERSION,
	'count' => count( $paras ), 'min' => min( array_keys( $paras ) ), 'max' => max( array_keys( $paras ) ),
	'link_mode' => 'pdf_page', 'pages' => $pages, 'para_hash' => $hash, 'shingles' => count( $idx ), 'index' => $idx,
);
$doc['checksum'] = sha1( json_encode( array( $doc['pages'], $doc['index'] ) ) );
file_put_contents( $argv[2], json_encode( $doc ) );
echo "paras {$doc['count']} ({$doc['min']}-{$doc['max']}), shingles {$doc['shingles']}, bytes " . filesize( $argv[2] ) . "\n";
