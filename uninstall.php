<?php
/**
 * Dezinstalare MWD Admin Studio.
 * Datele se sterg DOAR daca utilizatorul a activat optiunea „Sterge toate datele la dezinstalare"
 * (o reinstalare nu trebuie sa piarda istoricul de analitice din greseala).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mwd_as_opts = get_option( 'mwd_as_settings', array() );
if ( empty( $mwd_as_opts['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mwd_as_sessions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mwd_as_views" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mwd\_as\_%' OR option_name LIKE '\_transient\_timeout\_mwd\_as\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'mwd_as_period'" );
// phpcs:enable

delete_option( 'mwd_as_settings' );
delete_option( 'mwd_as_columns_cache' );
delete_option( 'mwd_as_db_version' );
wp_clear_scheduled_hook( 'mwd_as_cleanup' );
