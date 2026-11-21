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

// 9d. The name just before the quotation wins over a name in the text after it (a later sentence introducing the next quote).
$q = run1( $an, $get, '<p>Poverty is bound up with everything, again from Another Charter: “' . $sentence . '.” Test Document confirms this, adding something else entirely.</p>' );
check( '9d name just before beats a name in the next sentence -> another-charter, with a note', 'another-charter' === $q[0]['best']['doc'] && 'ambiguous' !== $q[0]['status'] && '' !== $q[0]['note'], json_encode( $q ) );
$q = run1( $an, $get, '<p>As Test Document and Another Charter both teach, the following lines are shared.</p><blockquote>' . $sentence . '.</blockquote>' );
check( '9e both named just before -> still ambiguous', 'ambiguous' === $q[0]['status'], json_encode( $q ) );

// 9f. A cite at the end of the quotation's own paragraph belongs to that quotation, not to its neighbours.
$riv = 'When the river rose that spring, the villagers gathered stones from the old mill and built a low wall along the bank, each family adding what it could carry.';
$lan = 'A lantern carried through the market at dusk reveals less about the road than about the one who carries it, for the light falls first on the hands and the face.';
$pil = 'The harbour pilot knows the shifting sandbars not from charts but from years of small corrections, each one made in humility before the tide.';
$q = run1( $an, $get, '<blockquote><p>“' . $riv . '” (TD 2).</p></blockquote><p>Then, he says, “' . $lan . '” (TD 3). And so we move to what follows:</p><blockquote><p>“' . $pil . '” (TD 6).</p></blockquote>' );
$locs = array(); foreach ( $q as $x ) { $locs[ $x['typed'][0]['loc'][0] ] = array( $x['status'], count( $x['typed'] ), $x['best']['locators'][0] ); }
ksort( $locs );
check( '9f cite inside each quotation paragraph -> three high, each with its own cite only', 3 === count( $q ) && array( 2 => array( 'high', 1, 2 ), 3 => array( 'high', 1, 3 ), 6 => array( 'high', 1, 6 ) ) === $locs, json_encode( $locs ) );
check( '9g the cite is not counted among the words', 1.0 === (float) $q[0]['best']['coverage'], json_encode( $q[0]['best'] ) );
$q = run1( $an, $get, '<p>Previously: “' . $riv . '” (TD 2).</p><blockquote><p>“' . $pil . '” (TD 3).</p></blockquote>' );
$bq = $q[0];
check( '9h the quotation\'s own cite is wrong -> conflict naming that cite, not the neighbour\'s', 'conflict' === $bq['status'] && 1 === count( $bq['typed'] ) && array( 3 ) === $bq['typed'][0]['loc'] && array( 6 ) === $bq['best']['locators'], json_encode( $bq ) );
$q = run1( $an, $get, '<blockquote><p>“' . $riv . ' (see the passage below)”</p></blockquote>' );


// 9j. Two inline quotations, each followed by its own cite: the first cite is wrong, and it must not borrow the second one.
$acx = 'Clay remembers the pressure of the hand that shaped it';
$q = run1( $an, $get, '<p>The council acknowledges that “' . $riv . '” (TD 5). The entire cosmos is on a journey. Another Charter declares that “' . $acx . '” (AC 3).</p>' );
$by = array(); foreach ( $q as $x ) { $by[ $x['best']['doc'] ] = $x; }
check( '9j the first cite is read alone: conflict, typed TD 5 only, with a note', 'conflict' === $by['test-document']['status'] && 1 === count( $by['test-document']['typed'] ) && array( 5 ) === $by['test-document']['typed'][0]['loc'], json_encode( $by['test-document'] ) );
check( '9k the second quotation takes its own cite only and is high', 'high' === $by['another-charter']['status'] && 1 === count( $by['another-charter']['typed'] ) && array( 3 ) === $by['another-charter']['typed'][0]['loc'], json_encode( $by['another-charter'] ) );
check( '9l a number one digit off the matched paragraph is called a likely typing slip', false !== strpos( $by['test-document']['note'], 'typing slip' ), $by['test-document']['note'] );

// 9m. "ibid." cites: each belongs to its own quotation, and an earlier full cite in the text is not borrowed.
$wea = 'Teachers of the weaving craft insist that a single thread proves nothing until it is drawn through many others, and that the pattern belongs to no one hand.';
$q = run1( $an, $get, '<p>The theme runs through the text (Test Document 3). “' . $riv . '” (ibid. 2). “' . $wea . '” (ibid. 5).</p>' );
$ib = array(); foreach ( $q as $x ) { $ib[ $x['best']['locators'][0] ] = array( $x['status'], count( $x['typed'] ) ); }
ksort( $ib );
check( '9m ibid. cites: two quotations, each high with its own cite and no leak from the earlier cite', array( 2 => array( 'high', 1 ), 5 => array( 'high', 1 ) ) === $ib, json_encode( $q ) );
$q = run1( $an, $get, '<p>The theme runs through the text (Test Document 3). “' . $riv . '” (Ibid., no. 2).</p>' );
check( '9n Ibid., no. 2 is read too', 1 === count( $q ) && 'high' === $q[0]['status'], json_encode( $q ) );

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

// 15. "#N" beside the quotation, the document named around the previous quotation (a Catechism-style post).
$p15 = '<blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body. The people who bake together also remember together.</blockquote>'
	. '<p>This is followed by the reference to article 3 of the Test Document.</p>'
	. '<p>Actually, #4 says:</p>'
	. '<blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>';
$q = run1( $an, $get, $p15 );
check( '15 "#4 says" after a post that named the document -> typed TD 4 (agrees with text: high)', 2 === count( $q ) && 'high' === $q[1]['status'] && 'test-document' === $q[1]['typed'][0]['doc'], json_encode( array_column( $q, 'status' ) ) . ' ' . json_encode( $q[1]['typed'] ?? null ) );
$q = run1( $an, $get, '<p>Issue #4 of the newsletter said:</p><blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>' );
check( '15b "#4" with no document named anywhere -> not a cite, no conflict', 'conflict' !== $q[0]['status'] && empty( $q[0]['typed'] ), json_encode( $q ) );
$q = run1( $an, $get, '<p>The Test Document says, see #3 for details:</p><blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>' );
check( '15c "#3" disagrees with the text (paragraph 4) -> conflict', 'conflict' === $q[0]['status'], json_encode( $q ) );
$q = run1( $an, $get, '<p>As Test Document #4 puts it:</p><blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>' );
check( '15d "Test Document #4" -> high', 'high' === $q[0]['status'], json_encode( $q ) );

// 15e. "Article 11 states" with no document in the sentence is not a cite; "article 3 of the Test Document" is.
$q = run1( $an, $get, '<p>As the Test Document puts it.</p><p>Article 11 states:</p><blockquote>Mountain bells ring differently in thin air, and the shepherds learned to read the weather from the length of each note.</blockquote>' );
check( '15e "Article 11 states" -> not read as a cite of the Test Document', ! array_filter( $q[0]['typed'], function ( $t ) { return array( 11 ) === $t['loc']; } ), json_encode( $q[0]['typed'] ) );
$q = run1( $an, $get, '<p>The reference is to article 4 of the Test Document, which says:</p><blockquote>Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.</blockquote>' );
check( '15f "article 4 of the Test Document" in the lead-in -> typed TD 4', ! empty( $q[0]['typed'] ) && array( 4 ) === $q[0]['typed'][0]['loc'] && 'test-document' === $q[0]['typed'][0]['doc'], json_encode( $q[0]['typed'] ) );

// 15g. The real case: "Article 11 states:" before the quotation, "article 4 of the Test Document" after it.
$q = run1( $an, $get, '<p>The signer adheres to some 11 statements. Article 11 states:</p><blockquote>Mountain bells ring differently in thin air, and the shepherds learned to read the weather from the length of each note.</blockquote><p>This is followed by the reference to article 4 of the Test Document.</p>' );
$locs = array_map( function ( $t ) { return $t['loc'][0]; }, $q[0]['typed'] );
check( '15g text before and after the quotation are read separately -> typed 4 only, not 11', array( 4 ) === $locs, json_encode( $q[0]['typed'] ) );

// 9o. A date in the cite is not a paragraph number.
foreach ( array(
	'(POPE FRANCIS, APOSTOLIC EXHORTATION TEST DOCUMENT, 24 NOVEMBER 2013, 2)' => '9o upper-case style with the date',
	'(Apostolic Exhortation Test Document (24 November 2013), 2: AAS 105 (2013), 1031)' => '9p Vatican footnote style with the date',
	'(Test Document, November 24, 2013, 2)' => '9q month-first date',
) as $cite => $label ) {
	$q = run1( $an, $get, '<p>“' . $riv . '” ' . $cite . '</p>' );
	$ok = 1 === count( $q ) && 'high' === $q[0]['status'] && 1 === count( $q[0]['typed'] ) && array( 2 ) === $q[0]['typed'][0]['loc'];
	check( $label . ' -> typed 2, high', $ok, json_encode( $q ) );
}

// 9r. A list of locators in one cite: the one that agrees with the words wins, the other is noted.
foreach ( array( '(Test Document, 5; 2)', '(Test Document 2; 5)' ) as $cite ) {
	$q = run1( $an, $get, '<p>Pope said that “' . $riv . '” ' . $cite . '</p>' );
	check( '9r ' . $cite . ' -> high, both numbers read, the wrong one noted', 'high' === $q[0]['status'] && 2 === count( $q[0]['typed'] ) && false !== strpos( $q[0]['note'], 'cited test-document 5' ), json_encode( $q ) );
}
$q = run1( $an, $get, '<p>Pope said that “' . $riv . '” (Test Document 2, 5)</p>' );
check( '9s "2, 5" read as two paragraphs', 'high' === $q[0]['status'] && 2 === count( $q[0]['typed'] ), json_encode( $q ) );
$q = run1( $an, $get, '<p>Pope said that “' . $riv . '” (Test Document, 5; 6)</p>' );
check( '9t two wrong numbers -> still conflict', 'conflict' === $q[0]['status'], json_encode( $q ) );

// 9u. A number-only cite at the end of a blockquote is the quotation's own: the later "citing AC #3" is not read for it,
// and the document is the one named just before the quotation, not the one cited last.
$prev = '<p>As he said: “Clay remembers the pressure of the hand that shaped it, and the potter reads in a cracked bowl the story of a hurried firing” (<em>Another Charter</em>, #3).</p>';
$mine = '<p>In <em>Test Document</em>, the author states:</p><blockquote>' . $riv . ' (#2)</blockquote><p>And in a footnote he explains this, citing <em>AC</em> #3. But here it is not so.</p>';
$q = run1( $an, $get, $prev . $mine );
$last = null; foreach ( $q as $x ) { if ( 'blockquote' === $x['kind'] ) { $last = $x; } }
check( '9u (#2) after "In Test Document ... states:" -> test-document 2 only, high', 'high' === $last['status'] && 1 === count( $last['typed'] ) && 'test-document' === $last['typed'][0]['doc'] && array( 2 ) === $last['typed'][0]['loc'], json_encode( $last ) );
$q = run1( $an, $get, $mine );
check( '9v the same quotation alone', 'high' === $q[0]['status'] && 1 === count( $q[0]['typed'] ), json_encode( $q ) );

// 9w. "(para. 2)" at the end of a quotation is its own cite, the document coming from the text before; an edition year is not a paragraph.
$q = run1( $an, $get, '<p>The Test Document (TD, 1997), compiling the teaching of the Council, defines it this way:</p><blockquote>' . $riv . ' (para. 2).</blockquote>' );
check( '9w "(para. 2)" -> test-document 2, high', 1 === count( $q ) && 'high' === $q[0]['status'] && 1 === count( $q[0]['typed'] ) && array( 2 ) === $q[0]['typed'][0]['loc'], json_encode( $q ) );
$q = run1( $an, $get, '<p>Article 2 of the charter is long.</p><p>The Council wrote “' . $riv . '” in a long letter.</p>' );
check( '9x a marked number outside any bracket still needs its document in the sentence', empty( $q[0]['typed'] ), json_encode( $q ) );
$rm = new ReflectionMethod( 'AF_Analyzer', 'strip_dates' ); $rm->setAccessible( true );
check( '9y "(CCC, 1997)" loses the year', false === strpos( $rm->invoke( null, 'The Catechism (CCC, 1997), defines' ), '1997' ), $rm->invoke( null, 'The Catechism (CCC, 1997), defines' ) );
check( '9z "CCC 1997" without a comma is kept', false !== strpos( $rm->invoke( null, '(CCC 1997)' ), '1997' ), $rm->invoke( null, '(CCC 1997)' ) );

// 9aa. A dash line after a blockquote is its attribution, and it is the quotation's own cite: the text after is not read for it.
$dash = function ( $mark ) use ( $riv ) { return '<p>In Test Document the author writes:</p><blockquote><p>“' . $riv . '”</p><p style="text-align: right;">' . $mark . ' Test Document, 2</p></blockquote><p>After this he says that “anything” (AC 3) and so on.</p>'; };
foreach ( array( '---', '&mdash;', '&ndash;', '-' ) as $mark ) {
	$q = run1( $an, $get, $dash( $mark ) );
	$bq = null; foreach ( $q as $x ) { if ( 'blockquote' === $x['kind'] ) { $bq = $x; } }
	check( '9aa "' . $mark . ' Test Document, 2" -> high, typed test-document 2 only, words unchanged', $bq && 'high' === $bq['status'] && 1 === count( $bq['typed'] ) && array( 2 ) === $bq['typed'][0]['loc'] && 1.0 === (float) $bq['best']['coverage'], json_encode( $q ) );
}
$q = run1( $an, $get, '<blockquote><p>' . $riv . '</p><p>- and so it goes on and on for a while longer than you would think</p></blockquote>' );
check( '9ab a dash line that is not an attribution stays in the quotation', false !== strpos( json_encode( $q ), 'status' ) && 0 === count( $q[0]['typed'] ), json_encode( $q ) );

// 16. A bare "(n)" takes its document from an earlier cite, which is a guess: kept when the text agrees, dropped when it does not.
$bread = 'Bread shared at a long table tastes of more than flour and salt, because the hunger it answers is never only of the body.';
$q = run1( $an, $get, '<p>As Test Document 3 says:</p><blockquote>Alpha begins the story of a long road that crosses many fields and rivers before the sea.</blockquote><p>And then:</p><blockquote>' . $bread . '</blockquote><p>(2)</p>' );
$last = end( $q );
check( '16a bare "(2)" that the text does not support -> dropped, no conflict', 'conflict' !== $last['status'] && empty( $last['typed'] ), json_encode( $last ) );
$q = run1( $an, $get, '<p>As Test Document 3 says:</p><blockquote>Alpha begins the story of a long road that crosses many fields and rivers before the sea.</blockquote><p>And then:</p><blockquote>' . $bread . '</blockquote><p>(4)</p>' );
$last = end( $q );
check( '16b bare "(4)" that the text supports -> kept, high', 'high' === $last['status'] && 1 === count( $last['typed'] ), json_encode( $last ) );

// 17. "Some Other Work, no. 3" belongs to that work; a number already read as a named cite is not read twice.
$q = run1( $an, $get, '<p>The Test Document says nothing here. As Familiaris Consortio, no. 3 puts it:</p><blockquote>' . $bread . '</blockquote>' );
check( '17a "Familiaris Consortio, no. 3" is not a number of the Test Document -> no conflict', 'conflict' !== $q[0]['status'] && empty( $q[0]['typed'] ), json_encode( $q[0] ) );
$q = run1( $an, $get, '<p>Earlier: Test Document 4. Then, as it says (Test Document, no. 4), we read:</p><blockquote>' . $bread . '</blockquote>' );
check( '17b a number read as a named cite is not read twice', 'high' === $q[0]['status'] && 1 === count( $q[0]['typed'] ), json_encode( $q[0]['typed'] ) );


// Quotations inserted by the plugin carry the paragraph they are meant to be.
$tdp4 = $doc_a[3];
$mk   = function ( $doc, $loc, $text ) { return '<blockquote class="af-quote" data-af-doc="' . $doc . '" data-af-locator="' . $loc . '"><p>' . $text . '</p><footer class="af-quote__source">&mdash; <cite><a href="https://x.example/">Some Title, no. ' . $loc . '</a></cite></footer></blockquote>'; };
$q = run1( $an, $get, $mk( 'test-document', 4, $tdp4 ) );
check( '18a inserted quote, text agrees with declared paragraph -> high, declared paragraph recorded', 1 === count( $q ) && 'high' === $q[0]['status'] && 'test-document' === $q[0]['typed'][0]['doc'] && array( 4 ) === $q[0]['typed'][0]['loc'], json_encode( $q ) );
$q = run1( $an, $get, $mk( 'test-document', 6, $tdp4 ) );
check( '18b inserted quote whose text is another paragraph than declared -> conflict', 'conflict' === $q[0]['status'], json_encode( $q ) );
$q = run1( $an, $get, $mk( 'no-such-doc', 4, $tdp4 ) );
check( '18c declared document not in the registry is ignored', 'conflict' !== $q[0]['status'], json_encode( $q ) );
$q = run1( $an, $get, str_replace( 'class="af-quote" ', '', $mk( 'test-document', 6, $tdp4 ) ) );
check( '18d data attributes without the af-quote class are ignored', 'conflict' !== $q[0]['status'], json_encode( $q ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
