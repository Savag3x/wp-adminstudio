/* MWD Admin Studio — personalizarea dashboard-ului (carduri afișate, per utilizator). */
(function () {
	'use strict';
	var menu = document.querySelector( '.mwd-dash-custom-menu' );
	if ( ! menu ) { return; }
	var details = menu.closest( 'details' );
	var boxes = menu.querySelectorAll( 'input[type="checkbox"]' );

	function save() {
		var hidden = [];
		Array.prototype.forEach.call( boxes, function ( b ) { if ( ! b.checked ) { hidden.push( b.value ); } } );
		return window.fetch( menu.getAttribute( 'data-url' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': menu.getAttribute( 'data-nonce' ) },
			body: JSON.stringify( { hidden: hidden } )
		} );
	}

	Array.prototype.forEach.call( boxes, function ( b ) {
		b.addEventListener( 'change', function () {
			var slot = document.querySelector( '.mwd-dash-slot[data-card="' + b.value + '"]' );
			if ( ! b.checked ) {
				// Ascundere imediată; la următoarea încărcare cardul nu mai e generat deloc.
				if ( slot ) { slot.hidden = true; }
				save();
			} else if ( slot ) {
				slot.hidden = false;
				save();
			} else {
				// Cardul nu a fost generat (era ascuns): reîncărcăm după salvare ca să-l aducem cu date.
				save().then( function () { window.location.reload(); } );
			}
		} );
	} );

	// Închide meniul la click în afara lui sau la Escape.
	document.addEventListener( 'click', function ( e ) {
		if ( details && details.open && ! details.contains( e.target ) ) { details.open = false; }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && details && details.open ) { details.open = false; }
	} );
})();
