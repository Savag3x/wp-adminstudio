/* MWD Admin Studio — beacon analitice (sesiuni, afisari, timp pe pagina) */
(function () {
	'use strict';
	var EP = window.MWDAnalytics;
	if ( ! EP || ! EP.hit ) { return; }

	function getCookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? m.pop() : '';
	}
	function setCookie( name, val, maxAge ) {
		document.cookie = name + '=' + val + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
	}
	function uuid() {
		return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = Math.random() * 16 | 0, v = c === 'x' ? r : ( r & 0x3 | 0x8 );
			return v.toString( 16 );
		} );
	}

	var uid = getCookie( 'mwd_uid' );
	if ( ! uid ) { uid = uuid(); setCookie( 'mwd_uid', uid, 31536000 ); }

	var sid = getCookie( 'mwd_sid' );
	var isNew = 0;
	if ( ! sid ) { sid = uuid(); isNew = 1; }
	setCookie( 'mwd_sid', sid, 1800 ); // sesiune glisanta 30 min

	var path = location.pathname + location.search;

	function param( name ) {
		try {
			return new URLSearchParams( location.search ).get( name ) || '';
		} catch ( e ) { return ''; }
	}

	function send( url, data ) {
		try {
			var body = JSON.stringify( data );
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) );
			} else {
				fetch( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true } );
			}
		} catch ( e ) {}
	}

	// Detalii despre dispozitiv: ecran, limba, ecran tactil (iPad se prezinta drept Mac) +
	// Client Hints pe browserele Chromium (model real pe Android, versiuni reale de OS / browser).
	function hints( done ) {
		var h = {
			sw: window.screen ? screen.width : 0,
			sh: window.screen ? screen.height : 0,
			tp: navigator.maxTouchPoints || 0,
			lang: navigator.language || ''
		};
		var uad = navigator.userAgentData;
		if ( ! uad || ! uad.getHighEntropyValues ) { done( h ); return; }
		h.mob = !! uad.mobile;
		var finished = false;
		function finish() { if ( ! finished ) { finished = true; done( h ); } }
		window.setTimeout( finish, 400 ); // nu intarziem afisarea daca browserul raspunde greu
		uad.getHighEntropyValues( [ 'model', 'platformVersion', 'fullVersionList' ] ).then( function ( v ) {
			h.m = v.model || '';
			h.pv = v.platformVersion || '';
			h.b = ( v.fullVersionList || [] ).map( function ( x ) { return { brand: x.brand, version: x.version }; } );
			finish();
		} ).catch( finish );
	}

	// Browsere automatizate (Puppeteer, Selenium etc.): nu sunt vizitatori.
	if ( navigator.webdriver ) { return; }

	// Afisarea de pagina
	hints( function ( h ) {
		send( EP.hit, { uid: uid, sid: sid, n: isNew, u: path, t: document.title, r: document.referrer, us: param( 'utm_source' ), um: param( 'utm_medium' ), uc: param( 'utm_campaign' ), h: h } );
	} );

	// Timp activ pe pagina
	var active = 0, last = Date.now(), visible = ! document.hidden;
	function tick() {
		if ( visible ) {
			active += Math.min( 30, ( Date.now() - last ) / 1000 );
		}
		last = Date.now();
	}
	setInterval( tick, 5000 );

	var sent = false;
	function flush() {
		tick();
		var d = Math.round( active );
		active = 0;
		if ( d > 0 ) { send( EP.beat, { sid: sid, uid: uid, u: path, d: d } ); }
		setCookie( 'mwd_sid', sid, 1800 );
	}

	document.addEventListener( 'visibilitychange', function () {
		tick();
		if ( document.hidden ) { flush(); }
		visible = ! document.hidden;
		last = Date.now();
	} );
	window.addEventListener( 'pagehide', flush );
	window.addEventListener( 'beforeunload', flush );
})();
