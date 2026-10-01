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

	/* =====================================================================
	 * Cache: invalidare la evenimente (comanda noua, status, stoc)
	 * ===================================================================== */

	public static function cache_hooks() {
		foreach ( array( 'woocommerce_new_order', 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_delete_order', 'woocommerce_trash_order' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_orders' ) );
		}
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_stock' ) );
		}
	}

	public static function flush_orders() {
		foreach ( array( 7, 14, 30 ) as $d ) {
			delete_transient( 'mwd_as_woo_sum2_' . $d );
		}
		delete_transient( 'mwd_as_woo_recent' );
		delete_transient( 'mwd_as_woo_best' );
	}

	public static function flush_stock() {
		delete_transient( 'mwd_as_woo_lowstock' );
	}

	/* =====================================================================
	 * Sursa de date pentru comenzi (SQL direct, fara a incarca obiecte WC_Order)
	 * ===================================================================== */

	/**
	 * HPOS (tabele custom de comenzi) activ?
	 */
	public static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private static function table_exists( $table ) {
		global $wpdb;
		static $seen = array();
		if ( ! isset( $seen[ $table ] ) ) {
			$seen[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return $seen[ $table ];
	}

	/**
	 * Descrierea sursei de comenzi, in ordinea preferintei:
	 *  1) wc_order_stats (tabela de analitice WooCommerce, indexata pe data, fara meta)
	 *  2) wc_orders (HPOS)
	 *  3) wp_posts + _order_total (stocare clasica)
	 * Toate intorc aceleasi coloane logice: id, data locala, total, status.
	 */
	public static function source() {
		global $wpdb;
		$hpos  = self::hpos();
		$meta  = $hpos ? array( $wpdb->prefix . 'wc_orders_meta', 'order_id' ) : array( $wpdb->postmeta, 'post_id' );
		$stats = $wpdb->prefix . 'wc_order_stats';

		if ( 'no' !== get_option( 'woocommerce_analytics_enabled', 'yes' ) && self::table_exists( $stats ) ) {
			return array(
				'from'   => "{$stats} o",
				'id'     => 'o.order_id',
				'date'   => 'o.date_created',
				'total'  => 'o.total_sales',
				'status' => 'o.status',
				'where'  => 'o.parent_id = 0',
				'meta'   => $meta,
				'gmt'    => false,
			);
		}
		if ( $hpos ) {
			return array(
				'from'   => "{$wpdb->prefix}wc_orders o",
				'id'     => 'o.id',
				'date'   => 'o.date_created_gmt',
				'total'  => 'o.total_amount',
				'status' => 'o.status',
				'where'  => "o.type = 'shop_order'",
				'meta'   => $meta,
				'gmt'    => true,
			);
		}
		return array(
			'from'   => "{$wpdb->posts} o INNER JOIN {$wpdb->postmeta} t ON t.post_id = o.ID AND t.meta_key = '_order_total'",
			'id'     => 'o.ID',
			'date'   => 'o.post_date',
			'total'  => 't.meta_value',
			'status' => 'o.post_status',
			'where'  => "o.post_type = 'shop_order'",
			'meta'   => $meta,
			'gmt'    => false,
		);
	}

	/**
	 * Data locala (Y-m-d H:i:s) convertita in formatul coloanei sursei.
	 */
	private static function bound( $src, $local ) {
		return $src['gmt'] ? get_gmt_from_date( $local ) : $local;
	}

	/**
	 * Fragment SQL "status IN (...)" pentru statusurile platite (cu prefixul wc-).
	 */
	private static function status_in( $src, $statuses ) {
		$list = array();
		foreach ( $statuses as $st ) {
			$list[] = "'" . esc_sql( 'wc-' . $st ) . "'";
		}
		return $src['status'] . ' IN (' . implode( ',', $list ) . ')';
	}

	/**
	 * Comenzi + vanzari grupate pe zi (locala) intre doua date locale.
	 * Grupam pe ora in SQL si mutam in zi locala in PHP: functioneaza identic pentru coloane GMT si locale.
	 *
	 * @return array 'Y-m-d' => array( 'orders' => int, 'sales' => float )
	 */
	public static function daily( $from_local, $to_local, $statuses ) {
		global $wpdb;
		$src = self::source();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT({$src['date']}, '%%Y-%%m-%%d %%H:00:00') h, COUNT(*) c, SUM({$src['total']} + 0) s
				 FROM {$src['from']}
				 WHERE {$src['where']} AND " . self::status_in( $src, $statuses ) . " AND {$src['date']} >= %s AND {$src['date']} <= %s
				 GROUP BY h",
				self::bound( $src, $from_local ),
				self::bound( $src, $to_local )
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$day = $src['gmt'] ? get_date_from_gmt( $r->h, 'Y-m-d' ) : substr( $r->h, 0, 10 );
			if ( ! isset( $out[ $day ] ) ) {
				$out[ $day ] = array( 'orders' => 0, 'sales' => 0.0 );
			}
			$out[ $day ]['orders'] += (int) $r->c;
			$out[ $day ]['sales']  += (float) $r->s;
		}
		return $out;
	}

	/**
	 * Venit + comenzi pe sursa de trafic (meta _mwd_source) intr-un interval.
	 */
	public static function attribution( $from_local, $statuses, $limit = 5 ) {
		global $wpdb;
		$src = self::source();
		list( $mt, $mcol ) = $src['meta'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(m.meta_value, ''), 'Necunoscut') src, COUNT(*) c, SUM({$src['total']} + 0) s
				 FROM {$src['from']}
				 LEFT JOIN {$mt} m ON m.{$mcol} = {$src['id']} AND m.meta_key = '_mwd_source'
				 WHERE {$src['where']} AND " . self::status_in( $src, $statuses ) . " AND {$src['date']} >= %s
				 GROUP BY src ORDER BY s DESC LIMIT %d",
				self::bound( $src, $from_local ),
				(int) $limit
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'source' => $r->src, 'orders' => (int) $r->c, 'revenue' => (float) $r->s );
		}
		return $out;
	}

	/**
	 * Rezumat comenzi + vanzari pe $days zile (+ perioada anterioara, pentru variatie).
	 * Exact (fara plafonul de 500 de comenzi din versiunile vechi) si fara a incarca obiecte WC_Order.
	 */
	public static function summary( $days = 7 ) {
		if ( ! self::active() ) {
			return null;
		}
		$days = max( 1, (int) $days );

		$cached = get_transient( 'mwd_as_woo_sum2_' . $days );
		if ( false !== $cached ) {
			return $cached;
		}

		$paid  = array( 'processing', 'completed', 'on-hold' );
		$today = current_time( 'Y-m-d' );
		$now   = current_time( 'mysql' );
		$cur_from  = gmdate( 'Y-m-d 00:00:00', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		$prev_from = gmdate( 'Y-m-d 00:00:00', strtotime( $today . ' -' . ( 2 * $days - 1 ) . ' days' ) );

		$by_day = self::daily( $prev_from, $now, $paid );

		$sales = array();
		$count = array();
		$dates = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d       = gmdate( 'Y-m-d', strtotime( $today . " -{$i} days" ) );
			$dates[] = $d;
			$sales[] = isset( $by_day[ $d ] ) ? round( $by_day[ $d ]['sales'], 2 ) : 0.0;
			$count[] = isset( $by_day[ $d ] ) ? $by_day[ $d ]['orders'] : 0;
		}
		$prev_sales  = 0.0;
		$prev_orders = 0;
		foreach ( $by_day as $d => $v ) {
			if ( $d < substr( $cur_from, 0, 10 ) ) {
				$prev_sales  += $v['sales'];
				$prev_orders += $v['orders'];
			}
		}

		$out = array(
			'to_process'    => self::count_status( array( 'processing', 'on-hold' ) ),
			'orders_today'  => isset( $by_day[ $today ] ) ? $by_day[ $today ]['orders'] : 0,
			'sales_today'   => isset( $by_day[ $today ] ) ? $by_day[ $today ]['sales'] : 0.0,
			'orders_period' => array_sum( $count ),
			'sales_period'  => array_sum( $sales ),
			'sales_series'  => $sales,
			'orders_series' => $count,
			'dates'         => $dates,
			'days'          => $days,
			'attribution'   => self::attribution( $cur_from, $paid ),
			'prev'          => array(
				'sales_period'  => $prev_sales,
				'orders_period' => $prev_orders,
			),
		);
		$out['aov']         = $out['orders_period'] > 0 ? $out['sales_period'] / $out['orders_period'] : 0.0;
		$out['prev']['aov'] = $prev_orders > 0 ? $prev_sales / $prev_orders : 0.0;

		set_transient( 'mwd_as_woo_sum2_' . $days, $out, 5 * MINUTE_IN_SECONDS );
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
				 INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = pm.post_id AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				 WHERE pm.meta_key = '_stock'
				   AND pm.meta_value <> ''
				   AND CAST(pm.meta_value AS SIGNED) <= %d
				   AND p.post_type IN ('product', 'product_variation')
				   AND p.post_status = 'publish'
				 ORDER BY CAST(pm.meta_value AS SIGNED) ASC
				 LIMIT 5",
				$threshold
			)
		);

		$out = array();
		foreach ( $rows as $r ) {
			// Variatiile nu au ecran propriu de editare: link catre produsul parinte.
			$parent = (int) wp_get_post_parent_id( $r->post_id );
			$out[]  = array(
				'name'  => get_the_title( $r->post_id ),
				'stock' => (int) $r->stock,
				'url'   => get_edit_post_link( $parent ? $parent : $r->post_id, '' ),
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