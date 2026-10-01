/* MWD Admin Studio — meniu lateral organizat pe secțiuni pliabile */
(function () {
	'use strict';
	var cfg = window.MWDMenuCollapse;
	if ( ! cfg ) { return; }
	var menu = document.getElementById( 'adminmenu' );
	if ( ! menu ) { return; }

	var essentials = cfg.essentials || [];
	var groups = cfg.groups || [];

	function isEssential( li ) {
		return li.id && essentials.indexOf( li.id ) !== -1;
	}
	function isActive( li ) {
		if ( li.classList && ( li.classList.contains( 'current' ) || li.classList.contains( 'wp-has-current-submenu' ) ) ) {
			return true;
		}
		return !! li.querySelector( '.current' );
	}
	function groupKeyOf( li ) {
		for ( var i = 0; i < groups.length; i++ ) {
			if ( li.className.indexOf( groups[i].key ) !== -1 ) { return groups[i].key; }
		}
		return '';
	}

	var items = [];
	var children = menu.children;
	for ( var i = 0; i < children.length; i++ ) {
		var c = children[i];
		if ( c.tagName === 'LI' && c.className.indexOf( 'menu-top' ) !== -1 &&
			c.className.indexOf( 'wp-menu-separator' ) === -1 &&
			c.className.indexOf( 'mwd-sec' ) === -1 ) {
			items.push( c );
		}
	}

	var buckets = {};
	groups.forEach( function ( g ) { buckets[g.key] = []; } );
	var more = [];

	items.forEach( function ( li ) {
		var gk = groupKeyOf( li );
		if ( gk ) { buckets[gk].push( li ); return; }
		if ( isEssential( li ) ) { return; }
		more.push( li );
	});

	function makeSection( key, label, list ) {
		if ( ! list.length ) { return; }
		var header = document.createElement( 'li' );
		header.className = 'menu-top mwd-sec';
		header.innerHTML = '<a href="#" class="menu-top">' +
			'<div class="wp-menu-image dashicons-before dashicons-menu-alt3"></div>' +
			'<div class="wp-menu-name">' + label + ' <span class="mwd-more-count">' + list.length + '</span></div></a>';
		menu.appendChild( header );

		var active = false;
		list.forEach( function ( li ) {
			li.setAttribute( 'data-mwd-sec', key );
			menu.appendChild( li );
			if ( isActive( li ) ) { active = true; }
		});

		// Pornesc mereu închise; se deschide doar grupul paginii curente.
		function apply( o ) {
			list.forEach( function ( li ) { li.style.display = o ? '' : 'none'; } );
			if ( o ) { header.className += ' is-open'; }
			else { header.className = header.className.replace( /\s*is-open/g, '' ); }
		}
		apply( active );

		header.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			apply( header.className.indexOf( 'is-open' ) === -1 );
		});
	}

	groups.forEach( function ( g ) {
		makeSection( g.key, g.label, buckets[g.key] );
	});

	var threshold = groups.length ? 1 : 3;
	if ( more.length >= threshold ) {
		makeSection( 'more', cfg.label, more );
	}
})();
