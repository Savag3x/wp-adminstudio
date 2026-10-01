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
		$css .= '}';

		if ( $logo ) {
			$css .= '.login h1 a{background-image:url(' . $logo . ') !important;background-size:contain !important;width:100% !important;height:64px !important;}';
		}

		wp_add_inline_style( 'mwd-as-login', $css );
	}
}
