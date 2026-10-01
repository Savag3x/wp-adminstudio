/* MWD Admin Studio — meniu lateral organizat pe secțiuni pliabile.
 * Doar mutare de <li> + clase; nu atinge #adminmenuwrap / #adminmenuback.
 */
(function () {
	'use strict';
	var cfg = window.MWDMenuCollapse;
	if ( ! cfg ) { return; }
	var menu = document.getElementById( 'adminmenu' );
	if ( ! menu ) { return; }

	var essentials = cfg.essentials || [];
	var groups = cfg.groups || [];
	var anchor = document.getElementById( 'collapse-menu' ); // butonul „Restrânge meniul" rămâne ultimul
	var sections = [];

	/* ---------- Stare persistentă (per utilizator) ---------- */
	function loadState() {
		try { return JSON.parse( window.localStorage.getItem( cfg.storageKey ) || '{}' ) || {}; } catch ( e ) { return {}; }
	}
	function saveState( st ) {
		try { window.localStorage.setItem( cfg.storageKey, JSON.stringify( st ) ); } catch ( e ) {}
	}
	var state = loadState();

	function isEssential( li ) {
		return li.id && essentials.indexOf( li.id ) !== -1;
	}
	function isActive( li ) {
		return li.classList.contains( 'current' ) || li.classList.contains( 'wp-has-current-submenu' ) || !! li.querySelector( '.current' );
	}
	function groupKeyOf( li ) {
		for ( var i = 0; i < groups.length; i++ ) {
			if ( li.classList.contains( groups[i].key ) ) { return groups[i].key; }
		}
		return '';
	}
	function insert( node, before ) {
		if ( before && before.parentNode === menu ) { menu.insertBefore( node, before ); }
		else { menu.appendChild( node ); }
	}

	/* ---------- Colectare item-e ---------- */
	var items = [];
	Array.prototype.forEach.call( menu.children, function ( c ) {
		if ( c.tagName === 'LI' && c.classList.contains( 'menu-top' ) && ! c.classList.contains( 'wp-menu-separator' ) && c.id !== 'collapse-menu' ) {
			items.push( c );
		}
	} );

	var buckets = {};
	groups.forEach( function ( g ) { buckets[ g.key ] = []; } );
	var more = [];

	items.forEach( function ( li ) {
		var gk = groupKeyOf( li );
		if ( gk ) { buckets[ gk ].push( li ); return; }
		if ( ! isEssential( li ) ) { more.push( li ); }
	} );

	/* ---------- Construire secțiune ---------- */
	function makeSection( key, label, icon, list, before ) {
		if ( ! list.length ) { return; }

		var header = document.createElement( 'li' );
		header.className = 'menu-top mwd-sec';
		header.setAttribute( 'data-mwd-sec', key );

		var a = document.createElement( 'a' );
		a.href = '#';
		a.className = 'menu-top mwd-sec-toggle';
		a.setAttribute( 'role', 'button' );

		var img = document.createElement( 'div' );
		img.className = 'wp-menu-image dashicons-before ' + ( icon || 'dashicons-category' );
		img.setAttribute( 'aria-hidden', 'true' );

		var name = document.createElement( 'div' );
		name.className = 'wp-menu-name';
		var lab = document.createElement( 'span' );
		lab.className = 'mwd-sec-label';
		lab.textContent = label;
		var cnt = document.createElement( 'span' );
		cnt.className = 'mwd-sec-count';
		cnt.textContent = list.length;
		var chev = document.createElement( 'span' );
		chev.className = 'mwd-sec-chev';
		chev.setAttribute( 'aria-hidden', 'true' );
		// Elementele flotante (dreapta) înaintea textului, ca să stea pe primul rând.
		name.appendChild( chev );
		name.appendChild( cnt );
		name.appendChild( lab );

		a.appendChild( img );
		a.appendChild( name );
		header.appendChild( a );
		insert( header, before );

		var active = false;
		var last = header;
		list.forEach( function ( li ) {
			li.classList.add( 'mwd-sec-item' );
			li.setAttribute( 'data-mwd-sec', key );
			insert( li, last.nextSibling || anchor );
			last = li;
			if ( isActive( li ) ) { active = true; }
		} );
		if ( list.length ) { list[ list.length - 1 ].classList.add( 'mwd-sec-last' ); }

		var sec = { key: key, header: header, link: a, list: list, active: active };
		sections.push( sec );

		var open = active || ( typeof state[ key ] === 'boolean' ? state[ key ] : false );
		apply( sec, open );

		a.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			toggle( sec );
		} );
	}

	function apply( sec, open ) {
		sec.open = open;
		sec.header.classList.toggle( 'is-open', open );
		sec.header.classList.toggle( 'has-active', sec.active );
		sec.link.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		sec.list.forEach( function ( li ) { li.classList.toggle( 'mwd-sec-hidden', ! open ); } );
	}

	function toggle( sec ) {
		var open = ! sec.open;
		if ( open && cfg.accordion ) {
			sections.forEach( function ( s ) {
				if ( s !== sec && s.open && ! s.active ) {
					apply( s, false );
					state[ s.key ] = false;
				}
			} );
		}
		apply( sec, open );
		state[ sec.key ] = open;
		saveState( state );
	}

	// Grupurile apar în poziția primului lor element (respectă ordinea din manager).
	groups.forEach( function ( g ) {
		var list = buckets[ g.key ];
		if ( list.length ) { makeSection( g.key, g.label, g.icon, list, list[0] ); }
	} );

	// „Mai multe" = restul elementelor ne-esențiale, la final (înainte de „Restrânge meniul").
	var threshold = groups.length ? 1 : 3;
	if ( more.length >= threshold ) {
		makeSection( 'more', cfg.label, 'dashicons-ellipsis', more, anchor );
	}

	document.documentElement.classList.add( 'mwd-menu-ready' );
})();
