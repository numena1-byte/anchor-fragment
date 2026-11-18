// Test of assets/pdf-extract.js with invented text-layer items. Usage: node tests/pdfextract.js
const X = require( '../anchor-fragment/assets/pdf-extract.js' );
let fail = 0;
function check( label, cond, detail ) { console.log( ( cond ? '  ok   ' : '  FAIL ' ) + label + ( cond ? '' : '  -> ' + detail ) ); if ( ! cond ) { fail++; } }
const it = ( str, x, y, size, w ) => ( { str, transform: [ size, 0, 0, size, x, y ], width: w === undefined ? str.length * size * 0.45 : w, height: size } );
const page1 = { items: [
	it( 'CHAPTER ONE', 174, 552, 9 ),                                  // running head (9 pt)
	it( 'Gradua', 93, 530, 12.5 ), it( 'L', 133, 530, 8.8 ), it( 'ness', 138, 530, 8.8 ), // small-capital heading
	it( '7.', 88, 500, 13 ), it( ' ', 109, 500, 13, 1 ), it( 'Alpha begins the long road that crosses', 122, 500, 13 ),
	it( 'many fields and a so-', 88, 484, 13 ), it( '12', 140, 487, 5.8 ),   // footnote mark, ignored
	it( 'called river, with a teach-', 88, 468, 13 ),
	it( 'ing about the lantern.', 88, 452, 13 ),
	it( '8.', 88, 420, 13 ), it( 'Beta closes the page and', 122, 420, 13 ),
	it( '1 A footnote that must not appear in the text.', 88, 150, 10 ),
	it( '221', 313, 79, 13 ),                                             // page number
] };
const page2 = { items: [
	it( 'continues onto the next page.', 88, 600, 13 ),
	it( '9.', 88, 570, 13 ), it( 'Gamma', 122, 570, 13 ),
] };
const r = X.extract( [ page1, page2 ], 7, 8 );
check( 'finds paragraphs 7 and 8, nothing missing', Object.keys( r.paragraphs ).join() === '7,8' && ! r.missing.length, JSON.stringify( r ) );
check( 'paragraph 7 is joined across lines', /^Alpha begins the long road that crosses many fields and a so/.test( r.paragraphs[ 7 ] ), r.paragraphs[ 7 ] );
check( 'a line-end hyphen is marked, not decided', r.paragraphs[ 7 ].includes( 'so' + X.SOFT + 'called' ) && r.paragraphs[ 7 ].includes( 'teach' + X.SOFT + 'ing' ), r.paragraphs[ 7 ] );
check( 'footnote mark, footnote, running head, heading and page number are left out', ! /12|footnote|CHAPTER|Gradua|221/.test( r.paragraphs[ 7 ] + r.paragraphs[ 8 ] ), JSON.stringify( r.paragraphs ) );
check( 'a paragraph continues onto the next page and stops at the next number', r.paragraphs[ 8 ] === 'Beta closes the page and continues onto the next page.', r.paragraphs[ 8 ] );
const r2 = X.extract( [ page1, page2 ], 7, 9 );
check( 'a wanted paragraph that is not there is reported', r2.missing.length === 0 && r2.paragraphs[ 9 ] === 'Gamma', JSON.stringify( r2 ) );
const r3 = X.extract( [ page2 ], 20, 21 );
check( 'asking for numbers that are not on the pages reports them missing', r3.missing.join() === '20,21', JSON.stringify( r3 ) );
console.log( fail ? '\n' + fail + ' FAILED' : '\nAll passed' );
process.exit( fail ? 1 : 0 );
