/* MWD Admin Studio — paleta de comenzi (Ctrl/⌘ + K) */
(function () {
	'use strict';
	var cfg = window.MWDPalette;
	if ( ! cfg ) { return; }
	var t = cfg.i18n || {};

	/* ---------- Utilitare ---------- */
	function norm( s ) {
		s = String( s || '' ).toLowerCase();
		return s.normalize ? s.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ) : s;
	}
	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}
	function cleanText( node ) {
		if ( ! node ) { return ''; }
		var c = node.cloneNode( true );
		Array.prototype.forEach.call( c.querySelectorAll( '.awaiting-mod, .update-plugins, .count, .plugin-count, .screen-reader-text, .mwd-sec-count' ), function ( x ) { x.remove(); } );
		return ( c.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/* ---------- Index din meniul randat (respectă ascunderile pe rol) ---------- */
	var index = null;
	function buildIndex() {
		var out = [];
		var seen = {};
		var menu = document.getElementById( 'adminmenu' );
		if ( ! menu ) { return out; }
		Array.prototype.forEach.call( menu.children, function ( li ) {
			if ( li.tagName !== 'LI' || li.classList.contains( 'wp-menu-separator' ) || li.classList.contains( 'mwd-sec' ) || li.classList.contains( 'mwd-shell' ) || li.id === 'collapse-menu' ) { return; }
			var top = li.querySelector( 'a.menu-top' );
			if ( ! top ) { return; }
			var label = cleanText( top.querySelector( '.wp-menu-name' ) );
			if ( ! label ) { return; }
			var icon = 'dashicons-admin-generic';
			var img = top.querySelector( '.wp-menu-image' );
			if ( img ) {
				var m = img.className.match( /dashicons-(?!before)[\w-]+/ );
				if ( m ) { icon = m[0]; }
			}
			if ( top.href && ! seen[ top.href ] ) {
				seen[ top.href ] = 1;
				out.push( { url: top.href, label: label, icon: icon, kind: 'page' } );
			}
			Array.prototype.forEach.call( li.querySelectorAll( '.wp-submenu a' ), function ( a ) {
				var sl = cleanText( a );
				if ( ! sl || ! a.href || seen[ a.href ] ) { return; }
				seen[ a.href ] = 1;
				out.push( { url: a.href, label: sl, path: label, icon: icon, kind: 'page' } );
			} );
		} );
		( cfg.actions || [] ).forEach( function ( a ) {
			out.push( { url: a.url, label: a.label, icon: a.icon, keywords: a.keywords, external: !! a.external, kind: 'action' } );
		} );
		out.forEach( function ( it ) {
			it._hay = norm( it.label + ' ' + ( it.path || '' ) + ' ' + ( it.keywords || '' ) );
			it._lab = norm( it.label );
		} );
		return out;
	}

	function score( it, q ) {
		if ( ! q ) { return 1; }
		if ( it._lab === q ) { return 100; }
		if ( it._lab.indexOf( q ) === 0 ) { return 80; }
		if ( ( ' ' + it._lab ).indexOf( ' ' + q ) !== -1 ) { return 65; }
		var i = it._hay.indexOf( q );
		if ( i !== -1 ) { return 50 - Math.min( i, 20 ); }
		// Potrivire „fuzzy": toate literele în ordine.
		var p = 0;
		for ( var k = 0; k < it._hay.length && p < q.length; k++ ) {
			if ( it._hay[ k ] === q[ p ] ) { p++; }
		}
		return p === q.length ? 10 : 0;
	}

	/* ---------- Recente ---------- */
	function getRecent() {
		try { return JSON.parse( window.localStorage.getItem( cfg.storageKey ) || '[]' ) || []; } catch ( e ) { return []; }
	}
	function pushRecent( it ) {
		var r = getRecent().filter( function ( x ) { return x.url !== it.url; } );
		r.unshift( { url: it.url, label: it.label, path: it.path || '', icon: it.icon } );
		try { window.localStorage.setItem( cfg.storageKey, JSON.stringify( r.slice( 0, 5 ) ) ); } catch ( e ) {}
	}

	/* ---------- UI ---------- */
	var root, input, list, status, results = [], active = 0, lastFocus = null, timer = null, reqId = 0;

	function build() {
		root = el( 'div', 'mwd-cmdk' );
		root.setAttribute( 'hidden', '' );
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.setAttribute( 'aria-label', t.placeholder || 'Search' );

		var backdrop = el( 'div', 'mwd-cmdk-backdrop' );
		backdrop.addEventListener( 'click', close );

		var panel = el( 'div', 'mwd-cmdk-panel' );
		var head = el( 'div', 'mwd-cmdk-head' );
		head.appendChild( el( 'span', 'dashicons dashicons-search mwd-cmdk-ico' ) );
		input = el( 'input', 'mwd-cmdk-input' );
		input.type = 'text';
		input.setAttribute( 'placeholder', t.placeholder || '' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'spellcheck', 'false' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-controls', 'mwd-cmdk-list' );
		input.setAttribute( 'aria-expanded', 'true' );
		head.appendChild( input );
		head.appendChild( el( 'kbd', 'mwd-cmdk-esc', 'Esc' ) );

		list = el( 'div', 'mwd-cmdk-list' );
		list.id = 'mwd-cmdk-list';
		list.setAttribute( 'role', 'listbox' );

		var foot = el( 'div', 'mwd-cmdk-foot' );
		status = el( 'span', 'mwd-cmdk-status' );
		foot.appendChild( el( 'span', '', t.hint || '' ) );
		foot.appendChild( status );

		panel.appendChild( head );
		panel.appendChild( list );
		panel.appendChild( foot );
		root.appendChild( backdrop );
		root.appendChild( panel );
		document.body.appendChild( root );

		input.addEventListener( 'input', function () { render(); searchContent(); } );
		input.addEventListener( 'keydown', onKey );
	}

	function open() {
		if ( ! root ) { build(); }
		if ( ! index ) { index = buildIndex(); }
		lastFocus = document.activeElement;
		root.removeAttribute( 'hidden' );
		document.documentElement.classList.add( 'mwd-cmdk-open' );
		input.value = '';
		render();
		window.setTimeout( function () { input.focus(); }, 0 );
	}
	function close() {
		if ( ! root || root.hasAttribute( 'hidden' ) ) { return; }
		root.setAttribute( 'hidden', '' );
		document.documentElement.classList.remove( 'mwd-cmdk-open' );
		reqId++;
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}
	function isOpen() { return root && ! root.hasAttribute( 'hidden' ); }

	function render( content ) {
		var q = norm( input.value.trim() );
		var groups = [];

		if ( ! q ) {
			var rec = getRecent();
			if ( rec.length ) { groups.push( { title: t.recent, items: rec } ); }
			groups.push( { title: t.actions, items: index.filter( function ( i ) { return i.kind === 'action'; } ) } );
		} else {
			var scored = index.map( function ( it ) { return { it: it, s: score( it, q ) }; } )
				.filter( function ( x ) { return x.s > 0; } )
				.sort( function ( a, b ) { return b.s - a.s; } );
			var pages = scored.filter( function ( x ) { return x.it.kind === 'page'; } ).slice( 0, 8 ).map( function ( x ) { return x.it; } );
			var acts = scored.filter( function ( x ) { return x.it.kind === 'action'; } ).slice( 0, 4 ).map( function ( x ) { return x.it; } );
			if ( pages.length ) { groups.push( { title: t.pages, items: pages } ); }
			if ( acts.length ) { groups.push( { title: t.actions, items: acts } ); }
			if ( content && content.length ) { groups.push( { title: t.content, items: content } ); }
		}

		list.textContent = '';
		results = [];
		groups.forEach( function ( g ) {
			if ( ! g.items.length ) { return; }
			list.appendChild( el( 'div', 'mwd-cmdk-group', g.title ) );
			g.items.forEach( function ( it ) {
				var idx = results.length;
				results.push( it );
				var row = el( 'a', 'mwd-cmdk-item' );
				row.href = it.url;
				row.id = 'mwd-cmdk-opt-' + idx;
				row.setAttribute( 'role', 'option' );
				if ( it.external ) { row.target = '_blank'; row.rel = 'noopener'; }
				row.appendChild( el( 'span', 'mwd-cmdk-item-ico dashicons ' + ( it.icon || 'dashicons-admin-generic' ) ) );
				var txt = el( 'span', 'mwd-cmdk-item-txt' );
				if ( it.path ) { txt.appendChild( el( 'span', 'mwd-cmdk-item-path', it.path + ' ›' ) ); }
				txt.appendChild( el( 'span', 'mwd-cmdk-item-label', it.label ) );
				row.appendChild( txt );
				if ( it.badge ) { row.appendChild( el( 'span', 'mwd-cmdk-badge', it.badge ) ); }
				row.appendChild( el( 'span', 'mwd-cmdk-item-go', '↵' ) );
				row.addEventListener( 'mousemove', function () { if ( active !== idx ) { setActive( idx ); } } );
				row.addEventListener( 'click', function () { pushRecent( it ); } );
				list.appendChild( row );
			} );
		} );
		if ( ! results.length ) {
			list.appendChild( el( 'div', 'mwd-cmdk-empty', t.empty ) );
		}
		setActive( 0 );
	}

	function setActive( i ) {
		var rows = list.querySelectorAll( '.mwd-cmdk-item' );
		if ( ! rows.length ) { input.removeAttribute( 'aria-activedescendant' ); return; }
		active = ( i + rows.length ) % rows.length;
		Array.prototype.forEach.call( rows, function ( r, k ) {
			r.classList.toggle( 'is-active', k === active );
			r.setAttribute( 'aria-selected', k === active ? 'true' : 'false' );
		} );
		input.setAttribute( 'aria-activedescendant', rows[ active ].id );
		var r = rows[ active ];
		if ( r.scrollIntoView ) { r.scrollIntoView( { block: 'nearest' } ); }
	}

	function go( i, newTab ) {
		var it = results[ i ];
		if ( ! it ) { return; }
		pushRecent( it );
		if ( newTab || it.external ) { window.open( it.url, '_blank', 'noopener' ); close(); return; }
		window.location.href = it.url;
	}

	function onKey( e ) {
		if ( e.key === 'ArrowDown' ) { e.preventDefault(); setActive( active + 1 ); }
		else if ( e.key === 'ArrowUp' ) { e.preventDefault(); setActive( active - 1 ); }
		else if ( e.key === 'Enter' ) { e.preventDefault(); go( active, e.metaKey || e.ctrlKey ); }
		else if ( e.key === 'Escape' ) { e.preventDefault(); close(); }
		else if ( e.key === 'Tab' ) { e.preventDefault(); setActive( active + ( e.shiftKey ? -1 : 1 ) ); }
	}

	/* ---------- Căutare în conținut (REST) ---------- */
	function searchContent() {
		window.clearTimeout( timer );
		status.textContent = '';
		var q = input.value.trim();
		if ( ! cfg.search || q.length < 2 ) { return; }
		var my = ++reqId;
		timer = window.setTimeout( function () {
			status.textContent = t.searching || '';
			var url = cfg.search + ( cfg.search.indexOf( '?' ) === -1 ? '?' : '&' ) + 'per_page=6&type=post&search=' + encodeURIComponent( q );
			window.fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' } )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( rows ) {
					if ( my !== reqId || ! isOpen() ) { return; }
					status.textContent = '';
					var items = ( rows || [] ).map( function ( r ) {
						// DOMParser = document inert (fără execuție de scripturi / încărcări).
						var doc = new window.DOMParser().parseFromString( String( r.title || '' ), 'text/html' );
						return {
							url: cfg.editBase + r.id,
							label: ( doc.body.textContent || '' ).trim() || '#' + r.id,
							icon: r.subtype === 'page' ? 'dashicons-admin-page' : ( r.subtype === 'product' ? 'dashicons-products' : 'dashicons-admin-post' ),
							badge: r.subtype || ''
						};
					} );
					var keep = active;
					render( items );
					setActive( keep );
				} )
				.catch( function () { if ( my === reqId ) { status.textContent = ''; } } );
		}, 220 );
	}

	/* ---------- Declanșatoare ---------- */
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.metaKey || e.ctrlKey ) && ! e.altKey && ( e.key === 'k' || e.key === 'K' ) ) {
			e.preventDefault();
			if ( isOpen() ) { close(); } else { open(); }
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		var trg = e.target.closest && e.target.closest( '.mwd-cmdk-trigger, [data-mwd-cmdk]' );
		if ( trg ) { e.preventDefault(); open(); }
	} );

	// Afișează scurtătura corectă pe Windows/Linux.
	function fixKbd() {
		Array.prototype.forEach.call( document.querySelectorAll( '.mwd-cmdk-kbd' ), function ( k ) { k.textContent = 'Ctrl K'; } );
	}
	if ( ! /Mac|iPhone|iPad/.test( navigator.platform || navigator.userAgent ) ) {
		if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', fixKbd ); } else { fixKbd(); }
	}
})();
