/* MWD Admin Studio — „shell" de aplicație în sidebar:
 * antet workspace (site) sus + cardul utilizatorului jos.
 * Elemente <li> obișnuite în #adminmenu; nu atinge #adminmenuwrap / #adminmenuback.
 */
(function () {
	'use strict';
	var cfg = window.MWDShell;
	var menu = document.getElementById( 'adminmenu' );
	if ( ! cfg || ! menu || menu.querySelector( '.mwd-shell' ) ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}
	function initials( s ) {
		var w = String( s || '?' ).trim().split( /\s+/ );
		return ( ( w[0] || '?' ).charAt( 0 ) + ( w.length > 1 ? w[ w.length - 1 ].charAt( 0 ) : '' ) ).toUpperCase();
	}
	function item( cls, href, mark, title, sub, external ) {
		var li = el( 'li', 'menu-top mwd-shell ' + cls );
		var a = el( 'a', 'menu-top' );
		a.href = href;
		if ( external ) { a.target = '_blank'; a.rel = 'noopener'; }
		var img = el( 'div', 'wp-menu-image' );
		img.appendChild( mark );
		var name = el( 'div', 'wp-menu-name' );
		name.appendChild( el( 'span', 'mwd-shell-title', title ) );
		if ( sub ) { name.appendChild( el( 'span', 'mwd-shell-sub', sub ) ); }
		a.appendChild( img );
		a.appendChild( name );
		li.appendChild( a );
		return li;
	}
	function mark( src, text, round ) {
		var m = el( 'span', 'mwd-shell-mark' + ( round ? ' is-round' : '' ) );
		if ( src ) {
			var i = el( 'img' );
			i.src = src;
			i.alt = '';
			i.loading = 'lazy';
			i.onerror = function () { m.textContent = text; };
			m.appendChild( i );
		} else {
			m.textContent = text;
		}
		return m;
	}

	// Workspace (site) — primul element din meniu.
	var s = cfg.site || {};
	var ws = item( 'mwd-ws', s.url, mark( s.icon, initials( s.name ) ), s.name, s.host || cfg.viewSite, true );
	ws.querySelector( 'a' ).setAttribute( 'title', cfg.viewSite + ' ↗' );
	menu.insertBefore( ws, menu.firstChild );

	// Utilizator — înainte de „Restrânge meniul".
	var u = cfg.user || {};
	var me = item( 'mwd-me', u.url, mark( u.avatar, initials( u.name ), true ), u.name, u.role, false );
	var collapse = document.getElementById( 'collapse-menu' );
	if ( collapse ) { menu.insertBefore( me, collapse ); } else { menu.appendChild( me ); }

	document.documentElement.classList.add( 'mwd-shell-ready' );
})();
