<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restilizeaza pagina wp-login.php in stilul setat.
 */
class MWD_AS_Login {

	public function hooks() {
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ), 99 );
		add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'logo_text' ) );
		add_filter( 'login_body_class', array( $this, 'body_class' ) );
		add_filter( 'login_message', array( $this, 'heading' ) );
	}

	/**
	 * Titlu deasupra formularului (doar pe ecranul de autentificare, fara alt mesaj).
	 */
	public function heading( $message ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( 'login' !== $action || '' !== trim( (string) $message ) ) {
			return $message;
		}
		return '<div class="mwd-login-head"><h2>Bine ai revenit</h2><p>' . esc_html( sprintf( 'Autentifică-te în %s', get_bloginfo( 'name' ) ) ) . '</p></div>';
	}

	public function body_class( $classes ) {
		$opts      = MWD_AS_Defaults::get_options();
		$classes[] = 'mwd-login';
		$classes[] = 'split' === $opts['login_layout'] ? 'mwd-login-split' : 'mwd-login-center';
		return $classes;
	}

	/**
	 * Text sigur pentru `content:` in CSS (string intre ghilimele).
	 */
	private function css_string( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( '\\', '"', "\r", "\n", '<', '>' ), array( '\\\\', '\\"', ' ', ' ', '', '' ), $text );
		return '"' . $text . '"';
	}

	public function logo_url() {
		return home_url( '/' );
	}

	public function logo_text() {
		return get_bloginfo( 'name' );
	}

	public function enqueue() {
		$opts  = MWD_AS_Defaults::get_options();
		$fonts = MWD_AS_Defaults::fonts();

		if ( isset( $fonts[ $opts['font'] ] ) && ! empty( $fonts[ $opts['font'] ][1] ) ) {
			wp_enqueue_style(
				'mwd-as-login-font',
				'https://fonts.googleapis.com/css2?family=' . $fonts[ $opts['font'] ][1] . '&display=swap',
				array(),
				MWD_AS_VERSION
			);
		}

		wp_enqueue_style(
			'mwd-as-login',
			MWD_AS_URL . 'assets/css/login.css',
			array(),
			MWD_AS_VERSION
		);

		$font_stack = ( 'system' === $opts['font'] )
			? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
			: '"' . esc_attr( $opts['font'] ) . '", -apple-system, BlinkMacSystemFont, sans-serif';

		$accent  = sanitize_hex_color( $opts['accent'] ) ?: '#10b981';
		$hover   = sanitize_hex_color( $opts['accent_hover'] ) ?: '#0ea371';
		$bg      = sanitize_hex_color( $opts['login_bg'] ) ?: '#0f2744';
		$radius  = (int) $opts['radius'];
		$logo    = esc_url_raw( $opts['login_logo_url'] );

		$css  = ':root{';
		$css .= '--mwd-accent:' . $accent . ';';
		$css .= '--mwd-accent-hover:' . $hover . ';';
		$css .= '--mwd-login-bg:' . $bg . ';';
		$css .= '--mwd-radius:' . $radius . 'px;';
		$css .= '--mwd-font:' . $font_stack . ';';
		$css .= '--mwd-accent-rgb:' . $this->rgb( $accent ) . ';';
		$tagline = '' !== trim( (string) $opts['login_tagline'] ) ? $opts['login_tagline'] : get_bloginfo( 'description' );
		$css .= '--mwd-login-title:' . $this->css_string( get_bloginfo( 'name' ) ) . ';';
		$css .= '--mwd-login-tagline:' . $this->css_string( $tagline ) . ';';
		$css .= '}';

		if ( $logo ) {
			$css .= '.login h1 a{background-image:url("' . str_replace( array( '"', '\\', '<', '>', ')' ), array( '%22', '%5C', '%3C', '%3E', '%29' ), $logo ) . '") !important;background-size:contain !important;background-position:center !important;width:100% !important;height:64px !important;}';
		}

		wp_add_inline_style( 'mwd-as-login', $css );
	}

	private function rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}
}
