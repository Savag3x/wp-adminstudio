/* MWD Admin Studio — aranjare „masonry" pentru cardurile din dashboard.
 *
 * Grila CSS rămâne (coloane responsive), dar rândurile au 1px: fiecare card ocupă exact
 * atâtea rânduri cât e de înalt (+ spațiul dintre carduri), iar `grid-auto-flow: dense`
 * umple golurile de sub cardurile scurte. Fără JS rămâne grila normală.
 */
(function () {
	'use strict';
	var grids = document.querySelectorAll( '.mwd-dash-grid' );
	if ( ! grids.length || ! window.ResizeObserver ) { return; }

	Array.prototype.forEach.call( grids, function ( grid ) {
		// Spațiul vertical dintre carduri = gap-ul original al grilei.
		var gap = parseFloat( getComputedStyle( grid ).rowGap ) || 14;
		grid.style.setProperty( '--mwd-masonry-gap', gap + 'px' );
		grid.classList.add( 'is-masonry' );

		function fit( card ) {
			// Înălțimea reală a cardului (fără span-ul aplicat) + spațiul de sub el.
			var h = card.getBoundingClientRect().height;
			card.style.gridRowEnd = 'span ' + Math.max( 1, Math.ceil( h + gap ) );
		}

		var ro = new window.ResizeObserver( function ( entries ) {
			entries.forEach( function ( e ) { fit( e.target ); } );
		} );
		Array.prototype.forEach.call( grid.children, function ( card ) {
			fit( card );
			ro.observe( card );
		} );
	} );
})();
