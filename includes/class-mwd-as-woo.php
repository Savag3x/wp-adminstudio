<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Date WooCommerce pentru dashboard (defensiv pentru HPOS + clasic, cu cache 5 min).
 */
class MWD_AS_Woo {

	public static function active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Inregistreaza sursa (atribuire) pe comanda la creare.
	 */
	public function hooks() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'attribute' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attribute' ), 10, 1 );
	}

	public function attribute( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		if ( $order->get_meta( '_mwd_source' ) ) {
			return; // deja atribuit
		}

		$sid = isset( $_COOKIE['mwd_sid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['mwd_sid'] ) ) : '';
		$uid = isset( $_COOKIE['mwd_uid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['mwd_uid'] ) ) : '';

		$row      = $sid ? MWD_AS_Tracker::session_by_sid( $sid ) : null;
		$source   = MWD_AS_Tracker::source_label( $row );
		$referrer = $row && ! empty( $row->referrer ) ? $row->referrer : '';
		$landing  = $row && ! empty( $row->entry_url ) ? $row->entry_url : '';
		$campaign = $row && ! empty( $row->utm_campaign ) ? $row->utm_campaign : '';

		$order->update_meta_data( '_mwd_source', $source );
		$order->update_meta_data( '_mwd_referrer', $referrer );
		$order->update_meta_data( '_mwd_landing', $landing );
		$order->update_meta_data( '_mwd_campaign', $campaign );
		if ( $uid ) {
			$order->update_meta_data( '_mwd_uid', substr( preg_replace( '/[^a-f0-9]/', '', $uid ), 0, 32 ) );
		}
		$order->save();
	}

	/**
	 * Rezumat comenzi + vanzari pe $days zile.
	 */
	public static function summary( $days = 7 ) {
		if ( ! self::active() ) {
			return null;
		}
		$days = max( 1, (int) $days );

		$cached = get_transient( 'mwd_as_woo_summary_' . $days );
		if ( false !== $cached ) {
			return $cached;
		}

		$out = array(
			'to_process'   => self::count_status( array( 'processing', 'on-hold' ) ),
			'orders_today' => 0,
			'orders_period' => 0,
			'sales_today'  => 0.0,
			'sales_period' => 0.0,
			'sales_series' => array_fill( 0, $days, 0.0 ),
			'days'         => $days,
		);

		$paid = array( 'processing', 'completed', 'on-hold' );

		// Chei pentru ultimele $days zile (vechi -> nou).
		$keys  = array();
		$today = current_time( 'Y-m-d' );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$keys[] = gmdate( 'Y-m-d', strtotime( $today . " -{$i} days" ) );
		}
		$buckets = array_fill_keys( $keys, 0.0 );

		// O singura interogare pentru perioada, apoi grupare pe zi in PHP.
		$period = self::orders_between( strtotime( '-' . ( $days - 1 ) . ' days midnight' ), time(), $paid );
		$out['orders_period'] = count( $period );
		$attr = array();
		foreach ( $period as $o ) {
			$created = $o->get_date_created();
			if ( $created ) {
				$d = $created->date( 'Y-m-d' );
				if ( isset( $buckets[ $d ] ) ) {
					$buckets[ $d ] += (float) $o->get_total();
				}
				if ( $d === $today ) {
					$out['orders_today']++;
				}
			}
			// Atribuire pe sursa.
			$src = $o->get_meta( '_mwd_source' );
			$src = '' !== $src ? $src : 'Necunoscut';
			if ( ! isset( $attr[ $src ] ) ) {
				$attr[ $src ] = array( 'orders' => 0, 'revenue' => 0.0 );
			}
			$attr[ $src ]['orders']++;
			$attr[ $src ]['revenue'] += (float) $o->get_total();
		}

		uasort( $attr, function ( $a, $b ) {
			return $b['revenue'] <=> $a['revenue'];
		} );
		$attribution = array();
		foreach ( array_slice( $attr, 0, 5, true ) as $src => $data ) {
			$attribution[] = array( 'source' => $src, 'orders' => $data['orders'], 'revenue' => $data['revenue'] );
		}
		$out['attribution'] = $attribution;
		$out['sales_today']  = $buckets[ $today ];
		$out['sales_series'] = array_values( $buckets );
		$out['sales_period'] = array_sum( $buckets );

		// Perioada anterioara (aceeasi lungime, imediat inainte).
		$cur_from  = strtotime( '-' . ( $days - 1 ) . ' days midnight' );
		$prev_from = strtotime( '-' . ( 2 * $days - 1 ) . ' days midnight' );
		$prev_to   = $cur_from - 1;
		$prev      = self::orders_between( $prev_from, $prev_to, $paid );
		$prev_sales = 0.0;
		foreach ( $prev as $o ) {
			$prev_sales += (float) $o->get_total();
		}
		$out['prev'] = array(
			'sales_period'  => $prev_sales,
			'orders_period' => count( $prev ),
		);

		set_transient( 'mwd_as_woo_summary_' . $days, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Numar comenzi pe statusuri (defensiv).
	 */
	private static function count_status( $statuses ) {
		$total = 0;
		if ( function_exists( 'wc_orders_count' ) ) {
			foreach ( $statuses as $st ) {
				$total += (int) wc_orders_count( $st );
			}
			return $total;
		}
		$counts = wp_count_posts( 'shop_order' );
		foreach ( $statuses as $st ) {
			$key = 'wc-' . $st;
			if ( isset( $counts->$key ) ) {
				$total += (int) $counts->$key;
			}
		}
		return $total;
	}

	/**
	 * Comenzi intre doua momente (limitat la 500 pentru siguranta).
	 */
	private static function orders_between( $from, $to, $statuses ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		return wc_get_orders(
			array(
				'limit'        => 500,
				'status'       => $statuses,
				'date_created' => $from . '...' . $to,
				'return'       => 'objects',
			)
		);
	}

	/**
	 * Produse cu stoc redus (max 5).
	 */
	public static function low_stock() {
		if ( ! self::active() ) {
			return array();
		}
		$cached = get_transient( 'mwd_as_woo_lowstock' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS stock
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_stock'
				   AND pm.meta_value <> ''
				   AND CAST(pm.meta_value AS SIGNED) <= %d
				   AND p.post_status = 'publish'
				 ORDER BY CAST(pm.meta_value AS SIGNED) ASC
				 LIMIT 5",
				$threshold
			)
		);

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'name'  => get_the_title( $r->post_id ),
				'stock' => (int) $r->stock,
				'url'   => get_edit_post_link( $r->post_id, '' ),
			);
		}

		set_transient( 'mwd_as_woo_lowstock', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Cele mai vandute produse (max 3, dupa total_sales).
	 */
	public static function best_sellers() {
		if ( ! self::active() ) {
			return array();
		}
		$cached = get_transient( 'mwd_as_woo_best' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT p.ID, CAST(pm.meta_value AS SIGNED) AS sales
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'total_sales'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			 ORDER BY sales DESC
			 LIMIT 3"
		);

		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r->sales <= 0 ) {
				continue;
			}
			$out[] = array(
				'name'  => get_the_title( $r->ID ),
				'sales' => (int) $r->sales,
				'url'   => get_edit_post_link( $r->ID, '' ),
			);
		}

		set_transient( 'mwd_as_woo_best', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Ultimele comenzi (max 5).
	 */
	public static function recent_orders() {
		if ( ! self::active() || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$cached = get_transient( 'mwd_as_woo_recent' );
		if ( false !== $cached ) {
			return $cached;
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 5,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);

		$out = array();
		foreach ( $orders as $o ) {
			$name = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
			if ( '' === $name ) {
				$name = $o->get_billing_company() ? $o->get_billing_company() : 'Client';
			}
			$out[] = array(
				'number'     => $o->get_order_number(),
				'name'       => $name,
				'status'     => wc_get_order_status_name( $o->get_status() ),
				'status_key' => $o->get_status(),
				'total'      => html_entity_decode( wp_strip_all_tags( self::price( $o->get_total() ) ), ENT_QUOTES, 'UTF-8' ),
				'url'        => method_exists( $o, 'get_edit_order_url' ) ? $o->get_edit_order_url() : admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ),
			);
		}

		set_transient( 'mwd_as_woo_recent', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function price( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $amount );
		}
		return number_format_i18n( $amount, 2 );
	}
}