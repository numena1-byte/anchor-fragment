<?php
// Local test for the pure-PHP parts (parser + fragment). Usage: php run.php <path-to-plugin-dir>
define( 'AF_TEST', true );
$dir = rtrim( $argv[1], '/' );
require $dir . '/includes/class-af-parser.php';
require $dir . '/includes/class-af-fragment.php';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . ( $cond ? '' : "  -> $detail" ) . "\n";
	if ( ! $cond ) { $fail++; }
}

$utf8 = <<<'HTML'
<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252"><title>Test</title>
<script>var x = "1. not a paragraph";</script></head><body>
<p>Menu item one two three four</p>
<h2>Introduction</h2>
<p align="justify">1. “Laudato si’”, sing the praises of all creatures together<a href="#_ftn1" name="_ftnref1">[1]</a>. Second sentence.</p>
<p>2. This sister now cries out to us because of the harm we have inflicted on her.</p>
<p>Continuation of paragraph two without a number.</p>
<h2>A heading</h2>
<p>3. We have forgotten that we ourselves are dust of the earth:</p>
<p>1. A sublist item inside three that must be ignored as out of sequence.</p>
<p>4. In our world there is more to say than we thought possible.</p>
<p>5. In our world there is more to say about water and rivers.</p>
<p>7. The number six is missing from this sequence entirely.</p>
<p>8. Écoute: accented opening with enough words to make a fragment.</p>
<p>9. Short <sup>[3]</sup> x</p>
<p><a href="#_ftnref1" name="_ftn1">[1]</a> 1. Footnote text that begins with a number and must not count.</p>
<p>10. After the footnotes, this one is still a paragraph.</p>
<p>11. <a href="#_ftn4" name="_ftnref4">[4]</a> Something odd.</p>
<table><tr><td>2.5 million is a decimal, not a locator, in a table cell</td></tr></table>
</body></html>
HTML;

// Pretend it was served as Windows-1252.
$bytes = mb_convert_encoding( $utf8, 'WINDOWS-1252', 'UTF-8' );
check( 'fixture really is not valid UTF-8', ! mb_check_encoding( $bytes, 'UTF-8' ) );

list( $back, $cs ) = AF_Parser::to_utf8( $bytes, 'text/html; charset=windows-1252' );
check( 'to_utf8 via HTTP header', $back === $utf8 && 'WINDOWS-1252' === $cs, $cs );
list( $back2, $cs2 ) = AF_Parser::to_utf8( $bytes, 'text/html' );
check( 'to_utf8 via <meta> declaration', $back2 === $utf8 && 'WINDOWS-1252' === $cs2, $cs2 );
list( $back3, $cs3 ) = AF_Parser::to_utf8( $bytes, '' );
check( 'to_utf8 reads <meta> when no header', $back3 === $utf8, $cs3 );
list( , $cs4 ) = AF_Parser::to_utf8( $bytes, 'text/html; charset=ISO-8859-1' );
check( 'ISO-8859-1 treated as Windows-1252', 'WINDOWS-1252' === $cs4, $cs4 );

$r  = AF_Parser::parse( $back );
$st = $r['stats'];
$keys = array_map( 'strval', array_keys( $r['paragraphs'] ) ); // PHP turns numeric keys into ints.
check( 'finds paragraphs 1-5,7-11', $keys === array( '1', '2', '3', '4', '5', '7', '8', '9', '10', '11' ), implode( ',', $keys ) );
check( 'reports the gap at 6', $st['gaps'] === array( 6 ), json_encode( $st['gaps'] ) );
check( 'ignores the out-of-sequence sublist "1."', $st['rejected'] === array( 1 ), json_encode( $st['rejected'] ) );
check( 'footnote "1." is not counted', ! isset( $r['paragraphs']['1'] ) || false !== strpos( $r['paragraphs']['1']['first'], 'Laudato' ) );
check( 'script text not parsed', false === strpos( $r['page'], 'not a paragraph' ) );
check( 'continuation joins paragraph 2', false !== strpos( $r['paragraphs']['2']['full'], 'Continuation of paragraph two' ) );
check( 'heading ends paragraph 2 (3 starts fresh)', false === strpos( $r['paragraphs']['2']['full'], 'dust of the earth' ) );
check( 'decimal in a table cell is not a locator', ! isset( $r['paragraphs']['2.5'] ) && 11 === $st['max'] );
check( 'number removed from first block', 0 === strpos( $r['paragraphs']['2']['first'], 'This sister' ), $r['paragraphs']['2']['first'] );

$f1 = AF_Fragment::build( $r['paragraphs']['1']['first'], $r['page'] );
check( 'para 1: cut before footnote marker, 4 words', 4 === $f1['words'] && 0 === strpos( $f1['frag'], '“Laudato si’”, sing' ), json_encode( $f1 ) );
$f4 = AF_Fragment::build( $r['paragraphs']['4']['first'], $r['page'] );
$f5 = AF_Fragment::build( $r['paragraphs']['5']['first'], $r['page'] );
check( 'para 4 extended past the shared opening (9 words)', 9 === $f4['words'] && (bool) preg_match( '/say than$/', $f4['frag'] ), json_encode( $f4 ) );
check( 'para 5 extended past the shared opening (9 words)', 9 === $f5['words'] && (bool) preg_match( '/say about$/', $f5['frag'] ), json_encode( $f5 ) );
$f8 = AF_Fragment::build( $r['paragraphs']['8']['first'], $r['page'] );
check( 'para 8: accented start works', null !== $f8['frag'] && 0 === strpos( $f8['frag'], 'Écoute' ), json_encode( $f8 ) );
$f9 = AF_Fragment::build( $r['paragraphs']['9']['first'], $r['page'], $r['paragraphs']['9']['lead'] );
check( 'para 9: short opening before a footnote marker uses the paragraph number', '9. Short' === $f9['frag'], json_encode( $f9 ) );
$f11 = AF_Fragment::build( $r['paragraphs']['11']['first'], $r['page'], $r['paragraphs']['11']['lead'] );
check( 'para 11: nothing before the marker is reported, not guessed', null === $f11['frag'] && '' !== $f11['reason'], json_encode( $f11 ) );
$f9b = AF_Fragment::build( $r['paragraphs']['9']['first'], $r['page'] );
check( 'without the number, the same short opening is refused', null === $f9b['frag'], json_encode( $f9b ) );

// Every fragment that was produced must occur exactly once in the page text.
$allunique = true;
foreach ( $r['paragraphs'] as $loc => $p ) {
	$f = AF_Fragment::build( $p['first'], $r['page'], $p['lead'] );
	if ( $f['frag'] && 1 !== substr_count( $r['page'], AF_Parser::fold( $f['frag'] ) ) ) { $allunique = false; echo "    not unique: $loc\n"; }
}
check( 'every produced fragment is unique in the page', $allunique );

check( 'encode: hyphen, comma, ampersand, space', 'A%2DB%2C%20C%26D' === AF_Fragment::encode( 'A-B, C&D' ), AF_Fragment::encode( 'A-B, C&D' ) );
check( 'encode: curly quote is UTF-8 percent-encoded', '%E2%80%99' === AF_Fragment::encode( '’' ) );
echo "\nLink for paragraph 4:\n  " . AF_Fragment::link( 'https://example.org/doc.html', $f4['frag'] ) . "\n";

// --- Mis-numbered paragraph: the source prints "8." where 3 belongs (as "81." appears to stand for 87).
echo "\nGap repair:\n";
$typo = '<html><body><p>1. First paragraph about apples and pears.</p><p>2. Second paragraph about oranges and plums.</p>'
	. '<p>Tail of two.</p><p>8. Typo paragraph that should have been three.</p><p>More of three.</p>'
	. '<p>4. Fourth paragraph about grapes and figs.</p><p>5. Fifth paragraph about melons and dates.</p></body></html>';
$t = AF_Parser::parse( $typo );
check( 'typo: all five paragraphs present', 5 === $t['stats']['count'] && array() === $t['stats']['gaps'], json_encode( $t['stats'] ) );
check( 'typo: repair is reported', isset( $t['stats']['inferred'][3] ) && false !== strpos( $t['stats']['inferred'][3], '8' ), json_encode( $t['stats']['inferred'] ) );
check( 'typo: used number is no longer listed as rejected', array() === $t['stats']['rejected'], json_encode( $t['stats']['rejected'] ) );
check( 'typo: paragraph 3 starts at the typo block and keeps its continuation', 0 === strpos( $t['paragraphs']['3']['first'], 'Typo paragraph' ) && false !== strpos( $t['paragraphs']['3']['full'], 'More of three' ), json_encode( $t['paragraphs']['3'] ) );
check( 'typo: paragraph 2 no longer contains the moved text', false === strpos( $t['paragraphs']['2']['full'], 'Typo' ) && false !== strpos( $t['paragraphs']['2']['full'], 'Tail of two' ), $t['paragraphs']['2']['full'] );
check( 'typo: paragraph 3 is marked inferred', '' !== $t['paragraphs']['3']['inferred'] );

// Two candidates between the neighbours: ambiguous, so the gap must stay visible.
$amb = '<html><body><p>1. First paragraph about apples and pears.</p><p>2. Second paragraph about oranges and plums.</p>'
	. '<p>9. Maybe three, maybe not.</p><p>9. Another stray number.</p>'
	. '<p>4. Fourth paragraph about grapes and figs.</p></body></html>';
$a = AF_Parser::parse( $amb );
check( 'ambiguous: gap at 3 is left alone', array( 3 ) === $a['stats']['gaps'] && array() === $a['stats']['inferred'], json_encode( $a['stats'] ) );

// No candidate at all: the gap stays.
$none = '<html><body><p>1. First paragraph about apples and pears.</p><p>2. Second paragraph about oranges and plums.</p>'
	. '<p>4. Fourth paragraph about grapes and figs.</p></body></html>';
$nn = AF_Parser::parse( $none );
check( 'no candidate: gap at 3 is left alone', array( 3 ) === $nn['stats']['gaps'], json_encode( $nn['stats'] ) );

// A real sublist between two ordinary paragraphs must not be mistaken for a missing paragraph.
$sub = '<html><body><p>1. First paragraph about apples and pears.</p><p>1. A sublist entry.</p><p>2. Second paragraph about oranges and plums.</p></body></html>';
$s = AF_Parser::parse( $sub );
check( 'sublist: nothing invented when there is no gap', 2 === $s['stats']['count'] && array() === $s['stats']['inferred'] && array( 1 ) === $s['stats']['rejected'], json_encode( $s['stats'] ) );

// Footnotes printed as numbered paragraphs continue the sequence; a cap keeps them out.
$fn = '<html><body><p>1. First article about apples and pears.</p><p>2. Second article about oranges and plums.</p>'
	. '<p>3. Cf. Gal. 3:7</p><p>4. Cf. Matt. 5:45</p></body></html>';
$capped = AF_Parser::parse( $fn, 2 );
check( 'max locator: footnote numbers are not taken as paragraphs', 2 === $capped['stats']['count'], json_encode( $capped['stats'] ) );
check( 'max locator: not set, they are', 4 === AF_Parser::parse( $fn )['stats']['count'] );

// A heading printed as an ordinary paragraph belongs to what follows, not to the paragraph before it.
$hd = '<html><body><p>1. First article about apples and pears, and more words here.</p><p>D) Norms for adapting the Liturgy to the culture of peoples</p>'
	. '<p>2. Second article about oranges and plums.</p><p>A sentence that continues the second article.</p></body></html>';
$h = AF_Parser::parse( $hd );
check( 'heading before next article is not part of the previous one', false === strpos( $h['paragraphs']['1']['full'], 'Norms' ), $h['paragraphs']['1']['full'] );
check( 'a normal continuation is kept', false !== strpos( $h['paragraphs']['2']['full'], 'continues' ) );
$ls = '<html><body><p>1. First article about apples:</p><p>a) the first thing;</p><p>b) the last thing</p><p>2. Second article about plums and figs.</p></body></html>';
$l = AF_Parser::parse( $ls );
check( 'list items stay with their article', false !== strpos( $l['paragraphs']['1']['full'], 'last thing' ), $l['paragraphs']['1']['full'] );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
