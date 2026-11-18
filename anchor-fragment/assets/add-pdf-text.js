/* "Add text from a PDF" on the Anchor Fragment test page. Reads the chosen PDF in the browser with PDF.js,
   cuts out the numbered paragraphs (pdf-extract.js) and sends them to the server, which keeps only the
   paragraphs that agree word for word with the document's index. Needs window.afPdf from the server. */
( function () {
	'use strict';
	var cfg = window.afPdf;
	var form = document.getElementById( 'af-pdf-form' );
	if ( ! cfg || ! form ) { return; }
	var out = document.getElementById( 'af-pdf-out' );
	var link = document.getElementById( 'af-pdf-link' );
	var info = null;

	function api( method, query, body ) {
		var url = cfg.rest + '?' + new URLSearchParams( query ).toString();
		var opt = { method: method, headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' };
		if ( body ) { opt.headers[ 'Content-Type' ] = 'application/json'; opt.body = JSON.stringify( body ); }
		return fetch( url, opt ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( j.message || 'Request failed.' ); } return j; } ); } );
	}

	function loadInfo() {
		out.textContent = 'Loading…';
		api( 'GET', { doc: form.doc.value } ).then( function ( j ) {
			info = j;
			link.href = j.url;
			out.textContent = j.stored.length ? j.stored.length + ' paragraph(s) of ' + j.title + ' are stored on this site.' : 'No text stored yet for ' + j.title + '.';
		} ).catch( function ( e ) { info = null; out.textContent = String( e.message || e ); } );
	}
	form.doc.addEventListener( 'change', loadInfo );
	loadInfo();

	document.getElementById( 'af-pdf-clear' ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Remove all text added from the PDF for this document?' ) ) { return; }
		api( 'DELETE', { doc: form.doc.value } ).then( loadInfo ).catch( function ( e ) { out.textContent = String( e.message || e ); } );
	} );

	form.addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		var file = form.file.files[ 0 ];
		var from = parseInt( form.from.value, 10 ), to = parseInt( form.to.value, 10 );
		if ( ! info ) { out.textContent = 'The document information has not loaded.'; return; }
		if ( ! file ) { out.textContent = 'Choose the PDF file first.'; return; }
		if ( ! ( from >= 1 && to >= from && to - from < 400 ) ) { out.textContent = 'Enter a first and last paragraph number.'; return; }
		var p1 = info.pages[ from ], pTo = info.pages[ to ];
		if ( ! p1 || ! pTo ) { out.textContent = 'The index has no page number for those paragraphs.'; return; }
		var p2 = info.pages[ to + 1 ] || pTo + 1; // the last paragraph may run onto the next page
		var t0 = Date.now();
		out.textContent = 'Reading pages ' + p1 + ' to ' + p2 + '…';
		var pdfDoc;
		Promise.all( [ import( cfg.pdfjs ), file.arrayBuffer() ] ).then( function ( r ) {
			r[ 0 ].GlobalWorkerOptions.workerSrc = cfg.worker;
			return r[ 0 ].getDocument( { data: new Uint8Array( r[ 1 ] ), isEvalSupported: false } ).promise;
		} ).then( function ( d ) {
			pdfDoc = d;
			if ( d.numPages < p2 ) { throw new Error( 'This PDF has only ' + d.numPages + ' pages; page ' + p2 + ' was expected. Is it the right document?' ); }
			var jobs = [];
			for ( var i = p1; i <= p2; i++ ) {
				jobs.push( d.getPage( i ).then( function ( pg ) { return pg.getTextContent(); } ).then( function ( tc ) { return { items: tc.items }; } ) );
			}
			return Promise.all( jobs );
		} ).then( function ( pages ) {
			var res = window.AFPdfExtract.extract( pages, from, to );
			var n = Object.keys( res.paragraphs ).length;
			out.textContent = 'Read ' + n + ' paragraph(s) in ' + ( Date.now() - t0 ) + ' ms' + ( res.missing.length ? '; not found in the PDF: ' + res.missing.join( ', ' ) : '' ) + '. Checking against the index…';
			if ( ! n ) { throw new Error( 'No numbered paragraphs were found on those pages.' ); }
			return api( 'POST', { doc: form.doc.value }, { paragraphs: res.paragraphs } ).then( function ( r ) { return { r: r, missing: res.missing, n: n }; } );
		} ).then( function ( x ) {
			var r = x.r;
			var msg = 'Added ' + r.accepted.length + ' of ' + x.n + ' paragraph(s) read; ' + r.stored + ' now stored for this document.';
			if ( r.rejected.length ) {
				msg += '\nNot added:\n' + r.rejected.map( function ( j ) { return '  ' + j.loc + ': ' + j.reason; } ).join( '\n' );
			}
			if ( x.missing.length ) { msg += '\nNot found in the PDF: ' + x.missing.join( ', ' ); }
			out.textContent = msg;
			if ( pdfDoc && pdfDoc.destroy ) { pdfDoc.destroy(); }
		} ).catch( function ( e ) { out.textContent = 'Failed: ' + String( e && e.message || e ); } );
	} );
}() );
