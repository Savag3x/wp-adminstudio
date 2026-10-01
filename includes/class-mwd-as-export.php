<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import configuratie (JSON) + export CSV pentru vizitatori si comenzi.
 */
class MWD_AS_Export {

	public function hooks() {
		add_action( 'admin_post_mwd_as_export_config', array( $this, 'export_config' ) );
		add_action( 'admin_post_mwd_as_import_config', array( $this, 'import_config' ) );
		add_action( 'admin_post_mwd_as_export_visitors', array( $this, 'export_visitors' ) );
		add_action( 'admin_post_mwd_as_export_orders', array( $this, 'export_orders' ) );
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( $action );
	}

	private function back( $msg ) {
		wp_safe_redirect( MWD_AS_Settings::url( array( 'mwd_msg' => $msg, 'tab' => 'config' ) ) );
		exit;
	}

	/**
	 * Descarca configuratia ca JSON.
	 */
	public function export_config() {
		$this->guard( 'mwd_as_export_config' );

		$opts = get_option( MWD_AS_OPTION, array() );
		$file = 'mwd-admin-studio-config-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $file );
		echo wp_json_encode( $opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Importa configuratia dintr-un fisier JSON.
	 */
	public function import_config() {
		$this->guard( 'mwd_as_import_config' );

		if ( empty( $_FILES['mwd_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['mwd_import']['tmp_name'] ) ) {
			$this->back( 'import_error' );
		}

		$raw  = file_get_contents( $_FILES['mwd_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$this->back( 'import_error' );
		}

		// Doar cheile cunoscute, completate cu valorile implicite, apoi aceeasi sanitizare ca la salvare
		// (un JSON modificat manual nu poate injecta HTML / valori invalide).
		$data = wp_parse_args( array_intersect_key( $data, MWD_AS_Defaults::defaults() ), MWD_AS_Defaults::defaults() );
		// Format vechi de meniu (fara roluri) -> 'default'.
		if ( ! empty( $data['menu'] ) && is_array( $data['menu'] ) ) {
			$first = reset( $data['menu'] );
			if ( is_array( $first ) && ( isset( $first['order'] ) || isset( $first['hidden'] ) || isset( $first['title'] ) ) ) {
				$data['menu'] = array( 'default' => $data['menu'] );
			}
		}
		update_option( MWD_AS_OPTION, MWD_AS_Settings::sanitize_options( $data ) );

		$this->back( 'imported' );
	}

	/**
	 * Export CSV vizitatori (max 50000 randuri din retentie).
	 */
	public function export_visitors() {
		$this->guard( 'mwd_as_export_visitors' );

		global $wpdb;
		$table = MWD_AS_Tracker::sessions_table();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=vizitatori-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Data', 'IP', 'Dispozitiv', 'Browser', 'OS', 'Tara', 'Oras', 'Sursa', 'Pagina intrare', 'Pagini', 'Durata(s)', 'Recurent' ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT started_at, ip, device, browser, os, country, city, referrer, entry_url, pageviews, duration, is_returning FROM {$table} ORDER BY started_at DESC LIMIT 50000", ARRAY_A );
			foreach ( $rows as $r ) {
				fputcsv( $out, $r );
			}
		}
		fclose( $out );
		exit;
	}

	/**
	 * Export CSV comenzi WooCommerce (max 5000, ultimele).
	 */
	public function export_orders() {
		$this->guard( 'mwd_as_export_orders' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=comenzi-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Numar', 'Data', 'Status', 'Total', 'Client', 'Email' ) );

		if ( MWD_AS_Woo::active() && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'limit' => 5000, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
			foreach ( $orders as $o ) {
				$created = $o->get_date_created();
				fputcsv(
					$out,
					array(
						$o->get_order_number(),
						$created ? $created->date( 'Y-m-d H:i' ) : '',
						wc_get_order_status_name( $o->get_status() ),
						$o->get_total(),
						trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
						$o->get_billing_email(),
					)
				);
			}
		}
		fclose( $out );
		exit;
	}
}
