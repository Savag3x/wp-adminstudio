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
		return $classes . ' mwd-as-active';
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

		$vars = array(
			'--mwd-sidebar-bg'    => $this->color( $opts['sidebar_bg'] ),
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
	 * Sanitizeaza o culoare hex; revine la string gol daca e invalida.
	 */
	private function color( $value ) {
		$value = sanitize_hex_color( $value );
		return $value ? $value : 'inherit';
	}
}
