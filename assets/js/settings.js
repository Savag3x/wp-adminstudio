/* MWD Admin Studio — interacțiuni pagina de setări */
(function ( $ ) {
	'use strict';

	$( function () {
		var $wrap = $( '.mwd-as-wrap' );
		if ( ! $wrap.length ) { return; }
		var $form = $( '#mwd-as-form' );
		var dirty = false;

		/* ---------- Modificări nesalvate ---------- */
		function markDirty() {
			if ( dirty ) { return; }
			dirty = true;
			$wrap.addClass( 'is-dirty' );
		}
		$form.on( 'input change', ':input:not(#mwd-as-filter):not(#mwd-as-copy-from)', markDirty );
		$form.on( 'submit', function () { dirty = false; } );
		$( '#mwd-as-import' ).on( 'submit', function () { dirty = false; } );
		$( '#mwd-as-discard' ).on( 'click', function () { dirty = false; } );
		$( window ).on( 'beforeunload', function () {
			if ( dirty ) { return 'Ai modificări nesalvate.'; }
		} );

		// Curăță parametrul de mesaj din URL (refresh-ul nu reafișează toast-ul).
		if ( window.history && history.replaceState && /[?&]mwd_msg=/.test( location.search ) ) {
			history.replaceState( null, '', location.href.replace( /([?&])mwd_msg=[^&#]*&?/, '$1' ).replace( /[?&](#|$)/, '$1' ) );
		}
		window.setTimeout( function () { $( '.mwd-as-toast' ).addClass( 'is-hiding' ); }, 4000 );

		/* ---------- Tab-uri ---------- */
		var $tabInput = $( '#mwd_tab' );
		function activate( id, push ) {
			var $btn = $wrap.find( '.mwd-as-navbtn[data-tab="' + id + '"]' );
			if ( ! $btn.length ) { return; }
			$wrap.find( '.mwd-as-navbtn' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			$btn.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			$wrap.find( '.mwd-as-panel' ).removeClass( 'is-active' ).filter( '[data-panel="' + id + '"]' ).addClass( 'is-active' );
			$tabInput.val( id );
			if ( push && window.history && history.replaceState ) {
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', id );
				url.hash = '';
				history.replaceState( null, '', url.toString() );
			}
		}
		$wrap.on( 'click', '.mwd-as-navbtn', function () { activate( $( this ).data( 'tab' ), true ); } );
		var hash = ( window.location.hash || '' ).replace( '#tab-', '' );
		activate( hash && $wrap.find( '[data-panel="' + hash + '"]' ).length ? hash : ( $tabInput.val() || 'aspect' ), false );

		/* ---------- Culori + previzualizare live ---------- */
		var $prev = $( '#mwd-prev' );
		var map = {
			sidebar_bg: '--p-side', sidebar_text: '--p-side-text', accent: '--p-accent',
			accent_hover: '--p-accent-h', content_bg: '--p-bg', link: '--p-link'
		};
		function setPrev( id, val ) {
			if ( map[ id ] && $prev.length ) { $prev[0].style.setProperty( map[ id ], val ); }
		}
		$( '.mwd-as-color' ).each( function () {
			var $i = $( this );
			setPrev( this.id, $i.val() );
			$i.wpColorPicker( {
				change: function ( e, ui ) { setPrev( e.target.id, ui.color.toString() ); markDirty(); },
				clear: function () { markDirty(); }
			} );
		} );

		var presets = {
			mwd:      { sidebar_bg: '#0f2744', sidebar_text: '#c7d2e0', accent: '#10b981', accent_hover: '#0ea371', content_bg: '#f4f6fb', link: '#0f2744' },
			midnight: { sidebar_bg: '#0b0f19', sidebar_text: '#a5b0c5', accent: '#6366f1', accent_hover: '#4f46e5', content_bg: '#f5f6fa', link: '#4338ca' },
			graphite: { sidebar_bg: '#18181b', sidebar_text: '#a1a1aa', accent: '#f97316', accent_hover: '#ea580c', content_bg: '#f7f7f8', link: '#18181b' },
			ocean:    { sidebar_bg: '#0c1e3a', sidebar_text: '#b6c6dc', accent: '#0ea5e9', accent_hover: '#0284c7', content_bg: '#f3f7fb', link: '#0369a1' },
			violet:   { sidebar_bg: '#1e1036', sidebar_text: '#c4b5dd', accent: '#a855f7', accent_hover: '#9333ea', content_bg: '#f7f5fb', link: '#6b21a8' },
			veedo:    { sidebar_bg: '#16161a', sidebar_text: '#c9c9d1', accent: '#e8380d', accent_hover: '#c72f0a', content_bg: '#f6f6f8', link: '#16161a' },
			light:    { sidebar_bg: '#ffffff', sidebar_text: '#475569', accent: '#2563eb', accent_hover: '#1d4ed8', content_bg: '#f8fafc', link: '#1d4ed8' }
		};
		$( '.mwd-as-preset' ).on( 'click', function () {
			var p = presets[ $( this ).data( 'preset' ) ];
			if ( ! p ) { return; }
			$( '.mwd-as-preset' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			$.each( p, function ( id, val ) {
				$( '#' + id ).val( val ).wpColorPicker( 'color', val );
				setPrev( id, val );
			} );
			markDirty();
		} );

		var $radius = $( '#radius' );
		function radius() {
			$( '#radius-val' ).text( $radius.val() + 'px' );
			if ( $prev.length ) { $prev[0].style.setProperty( '--p-r', $radius.val() + 'px' ); }
		}
		$radius.on( 'input', radius );
		radius();

		/* ---------- Analitice: adaugă IP-ul curent ---------- */
		$( '#mwd-add-ip' ).on( 'click', function ( e ) {
			e.preventDefault();
			var ip = ( $( '#mwd-myip' ).text() || '' ).trim();
			if ( ! ip ) { return; }
			var $ta = $( '#exclude_ips' ), val = ( $ta.val() || '' ).trim();
			if ( val.split( /\r\n|\r|\n/ ).indexOf( ip ) === -1 ) {
				$ta.val( val ? val + '\n' + ip : ip ).trigger( 'change' );
			}
		} );

		/* ---------- Manager meniu ---------- */
		var $list = $( '#mwd-as-menu-list' );
		var $inherit = $( 'input[name="menu_inherit"]' );

		function ownConfig() {
			if ( $inherit.length && $inherit.prop( 'checked' ) ) {
				$inherit.prop( 'checked', false );
				$inherit.closest( '.mwd-as-inherit' ).removeClass( 'is-inheriting' );
			}
		}
		$inherit.on( 'change', function () {
			$( this ).closest( '.mwd-as-inherit' ).toggleClass( 'is-inheriting', this.checked );
		} );

		$list.sortable( {
			handle: '.mwd-mi-drag',
			placeholder: 'mwd-mi-placeholder',
			axis: 'y',
			forcePlaceholderSize: true,
			update: function () { ownConfig(); markDirty(); }
		} );
		$list.on( 'input change', ':input', ownConfig );

		// Ochiul de ascundere -> stare vizuală pe rând.
		$list.on( 'change', '.mwd-eye input', function () {
			$( this ).closest( '.mwd-si, .mwd-mi' ).first().toggleClass( 'is-hidden', this.checked );
		} );

		// Sub-pagini
		function setOpen( $li, open ) {
			$li.toggleClass( 'is-collapsed', ! open );
			$li.find( '> .mwd-mi-main .mwd-mi-toggle' ).attr( 'aria-expanded', open ? 'true' : 'false' );
		}
		$list.on( 'click', '.mwd-mi-toggle', function () {
			var $li = $( this ).closest( '.mwd-mi' );
			setOpen( $li, $li.hasClass( 'is-collapsed' ) );
		} );
		$( '#mwd-as-expand-all' ).on( 'click', function () { $list.find( '.mwd-mi.has-subs' ).each( function () { setOpen( $( this ), true ); } ); } );
		$( '#mwd-as-collapse-all' ).on( 'click', function () { $list.find( '.mwd-mi.has-subs' ).each( function () { setOpen( $( this ), false ); } ); } );

		// Filtrare
		$( '#mwd-as-filter' ).on( 'input', function () {
			var q = $.trim( $( this ).val() ).toLowerCase();
			var shown = 0;
			$list.find( '> .mwd-mi' ).each( function () {
				var $li = $( this );
				var hay = $li.attr( 'data-search' ) + ' ' + $li.find( '.mwd-si-name' ).text().toLowerCase();
				var ok = ! q || hay.indexOf( q ) !== -1;
				$li.toggle( ok );
				if ( ok ) { shown++; }
				if ( q && ok && $li.hasClass( 'has-subs' ) && $li.find( '.mwd-si-name' ).text().toLowerCase().indexOf( q ) !== -1 ) {
					setOpen( $li, true );
				}
			} );
			$( '#mwd-as-filter-empty' ).prop( 'hidden', shown > 0 );
			$list.sortable( q ? 'disable' : 'enable' );
		} );

		// Sugerează grupuri pentru câmpurile goale.
		$( '#mwd-as-suggest-groups' ).on( 'click', function () {
			var filled = 0;
			$list.find( '.mwd-as-group' ).each( function () {
				var $i = $( this );
				var s = $i.attr( 'data-mwd-suggest' );
				if ( ! $.trim( $i.val() ) && s ) {
					$i.val( s ).addClass( 'is-suggested' );
					filled++;
				}
			} );
			if ( filled ) { ownConfig(); markDirty(); }
			$( this ).find( '.mwd-as-btn-label' ).remove();
			$( this ).append( '<span class="mwd-as-btn-label"> · ' + ( filled ? filled + ' completate' : 'nimic nou' ) + '</span>' );
		} );

		// Copiază din alt rol: actualizează link-ul.
		var $copy = $( '#mwd-as-copy-go' );
		if ( $copy.length ) {
			var base = $copy.attr( 'href' );
			var syncCopy = function () {
				var u = new URL( base, window.location.href );
				u.searchParams.set( 'from', $( '#mwd-as-copy-from' ).val() );
				$copy.attr( 'href', u.toString() );
			};
			$( '#mwd-as-copy-from' ).on( 'change', syncCopy );
			syncCopy();
		}
		$wrap.on( 'click', '[data-confirm]', function ( e ) {
			if ( ! window.confirm( $( this ).attr( 'data-confirm' ) ) ) { e.preventDefault(); return; }
			dirty = false;
		} );

		// Iconițe grupuri: previzualizare.
		$wrap.on( 'input change', '.mwd-as-icon-input', function () {
			var cls = $.trim( $( this ).val() ).replace( /[^a-z0-9_-]/gi, '' );
			$( this ).siblings( '.mwd-as-icon-prev' ).attr( 'class', 'mwd-as-icon-prev dashicons ' + cls );
		} );

		/* ---------- Logo login din Media ---------- */
		var frame;
		function setLogo( url ) {
			$( '#login_logo_url' ).val( url ).trigger( 'change' );
			$( '#mwd-logo-prev' ).css( 'background-image', url ? 'url("' + url.replace( /"/g, '%22' ) + '")' : '' )
				.html( url ? '' : '<span class="dashicons dashicons-format-image"></span>' );
		}
		$( '#mwd-logo-pick' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! wp.media ) { return; }
			if ( ! frame ) {
				frame = wp.media( { title: 'Alege logo-ul', button: { text: 'Folosește logo-ul' }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					setLogo( a.url );
				} );
			}
			frame.open();
		} );
		$( '#mwd-logo-clear' ).on( 'click', function ( e ) { e.preventDefault(); setLogo( '' ); } );
		$( '#login_logo_url' ).on( 'change', function () {
			var v = $.trim( $( this ).val() );
			$( '#mwd-logo-prev' ).css( 'background-image', v ? 'url("' + v.replace( /"/g, '%22' ) + '")' : '' );
		} );

		/* ---------- Ctrl/⌘ + S salvează ---------- */
		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.metaKey || e.ctrlKey ) && ( e.key === 's' || e.key === 'S' ) ) {
				e.preventDefault();
				dirty = false;
				$form.trigger( 'submit' );
			}
		} );
	} );
})( jQuery );
