/* MWD Admin Studio — „Online acum" (auto-refresh) */
(function () {
	'use strict';
	var cfg = window.MWDOnline;
	if ( ! cfg || ! cfg.url ) { return; }
	var el = document.getElementById( 'mwd-online-count' );
	if ( ! el ) { return; }
	var pill = el.closest ? el.closest( '.mwd-dash-online' ) : el.parentNode;

	function poll() {
		fetch( cfg.url, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				if ( ! d || typeof d.count === 'undefined' ) { return; }
				el.textContent = d.count;
				if ( pill ) {
					if ( d.count > 0 ) { pill.className += pill.className.indexOf( 'is-live' ) === -1 ? ' is-live' : ''; }
					else { pill.className = pill.className.replace( /\s*is-live/g, '' ); }
				}
			} )
			.catch( function () {} );
	}

	poll();
	setInterval( poll, cfg.interval || 20000 );
})();
