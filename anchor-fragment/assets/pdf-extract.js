/* Cuts numbered paragraphs out of the text layer of a PDF page range (as returned by PDF.js getTextContent).
   Written for the English Amoris Laetitia PDF: body text 13 pt, footnote marks smaller, footnotes and running
   heads smaller than the body. Line-end hyphens that might be real hyphens are marked with U+00AD; the server
   chooses between "joined" and "hyphenated" by checking the words against the hashed index.
   No dependencies; works in a browser and in Node. */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) { module.exports = factory(); } else { root.AFPdfExtract = factory(); }
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var BODY_MIN = 12.9;   // pt; the body text is 13 pt (headings are 12.8 or 12.5)
	var SOFT = '­';   // marks a line-end hyphen that may or may not be part of the word

	function sizeOf( it ) { return Math.hypot( it.transform[ 0 ], it.transform[ 1 ] ); }

	/** Lines of body text for one page: [{ y, text }], top to bottom. */
	function pageLines( items ) {
		var body = [];
		items.forEach( function ( it ) {
			if ( undefined === it.str || '' === it.str ) { return; }
			var s = sizeOf( it );
			var x = it.transform[ 4 ], y = it.transform[ 5 ];
			if ( s < BODY_MIN ) {
				// Small capitals (headings) are printed at 8 to 9.5 pt next to a full-size initial: keep them
				// so that the whole line can be recognised and dropped. Smaller marks and footnotes are ignored.
				if ( s >= 7.5 && s < 9.5 ) { body.push( { s: it.str, x: x, y: y, w: it.width, size: s, small: true } ); }
				return;
			}
			if ( /^\d{1,3}$/.test( it.str.trim() ) && y < 100 ) { return; } // page number at the foot
			body.push( { s: it.str.replace( /\u00AD/g, '' ), x: x, y: y, w: it.width, size: s } );
		} );
		body.sort( function ( a, b ) { return b.y - a.y || a.x - b.x; } );
		var rows = [];
		body.forEach( function ( it ) {
			var row = rows.length ? rows[ rows.length - 1 ] : null;
			if ( row && Math.abs( row.y - it.y ) <= 3 ) { row.items.push( it ); } else { rows.push( { y: it.y, items: [ it ] } ); }
		} );
		return rows.filter( function ( row ) {
			return ! row.items.some( function ( it ) { return it.small; } );   // a heading line, or a running head
		} ).map( function ( row ) {
			row.items.sort( function ( a, b ) { return a.x - b.x; } );
			var text = '', prev = null;
			row.items.forEach( function ( it ) {
				if ( '' === it.s.trim() ) { if ( text && ! /\s$/.test( text ) ) { text += ' '; } prev = it; return; }
				var gap = prev ? it.x - ( prev.x + prev.w ) : 0;
				var needSpace = prev && ! /\s$/.test( text ) && gap > 1.2 && ! /^[,.;:)”’!?]/.test( it.s );
				text += ( needSpace ? ' ' : '' ) + it.s;
				prev = it;
			} );
			return { y: row.y, text: text.replace( /\s+/g, ' ' ).trim() };
		} ).filter( function ( l ) { return l.text && ! /[a-z][A-Z]/.test( l.text ); } ); // small-capital headings print as aB
	}

	function join( lines ) {
		var out = lines[ 0 ] || '';
		for ( var i = 1; i < lines.length; i++ ) {
			var nx = lines[ i ];
			if ( /[A-Za-z’']-$/.test( out ) && /^[a-z]/.test( nx ) ) {
				out = out.slice( 0, -1 ) + SOFT + nx;
			} else if ( /-$/.test( out ) && /^[A-Z0-9]/.test( nx ) && /[A-Za-z0-9]-$/.test( out ) ) {
				out = out + nx;
			} else {
				out = out + ' ' + nx;
			}
		}
		return out.replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * @param {Array}  pages  [{ items: textContent.items }] in reading order
	 * @param {number} first  first paragraph number wanted
	 * @param {number} last   last paragraph number wanted
	 * @return {Object} { paragraphs: { n: text }, missing: [n] }
	 */
	function extract( pages, first, last ) {
		var all = [];
		pages.forEach( function ( p ) { pageLines( p.items ).forEach( function ( l ) { all.push( l.text ); } ); } );
		var paras = {}, cur = null, prevNo = first - 1;
		all.forEach( function ( line ) {
			var m = /^(\d{1,3})\.\s*(.*)$/.exec( line );
			if ( m ) {
				var n = parseInt( m[ 1 ], 10 );
				if ( n > prevNo && n <= prevNo + 3 && n <= last + 1 ) { prevNo = n; cur = n; paras[ cur ] = [ m[ 2 ] ]; return; }
			}
			if ( null !== cur ) { paras[ cur ].push( line ); }
		} );
		var out = {}, missing = [];
		for ( var n = first; n <= last; n++ ) {
			if ( paras[ n ] ) { out[ n ] = join( paras[ n ] ).replace( /(\d)- (\d)/g, '$1-$2' ); } else { missing.push( n ); }
		}
		return { paragraphs: out, missing: missing };
	}

	return { extract: extract, pageLines: pageLines, SOFT: SOFT };
} ) );
