/* Insert quotation: a button above the classic editor that fetches one paragraph of a source document and inserts it
   in the standard af-quote markup. Needs afQuote = { rest, nonce, docs: [{id, title}] } from the server. */
( function () {
	'use strict';
	var cfg = window.afQuote;
	if ( ! cfg ) { return; }

	function el( tag, attrs, children ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) { n.textContent = attrs[ k ]; } else if ( 'style' === k ) { n.style.cssText = attrs[ k ]; } else { n.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { n.appendChild( c ); } );
		return n;
	}

	function insertIntoEditor( html ) {
		if ( window.wp && wp.media && wp.media.editor && wp.media.editor.insert ) {
			wp.media.editor.insert( html + '\n' );
		} else if ( window.send_to_editor ) {
			window.send_to_editor( html + '\n' );
		}
	}

	var overlay, select, number, status, preview, fetchBtn, insertBtn, current = null;

	function close() {
		if ( overlay && overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); }
		overlay = null; current = null;
	}

	function fetchPassage() {
		current = null;
		insertBtn.disabled = true;
		preview.textContent = '';
		status.textContent = 'Fetching from the source…';
		var q = new URLSearchParams( { doc: select.value, locator: number.value.replace( /\D/g, '' ) } );
		fetch( cfg.rest + ( cfg.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				if ( ! res.ok ) { status.textContent = res.body.message || 'Request failed.'; return; }
				var b = res.body;
				var wrap = el( 'div' );
				wrap.innerHTML = b.html; // built and escaped on the server by AF_Quote
				preview.appendChild( wrap );
				status.textContent = ( b.verified ? 'Checked against the index: same paragraph. ' : 'NOT checked against the index: the text found differs from the indexed paragraph. ' ) + b.text.split( /\s+/ ).length + ' words.';
				current = b;
				insertBtn.disabled = false;
			} )
			.catch( function ( err ) { status.textContent = 'Request failed (' + err + ').'; } );
	}

	function open() {
		if ( overlay ) { return; }
		select = el( 'select', { 'aria-label': 'Document' }, cfg.docs.map( function ( d ) { return el( 'option', { value: d.id, text: d.title } ); } ) );
		number = el( 'input', { type: 'text', size: '6', 'aria-label': 'Paragraph number', placeholder: 'No.' } );
		fetchBtn = el( 'button', { type: 'button', 'class': 'button button-primary', text: 'Find passage' } );
		insertBtn = el( 'button', { type: 'button', 'class': 'button', text: 'Insert into post' } );
		insertBtn.disabled = true;
		var cancel = el( 'button', { type: 'button', 'class': 'button-link', text: 'Cancel', style: 'margin-left:1em' } );
		status = el( 'p', { 'aria-live': 'polite' } );
		preview = el( 'div', { style: 'max-height:40vh;overflow:auto' } );
		var box = el( 'div', { role: 'dialog', 'aria-label': 'Insert quotation', style: 'background:#fff;max-width:640px;width:92%;margin:8vh auto;padding:1.25em 1.5em;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.3)' }, [
			el( 'h2', { text: 'Insert quotation', style: 'margin-top:0' } ),
			el( 'p', {}, [ select, document.createTextNode( ' ' ), number, document.createTextNode( ' ' ), fetchBtn ] ),
			status, preview,
			el( 'p', {}, [ insertBtn, cancel ] )
		] );
		overlay = el( 'div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:160000;overflow:auto' }, [ box ] );
		document.body.appendChild( overlay );
		number.focus();

		fetchBtn.addEventListener( 'click', fetchPassage );
		number.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); fetchPassage(); } } );
		insertBtn.addEventListener( 'click', function () { if ( current ) { insertIntoEditor( current.html ); close(); } } );
		cancel.addEventListener( 'click', close );
		overlay.addEventListener( 'mousedown', function ( e ) { if ( e.target === overlay ) { close(); } } );
		document.addEventListener( 'keydown', function esc( e ) {
			if ( 'Escape' === e.key ) { close(); document.removeEventListener( 'keydown', esc ); }
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target && e.target.classList && e.target.classList.contains( 'af-insert-quote' ) ) { e.preventDefault(); open(); }
	} );
}() );
