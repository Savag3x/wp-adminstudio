<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incarca fontul, CSS-ul de baza si paleta dinamica (din setari) in admin.
 */
class MWD_AS_Styler {

	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 99 );
		add_action( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Adauga o clasa pe body ca sa putem incadra tot CSS-ul si sa evitam coliziuni.
	 */
	public function body_class( $classes ) {
		if ( ! $this->applies() ) {
			return $classes;
		}
		$opts    = MWD_AS_Defaults::get_options();
		$classes .= ' mwd-as-active';

		// Stilurile de continut (inputuri, linkuri, tabele) nu se aplica in editorul de blocuri,
		// care are propriul design system.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$block_editor = $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		if ( ! $block_editor ) {
			$classes .= ' mwd-as-ui';
			// Layout „canvas": continutul pe un panou rotunjit, incadrat de sidebar + bara de sus.
			if ( ! empty( $opts['layout_canvas'] ) ) {
				$classes .= ' mwd-as-canvas';
			}
		}
		// Finisaj glossy + tonul sidebar-ului (pentru reflexe potrivite pe fundal inchis / deschis).
		if ( ! isset( $opts['finish'] ) || 'flat' !== $opts['finish'] ) {
			$classes .= ' mwd-as-glossy';
		}
		$classes .= self::is_dark( $this->color( $opts['sidebar_bg'] ) ) ? ' mwd-sb-dark' : ' mwd-sb-light';
		if ( isset( $opts['density'] ) && 'compact' === $opts['density'] ) {
			$classes .= ' mwd-as-compact';
		}
		return $classes;
	}

	/**
	 * Skin-ul se aplica rolului utilizatorului curent?
	 */
	private function applies() {
		$role = MWD_AS_Defaults::user_role();
		return MWD_AS_Defaults::skin_applies_to( $role );
	}

	public function enqueue_admin() {
		if ( ! $this->applies() ) {
			return;
		}

		$opts = MWD_AS_Defaults::get_options();

		// 1) Font Google (daca nu e "system").
		$fonts = MWD_AS_Defaults::fonts();
		if ( isset( $fonts[ $opts['font'] ] ) && ! empty( $fonts[ $opts['font'] ][1] ) ) {
			$family = $fonts[ $opts['font'] ][1];
			wp_enqueue_style(
				'mwd-as-font',
				'https://fonts.googleapis.com/css2?family=' . $family . '&display=swap',
				array(),
				MWD_AS_VERSION
			);
		}

		// 2) CSS de baza (static, fara culori hardcodate – foloseste variabile).
		wp_enqueue_style(
			'mwd-as-admin',
			MWD_AS_URL . 'assets/css/admin.css',
			array(),
			MWD_AS_VERSION
		);

		// 3) Paleta dinamica din setari, injectata ca variabile CSS.
		wp_add_inline_style( 'mwd-as-admin', $this->dynamic_css( $opts ) );

		// 3b) Finisajul glossy: strat separat, incarcat dupa skin (si dupa dashboard / setari).
		if ( ! isset( $opts['finish'] ) || 'flat' !== $opts['finish'] ) {
			wp_enqueue_style( 'mwd-as-glossy', MWD_AS_URL . 'assets/css/glossy.css', array( 'mwd-as-admin' ), MWD_AS_VERSION );
		}

		// 4) „Shell": antetul workspace + cardul utilizatorului din sidebar.
		$user  = wp_get_current_user();
		$roles = MWD_AS_Defaults::roles();
		$role  = MWD_AS_Defaults::user_role( $user );
		$name  = get_bloginfo( 'name' );
		wp_enqueue_script( 'mwd-as-shell', MWD_AS_URL . 'assets/js/shell.js', array(), MWD_AS_VERSION, true );
		wp_localize_script(
			'mwd-as-shell',
			'MWDShell',
			array(
				'site'     => array(
					'name' => '' !== trim( $name ) ? $name : wp_parse_url( home_url(), PHP_URL_HOST ),
					'url'  => home_url( '/' ),
					'icon' => (string) get_site_icon_url( 64 ),
					'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				),
				'user'     => array(
					'name'   => $user->display_name,
					'role'   => isset( $roles[ $role ] ) ? $roles[ $role ] : '',
					'avatar' => (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ),
					'url'    => admin_url( 'profile.php' ),
				),
				'viewSite' => 'Vezi site-ul',
			)
		);
	}

	/**
	 * Construieste :root cu variabilele din optiuni.
	 * NB: nu generam reguli structurale aici – doar valori de tema.
	 */
	private function dynamic_css( $opts ) {
		$fonts = MWD_AS_Defaults::fonts();
		$font_stack = ( 'system' === $opts['font'] )
			? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'
			: '"' . esc_attr( $opts['font'] ) . '", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

		$radius = (int) $opts['radius'];

		$accent  = $this->color( $opts['accent'] );
		$sidebar = $this->color( $opts['sidebar_bg'] );

		$vars = array(
			'--mwd-sidebar-bg'    => $sidebar,
			'--mwd-sidebar-bg-2'  => $this->shade( $sidebar, -0.18 ),
			'--mwd-sidebar-line'  => self::is_dark( $sidebar ) ? 'rgba(255,255,255,.07)' : 'rgba(15,23,42,.08)',
			'--mwd-sidebar-hover' => self::is_dark( $sidebar ) ? 'rgba(255,255,255,.055)' : 'rgba(15,23,42,.045)',
			'--mwd-sidebar-active' => self::is_dark( $sidebar ) ? 'rgba(255,255,255,.09)' : 'rgba(15,23,42,.075)',
			'--mwd-sidebar-raised' => self::is_dark( $sidebar ) ? $this->shade( $sidebar, 0.07 ) : '#ffffff',
			'--mwd-sidebar-strong' => self::is_dark( $sidebar ) ? '#ffffff' : '#0f172a',
			'--mwd-accent-rgb'    => $this->rgb( $accent ),
			'--mwd-on-accent'     => self::is_dark( $accent ) ? '#ffffff' : '#0f172a',
			'--mwd-sidebar-text'  => $this->color( $opts['sidebar_text'] ),
			'--mwd-accent'        => $this->color( $opts['accent'] ),
			'--mwd-accent-hover'  => $this->color( $opts['accent_hover'] ),
			'--mwd-content-bg'    => $this->color( $opts['content_bg'] ),
			'--mwd-link'          => $this->color( $opts['link'] ),
			'--mwd-radius'        => $radius . 'px',
			'--mwd-radius-sm'     => max( 4, $radius - 4 ) . 'px',
			'--mwd-font'          => $font_stack,
		);

		$css = ':root{';
		// Tokens de design (fixe) pentru aspectul SaaS.
		$css .= '--mwd-surface:#ffffff;--mwd-text:#0f172a;--mwd-text-2:#475569;--mwd-muted:#64748b;'
			. '--mwd-border:rgba(15,23,42,.08);--mwd-border-strong:rgba(15,23,42,.14);'
			. '--mwd-shadow-sm:0 1px 2px rgba(15,23,42,.05);'
			. '--mwd-shadow:0 1px 2px rgba(15,23,42,.04),0 4px 16px -4px rgba(15,23,42,.08);'
			. '--mwd-shadow-lg:0 12px 40px -12px rgba(15,23,42,.25);'
			. '--mwd-ring:0 0 0 3px rgba(var(--mwd-accent-rgb),.22);';
		foreach ( $vars as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		$css .= '}';

		// Admin bar optional.
		if ( empty( $opts['style_adminbar'] ) ) {
			$css .= 'body.mwd-as-active #wpadminbar{background:initial;}';
		}

		return $css;
	}

	/**
	 * "r,g,b" dintr-o culoare hex (pentru rgba( var(--mwd-accent-rgb), .x )).
	 */
	private function rgb( $hex ) {
		$c = self::hex_parts( $hex );
		return $c ? implode( ',', $c ) : '16,185,129';
	}

	/**
	 * Lumineaza (+) / intuneca (-) o culoare hex cu un factor 0..1.
	 */
	private function shade( $hex, $f ) {
		$c = self::hex_parts( $hex );
		if ( ! $c ) {
			return $hex;
		}
		foreach ( $c as $i => $v ) {
			$c[ $i ] = (int) round( $f < 0 ? $v * ( 1 + $f ) : $v + ( 255 - $v ) * $f );
		}
		return sprintf( '#%02x%02x%02x', $c[0], $c[1], $c[2] );
	}

	private static function hex_parts( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Culoare "inchisa" (luminanta relativa sub prag) => text alb deasupra.
	 */
	public static function is_dark( $hex ) {
		$c = self::hex_parts( $hex );
		if ( ! $c ) {
			return true;
		}
		$l = ( 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2] ) / 255;
		return $l < 0.6;
	}

	/**
	 * Sanitizeaza o culoare hex; revine la string gol daca e invalida.
	 */
	private function color( $value ) {
		$value = sanitize_hex_color( $value );
		return $value ? $value : 'inherit';
	}
}
