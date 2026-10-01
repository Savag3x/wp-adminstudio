/* MWD Admin Studio — interactiuni pagina de setari */
(function ($) {
	'use strict';

	$(function () {
		// Color pickers
		$('.mwd-as-color').wpColorPicker();

		// Slider radius
		$('#radius').on('input', function () {
			$('#radius-val').text($(this).val());
		});

		// Adauga IP-ul curent la excluderi
		$('#mwd-add-ip').on('click', function ( e ) {
			e.preventDefault();
			var ip = ( $('#mwd-myip').text() || '' ).trim();
			if ( ! ip ) { return; }
			var ta = $('#exclude_ips'), val = ( ta.val() || '' ).trim();
			var has = val.split(/\r\n|\r|\n/).indexOf( ip ) !== -1;
			if ( ! has ) { ta.val( val ? val + '\n' + ip : ip ); }
		});

		// Preseturi de culori
		var presets = {
			mwd:   { sidebar_bg:'#0f2744', sidebar_text:'#c7d2e0', accent:'#10b981', accent_hover:'#0ea371', content_bg:'#f4f6fb', link:'#0f2744' },
			veedo: { sidebar_bg:'#16161a', sidebar_text:'#c9c9d1', accent:'#E8380D', accent_hover:'#c72f0a', content_bg:'#f6f6f8', link:'#16161a' },
			light: { sidebar_bg:'#1e293b', sidebar_text:'#cbd5e1', accent:'#2563eb', accent_hover:'#1d4ed8', content_bg:'#f8fafc', link:'#1e293b' },
			dark:  { sidebar_bg:'#0b0f17', sidebar_text:'#aab2c0', accent:'#6366f1', accent_hover:'#4f46e5', content_bg:'#1a2030', link:'#6366f1' }
		};
		$('.mwd-as-preset').on('click', function () {
			var p = presets[$(this).data('preset')];
			if (!p) { return; }
			$.each(p, function (id, val) {
				$('#' + id).val(val).wpColorPicker('color', val);
			});
		});

		// Lista meniu sortabila
		$('#mwd-as-menu-list').sortable({
			handle: '.mwd-as-drag',
			placeholder: 'mwd-as-placeholder',
			axis: 'y',
			forcePlaceholderSize: true
		});

		// Colapsare sub-pagini
		$(document).on('click', '.mwd-as-toggle-sub', function () {
			$(this).closest('.mwd-as-menu-item').toggleClass('is-collapsed');
		});
		$('#mwd-as-expand-all').on('click', function (e) {
			e.preventDefault();
			$('.mwd-as-menu-item.has-subs').removeClass('is-collapsed');
		});
		$('#mwd-as-collapse-all').on('click', function (e) {
			e.preventDefault();
			$('.mwd-as-menu-item.has-subs').addClass('is-collapsed');
		});

		// Sugerează grupuri (completează câmpurile goale cu propunerea automată)
		$('#mwd-as-suggest-groups').on('click', function (e) {
			e.preventDefault();
			var filled = 0;
			$('.mwd-as-group').each(function () {
				var $i = $(this);
				if ( ! $.trim( $i.val() ) ) {
					var s = $i.attr('data-mwd-suggest');
					if ( s ) { $i.val( s ); filled++; }
				}
			});
			$(this).text( filled ? 'Completat ' + filled + ' — verifică și salvează' : 'Nimic de sugerat' );
		});

		// ---- Tab-uri pentru pagina de setari ----
		buildTabs();
	});

	function buildTabs() {
		var $wrap = jQuery('.mwd-as-wrap');
		if ( ! $wrap.length ) { return; }
		var $mainForm = $wrap.find('form').first();
		if ( ! $mainForm.length ) { return; }

		var groups = [
			{ id: 'aspect',     label: 'Aspect',           match: ['General', 'Tipografie', 'Paleta de culori', 'Branding login'] },
			{ id: 'meniu',      label: 'Meniu & coloane',  match: ['Manager meniu', 'Manager coloane'] },
			{ id: 'analitice',  label: 'Analitice',        match: ['Excluderi analitice'] },
			{ id: 'whitelabel', label: 'White-label',      match: ['White-label'] },
			{ id: 'config',     label: 'Configurație',     match: ['Configurație', 'Importă configurația'] }
		];

		function tabFor( txt ) {
			for ( var i = 0; i < groups.length; i++ ) {
				for ( var j = 0; j < groups[i].match.length; j++ ) {
					if ( txt.indexOf( groups[i].match[j] ) !== -1 ) { return groups[i].id; }
				}
			}
			return null;
		}

		var present = {};
		var $cards = $wrap.find('.mwd-as-card');
		$cards.each(function () {
			var $h = jQuery(this).find('h2').first();
			if ( ! $h.length ) { return; }
			var id = tabFor( jQuery.trim( $h.text() ) );
			if ( ! id ) { return; }
			jQuery(this).attr('data-mwd-tab', id);
			present[id] = true;
		});

		var $nav = jQuery('<div class="mwd-as-tabnav"></div>');
		groups.forEach(function ( g ) {
			if ( ! present[g.id] ) { return; }
			$nav.append('<button type="button" class="mwd-as-tabbtn" data-tab="' + g.id + '">' + g.label + '</button>');
		});
		$mainForm.before( $nav );

		function activate( id ) {
			$nav.find('.mwd-as-tabbtn').each(function () {
				jQuery(this).toggleClass('is-active', jQuery(this).data('tab') === id );
			});
			$cards.each(function () {
				var t = jQuery(this).attr('data-mwd-tab');
				if ( ! t ) { return; }
				jQuery(this).css('display', t === id ? '' : 'none');
			});
			if ( window.history && history.replaceState ) {
				history.replaceState( null, '', '#tab-' + id );
			}
		}

		$nav.on('click', '.mwd-as-tabbtn', function () {
			activate( jQuery(this).data('tab') );
		});

		// Link-urile de rol păstrează tab-ul „Meniu" după reîncărcare.
		$wrap.find('.mwd-as-role-tab').each(function () {
			var href = jQuery(this).attr('href');
			if ( href ) { jQuery(this).attr('href', href.split('#')[0] + '#tab-meniu'); }
		});

		var initial = ( window.location.hash || '' ).replace('#tab-', '');
		if ( ! present[ initial ] ) {
			initial = groups.filter(function ( g ) { return present[g.id]; } )[0].id;
		}
		activate( initial );
	}
})(jQuery);
