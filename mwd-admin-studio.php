<?php
/**
 * Plugin Name:       MWD Admin Studio
 * Plugin URI:        https://mywebdesign.ro/
 * Description:       Reskin SaaS complet al panoului de administrare WordPress: design modern, paleta de comenzi (⌘K), meniu organizat pe sectiuni, manager de meniu/coloane pe roluri, login restilizat, dashboard si analitice.
 * Version:           2.2.0
 * Author:            Alexandru Raileanu / MyWebDesign.ro
 * Author URI:        https://mywebdesign.ro/
 * Text Domain:       mwd-admin-studio
 * Domain Path:       /languages
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 *
 * REGULA CRITICA DE STIL (nu o incalca niciodata):
 * Aplica DOAR culori, fonturi si border-radius. Nu seta NICIODATA proprietati
 * structurale (overflow / position / z-index) pe #adminmenuwrap sau #adminmenuback,
 * pentru ca strica scroll-ul sidebar-ului si flyout-urile native ale submeniurilor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MWD_AS_VERSION', '2.2.0' );
define( 'MWD_AS_FILE', __FILE__ );
define( 'MWD_AS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWD_AS_URL', plugin_dir_url( __FILE__ ) );
define( 'MWD_AS_OPTION', 'mwd_as_settings' );

require_once MWD_AS_DIR . 'includes/class-mwd-as-defaults.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-settings.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-styler.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-menu.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-login.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-dashboard.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-tracker.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-woo.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-branding.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-guard.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-columns.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-menucollapse.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-export.php';
require_once MWD_AS_DIR . 'includes/class-mwd-as-palette.php';

/**
 * Bootstrap.
 */
function mwd_as_init() {
	$settings = new MWD_AS_Settings();
	$settings->hooks();

	$options = MWD_AS_Defaults::get_options();

	if ( ! empty( $options['enabled'] ) ) {
		( new MWD_AS_Styler() )->hooks();

		if ( ! empty( $options['style_login'] ) ) {
			( new MWD_AS_Login() )->hooks();
		}

		if ( ! empty( $options['custom_dashboard'] ) ) {
			( new MWD_AS_Dashboard() )->hooks();
		}

		if ( ! empty( $options['track_visitors'] ) ) {
			( new MWD_AS_Tracker() )->hooks();
			if ( MWD_AS_Woo::active() ) {
				( new MWD_AS_Woo() )->hooks();
			}
		}
	}

	// Module independente de skin (se auto-controleaza prin optiunile lor).
	// Managerul de meniu ruleaza mereu: ascunderea paginilor nu trebuie sa depinda de skin-ul vizual
	// (altfel paginile "blocate" de Guard ar redeveni vizibile cand skin-ul e oprit).
	( new MWD_AS_Menu() )->hooks();
	( new MWD_AS_Branding() )->hooks();

	// Datele WooCommerce din dashboard se reimprospateaza imediat la comenzi / stoc noi.
	if ( MWD_AS_Woo::active() ) {
		MWD_AS_Woo::cache_hooks();
	}
	( new MWD_AS_Columns() )->hooks();
	( new MWD_AS_Export() )->hooks();

	if ( ! empty( $options['collapse_menu'] ) ) {
		( new MWD_AS_MenuCollapse() )->hooks();
	}

	if ( ! empty( $options['cmd_palette'] ) ) {
		( new MWD_AS_Palette() )->hooks();
	}

	if ( ! empty( $options['block_access'] ) ) {
		( new MWD_AS_Guard() )->hooks();
	}
}
add_action( 'plugins_loaded', 'mwd_as_init' );

// Cache-ul de optiuni se invalideaza la orice scriere a optiunii.
add_action( 'add_option_' . MWD_AS_OPTION, array( 'MWD_AS_Defaults', 'flush' ) );
add_action( 'update_option_' . MWD_AS_OPTION, array( 'MWD_AS_Defaults', 'flush' ) );
add_action( 'delete_option_' . MWD_AS_OPTION, array( 'MWD_AS_Defaults', 'flush' ) );

/**
 * Creeaza/actualizeaza tabela tracker-ului cand se schimba versiunea (la update fara reactivare).
 */
function mwd_as_maybe_upgrade() {
	if ( get_option( 'mwd_as_db_version' ) !== MWD_AS_Tracker::DB_VERSION ) {
		MWD_AS_Tracker::install();
	}
}
add_action( 'admin_init', 'mwd_as_maybe_upgrade' );

/**
 * La activare: optiuni implicite + tabela tracker + cron de curatare.
 */
function mwd_as_activate() {
	if ( false === get_option( MWD_AS_OPTION ) ) {
		add_option( MWD_AS_OPTION, MWD_AS_Defaults::defaults() );
	}
	MWD_AS_Tracker::install();
	if ( ! wp_next_scheduled( 'mwd_as_cleanup' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mwd_as_cleanup' );
	}
}
register_activation_hook( __FILE__, 'mwd_as_activate' );

/**
 * La dezactivare: opreste cron-ul de curatare.
 */
function mwd_as_deactivate() {
	$ts = wp_next_scheduled( 'mwd_as_cleanup' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'mwd_as_cleanup' );
	}
}
register_deactivation_hook( __FILE__, 'mwd_as_deactivate' );
