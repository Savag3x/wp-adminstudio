<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * White-label: footer admin, ascundere versiune WP + logo, ascundere notificari pentru clienti.
 */
class MWD_AS_Branding {

	public function hooks() {
		$o = MWD_AS_Defaults::get_options();

		if ( '' !== trim( (string) $o['admin_footer_text'] ) ) {
			add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
		}
		if ( ! empty( $o['hide_wp_version'] ) ) {
			add_filter( 'update_footer', '__return_empty_string', 11 );
		}
		if ( ! empty( $o['hide_wp_logo'] ) ) {
			add_action( 'admin_bar_menu', array( $this, 'remove_logo' ), 999 );
		}
		if ( ! empty( $o['hide_notices'] ) ) {
			add_action( 'admin_print_scripts', array( $this, 'maybe_hide_notices' ), 1 );
		}
	}

	public function footer_text() {
		$o = MWD_AS_Defaults::get_options();
		return wp_kses( $o['admin_footer_text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array(), 'em' => array() ) );
	}

	public function remove_logo( $bar ) {
		$bar->remove_node( 'wp-logo' );
	}

	/**
	 * Ascunde notificarile admin pentru cei care NU pot administra (clientii).
	 * Adminii (manage_options) vad in continuare totul.
	 */
	public function maybe_hide_notices() {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		add_action( 'admin_head', array( $this, 'notice_css' ) );
	}

	public function notice_css() {
		echo '<style>.update-nag,.notice,.notice-warning,.notice-error,.notice-info,.update-message,div.error{display:none !important;}</style>';
	}
}
