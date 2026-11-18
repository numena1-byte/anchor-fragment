<?php
// Text added from a PDF: checked against the index, hyphen choice, stored, served by passage(). Invented text only.
// Usage: php pdftext.php <path-to-plugin-dir>
$src = rtrim( realpath( $argv[1] ), '/' );
$tmp = sys_get_temp_dir() . '/af-pdftext-' . getmypid();
mkdir( $tmp );
exec( 'cp -r ' . escapeshellarg( $src ) . ' ' . escapeshellarg( $tmp . '/plugin' ) );

$words = explode( ' ', 'orchard keeper valley patience harvest ladder season lesson river stone wall family lantern market dusk road hand face light bread table flour salt hunger body memory thread loom pattern cloth pilot sandbar chart tide winter household neighbour arithmetic heart field traveller company way' );
function mk( $seed, $n, $words ) { $o = array(); for ( $i = 0; $i < $n; $i++ ) { $o[] = $words[ ( $seed * 7 + $i * 11 + ( $i * $i ) ) % count( $words ) ] . ( 0 === $i % 9 ? ',' : '' ); } return implode( ' ', $o ); }
$paras = array();
for ( $n = 1; $n <= 8; $n++ ) { $paras[ $n ] = 'Paragraph ' . $n . ' says ' . mk( $n, 50, $words ) . '.'; }
$paras[3] = 'Paragraph 3 says that a self-giving love needs teaching of the heart, and that the lantern of the market shows the road only to those who carry it with patience through winter.';
$in = array( 'paras' => $paras, 'pg' => array_combine( array_keys( $paras ), array_map( function ( $n ) { return 10 + $n; }, array_keys( $paras ) ) ) );
file_put_contents( "$tmp/paras.json", json_encode( $in ) );
exec( 'php ' . escapeshellarg( dirname( __DIR__ ) . '/tools-build-hosted.php' ) . " $tmp/paras.json $tmp/hosted.json pdf-test 'Test PDF Document' 2>&1" );
$body = file_get_contents( "$tmp/hosted.json" );

$reg = json_decode( file_get_contents( "$tmp/plugin/registry.json" ), true );
$reg['documents'][] = array(
	'id' => 'pdf-test', 'title' => 'Test PDF Document', 'short' => 'PT', 'aliases' => array( 'test pdf document' ), 'language' => 'en', 'scheme' => 'paragraph',
	'expected_count' => 8, 'max_locator' => 8, 'url' => 'https://www.vatican.va/content/dam/test/test_en.pdf',
	'hosted' => array( 'url' => 'https://raw.githubusercontent.com/numena1-byte/anchor-fragment/x/data/pdf-test.json', 'sha256' => hash( 'sha256', $body ) ),
);
file_put_contents( "$tmp/plugin/registry.json", json_encode( $reg ) );

$GLOBALS['af_plugin_dir'] = "$tmp/plugin";
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'fragment', 'matcher', 'analyzer', 'quote', 'service' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }

$r = AF_Service::import_hosted_body( 'pdf-test', $body );
check( 'setup: the invented PDF-only document imports (8 paragraphs)', ! is_wp_error( $r ) && 8 === $r['count'], is_wp_error( $r ) ? $r->get_error_message() : '' );

$S = "\xC2\xAD";
check( 'before: passage says the text has not been added', is_wp_error( AF_Service::passage( 'pdf-test', 2 ) ) && 'af_pdf_text' === AF_Service::passage( 'pdf-test', 2 )->get_error_code() );
check( 'before: the editor button does not offer it', ! in_array( 'pdf-test', array_column( AF_Quote::picker_docs(), 'id' ), true ) );

// Exact text, and a line-end hyphen that is only a line break.
$p2 = str_replace( 'says', 'sa' . $S . 'ys', $paras[2] );
$p3 = str_replace( array( 'self-giving', 'teaching' ), array( 'self' . $S . 'giving', 'teach' . $S . 'ing' ), $paras[3] );
$res = AF_Service::accept_text( 'pdf-test', array( 2 => $p2, 3 => $p3, 4 => $paras[4] ) );
check( 'accepts text that agrees with the index, including marked line-end hyphens', array( 2, 3, 4 ) === $res['accepted'] && ! $res['rejected'], json_encode( $res ) );
$st = AF_Service::stored_text( 'pdf-test' );
check( 'a break that was a real hyphen keeps it; a break inside a word is joined', false !== strpos( $st[3], 'self-giving' ) && false !== strpos( $st[3], 'teaching' ) && false === strpos( $st[2], $S ) && false !== strpos( $st[2], ' says ' ), $st[3] ?? '' );

// Things that must be refused.
$altered   = str_replace( 'orchard', 'orchids', $paras[5] );
$truncated = implode( ' ', array_slice( explode( ' ', $paras[6] ), 0, 40 ) );
$extra     = $paras[7] . ' And then a heading line that is not part of the paragraph.';
$res = AF_Service::accept_text( 'pdf-test', array( 5 => $altered, 6 => $truncated, 7 => $extra, 8 => 'Too short.' ) );
check( 'refuses a changed word, a truncated paragraph, extra words and a too-short text', array() === $res['accepted'] && 4 === count( $res['rejected'] ), json_encode( $res ) );
check( 'the reasons say what was wrong', false !== strpos( json_encode( $res['rejected'] ), 'differ from the index' ) && false !== strpos( json_encode( $res['rejected'] ), 'incomplete' ), json_encode( $res['rejected'] ) );
check( 'refused paragraphs were not stored', array( 2, 3, 4 ) === array_keys( AF_Service::stored_text( 'pdf-test' ) ) );

// Served by passage() and offered by the button.
$p = AF_Service::passage( 'pdf-test', 3 );
check( 'passage returns the stored text, marked as checked, with the standard markup and a PDF page link', ! is_wp_error( $p ) && true === $p['verified'] && 'stored' === $p['source'] && 0 === strpos( $p['html'], '<blockquote class="af-quote" data-af-doc="pdf-test" data-af-locator="3">' ) && false !== strpos( $p['html'], '.pdf#page=13' ), json_encode( $p ) );
check( 'passage for a paragraph not added is still an error', is_wp_error( AF_Service::passage( 'pdf-test', 5 ) ) );
check( 'passage for a paragraph number the document does not have is an error', is_wp_error( AF_Service::passage( 'pdf-test', 99 ) ) );
check( 'the editor button now offers the document', in_array( 'pdf-test', array_column( AF_Quote::picker_docs(), 'id' ), true ) );

// Bad input.
check( 'unknown document is an error', is_wp_error( AF_Service::accept_text( 'nope', array( 1 => 'x' ) ) ) );
check( 'a document with no index is an error', is_wp_error( AF_Service::accept_text( 'laudato-si', array( 1 => 'x' ) ) ) );
$info = AF_Service::text_info( 'pdf-test' );
check( 'text_info gives pages and what is stored', ! is_wp_error( $info ) && 13 === $info['pages'][3] && array( 2, 3, 4 ) === $info['stored'], json_encode( $info ) );
AF_Service::clear_text( 'pdf-test' );
check( 'clearing removes the stored text', array() === AF_Service::stored_text( 'pdf-test' ) );

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
