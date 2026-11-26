<?php
// Earlier wordings of revised paragraphs: the shipped data file and the index overlay.
// Usage: php editions.php <path-to-plugin-dir>
define( 'AF_TEST', true );
$dir = rtrim( $argv[1], '/' );
foreach ( array( 'parser', 'matcher' ) as $f ) { require $dir . "/includes/class-af-$f.php"; }
$fail = 0;
function check( $label, $cond, $detail = '' ) { global $fail; echo ( $cond ? "  ok   " : "  FAIL " ) . $label . ( $cond ? '' : "  -> $detail" ) . "\n"; if ( ! $cond ) { $fail++; } }

$d = json_decode( file_get_contents( $dir . '/data/catechism-editions.json' ), true );
check( 'the data file is valid and made for this run length', is_array( $d ) && 1 === $d['format'] && AF_Matcher::K === $d['matcher_k'] );
$e = $d['editions'][0];
check( 'it holds hashed runs for Catechism 2267, and no text', 'catechism' === $e['doc'] && 2267 === $e['locator'] && 149 === count( $e['hashes'] ) && ! isset( $e['text'] ) && is_int( $e['hashes'][0] ), json_encode( array_keys( $e ) ) );
$s = 0; foreach ( $e['hashes'] as $h ) { $s = ( $s + $h ) % 1000000007; }
check( 'checksum of the hashes', 225458724 === $s, (string) $s );

// Overlay: an old wording is matched to the paragraph number it had.
$new_text = 'The practice of capital punishment is inadmissible because it is an attack on the inviolability and dignity of the person, and so the Church works with determination for its abolition everywhere.';
$old_text = 'Assuming that the guilty party is fully identified the traditional teaching of the Church allows recourse to the ultimate penalty if this is the only possible way of defending human lives.';
$idx = AF_Matcher::build_index( array( 2267 => array( 'full' => $new_text ), 2268 => array( 'full' => 'Another paragraph with entirely different words that say nothing about the matter at hand today.' ) ) );
$seg = AF_Matcher::segments( $old_text );
$before = AF_Matcher::score( $seg, $idx );
check( 'the old wording is not found in the current text', $before['coverage'] < 0.2, json_encode( $before ) );
$idx2 = AF_Matcher::merge_index( $idx, AF_Matcher::hashes( AF_Matcher::tokens( $old_text ) ), 2267 );
$after = AF_Matcher::score( $seg, $idx2 );
check( 'with the earlier wording merged it matches that paragraph', 1.0 === (float) $after['coverage'] && array( 2267 ) === $after['locators'], json_encode( $after ) );
$still = AF_Matcher::score( AF_Matcher::segments( $new_text ), $idx2 );
check( 'the current wording still matches', 1.0 === (float) $still['coverage'] && array( 2267 ) === $still['locators'] );
$shared = AF_Matcher::merge_index( array( 5 => 1 ), array( 5 ), 2267 );
check( 'a run both paragraphs share lists both', array( 1, 2267 ) === $shared[5], json_encode( $shared ) );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
