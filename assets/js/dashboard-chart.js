/* MWD Admin Studio — graficul de trend din dashboard (SVG, fără dependențe).
 * O singură metrică afișată odată (fără axe duble); tab-urile comută metrica.
 * Arie: crosshair + tooltip. Coloane: fiecare coloană e țintă de hover.
 */
(function () {
	'use strict';
	var NS = 'http://www.w3.org/2000/svg';

	function svg( tag, attrs, parent ) {
		var n = document.createElementNS( NS, tag );
		for ( var k in attrs ) { if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) { n.setAttribute( k, attrs[ k ] ); } }
		if ( parent ) { parent.appendChild( n ); }
		return n;
	}
	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	// Pas „rotund" pentru axa Y: 1, 2, 2.5, 5 × 10^n.
	function niceStep( max, count ) {
		if ( max <= 0 ) { return 1; }
		var raw = max / count;
		var mag = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		var steps = [ 1, 2, 2.5, 5, 10 ];
		for ( var i = 0; i < steps.length; i++ ) {
			if ( raw <= steps[ i ] * mag ) { return steps[ i ] * mag; }
		}
		return 10 * mag;
	}

	function init( card ) {
		var data;
		try { data = JSON.parse( card.getAttribute( 'data-mwd-chart' ) ); } catch ( e ) { return; }
		if ( ! data || ! data.metrics || ! data.metrics.length ) { return; }

		var plot = card.querySelector( '[data-role="plot"]' );
		var table = card.querySelector( '[data-role="table"]' );
		var totalEl = card.querySelector( '[data-role="total"]' );
		var deltaEl = card.querySelector( '[data-role="delta"]' );
		var subEl = card.querySelector( '[data-role="sub"]' );
		var tabs = card.querySelectorAll( '.mwd-dash-trend-tab' );
		var locale = data.locale || 'ro-RO';
		var current = data.metrics[0];

		var fmtInt, fmtMoney, fmtCompact, fmtDate, fmtDateLong;
		try {
			fmtInt = new Intl.NumberFormat( locale, { maximumFractionDigits: 0 } );
			fmtCompact = new Intl.NumberFormat( locale, { notation: 'compact', maximumFractionDigits: 1 } );
			fmtMoney = data.currency
				? new Intl.NumberFormat( locale, { style: 'currency', currency: data.currency, minimumFractionDigits: data.decimals, maximumFractionDigits: data.decimals } )
				: new Intl.NumberFormat( locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
			fmtDate = new Intl.DateTimeFormat( locale, { day: 'numeric', month: 'short' } );
			fmtDateLong = new Intl.DateTimeFormat( locale, { weekday: 'short', day: 'numeric', month: 'long' } );
		} catch ( e ) {
			fmtInt = fmtCompact = fmtMoney = { format: function ( v ) { return String( Math.round( v * 100 ) / 100 ); } };
			fmtDate = fmtDateLong = { format: function ( d ) { return d.toISOString().slice( 0, 10 ); } };
		}
		function value( m, v ) { return m.money ? fmtMoney.format( v ) : fmtInt.format( v ); }
		function axisValue( m, v ) { return v >= 1000 ? fmtCompact.format( v ) : fmtInt.format( v ); }
		function day( s ) { return new Date( s + 'T00:00:00' ); }

		/* ---------- Antet: total + variație ---------- */
		function header( m ) {
			totalEl.textContent = value( m, m.total );
			deltaEl.textContent = '';
			deltaEl.className = '';
			var d = m.delta;
			if ( d ) {
				var badge = el( 'span', 'mwd-delta ' + ( d['new'] ? 'up' : d.dir ) );
				badge.textContent = d['new'] ? '▲ nou' : ( d.dir === 'flat' ? '0%' : ( d.dir === 'up' ? '▲ ' : '▼ ' ) + d.pct + '%' );
				deltaEl.appendChild( badge );
			}
			subEl.textContent = data.prevText || '';
		}

		/* ---------- Tabel accesibil ---------- */
		function fillTable( m ) {
			while ( table.rows.length ) { table.deleteRow( 0 ); }
			var h = table.createTHead().insertRow();
			h.appendChild( el( 'th', '', 'Data' ) );
			h.appendChild( el( 'th', '', m.label ) );
			var body = table.tBodies[0] || table.appendChild( document.createElement( 'tbody' ) );
			data.dates.forEach( function ( d, i ) {
				var r = body.insertRow();
				r.insertCell().textContent = fmtDateLong.format( day( d ) );
				r.insertCell().textContent = value( m, m.values[ i ] || 0 );
			} );
		}

		/* ---------- Desen ---------- */
		function draw() {
			var m = current;
			var vals = ( m.values || [] ).map( Number );
			var n = vals.length;
			plot.textContent = '';
			if ( ! n ) { return; }

			var W = Math.max( 280, plot.clientWidth );
			var H = 230;
			var padT = 12, padB = 26, padR = 8;
			var max = Math.max.apply( null, vals );
			var step = niceStep( max, 4 );
			var top = Math.max( step * 4, step );
			var ticks = [];
			for ( var t = 0; t <= top + 1e-9; t += step ) { ticks.push( t ); }

			// Lățimea etichetelor Y.
			var maxLabel = ticks.reduce( function ( a, t ) { return Math.max( a, axisValue( m, t ).length ); }, 1 );
			var padL = Math.min( 64, 12 + maxLabel * 7 );
			var iw = W - padL - padR;
			var ih = H - padT - padB;
			var isBar = m.type === 'bar';
			var slot = iw / n;
			function x( i ) { return isBar ? padL + slot * i + slot / 2 : padL + ( n === 1 ? iw / 2 : iw * i / ( n - 1 ) ); }
			function y( v ) { return padT + ih - ( v / top ) * ih; }

			var s = svg( 'svg', { 'class': 'mwd-chart', viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'presentation' }, plot );

			// Grilă + axa Y (recesive).
			ticks.forEach( function ( t ) {
				var yy = Math.round( y( t ) ) + 0.5;
				svg( 'line', { 'class': t === 0 ? 'mwd-chart-base' : 'mwd-chart-grid', x1: padL, x2: W - padR, y1: yy, y2: yy }, s );
				var lab = svg( 'text', { 'class': 'mwd-chart-axis', x: padL - 8, y: yy + 4, 'text-anchor': 'end' }, s );
				lab.textContent = axisValue( m, t );
			} );

			// Axa X: ~6 etichete distribuite uniform.
			var every = Math.max( 1, Math.ceil( n / 6 ) );
			for ( var i = 0; i < n; i += every ) {
				var lx = svg( 'text', { 'class': 'mwd-chart-axis', x: x( i ), y: H - 6, 'text-anchor': 'middle' }, s );
				lx.textContent = fmtDate.format( day( data.dates[ i ] ) );
			}

			var marks = [];
			if ( isBar ) {
				var bw = Math.max( 2, Math.min( 24, slot - 2, slot * 0.62 ) );
				var r = Math.min( 4, bw / 2 );
				vals.forEach( function ( v, k ) {
					var bh = Math.max( 0, y( 0 ) - y( v ) );
					var bx = x( k ) - bw / 2, by = y( 0 ) - bh;
					var rr = Math.min( r, bh );
					// Capăt rotunjit sus, drept la bază.
					var d = 'M' + bx + ',' + ( by + bh ) + ' V' + ( by + rr ) + ' Q' + bx + ',' + by + ' ' + ( bx + rr ) + ',' + by +
						' H' + ( bx + bw - rr ) + ' Q' + ( bx + bw ) + ',' + by + ' ' + ( bx + bw ) + ',' + ( by + rr ) + ' V' + ( by + bh ) + ' Z';
					marks.push( svg( 'path', { 'class': 'mwd-chart-bar', d: bh > 0 ? d : 'M0,0' }, s ) );
				} );
			} else {
				var pts = vals.map( function ( v, k ) { return [ x( k ), y( v ) ]; } );
				var line = pts.map( function ( p, k ) { return ( k ? 'L' : 'M' ) + p[0].toFixed( 1 ) + ',' + p[1].toFixed( 1 ); } ).join( ' ' );
				svg( 'path', { 'class': 'mwd-chart-area', d: line + ' L' + pts[ n - 1 ][0].toFixed( 1 ) + ',' + y( 0 ) + ' L' + pts[0][0].toFixed( 1 ) + ',' + y( 0 ) + ' Z' }, s );
				svg( 'path', { 'class': 'mwd-chart-line', d: line }, s );
				// Punct la final (valoarea de azi).
				svg( 'circle', { 'class': 'mwd-chart-dot', cx: pts[ n - 1 ][0], cy: pts[ n - 1 ][1], r: 4 }, s );
			}

			// Strat de hover: crosshair + punct + tooltip.
			var cross = svg( 'line', { 'class': 'mwd-chart-cross', x1: 0, x2: 0, y1: padT, y2: padT + ih, visibility: 'hidden' }, s );
			var hot = isBar ? null : svg( 'circle', { 'class': 'mwd-chart-dot is-hover', r: 5, visibility: 'hidden' }, s );
			var tip = el( 'div', 'mwd-chart-tip' );
			tip.hidden = true;
			var tv = el( 'strong' ), td = el( 'span' );
			tip.appendChild( tv );
			tip.appendChild( td );
			plot.appendChild( tip );
			var over = svg( 'rect', { x: padL, y: 0, width: iw, height: H - padB, fill: 'transparent' }, s );

			function show( k ) {
				var cx = x( k );
				if ( isBar ) {
					marks.forEach( function ( mk, j ) { mk.classList.toggle( 'is-hover', j === k ); mk.classList.toggle( 'is-dim', j !== k ); } );
				} else {
					cross.setAttribute( 'x1', cx ); cross.setAttribute( 'x2', cx );
					cross.setAttribute( 'visibility', 'visible' );
					hot.setAttribute( 'cx', cx ); hot.setAttribute( 'cy', y( vals[ k ] ) );
					hot.setAttribute( 'visibility', 'visible' );
				}
				tv.textContent = value( m, vals[ k ] );
				td.textContent = fmtDateLong.format( day( data.dates[ k ] ) );
				tip.hidden = false;
				var tw = tip.offsetWidth;
				var left = Math.min( Math.max( cx - tw / 2, 0 ), W - tw );
				tip.style.left = left + 'px';
				tip.style.top = Math.max( 0, ( isBar ? y( vals[ k ] ) : y( vals[ k ] ) ) - tip.offsetHeight - 12 ) + 'px';
			}
			function hide() {
				tip.hidden = true;
				cross.setAttribute( 'visibility', 'hidden' );
				if ( hot ) { hot.setAttribute( 'visibility', 'hidden' ); }
				marks.forEach( function ( mk ) { mk.classList.remove( 'is-hover', 'is-dim' ); } );
			}
			over.addEventListener( 'pointermove', function ( e ) {
				var rect = s.getBoundingClientRect();
				var px = ( e.clientX - rect.left ) * ( W / rect.width );
				var k = isBar ? Math.floor( ( px - padL ) / slot ) : Math.round( ( px - padL ) / ( n === 1 ? 1 : iw / ( n - 1 ) ) );
				show( Math.max( 0, Math.min( n - 1, k ) ) );
			} );
			over.addEventListener( 'pointerleave', hide );
		}

		function select( key ) {
			data.metrics.forEach( function ( m ) { if ( m.key === key ) { current = m; } } );
			Array.prototype.forEach.call( tabs, function ( t ) {
				var on = t.getAttribute( 'data-metric' ) === key;
				t.classList.toggle( 'is-active', on );
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			header( current );
			fillTable( current );
			draw();
			try { window.localStorage.setItem( 'mwdTrendMetric', key ); } catch ( e ) {}
		}

		Array.prototype.forEach.call( tabs, function ( t ) {
			t.addEventListener( 'click', function () { select( t.getAttribute( 'data-metric' ) ); } );
		} );

		var saved = null;
		try { saved = window.localStorage.getItem( 'mwdTrendMetric' ); } catch ( e ) {}
		var has = data.metrics.some( function ( m ) { return m.key === saved; } );
		select( has ? saved : data.metrics[0].key );

		// Redesenare la redimensionare (debounce pe frame).
		var raf = 0;
		var onResize = function () {
			window.cancelAnimationFrame( raf );
			raf = window.requestAnimationFrame( draw );
		};
		if ( window.ResizeObserver ) { new window.ResizeObserver( onResize ).observe( plot ); }
		else { window.addEventListener( 'resize', onResize ); }
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-mwd-chart]' ), init );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
})();
