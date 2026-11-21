<?php
// The Catechism footnote map. Usage: php xref.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'analyzer', 'xref' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }
// The Holy See's archive pages: a reference links to "#$code", its note to "#-code".
$archive = '<html><body><table><tbody><tr><td><a href="_INDEX.HTM"><b>Help</b></a> <a href="__P25.HTM"><b>Previous</b></a></td></tr></tbody></table><hr size="1">
<p class="MsoNormal">748
"Christ is the light of humanity; and it is, accordingly, the desire of this sacred Council."<font face="Verdana" size="2"><sup><a name="-YU" href="#$YU">135</a></sup></font>
These words open the Dogmatic Constitution on the Church.<font><sup><a name="-YT" href="#$YT">134</a></sup></font></p>
<p class="MsoNormal">749
The Holy Spirit is at work<font><sup><a name="-YV" href="#$YV">136</a></sup></font> and<font><sup><a name="-YW" href="#$YW">137</a></sup></font>.</p>
<p class="MsoNormal" style="margin-left:35.4pt">Another part of the same paragraph<font><sup><a name="-YX" href="#$YX">138</a></sup></font>.</p>
<p class="MsoNormal">750 No notes here.</p>
<p class="MsoNormal"><b></b></p><br><br><hr size="1" width="30%" align="left/"><font size="3"><b><a name="$YT" href="#-YT">134</a></b></font><font face="Verdana" size="1"> GS 22; cf. Mk 1:1.<br>
<br><br></font><font size="3"><b><a name="$YU" href="#-YU">135</a></b></font><font face="Verdana" size="1"> LG 1; cf. Mk 16:15.<br>
<br><br></font><font size="3"><b><a name="$YV" href="#-YV">136</a></b></font><font face="Verdana" size="1"> Roman Catechism I, 10, 1.<br>
<br><br></font><font size="3"><b><a name="$YW" href="#-YW">137</a></b></font><font face="Verdana" size="1"> St. Hippolytus, Trad. Ap. 35: SCh 11, 118.<br>
<br><br></font><font size="3"><b><a name="$YX" href="#-YX">138</a></b></font><font face="Verdana" size="1"> LG 4.<br>
<br><br></font><table><tr><td><a href="__P25.HTM"><b>Previous</b></a> <a href="__P27.HTM"><b>Next</b></a></td></tr></table></body></html>';
$p = AF_Xref::parse_page( $archive );
check( 'archive style recognised', 'archive' === $p['style'], json_encode( $p ) );
check( '748 has the notes of its two marks, the second after a line of text', array( 'LG 1; cf. Mk 16:15.', 'GS 22; cf. Mk 1:1.' ) === $p['paras'][748], json_encode( $p['paras'] ) );
check( '749 has two notes, and the continuation paragraph after it adds a third', 3 === count( $p['paras'][749] ) && 'LG 4.' === $p['paras'][749][2] && 'St. Hippolytus, Trad. Ap. 35: SCh 11, 118.' === $p['paras'][749][1], json_encode( $p['paras'] ) );
check( 'a paragraph without marks has nothing; the note blocks do not start paragraphs', ! isset( $p['paras'][750] ) && ! isset( $p['paras'][134] ) && ! isset( $p['paras'][135] ) && ! strpos( json_encode( $p['paras'] ), 'Previous' ), json_encode( $p['paras'] ) );

// A lead-in line before a numbered paragraph (real CCC 111-112): its note belongs to the quotation in 112.
$lead = '<html><body><p class="MsoNormal">111 Read in the Spirit.<font><sup><a name="-A1" href="#$A1">77</a></sup></font></p>
<p class="MsoNormal">The Council indicates three criteria.<font><sup><a name="-A2" href="#$A2">78</a></sup></font></p>
<p class="MsoNormal">112 Be attentive to the unity of Scripture.<font><sup><a name="-A3" href="#$A3">79</a></sup></font></p>
<br><br><hr><font size="3"><b><a name="$A1" href="#-A1">77</a></b></font><font size="1"> DV 12 par. 2.<br>
<br><br></font><font size="3"><b><a name="$A2" href="#-A2">78</a></b></font><font size="1"> DV 12 § 3.<br>
<br><br></font><font size="3"><b><a name="$A3" href="#-A3">79</a></b></font><font size="1"> Cf. Lk 24:25-27, 44-46.<br></font></body></html>';
$pl = AF_Xref::parse_page( $lead );
check( 'the note of a lead-in line also counts for the numbered paragraph after it', isset( $pl['paras'][112] ) && in_array( 'DV 12 § 3.', $pl['paras'][112], true ), json_encode( $pl['paras'] ) );

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
$lo = AF_Xref::refs_of( $an, array( 'LG 12 § 3.' ) );
check( 'a note written "LG 12 § 3." reads as Lumen Gentium 12', array( 'lumen-gentium:12' ) === $lo, json_encode( $lo ) );
check( 'footnote texts give document:locator', array( 'gaudium-et-spes:22', 'lumen-gentium:1', 'lumen-gentium:4', 'lumen-gentium:6' ) === $out, json_encode( $out ) );
$map = array( 748 => array( 'lumen-gentium:1', 'gaudium-et-spes:22' ) );
check( 'exact: CCC 748 cites LG 1', 'exact' === AF_Xref::explains( $map, 748, 'lumen-gentium', 1 ) );
check( 'document: CCC 748 cites LG, not paragraph 9', 'document' === AF_Xref::explains( $map, 748, 'lumen-gentium', 9 ) );
check( 'none: CCC 748 cites nothing of Dei Verbum', '' === AF_Xref::explains( $map, 748, 'dei-verbum', 1 ) );
check( 'none: a paragraph with no footnotes', '' === AF_Xref::explains( $map, 750, 'lumen-gentium', 1 ) );
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
