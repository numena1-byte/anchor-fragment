<?php
// Linking the citations of a post when it is shown. Usage: php links.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'analyzer', 'links' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }

$reg = array(
	'evangelii-gaudium' => array( 'title' => 'Evangelii Gaudium', 'short' => 'EG', 'aliases' => array(), 'url' => 'https://www.vatican.va/x/eg.html', 'expected_count' => 288 ),
	'amoris-laetitia'   => array( 'title' => 'Amoris Laetitia', 'short' => 'AL', 'aliases' => array(), 'url' => 'https://www.vatican.va/x/al.html', 'expected_count' => 325 ),
	'catechism'         => array( 'title' => 'Catechism of the Catholic Church', 'short' => 'CCC', 'aliases' => array( 'catechism' ), 'url' => 'https://www.vatican.va/x/ccc.html', 'expected_count' => 2865 ),
);
$an     = new AF_Analyzer( $reg );
$linker = function ( $doc, $loc ) { return 'evangelii-gaudium' === $doc && 99 === $loc ? '' : 'https://src.example/' . $doc . '#:~:text=p' . $loc; };
$edges  = array( 'evangelii-gaudium:30' => true, 'amoris-laetitia:222' => true, 'catechism:112' => true, 'evangelii-gaudium:99' => true );

$r = AF_Cite_Links::decorate( '<p>He writes, <em>so</em> (EG, 30). Then more.</p>', $edges, $an, $linker );
check( '1 a confirmed citation is linked, the brackets are not', 1 === $r['linked'] && false !== strpos( $r['html'], '(<a href="https://src.example/evangelii-gaudium#:~:text=p30" class="af-cite" rel="noopener">EG, 30</a>)' ), $r['html'] );
check( '1b the rest of the paragraph is untouched', false !== strpos( $r['html'], '<p>He writes, <em>so</em> (' ) && false !== strpos( $r['html'], '. Then more.</p>' ), $r['html'] );

$r = AF_Cite_Links::decorate( '<p>Said (EG, 31).</p>', $edges, $an, $linker );
check( '2 a citation that is not a confirmed edge of the post is not linked', 0 === $r['linked'] && '<p>Said (EG, 31).</p>' === $r['html'] && 'not a confirmed citation of this post' === $r['skipped'][0][1], json_encode( $r ) );

$r = AF_Cite_Links::decorate( '<p>Said (<a href="https://x.example/">EG, 30</a>) and <a href="https://y.example/">see (EG, 30)</a>.</p>', $edges, $an, $linker );
check( '3 nothing already inside a link is touched', 0 === $r['linked'] && 2 === substr_count( $r['html'], '<a ' ), $r['html'] );

$r = AF_Cite_Links::decorate( '<p>Two: (EG, 30; 33).</p>', $edges, $an, $linker );
check( '4 more than one citation in one bracket is left alone and reported', 0 === $r['linked'] && 'more than one citation in the brackets' === $r['skipped'][0][1], json_encode( $r ) );

$r = AF_Cite_Links::decorate( '<p>Gap (EG, 99).</p>', $edges, $an, $linker );
check( '5 a paragraph with no link available is left alone and reported', 0 === $r['linked'] && 'no link for that paragraph' === $r['skipped'][0][1], json_encode( $r ) );

$r = AF_Cite_Links::decorate( '<p>One (EG, 30) and again (EG, 30), plus [AL 222] and (Catechism, 112).</p><blockquote>quoted words</blockquote>', $edges, $an, $linker );
check( '6 every confirmed citation in the post is linked, square brackets too', 4 === $r['linked'] && 4 === substr_count( $r['html'], 'class="af-cite"' ) && false !== strpos( $r['html'], '[<a href="https://src.example/amoris-laetitia#:~:text=p222" class="af-cite" rel="noopener">AL 222</a>]' ), $r['html'] );
check( '6b the quotation is untouched', false !== strpos( $r['html'], '<blockquote>quoted words</blockquote>' ), $r['html'] );

$r = AF_Cite_Links::decorate( '<p>Curly “quote” and café (EG, 30) — fine.</p>', $edges, $an, $linker );
check( '7 non-ASCII text survives', 1 === $r['linked'] && false !== strpos( html_entity_decode( $r['html'], ENT_QUOTES, 'UTF-8' ), '“quote” and café (' ) && false !== strpos( html_entity_decode( $r['html'], ENT_QUOTES, 'UTF-8' ), ') — fine.' ), $r['html'] );

$r = AF_Cite_Links::decorate( '<p>No brackets at all.</p>', $edges, $an, $linker );
check( '8 no brackets: returned as it came', 0 === $r['linked'] && '<p>No brackets at all.</p>' === $r['html'] );

$r = AF_Cite_Links::decorate( '<p>Said (cf. EG, 30) and (see AL 222).</p>', $edges, $an, $linker );
check( '9 "cf." and "see" stay outside the link', 2 === $r['linked'] && false !== strpos( $r['html'], '(cf. <a href="https://src.example/evangelii-gaudium#:~:text=p30" class="af-cite" rel="noopener">EG, 30</a>)' ) && false !== strpos( $r['html'], '(see <a href="https://src.example/amoris-laetitia#:~:text=p222" class="af-cite" rel="noopener">AL 222</a>)' ), $r['html'] );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
