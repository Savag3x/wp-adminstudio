/* MWD Admin Studio — ține sincronizat „pin"-ul nativ al meniului lateral.
 *
 * WordPress (common.js) măsoară înălțimea meniului o singură dată, la încărcare, și decide
 * dacă îl fixează pe ecran (body.sticky-menu / position: fixed). Dacă meniul crește ulterior
 * (secțiuni deschise, fonturi încărcate târziu, bule de notificări, pagini de update care se
 * încarcă progresiv), WP nu află, iar partea de jos a meniului nu mai poate fi derulată.
 *
 * Soluția: la orice schimbare de înălțime declanșăm evenimentul oficial `wp-pin-menu`, la care
 * WP își recalculează singur poziționarea. Nu atingem stilurile #adminmenuwrap.
 */
(function ( $ ) {
	'use strict';
	if ( ! $ ) { return; }
	var wrap = document.getElementById( 'adminmenuwrap' );
	var page = document.getElementById( 'wpwrap' );
	if ( ! wrap ) { return; }

	// WP decide pin-ul din înălțimea meniului ȘI a paginii (ex. pe update.php conținutul
	// crește pe măsură ce apar mesajele de progres) — urmărim ambele.
	var raf = 0, last = '';
	function repin() {
		window.cancelAnimationFrame( raf );
		raf = window.requestAnimationFrame( function () {
			var key = wrap.offsetHeight + 'x' + ( page ? page.offsetHeight : 0 );
			if ( key === last ) { return; }
			last = key;
			$( document ).trigger( 'wp-pin-menu' );
		} );
	}

	if ( window.ResizeObserver ) {
		var ro = new window.ResizeObserver( repin );
		ro.observe( wrap );
		if ( page ) { ro.observe( page ); }
	}
	// Plase de siguranță pentru browsere fără ResizeObserver și pentru resurse încărcate târziu.
	$( window ).on( 'load', repin );
	if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( repin ); }
	$( document ).on( 'click', '#adminmenu .mwd-sec > a', function () { window.setTimeout( repin, 0 ); } );
	$( repin );
})( window.jQuery );
