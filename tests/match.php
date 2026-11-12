<?php
// Local test for the matcher and analyzer. Usage: php match.php <path-to-plugin-dir>
// Uses invented documents and posts only.
define( 'AF_TEST', true );
$dir = rtrim( $argv[1], '/' );
foreach ( array( 'parser', 'fragment', 'matcher', 'analyzer' ) as $f ) {
	require $dir . "/includes/class-af-$f.php";
}

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . ( $cond ? '' : "  -> $detail" ) . "\n";
	if ( ! $cond ) { $fail++; }
}

$doc_a = array(
	'The orchard keepers of the northern valley have long said that patience is the first tool of the harvest, and that no ladder can reach what the roots have not already prepared. Every season the same lesson returns to those who would hurry the fruit.',
	'When the river rose that spring, the villagers gathered stones from the old mill and built a low wall along the bank, each family adding what it could carry. The wall did not stop the water, but it taught them how to stand together.',
	'A lantern carried through the market at dusk reveals less about the road than about the one who carries it, for the light falls first on the hands and the face. We learn to trust those whose light does not blind us.',
	'Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body. The people who bake together also remember together, and memory is a kind of nourishment.',
	'Teachers of the weaving craft insist that a single thread proves nothing until it is drawn through many others, and that the pattern belongs to no one hand. Whoever claims the cloth alone has misunderstood the loom.',
	'The harbour pilot knows the shifting sandbars not from charts but from years of small corrections, each one made in humility before the tide. Such knowledge cannot be hurried and cannot be written down entire.',
	'Winter forces every household to count what it has stored, and in the counting the neighbours discover who has more than enough and who too little. Generosity is simply arithmetic done with an honest heart.',
	'At the end of the long road the travellers found not a city but an open field, and they understood that the destination had been the company they kept along the way.',
);
$doc_b = array(
	'Mountain bells ring differently in thin air, and the shepherds learned to read the weather from the length of each note. Their children inherited this reading without ever being taught it.',
	'Whoever claims the cloth alone has misunderstood the loom, and whoever keeps the harvest alone has misunderstood the field. Shared work leaves a shared inheritance.',
	'Clay remembers the pressure of the hand that shaped it, and the potter reads in a cracked bowl the story of a hurried firing.',
);

function build_index_for( array $texts ) {
	$html = '<html><body>';
	foreach ( $texts as $i => $t ) {
		$html .= '<p>' . ( $i + 1 ) . '. ' . $t . '</p>';
	}
	$parsed = AF_Parser::parse( $html . '</body></html>' );
	return array( $parsed, AF_Matcher::build_index( $parsed['paragraphs'] ) );
}

list( $pa, $ia ) = build_index_for( $doc_a );
list( $pb, $ib ) = build_index_for( $doc_b );
check( 'doc A parsed to 8 paragraphs, doc B to 3', 8 === $pa['stats']['count'] && 3 === $pb['stats']['count'] );

$registry = array(
	'test-document'   => array( 'id' => 'test-document', 'title' => "Test Document", 'short' => 'TD', 'aliases' => array(), 'url' => 'https://www.vatican.va/test/doc-a_test-document.html' ),
	'another-charter' => array( 'id' => 'another-charter', 'title' => "Another Charter", 'short' => 'AC', 'aliases' => array( 'the other charter' ), 'url' => 'https://www.vatican.va/test/doc-b_another-charter.html' ),
);
$an  = new AF_Analyzer( $registry );
$get = function ( $id ) use ( $ia, $ib ) {
	return 'test-document' === $id ? $ia : $ib;
};
function run1( $an, $get, $html, $tags = array() ) {
	$r = $an->analyze( $html, $tags, $get );
	return $r['quotes'];
}

// Tokens, segments, hashes.
check( 'tokens ignore case, accents, quote styles and footnote markers', array( 'ecoute', 'l', 'ami', 'dit' ) === AF_Matcher::tokens( "Écoute l’ami[12], dit" ) , json_encode( AF_Matcher::tokens( "Écoute l’ami[12], dit" ) ) );
check( 'segments split at ellipsis and bracketed edits', 3 === count( AF_Matcher::segments( 'one two three four five six … seven eight nine ten eleven twelve [the] thirteen fourteen fifteen sixteen seventeen eighteen' ) ) );

// 1. Exact blockquote with a typed cite in the last paragraph -> high.
$q = run1( $an, $get, '<p>As the Test Document observes:</p><blockquote><p>A lantern carried through the market at dusk reveals less about the road than about the one who carries it, for the light falls first on the hands and the face.</p><p>(TD 3)</p></blockquote>' );
check( '1 exact quote + typed cite -> high, paragraph 3', 1 === count( $q ) && 'high' === $q[0]['status'] && array( 3 ) === $q[0]['best']['locators'] && 'test-document' === $q[0]['best']['doc'], json_encode( $q ) );
check( '1 coverage is full', 1.0 === (float) $q[0]['best']['coverage'] );

// 2. Edited quote (footnote marker, ellipsis) with the document named in the lead-in -> medium.
$q = run1( $an, $get, '<p>In the Test Document we read this:</p><blockquote>Teachers of the weaving craft insist that a single thread proves nothing until it is drawn through many others[12] … Whoever claims the cloth alone has misunderstood the loom.</blockquote>' );
check( '2 edited quote, lead-in names the document -> medium, paragraph 5', 'medium' === $q[0]['status'] && array( 5 ) === $q[0]['best']['locators'], json_encode( $q ) );
check( '2 the other document shows as an alternative', 1 === count( $q[0]['alt'] ) && 'another-charter' === $q[0]['alt'][0]['doc'], json_encode( $q[0]['alt'] ) );

// 3. Spans two paragraphs, with renumbered pasted numbers -> locators 6 and 7.
$q = run1( $an, $get, '<blockquote><p>1. The harbour pilot knows the shifting sandbars not from charts but from years of small corrections, each one made in humility before the tide. Such knowledge cannot be hurried and cannot be written down entire.</p><p>2. Winter forces every household to count what it has stored, and in the counting the neighbours discover who has more than enough and who too little.</p></blockquote>' );
check( '3 spans two paragraphs -> 6 and 7', array( 6, 7 ) === $q[0]['best']['locators'] && in_array( $q[0]['status'], array( 'low', 'medium' ), true ), json_encode( $q ) );

// 4. Typed cite disagrees with the text -> conflict.
$q = run1( $an, $get, '<blockquote>Teachers of the weaving craft insist that a single thread proves nothing until it is drawn through many others, and that the pattern belongs to no one hand.</blockquote><p>(TD 4)</p>' );
check( '4 typed TD 4 but text is paragraph 5 -> conflict', 'conflict' === $q[0]['status'] && array( 5 ) === $q[0]['best']['locators'], json_encode( $q ) );

// 5. Unrelated -> no_match.
$q = run1( $an, $get, '<blockquote>The committee reconvened after lunch to discuss the quarterly budget projections and the allocation of resources to regional offices.</blockquote>' );
check( '5 unrelated text -> no_match', 'no_match' === $q[0]['status'] && null === $q[0]['best'], json_encode( $q ) );

// 6. Too short.
$q = run1( $an, $get, '<blockquote>Patience is the first tool.</blockquote>' );
check( '6 five words -> too_short', 'too_short' === $q[0]['status'], json_encode( $q ) );

// 7. Inline quotation + a link to the document -> medium.
$q = run1( $an, $get, '<p>He wrote that &#8220;Generosity is simply arithmetic done with an honest heart&#8221; and linked <a href="https://www.vatican.va/test/doc-a_test-document.html">it</a>.</p>' );
check( '7 inline quote + link names the document -> medium, paragraph 7', 1 === count( $q ) && 'inline' === $q[0]['kind'] && 'medium' === $q[0]['status'] && array( 7 ) === $q[0]['best']['locators'], json_encode( $q ) );

// 8. Out of scope: Catechism named, text not in our documents.
$q = run1( $an, $get, '<p>See the Catechism 2415.</p><blockquote>The universal destination of goods remains the first principle of the whole ethical and social order and the proper object of deep reflection today.</blockquote>' );
check( '8 Catechism named, no match -> out_of_scope', 'out_of_scope' === $q[0]['status'], json_encode( $q ) );

// 9. Same sentence in two documents: ambiguous without a hint, broken by a hint.
$sentence = 'Whoever claims the cloth alone has misunderstood the loom';
$q = run1( $an, $get, '<blockquote>' . $sentence . '.</blockquote>' );
check( '9a same sentence in two documents, no hint -> ambiguous', 'ambiguous' === $q[0]['status'], json_encode( $q ) );
$q = run1( $an, $get, '<p>Another Charter says:</p><blockquote>' . $sentence . '.</blockquote>' );
check( '9b lead-in names Another Charter -> medium, paragraph 2 of that document', 'medium' === $q[0]['status'] && 'another-charter' === $q[0]['best']['doc'] && array( 2 ) === $q[0]['best']['locators'], json_encode( $q ) );
$q = run1( $an, $get, '<blockquote>' . $sentence . '.</blockquote>', array( 'Another Charter' ) );
check( '9c a tag naming Another Charter also breaks the tie', 'another-charter' === $q[0]['best']['doc'] && 'ambiguous' !== $q[0]['status'], json_encode( $q ) );

// 10. Bare number carries over from an earlier cite.
$q = run1( $an, $get, '<blockquote>When the river rose that spring, the villagers gathered stones from the old mill and built a low wall along the bank, each family adding what it could carry.</blockquote><p>(TD 2)</p><blockquote>A lantern carried through the market at dusk reveals less about the road than about the one who carries it, for the light falls first on the hands and the face.</blockquote><p>(3)</p>' );
check( '10 two quotes; second cited only as (3) after (TD 2) -> both high', 2 === count( $q ) && 'high' === $q[0]['status'] && 'high' === $q[1]['status'], json_encode( array_column( $q, 'status' ) ) );

// 11. Citation styles.
$q = run1( $an, $get, '<blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote><p>Test Document, no. 4</p>' );
check( '11 "Test Document, no. 4" -> high', 'high' === $q[0]['status'], json_encode( $q ) );
$q = run1( $an, $get, '<blockquote>At the end of the long road the travellers found not a city but an open field, and they understood that the destination had been the company they kept along the way.</blockquote><p>(Test Document 8)</p>' );
check( '11b "(Test Document 8)" -> high', 'high' === $q[0]['status'], json_encode( $q ) );

// 12. Years and other numbers are not read as paragraph numbers.
$q = run1( $an, $get, '<p>The Test Document of 2015 is long.</p><blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>' );
check( '12 a year next to the title is not a cite -> medium, not conflict', 'medium' === $q[0]['status'], json_encode( $q ) );

// 13. Summary counts.
$all = $an->analyze( '<blockquote>Patience is the first tool.</blockquote><blockquote>The committee reconvened after lunch to discuss the quarterly budget projections and the allocation of resources to regional offices.</blockquote>', array(), $get );
check( '13 summary counts by status', array( 'too_short' => 1, 'no_match' => 1 ) === $all['summary'], json_encode( $all['summary'] ) );

// 14. A document with four-digit paragraph numbers (the Catechism).
$cc_texts = array(
	2413 => 'Divine providence is also exercised through human work, which the Creator entrusted to every person as a share in the shaping of the world around them.',
	2414 => 'The seventh commandment forbids acts or enterprises that for any reason lead to the enslavement of human beings, and to their being bought, sold and exchanged like merchandise.',
	2415 => 'The seventh commandment enjoins respect for the integrity of creation, and animals like plants and inanimate beings are by nature destined for the common good of past, present and future humanity.',
);
$cc_html = '<html><body>';
foreach ( $cc_texts as $n => $txt ) {
	$cc_html .= '<p>' . $n . ' ' . $txt . '<sup><a href="#$K">1</a></sup></p>';
}
$ccp = AF_Parser::parse( $cc_html . '</body></html>', 2865, array( 'bare_numbers' => true, 'strip_sup' => true, 'after' => 2412, 'window' => 5, 'repair' => false ) );
check( '14 four-digit paragraphs parsed', 3 === $ccp['stats']['count'] && 2415 === $ccp['stats']['max'], json_encode( $ccp['stats'] ) );
$icc  = AF_Matcher::build_index( $ccp['paragraphs'] );
$reg2 = $registry + array(
	'catechism' => array( 'id' => 'catechism', 'title' => 'Catechism of the Catholic Church', 'short' => 'CCC', 'aliases' => array( 'catechism', 'ccc' ), 'expected_count' => 2865, 'url' => 'https://www.vatican.va/archive/ENG0015/_INDEX.HTM' ),
);
$qtext = 'The seventh commandment enjoins respect for the integrity of creation, and animals like plants and inanimate beings are by nature destined for the common good of past, present and future humanity.';
$an2   = new AF_Analyzer( $reg2 );
$no    = $an2->analyze( '<p>See the Catechism 2415.</p><blockquote>' . $qtext . '</blockquote>', array(), function ( $id ) use ( $get ) { return 'catechism' === $id ? null : $get( $id ); } );
check( '14a Catechism not indexed yet -> out_of_scope', 'out_of_scope' === $no['quotes'][0]['status'], json_encode( $no['quotes'] ) );
$yes = $an2->analyze( '<p>See the Catechism 2415.</p><blockquote>' . $qtext . '</blockquote>', array(), function ( $id ) use ( $get, $icc ) { return 'catechism' === $id ? $icc : $get( $id ); } );
$q   = $yes['quotes'][0];
check( '14b Catechism indexed -> high, paragraph 2415, typed cite agrees', 'high' === $q['status'] && 'catechism' === $q['best']['doc'] && array( 2415 ) === $q['best']['locators'] && array( 2415 ) === $q['typed'][0]['loc'], json_encode( $q ) );
$yr  = $an2->analyze( '<p>The Catechism (1992) says:</p><blockquote>' . $qtext . '</blockquote>', array(), function ( $id ) use ( $get, $icc ) { return 'catechism' === $id ? $icc : $get( $id ); } );
check( '14c a year next to the title is not a cite', empty( $yr['quotes'][0]['typed'] ) || 1992 !== $yr['quotes'][0]['typed'][0]['loc'][0], json_encode( $yr['quotes'][0]['typed'] ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
