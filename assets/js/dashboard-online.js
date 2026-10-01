/* MWD Admin Studio — „Online acum" (auto-refresh, oprit cât timp tab-ul nu e vizibil) */
(function () {
	'use strict';
	var cfg = window.MWDOnline;
	if ( ! cfg || ! cfg.url ) { return; }
	var el = document.getElementById( 'mwd-online-count' );
	var list = document.getElementById( 'mwd-live-list' );
	if ( ! el && ! list ) { return; }
	var pill = el && el.closest ? el.closest( '.mwd-dash-online' ) : null;
	var timer = null;

	function icon( device ) {
		return device === 'mobile' ? 'dashicons-smartphone' : ( device === 'tablet' ? 'dashicons-tablet' : 'dashicons-desktop' );
	}

	function renderList( rows ) {
		if ( ! list ) { return; }
		list.textContent = '';
		if ( ! rows || ! rows.length ) {
			var p = document.createElement( 'p' );
			p.className = 'mwd-dash-empty';
			p.textContent = 'Niciun vizitator în ultimele 5 minute.';
			list.appendChild( p );
			return;
		}
		rows.forEach( function ( r ) {
			var row = document.createElement( 'div' );
			row.className = 'mwd-dash-row';
			var t = document.createElement( 'span' );
			t.className = 'mwd-dash-row-t mwd-dash-ellip';
			var ic = document.createElement( 'span' );
			ic.className = 'dashicons ' + icon( r.device );
			t.appendChild( ic );
			t.appendChild( document.createTextNode( ' ' + ( r.page || '/' ) ) );
			var m = document.createElement( 'span' );
			m.className = 'mwd-dash-row-m';
			m.textContent = r.country || '—';
			row.appendChild( t );
			row.appendChild( m );
			list.appendChild( row );
		} );
	}

	function poll() {
		fetch( cfg.url, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) {
				if ( ! d || typeof d.count === 'undefined' ) { return; }
				if ( el ) { el.textContent = d.count; }
				if ( pill ) { pill.classList.toggle( 'is-live', d.count > 0 ); }
				renderList( d.list );
			} )
			.catch( function () {} );
	}

	function start() {
		if ( timer ) { return; }
		timer = window.setInterval( poll, cfg.interval || 20000 );
	}
	function stop() {
		window.clearInterval( timer );
		timer = null;
	}

	// Fără cereri în fundal: tab ascuns = polling oprit; la revenire, o actualizare imediată.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) { stop(); } else { poll(); start(); }
	} );
	if ( ! document.hidden ) { start(); }
})();
