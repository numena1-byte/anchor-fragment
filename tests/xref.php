<?php
// The Catechism footnote map. Usage: php xref.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'analyzer', 'xref' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }
// The Holy See's archive pages: a reference links to "#$code", its note to "#-code".
$archive = '<html><body>
<p><a name="-YU" href="#$YU">135</a></p>
<p>748 Christ is the light of humanity; and it is the desire of this sacred Council <a name="-YT" href="#$YT">134</a> to bring light to all.</p>
<p>749 The Holy Spirit is at work <a name="-YV" href="#$YV">136</a> and <a name="-YW" href="#$YW">137</a>.</p>
<p>Another part of the same paragraph <a name="-YX" href="#$YX">138</a>.</p>
<p>750 No notes here.</p>
<p><a name="$YT" href="#-YT">134</a> GS 22; cf. Mk 1:1.</p>
<p><a name="$YU" href="#-YU">135</a> LG 1; cf. Mk 16:15.</p>
<p><a name="$YV" href="#-YV">136</a> Roman Catechism I, 10, 1.</p>
<p><a name="$YW" href="#-YW">137</a> St. Hippolytus, Trad. Ap. 35: SCh 11, 118.</p>
<p><a name="$YX" href="#-YX">138</a> LG 4.</p>
</body></html>';
$p = AF_Xref::parse_page( $archive );
check( 'archive style recognised', 'archive' === $p['style'], json_encode( $p ) );
check( '748 has the note of its own mark', array( 'GS 22; cf. Mk 1:1.' ) === $p['paras'][748], json_encode( $p['paras'] ) );
check( '749 has two notes, and the continuation paragraph after it adds a third', 3 === count( $p['paras'][749] ) && 'LG 4.' === $p['paras'][749][2], json_encode( $p['paras'] ) );
check( 'a paragraph without marks has nothing; the note blocks do not start paragraphs', ! isset( $p['paras'][750] ) && ! isset( $p['paras'][134] ) && ! isset( $p['paras'][135] ), json_encode( $p['paras'] ) );

// Word-style pages.
$word = '<html><body><p>301 Text of the paragraph<a href="#_ftn1" name="_ftnref1">[1]</a> goes on.</p><p><a href="#_ftnref1" name="_ftn1">[1]</a> DV 7; cf. LG 20.</p></body></html>';
$w = AF_Xref::parse_page( $word );
check( 'word style recognised', 'word' === $w['style'] && array( 'DV 7; cf. LG 20.' ) === $w['paras'][301], json_encode( $w ) );

// Citations in the notes, and the check itself.
$reg = array(
	'lumen-gentium'      => array( 'title' => 'Lumen Gentium', 'short' => 'LG', 'aliases' => array(), 'url' => 'https://www.vatican.va/x/lg.html', 'expected_count' => 69 ),
	'gaudium-et-spes'    => array( 'title' => 'Gaudium et Spes', 'short' => 'GS', 'aliases' => array(), 'url' => 'https://www.vatican.va/x/gs.html', 'expected_count' => 93 ),
);
$an  = new AF_Analyzer( $reg );
$out = AF_Xref::refs_of( $an, array( 'LG 1; cf. Mk 16:15.', 'GS 22; LG 4, 6' ) );
sort( $out );
check( 'footnote texts give document:locator', array( 'gaudium-et-spes:22', 'lumen-gentium:1', 'lumen-gentium:4', 'lumen-gentium:6' ) === $out, json_encode( $out ) );
$map = array( 748 => array( 'lumen-gentium:1', 'gaudium-et-spes:22' ) );
check( 'exact: CCC 748 cites LG 1', 'exact' === AF_Xref::explains( $map, 748, 'lumen-gentium', 1 ) );
check( 'document: CCC 748 cites LG, not paragraph 9', 'document' === AF_Xref::explains( $map, 748, 'lumen-gentium', 9 ) );
check( 'none: CCC 748 cites nothing of Dei Verbum', '' === AF_Xref::explains( $map, 748, 'dei-verbum', 1 ) );
check( 'none: a paragraph with no footnotes', '' === AF_Xref::explains( $map, 750, 'lumen-gentium', 1 ) );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
