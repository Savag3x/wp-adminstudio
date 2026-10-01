<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Motor de analitice integrat:
 * - sesiuni reale (cookie de sesiune 30 min + cookie vizitator 1 an pentru recurenti)
 * - afisari de pagina + timp pe pagina / pe site (via beacon JS, REST, fara admin-ajax)
 * - device / browser / OS din User-Agent, IP (cu anonimizare optionala), tara (Cloudflare / ip-api optional)
 * - exclude staff-ul si botii (botii oricum nu ruleaza JS)
 * - curatare automata la RETENTION_DAYS
 */
class MWD_AS_Tracker {

	const DB_VERSION    = '3';
	const RETENTION_DAYS = 120;
	const NS            = 'mwd-analytics/v1';

	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'mwd_as_sessions';
	}
	public static function views_table() {
		global $wpdb;
		return $wpdb->prefix . 'mwd_as_views';
	}
	// Compat: folosit de export.
	public static function table() {
		return self::sessions_table();
	}

	/**
	 * Exista tabela de sesiuni? (o singura interogare SHOW TABLES pe cerere, nu una per functie)
	 */
	public static function ready() {
		static $ready = null;
		if ( null === $ready ) {
			global $wpdb;
			$st    = self::sessions_table();
			$ready = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $st ) ) === $st );
		}
		return $ready;
	}

	/**
	 * Cate zile se pastreaza datele (setare, 30..730).
	 */
	public static function retention_days() {
		$o = MWD_AS_Defaults::get_options();
		$d = isset( $o['retention_days'] ) ? (int) $o['retention_days'] : self::RETENTION_DAYS;
		return max( 30, min( 730, $d ) );
	}

	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$s = self::sessions_table();
		$v = self::views_table();

		$sql1 = "CREATE TABLE {$s} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uid CHAR(32) NOT NULL DEFAULT '',
			sid CHAR(32) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			device VARCHAR(10) NOT NULL DEFAULT 'desktop',
			browser VARCHAR(40) NOT NULL DEFAULT '',
			os VARCHAR(40) NOT NULL DEFAULT '',
			country CHAR(2) NOT NULL DEFAULT '',
			city VARCHAR(64) NOT NULL DEFAULT '',
			referrer VARCHAR(255) NOT NULL DEFAULT '',
			utm_source VARCHAR(100) NOT NULL DEFAULT '',
			utm_medium VARCHAR(100) NOT NULL DEFAULT '',
			utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
			entry_url VARCHAR(255) NOT NULL DEFAULT '',
			exit_url VARCHAR(255) NOT NULL DEFAULT '',
			pageviews INT UNSIGNED NOT NULL DEFAULT 1,
			duration INT UNSIGNED NOT NULL DEFAULT 0,
			is_returning TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY sid (sid),
			KEY uid (uid),
			KEY started_at (started_at)
		) {$charset};";

		$sql2 = "CREATE TABLE {$v} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sid CHAR(32) NOT NULL DEFAULT '',
			uid CHAR(32) NOT NULL DEFAULT '',
			url VARCHAR(255) NOT NULL DEFAULT '',
			title VARCHAR(190) NOT NULL DEFAULT '',
			duration INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY sid (sid),
			KEY url (url),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		update_option( 'mwd_as_db_version', self::DB_VERSION );
	}

	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'mwd_as_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'admin_menu', array( $this, 'admin_page' ), 20 );
	}

	/* ============ Front-end beacon ============ */

	private function should_track() {
		if ( is_admin() ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false; // staff
		}
		if ( self::is_excluded_ip( self::current_ip() ) ) {
			return false;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( self::is_excluded_path( (string) $path ) ) {
			return false;
		}
		return true;
	}

	public function enqueue_beacon() {
		if ( ! $this->should_track() ) {
			return;
		}
		wp_enqueue_script( 'mwd-as-track', MWD_AS_URL . 'assets/js/track.js', array(), MWD_AS_VERSION, true );
		wp_localize_script(
			'mwd-as-track',
			'MWDAnalytics',
			array(
				'hit'  => esc_url_raw( rest_url( self::NS . '/hit' ) ),
				'beat' => esc_url_raw( rest_url( self::NS . '/beat' ) ),
			)
		);
	}

	/* ============ REST ============ */

	public function routes() {
		register_rest_route( self::NS, '/hit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_hit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/beat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_beat' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/online', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_online' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	private function same_origin() {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$host = wp_parse_url( (string) $_SERVER[ $h ], PHP_URL_HOST );
				if ( $host && $host === $home ) {
					return true;
				}
				if ( $host && $host !== $home ) {
					return false;
				}
			}
		}
		return true; // lipsa header -> permitem (unele browsere)
	}

	public function rest_hit( $request ) {
		if ( ! $this->same_origin() || $this->is_bot() ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		$p   = $request->get_json_params();
		$uid = isset( $p['uid'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', (string) $p['uid'] ), 0, 32 ) : '';
		$sid = isset( $p['sid'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', (string) $p['sid'] ), 0, 32 ) : '';
		if ( '' === $uid || '' === $sid ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$new   = ! empty( $p['n'] );
		$url   = isset( $p['u'] ) ? esc_url_raw( (string) $p['u'] ) : '/';
		$url   = substr( wp_parse_url( $url, PHP_URL_PATH ) ? wp_parse_url( $url, PHP_URL_PATH ) : '/', 0, 255 );
		$title = isset( $p['t'] ) ? substr( sanitize_text_field( (string) $p['t'] ), 0, 190 ) : '';
		$ref   = isset( $p['r'] ) ? $this->referrer_host( (string) $p['r'] ) : '';

		// Excluderi (IP / cale).
		$raw_ip = $this->ip();
		if ( self::is_excluded_ip( $raw_ip ) || self::is_excluded_path( $url ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$st  = self::sessions_table();
		$vt  = self::views_table();

		// Sesiune existenta?
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$session = $wpdb->get_row( $wpdb->prepare( "SELECT id, pageviews FROM {$st} WHERE sid = %s", $sid ) );

		if ( $session && ! $new ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$st} SET pageviews = pageviews + 1, exit_url = %s, updated_at = %s WHERE id = %d", $url, $now, $session->id ) );
		} else {
			$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
			$ip  = $raw_ip;
			$geo = $this->geo( $ip );

			// Recurent? (uid vazut inainte)
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$returning = (int) ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE uid = %s LIMIT 1", $uid ) ) );

			$wpdb->insert(
				$st,
				array(
					'uid'          => $uid,
					'sid'          => $sid,
					'ip'           => $this->mask_ip( $ip ),
					'device'       => $this->device( $ua ),
					'browser'      => $this->browser( $ua ),
					'os'           => $this->os( $ua ),
					'country'      => $geo['country'],
					'city'         => $geo['city'],
					'referrer'     => $ref,
					'utm_source'   => isset( $p['us'] ) ? substr( sanitize_text_field( (string) $p['us'] ), 0, 100 ) : '',
					'utm_medium'   => isset( $p['um'] ) ? substr( sanitize_text_field( (string) $p['um'] ), 0, 100 ) : '',
					'utm_campaign' => isset( $p['uc'] ) ? substr( sanitize_text_field( (string) $p['uc'] ), 0, 100 ) : '',
					'entry_url'    => $url,
					'exit_url'     => $url,
					'pageviews'    => 1,
					'duration'     => 0,
					'is_returning' => $returning,
					'started_at'   => $now,
					'updated_at'   => $now,
				)
			);
		}

		$wpdb->insert(
			$vt,
			array(
				'sid'        => $sid,
				'uid'        => $uid,
				'url'        => $url,
				'title'      => $title,
				'duration'   => 0,
				'created_at' => $now,
			)
		);

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function rest_beat( $request ) {
		if ( ! $this->same_origin() ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$p   = $request->get_json_params();
		$sid = isset( $p['sid'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', (string) $p['sid'] ), 0, 32 ) : '';
		$d   = isset( $p['d'] ) ? max( 0, min( 3600, (int) $p['d'] ) ) : 0;
		$url = isset( $p['u'] ) ? substr( wp_parse_url( esc_url_raw( (string) $p['u'] ), PHP_URL_PATH ) ? wp_parse_url( esc_url_raw( (string) $p['u'] ), PHP_URL_PATH ) : '/', 0, 255 ) : '';
		if ( '' === $sid || $d <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		global $wpdb;
		$st = self::sessions_table();
		$vt = self::views_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$st} SET duration = duration + %d, updated_at = %s WHERE sid = %s", $d, current_time( 'mysql' ), $sid ) );
		if ( '' !== $url ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$vt} SET duration = duration + %d WHERE sid = %s AND url = %s ORDER BY id DESC LIMIT 1", $d, $sid, $url ) );
		}
		// phpcs:enable

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ============ Helpers captura ============ */

	private function ip() {
		return self::current_ip();
	}

	public static function current_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			return trim( $parts[0] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	private static function lines( $text ) {
		return array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ) );
	}

	public static function is_excluded_ip( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$o = MWD_AS_Defaults::get_options();
		foreach ( self::lines( isset( $o['exclude_ips'] ) ? $o['exclude_ips'] : '' ) as $p ) {
			if ( $p === $ip ) {
				return true;
			}
			if ( '*' === substr( $p, -1 ) && 0 === strpos( $ip, rtrim( $p, '*' ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_excluded_path( $path ) {
		if ( '' === $path ) {
			return false;
		}
		$o = MWD_AS_Defaults::get_options();
		foreach ( self::lines( isset( $o['exclude_paths'] ) ? $o['exclude_paths'] : '' ) as $p ) {
			if ( $p === $path ) {
				return true;
			}
			if ( false !== strpos( $p, '*' ) && fnmatch( $p, $path ) ) {
				return true;
			}
			if ( '*' !== substr( $p, -1 ) && 0 === strpos( $path, $p ) ) {
				return true;
			}
		}
		return false;
	}

	private function mask_ip( $ip ) {
		$o = MWD_AS_Defaults::get_options();
		if ( empty( $o['anonymize_ip'] ) ) {
			return substr( $ip, 0, 45 );
		}
		if ( strpos( $ip, '.' ) !== false ) {
			$p = explode( '.', $ip );
			if ( count( $p ) === 4 ) {
				$p[3] = '0';
				return implode( '.', $p );
			}
		} elseif ( strpos( $ip, ':' ) !== false ) {
			$p = explode( ':', $ip );
			$p = array_slice( $p, 0, 4 );
			return implode( ':', $p ) . '::';
		}
		return '';
	}

	private function geo( $ip ) {
		$out = array( 'country' => '', 'city' => '' );
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$c = strtoupper( substr( (string) $_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2 ) );
			if ( 'XX' !== $c && 'T1' !== $c ) {
				$out['country'] = $c;
			}
		}

		$o = MWD_AS_Defaults::get_options();
		if ( ( '' === $out['country'] || empty( $out['city'] ) ) && ! empty( $o['geo_lookup'] ) && $ip ) {
			$key    = 'mwd_as_geo_' . md5( $ip );
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
			$resp = wp_remote_get( 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,countryCode,city', array( 'timeout' => 2 ) );
			if ( ! is_wp_error( $resp ) ) {
				$body = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( isset( $body['status'] ) && 'success' === $body['status'] ) {
					$out['country'] = $out['country'] ? $out['country'] : strtoupper( substr( (string) $body['countryCode'], 0, 2 ) );
					$out['city']    = substr( (string) $body['city'], 0, 64 );
				}
			}
			set_transient( $key, $out, DAY_IN_SECONDS );
		}

		return $out;
	}

	private function referrer_host( $ref ) {
		if ( ! $ref ) {
			return '';
		}
		$host = wp_parse_url( $ref, PHP_URL_HOST );
		if ( ! $host || $host === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return '';
		}
		return substr( preg_replace( '/^www\./', '', $host ), 0, 255 );
	}

	private function device( $ua ) {
		$ua = strtolower( $ua );
		if ( preg_match( '/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobile|iphone|ipod|android.*mobile|windows phone/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	private function browser( $ua ) {
		$map = array(
			'Edg'      => 'Edge',
			'OPR'      => 'Opera',
			'Opera'    => 'Opera',
			'Chrome'   => 'Chrome',
			'CriOS'    => 'Chrome',
			'Firefox'  => 'Firefox',
			'FxiOS'    => 'Firefox',
			'Safari'   => 'Safari',
			'MSIE'     => 'Internet Explorer',
			'Trident'  => 'Internet Explorer',
		);
		foreach ( $map as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $name;
			}
		}
		return 'Altul';
	}

	private function os( $ua ) {
		$checks = array(
			'Windows'      => 'Windows',
			'Android'      => 'Android',
			'iPhone'       => 'iOS',
			'iPad'         => 'iOS',
			'Mac OS X'     => 'macOS',
			'Macintosh'    => 'macOS',
			'Linux'        => 'Linux',
			'CrOS'         => 'ChromeOS',
		);
		foreach ( $checks as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $name;
			}
		}
		return 'Altul';
	}

	private function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|feedfetcher|ahrefs|semrush|mj12|dotbot|petalbot|gptbot|ccbot|headless|monitor|uptime|pingdom|lighthouse/i', $ua );
	}

	/**
	 * Sesiunea dupa sid (pentru atribuire comenzi).
	 */
	public static function session_by_sid( $sid ) {
		$sid = substr( preg_replace( '/[^a-f0-9]/', '', (string) $sid ), 0, 32 );
		if ( '' === $sid ) {
			return null;
		}
		global $wpdb;
		$st = self::sessions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT referrer, entry_url, utm_source, utm_medium, utm_campaign FROM {$st} WHERE sid = %s ORDER BY id DESC LIMIT 1", $sid ) );
	}

	/**
	 * Eticheta sursei dintr-o sesiune: UTM > referrer > Direct.
	 */
	public static function source_label( $row ) {
		if ( ! $row ) {
			return 'Direct';
		}
		if ( ! empty( $row->utm_source ) ) {
			return $row->utm_source;
		}
		if ( ! empty( $row->referrer ) ) {
			return $row->referrer;
		}
		return 'Direct';
	}

	/**
	 * Vizitatori activi in ultimele $min minute.
	 */
	public static function online_count( $min = 5 ) {
		global $wpdb;
		$st = self::sessions_table();
		if ( ! self::ready() ) {
			return 0;
		}
		$th = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $min * 60 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE updated_at >= %s", $th ) );
	}

	public static function online_list( $min = 5, $limit = 8 ) {
		global $wpdb;
		$st = self::sessions_table();
		if ( ! self::ready() ) {
			return array();
		}
		$th = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $min * 60 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT exit_url, device, country FROM {$st} WHERE updated_at >= %s ORDER BY updated_at DESC LIMIT %d", $th, (int) $limit ) );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'page'    => $r->exit_url,
				'device'  => $r->device,
				'country' => $r->country,
			);
		}
		return $out;
	}

	public function rest_online() {
		return new WP_REST_Response(
			array(
				'count' => self::online_count(),
				'list'  => self::online_list(),
			),
			200
		);
	}

	public static function cleanup() {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::retention_days() . ' days' ) );
		$s = self::sessions_table();
		$v = self::views_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$s} WHERE started_at < %s", $before ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$v} WHERE created_at < %s", $before ) );
		// phpcs:enable
	}

	/* ============ Rezumat dashboard ============ */

	public static function summary( $days = 7 ) {
		$days   = max( 1, (int) $days );
		$cached = get_transient( 'mwd_as_an_sum2_' . $days );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$st = self::sessions_table();
		$vt = self::views_table();
		$out = self::empty_summary( $days );

		if ( ! self::ready() ) {
			return $out;
		}

		$today      = current_time( 'Y-m-d 00:00:00' );
		$dn         = gmdate( 'Y-m-d 00:00:00', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		$prev_start = gmdate( 'Y-m-d 00:00:00', strtotime( $today . ' -' . ( 2 * $days - 1 ) . ' days' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// O singura trecere prin sesiuni pentru perioada curenta + cea anterioara (agregare conditionala).
		$agg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN started_at >= %s THEN 1 ELSE 0 END) AS sessions,
					SUM(CASE WHEN started_at >= %s THEN 1 ELSE 0 END) AS today,
					COUNT(DISTINCT CASE WHEN started_at >= %s THEN uid END) AS uniq,
					SUM(CASE WHEN started_at >= %s AND is_returning = 1 THEN 1 ELSE 0 END) AS returning_s,
					AVG(CASE WHEN started_at >= %s THEN duration END) AS avg_time,
					SUM(CASE WHEN started_at >= %s AND pageviews <= 1 THEN 1 ELSE 0 END) AS bounces,
					SUM(CASE WHEN started_at >= %s AND device = 'desktop' THEN 1 ELSE 0 END) AS d_desktop,
					SUM(CASE WHEN started_at >= %s AND device = 'mobile' THEN 1 ELSE 0 END) AS d_mobile,
					SUM(CASE WHEN started_at >= %s AND device = 'tablet' THEN 1 ELSE 0 END) AS d_tablet,
					SUM(CASE WHEN started_at < %s THEN 1 ELSE 0 END) AS p_sessions,
					COUNT(DISTINCT CASE WHEN started_at < %s THEN uid END) AS p_uniq,
					AVG(CASE WHEN started_at < %s THEN duration END) AS p_avg_time,
					SUM(CASE WHEN started_at < %s AND pageviews <= 1 THEN 1 ELSE 0 END) AS p_bounces
				 FROM {$st} WHERE started_at >= %s",
				$dn, $today, $dn, $dn, $dn, $dn, $dn, $dn, $dn, $dn, $dn, $dn, $dn, $prev_start
			)
		);
		$views = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS cur, SUM(CASE WHEN created_at < %s THEN 1 ELSE 0 END) AS prev
				 FROM {$vt} WHERE created_at >= %s",
				$dn, $dn, $prev_start
			)
		);

		$out['sessions']  = (int) ( $agg ? $agg->sessions : 0 );
		$out['today']     = (int) ( $agg ? $agg->today : 0 );
		$out['unique']    = (int) ( $agg ? $agg->uniq : 0 );
		$out['returning'] = (int) ( $agg ? $agg->returning_s : 0 );
		$out['avg_time']  = (int) ( $agg ? $agg->avg_time : 0 );
		$out['views']     = (int) ( $views ? $views->cur : 0 );
		$out['bounce']    = $out['sessions'] > 0 ? (int) round( ( $agg ? $agg->bounces : 0 ) / $out['sessions'] * 100 ) : 0;
		$out['devices']   = array(
			'desktop' => (int) ( $agg ? $agg->d_desktop : 0 ),
			'mobile'  => (int) ( $agg ? $agg->d_mobile : 0 ),
			'tablet'  => (int) ( $agg ? $agg->d_tablet : 0 ),
		);

		$p_sessions  = (int) ( $agg ? $agg->p_sessions : 0 );
		$out['prev'] = array(
			'sessions' => $p_sessions,
			'unique'   => (int) ( $agg ? $agg->p_uniq : 0 ),
			'views'    => (int) ( $views ? $views->prev : 0 ),
			'avg_time' => (int) ( $agg ? $agg->p_avg_time : 0 ),
			'bounce'   => $p_sessions > 0 ? (int) round( ( $agg ? $agg->p_bounces : 0 ) / $p_sessions * 100 ) : 0,
		);

		$out['pages']     = $wpdb->get_results( $wpdb->prepare( "SELECT url, COUNT(*) c, AVG(duration) t FROM {$vt} WHERE created_at >= %s GROUP BY url ORDER BY c DESC LIMIT 5", $dn ) );
		$out['sources']   = $wpdb->get_results( $wpdb->prepare( "SELECT referrer, COUNT(*) c FROM {$st} WHERE started_at >= %s AND referrer <> '' GROUP BY referrer ORDER BY c DESC LIMIT 5", $dn ) );
		$out['countries'] = $wpdb->get_results( $wpdb->prepare( "SELECT country, COUNT(*) c FROM {$st} WHERE started_at >= %s AND country <> '' GROUP BY country ORDER BY c DESC LIMIT 5", $dn ) );

		$series  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(started_at) d, COUNT(*) c FROM {$st} WHERE started_at >= %s GROUP BY DATE(started_at)", $dn ), OBJECT_K );
		$vseries = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) c FROM {$vt} WHERE created_at >= %s GROUP BY DATE(created_at)", $dn ), OBJECT_K );
		// phpcs:enable

		$spark = array();
		$vspark = array();
		$labels = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$key      = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " -{$i} days" ) );
			$spark[]  = isset( $series[ $key ] ) ? (int) $series[ $key ]->c : 0;
			$vspark[] = isset( $vseries[ $key ] ) ? (int) $vseries[ $key ]->c : 0;
			$labels[] = $key;
		}
		$out['spark']       = $spark;
		$out['views_spark'] = $vspark;
		$out['dates']       = $labels;

		set_transient( 'mwd_as_an_sum2_' . $days, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	private static function empty_summary( $days = 7 ) {
		$days  = max( 1, (int) $days );
		$dates = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " -{$i} days" ) );
		}
		return array(
			'sessions'  => 0,
			'today'     => 0,
			'unique'    => 0,
			'returning' => 0,
			'views'     => 0,
			'avg_time'  => 0,
			'bounce'    => 0,
			'devices'   => array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 ),
			'pages'     => array(),
			'sources'   => array(),
			'countries' => array(),
			'spark'     => array_fill( 0, max( 1, (int) $days ), 0 ),
			'views_spark' => array_fill( 0, max( 1, (int) $days ), 0 ),
			'dates'     => $dates,
			'prev'      => array( 'sessions' => 0, 'unique' => 0, 'views' => 0, 'avg_time' => 0, 'bounce' => 0 ),
		);
	}

	/**
	 * Calculeaza variatia procentuala fata de o valoare anterioara.
	 * Returneaza null cand nu are sens (fara istoric).
	 */
	public static function delta( $cur, $prev ) {
		$cur  = (float) $cur;
		$prev = (float) $prev;
		if ( $prev <= 0 ) {
			return $cur > 0 ? array( 'dir' => 'up', 'pct' => 0, 'new' => true ) : null;
		}
		$p = (int) round( ( $cur - $prev ) / $prev * 100 );
		if ( 0 === $p ) {
			return array( 'dir' => 'flat', 'pct' => 0 );
		}
		return array( 'dir' => $p > 0 ? 'up' : 'down', 'pct' => abs( $p ) );
	}

	/**
	 * Randeaza un badge de variatie. $good_up = daca o crestere e pozitiva.
	 */
	public static function delta_badge( $delta, $good_up = true ) {
		if ( empty( $delta ) ) {
			return '';
		}
		if ( ! empty( $delta['new'] ) ) {
			return '<span class="mwd-delta up">▲ nou</span>';
		}
		if ( 'flat' === $delta['dir'] ) {
			return '<span class="mwd-delta flat">0%</span>';
		}
		$positive = ( 'up' === $delta['dir'] ) === (bool) $good_up;
		$cls      = $positive ? 'up' : 'down';
		$arrow    = 'up' === $delta['dir'] ? '▲' : '▼';
		return '<span class="mwd-delta ' . $cls . '">' . $arrow . ' ' . (int) $delta['pct'] . '%</span>';
	}

	public static function recent_sessions( $days = 7, $limit = 60 ) {
		global $wpdb;
		$st = self::sessions_table();
		if ( ! self::ready() ) {
			return array();
		}
		$dn = gmdate( 'Y-m-d 00:00:00', strtotime( current_time( 'Y-m-d 00:00:00' ) . ' -' . ( max( 1, (int) $days ) - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$st} WHERE started_at >= %s ORDER BY started_at DESC LIMIT %d", $dn, (int) $limit ) );
	}

	/* ============ Pagina Analitice ============ */

	public function admin_page() {
		add_submenu_page(
			'mwd-admin-studio',
			'Analitice',
			'Analitice',
			'manage_options',
			'mwd-analytics',
			array( $this, 'render_page' )
		);
	}

	public static function fmt_time( $sec ) {
		$sec = (int) $sec;
		if ( $sec < 60 ) {
			return $sec . 's';
		}
		$m = floor( $sec / 60 );
		$s = $sec % 60;
		return $m . 'm ' . str_pad( $s, 2, '0', STR_PAD_LEFT ) . 's';
	}

	public function render_page() {
		$allowed = array( 7, 14, 30 );
		$days    = isset( $_GET['p'] ) ? (int) $_GET['p'] : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $days, $allowed, true ) ) {
			$days = 7;
		}
		$s    = self::summary( $days );
		$rows = self::recent_sessions( $days, 60 );
		$base = admin_url( 'admin.php?page=mwd-analytics' );
		?>
		<div class="wrap mwd-an">
			<h1 class="mwd-an-title">Analitice</h1>

			<div class="mwd-an-period">
				<?php foreach ( $allowed as $opt ) : ?>
					<a class="mwd-an-pbtn<?php echo $opt === $days ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'p', $opt, $base ) ); ?>"><?php echo (int) $opt; ?> zile</a>
				<?php endforeach; ?>
			</div>

			<div class="mwd-an-kpis">
				<?php
				$prev = isset( $s['prev'] ) ? $s['prev'] : array();
				$kpis = array(
					array( 'Vizite', number_format_i18n( $s['sessions'] ), self::delta( $s['sessions'], isset( $prev['sessions'] ) ? $prev['sessions'] : 0 ), true ),
					array( 'Afișări', number_format_i18n( $s['views'] ), self::delta( $s['views'], isset( $prev['views'] ) ? $prev['views'] : 0 ), true ),
					array( 'Vizitatori unici', number_format_i18n( $s['unique'] ), self::delta( $s['unique'], isset( $prev['unique'] ) ? $prev['unique'] : 0 ), true ),
					array( 'Recurenți', number_format_i18n( $s['returning'] ), null, true ),
					array( 'Timp mediu / vizită', self::fmt_time( $s['avg_time'] ), self::delta( $s['avg_time'], isset( $prev['avg_time'] ) ? $prev['avg_time'] : 0 ), true ),
					array( 'Rată respingere', $s['bounce'] . '%', self::delta( $s['bounce'], isset( $prev['bounce'] ) ? $prev['bounce'] : 0 ), false ),
				);
				foreach ( $kpis as $k ) : ?>
					<div class="mwd-an-kpi">
						<span class="mwd-an-kpi-l"><?php echo esc_html( $k[0] ); ?></span>
						<span class="mwd-an-kpi-v num"><?php echo esc_html( $k[1] ); ?> <?php echo self::delta_badge( $k[2], $k[3] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 class="mwd-an-h2">Vizitatori recenți</h2>
			<table class="widefat striped mwd-an-table">
				<thead>
					<tr>
						<th>Data</th><th>Țară</th><th>Dispozitiv</th><th>Browser / OS</th><th>Sursă</th><th>Pagină intrare</th><th>Pagini</th><th>Durată</th><th>Tip</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( $rows ) : foreach ( $rows as $r ) : ?>
						<tr>
							<td class="num"><?php echo esc_html( date_i18n( 'j M, H:i', strtotime( $r->started_at ) ) ); ?></td>
							<td><?php echo esc_html( $r->country ? $r->country . ( $r->city ? ' · ' . $r->city : '' ) : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $r->device ) ); ?></td>
							<td><?php echo esc_html( trim( $r->browser . ' / ' . $r->os, ' /' ) ); ?></td>
							<td><?php echo esc_html( $r->referrer ? $r->referrer : 'Direct' ); ?></td>
							<td class="mwd-an-ellip"><?php echo esc_html( $r->entry_url ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->pageviews ) ); ?></td>
							<td class="num"><?php echo esc_html( self::fmt_time( $r->duration ) ); ?></td>
							<td><?php echo $r->is_returning ? '<span class="mwd-an-tag">recurent</span>' : 'nou'; ?></td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="9">Încă fără date pentru perioada selectată.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2 class="mwd-an-h2">Top pagini (cu timp mediu)</h2>
			<table class="widefat striped mwd-an-table">
				<thead><tr><th>Pagină</th><th>Afișări</th><th>Timp mediu</th></tr></thead>
				<tbody>
					<?php if ( ! empty( $s['pages'] ) ) : foreach ( $s['pages'] as $p ) : ?>
						<tr><td class="mwd-an-ellip"><?php echo esc_html( $p->url ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $p->c ) ); ?></td><td class="num"><?php echo esc_html( self::fmt_time( $p->t ) ); ?></td></tr>
					<?php endforeach; else : ?>
						<tr><td colspan="3">Încă fără date.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:14px;max-width:760px;">
				Notă GDPR: acest modul folosește cookie-uri proprii (sesiune + vizitator) și poate stoca IP-ul (anonimizat implicit). Asigură-te că ai mențiune în politica de confidențialitate și, dacă e cazul, în bannerul de consimțământ.
			</p>
		</div>
		<?php
	}
}
