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

	const DB_VERSION    = '5';
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
			model VARCHAR(80) NOT NULL DEFAULT '',
			os_ver VARCHAR(24) NOT NULL DEFAULT '',
			browser_ver VARCHAR(24) NOT NULL DEFAULT '',
			screen VARCHAR(16) NOT NULL DEFAULT '',
			lang VARCHAR(16) NOT NULL DEFAULT '',
			country CHAR(2) NOT NULL DEFAULT '',
			region VARCHAR(64) NOT NULL DEFAULT '',
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
			KEY started_at (started_at),
			KEY updated_at (updated_at)
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
		// Completeaza tara / orasul pentru vizitele inregistrate fara locatie (ex. inainte de 2.4).
		add_action( 'mwd_as_geo_backfill', array( __CLASS__, 'backfill_geo' ) );
		add_action( 'mwd_as_geo_soon', array( __CLASS__, 'backfill_geo' ) );
		add_action( 'admin_post_mwd_as_geo_backfill', array( $this, 'backfill_now' ) );
		if ( ! wp_next_scheduled( 'mwd_as_geo_backfill' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'mwd_as_geo_backfill' );
		}
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
		// `defer`: nu blocheaza randarea paginii vizitatorilor (WP < 6.3 il pune simplu in footer).
		wp_enqueue_script( 'mwd-as-track', MWD_AS_URL . 'assets/js/track.js', array(), MWD_AS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
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

	/**
	 * Limitare pe IP (max 120 cereri / minut) — doar cu cache de obiecte persistent
	 * (Redis / Memcached), ca sa nu adaugam scrieri in baza de date la fiecare afisare.
	 */
	private static function rate_limited() {
		if ( ! wp_using_ext_object_cache() ) {
			return false;
		}
		$key = 'rl_' . md5( self::current_ip() ) . '_' . (int) floor( time() / 60 );
		if ( false === wp_cache_add( $key, 1, 'mwd_as', 70 ) ) {
			$n = (int) wp_cache_incr( $key, 1, 'mwd_as' );
			return $n > (int) apply_filters( 'mwd_as_rate_limit', 120 );
		}
		return false;
	}

	/**
	 * Rezolva locatia DUPA ce raspunsul a fost trimis browserului (apelurile externe pot dura
	 * secunde si ar tine ocupat un proces PHP). Fara fastcgi/litespeed_finish_request,
	 * se programeaza completarea prin WP-Cron.
	 */
	private function resolve_geo_later( $row_id, $ip ) {
		$finish = function_exists( 'fastcgi_finish_request' ) ? 'fastcgi_finish_request' : ( function_exists( 'litespeed_finish_request' ) ? 'litespeed_finish_request' : '' );
		if ( '' === $finish ) {
			if ( ! wp_next_scheduled( 'mwd_as_geo_soon' ) ) {
				wp_schedule_single_event( time() + 60, 'mwd_as_geo_soon' );
			}
			return;
		}
		register_shutdown_function(
			function () use ( $finish, $row_id, $ip ) {
				call_user_func( $finish );
				$g = MWD_AS_Client::geo( $ip, false, true );
				if ( '' === $g['country'] ) {
					return;
				}
				global $wpdb;
				$wpdb->update( self::sessions_table(), array( 'country' => $g['country'], 'region' => $g['region'], 'city' => $g['city'] ), array( 'id' => (int) $row_id ) );
			}
		);
	}

	public function rest_hit( $request ) {
		if ( self::rate_limited() ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
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
			$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$ip  = $raw_ip;
			// Doar surse rapide aici (headere, cache, MaxMind local); serviciile externe ruleaza dupa raspuns.
			$geo = MWD_AS_Client::geo( $ip, true, false ); // pe IP-ul complet, inainte de anonimizare
			$dev = MWD_AS_Client::parse( $ua, isset( $p['h'] ) && is_array( $p['h'] ) ? $p['h'] : array() );

			// Recurent? (uid vazut inainte)
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$returning = (int) ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE uid = %s LIMIT 1", $uid ) ) );

			$wpdb->insert(
				$st,
				array(
					'uid'          => $uid,
					'sid'          => $sid,
					'ip'           => $this->mask_ip( $ip ),
					'device'       => $dev['device'],
					'browser'      => $dev['browser'],
					'os'           => $dev['os'],
					'model'        => $dev['model'],
					'os_ver'       => $dev['os_ver'],
					'browser_ver'  => $dev['browser_ver'],
					'screen'       => $dev['screen'],
					'lang'         => $dev['lang'],
					'country'      => $geo['country'],
					'region'       => $geo['region'],
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
			if ( '' === $geo['country'] || '' === $geo['city'] ) {
				$opts = MWD_AS_Defaults::get_options();
				if ( ! empty( $opts['geo_api'] ) && $wpdb->insert_id ) {
					$this->resolve_geo_later( (int) $wpdb->insert_id, $ip );
				}
			}
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
		if ( self::rate_limited() || ! $this->same_origin() ) {
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
		return MWD_AS_Client::ip();
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

	/**
	 * Completeaza locatia pentru sesiunile fara tara (cate un lot mic, pe IP-uri distincte).
	 * Functioneaza si pe IP-uri anonimizate (ex. 86.120.4.0): tara si orasul raman corecte.
	 *
	 * @return int Numarul de IP-uri rezolvate.
	 */
	public static function backfill_geo( $limit = 25 ) {
		if ( ! self::ready() ) {
			return 0;
		}
		// do_action( 'hook' ) fara argumente trimite un sir gol: revenim la valoarea implicita.
		$limit = (int) $limit > 0 ? (int) $limit : 25;
		global $wpdb;
		$st = self::sessions_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ips = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ip FROM {$st} WHERE country = '' AND ip <> '' AND started_at >= %s GROUP BY ip ORDER BY MAX(started_at) DESC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::retention_days() . ' days' ) ),
				(int) $limit
			)
		);
		$done = 0;
		foreach ( (array) $ips as $ip ) {
			$g = MWD_AS_Client::geo( $ip, false );
			if ( '' === $g['country'] ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$st} SET country = %s, region = %s, city = %s WHERE ip = %s AND country = ''", $g['country'], $g['region'], $g['city'], $ip ) );
			$done++;
		}
		// phpcs:enable
		if ( $done ) {
			foreach ( array( 7, 14, 30 ) as $d ) {
				delete_transient( 'mwd_as_an_sum2_' . $d );
			}
		}
		return $done;
	}

	/**
	 * Buton „Completează locațiile" din pagina Analitice.
	 */
	public function backfill_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( 'mwd_as_geo_backfill' );
		$n = self::backfill_geo( 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'mwd-analytics', 'geo' => (int) $n ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function cleanup() {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::retention_days() . ' days' ) );
		// Pe loturi de 5.000 de randuri: o stergere uriasa intr-o singura interogare poate bloca tabelele.
		foreach ( array( array( self::sessions_table(), 'started_at' ), array( self::views_table(), 'created_at' ) ) as $t ) {
			list( $table, $col ) = $t;
			for ( $i = 0; $i < 200; $i++ ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$col} < %s ORDER BY id ASC LIMIT 5000", $before ) );
				if ( ! $ids ) {
					break;
				}
				$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
				// phpcs:enable
				if ( count( $ids ) < 5000 ) {
					break;
				}
			}
		}
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

	/* ============ Filtre pentru lista de vizite ============ */

	/**
	 * Filtrele din cerere (pagina Analitice / export), validate.
	 */
	public static function filters_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g  = wp_unslash( $_GET );
		$cc = isset( $g['country'] ) ? strtoupper( sanitize_text_field( (string) $g['country'] ) ) : '';
		$dv = isset( $g['device'] ) ? sanitize_key( (string) $g['device'] ) : '';
		// phpcs:enable
		return array(
			'country' => preg_match( '/^([A-Z]{2}|NONE)$/', $cc ) ? $cc : '',
			'device'  => in_array( $dv, array( 'mobile', 'tablet', 'desktop' ), true ) ? $dv : '',
			'source'  => isset( $g['source'] ) ? substr( sanitize_text_field( (string) $g['source'] ), 0, 100 ) : '',
			'q'       => isset( $g['q'] ) ? substr( sanitize_text_field( (string) $g['q'] ), 0, 100 ) : '',
			'type'    => isset( $g['type'] ) && in_array( $g['type'], array( 'new', 'returning' ), true ) ? $g['type'] : '',
		);
	}

	/**
	 * Clauza WHERE (cu argumente pentru prepare) pentru perioada + filtre. $days = 0 -> toata perioada.
	 */
	public static function filter_where( $days, $f ) {
		global $wpdb;
		$w    = array( '1=1' );
		$args = array();
		if ( $days > 0 ) {
			$w[]    = 'started_at >= %s';
			$args[] = gmdate( 'Y-m-d 00:00:00', strtotime( current_time( 'Y-m-d 00:00:00' ) . ' -' . ( (int) $days - 1 ) . ' days' ) );
		}
		if ( 'NONE' === $f['country'] ) {
			$w[] = "country = ''";
		} elseif ( '' !== $f['country'] ) {
			$w[]    = 'country = %s';
			$args[] = $f['country'];
		}
		if ( '' !== $f['device'] ) {
			$w[]    = 'device = %s';
			$args[] = $f['device'];
		}
		if ( 'direct' === $f['source'] ) {
			$w[] = "referrer = '' AND utm_source = ''";
		} elseif ( '' !== $f['source'] ) {
			$w[]    = '(utm_source = %s OR referrer = %s)';
			$args[] = $f['source'];
			$args[] = $f['source'];
		}
		if ( '' !== $f['type'] ) {
			$w[] = 'returning' === $f['type'] ? 'is_returning = 1' : 'is_returning = 0';
		}
		if ( '' !== $f['q'] ) {
			$like   = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$w[]    = '(ip LIKE %s OR entry_url LIKE %s OR exit_url LIKE %s OR city LIKE %s OR model LIKE %s)';
			$args[] = $wpdb->esc_like( $f['q'] ) . '%';
			array_push( $args, $like, $like, $like, $like );
		}
		return array( implode( ' AND ', $w ), $args );
	}

	/**
	 * O pagina de vizite + totalul (pentru paginare).
	 */
	public static function query_sessions( $days, $f, $per_page = 50, $page = 1 ) {
		if ( ! self::ready() ) {
			return array( array(), 0 );
		}
		global $wpdb;
		$st             = self::sessions_table();
		list( $where, $args ) = self::filter_where( $days, $f );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$st} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$q_args    = array_merge( $args, array( (int) $per_page, (int) max( 0, ( $page - 1 ) * $per_page ) ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$st} WHERE {$where} ORDER BY started_at DESC LIMIT %d OFFSET %d", $q_args ) );
		// phpcs:enable
		return array( $rows, $total );
	}

	/**
	 * Valorile disponibile pentru filtre (tari si surse din perioada).
	 */
	private static function filter_options( $days ) {
		if ( ! self::ready() ) {
			return array( array(), array() );
		}
		global $wpdb;
		$st = self::sessions_table();
		$dn = gmdate( 'Y-m-d 00:00:00', strtotime( current_time( 'Y-m-d 00:00:00' ) . ' -' . ( (int) $days - 1 ) . ' days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$countries = $wpdb->get_col( $wpdb->prepare( "SELECT country FROM {$st} WHERE started_at >= %s AND country <> '' GROUP BY country ORDER BY COUNT(*) DESC LIMIT 60", $dn ) );
		$sources   = $wpdb->get_col( $wpdb->prepare( "SELECT CASE WHEN utm_source <> '' THEN utm_source ELSE referrer END s FROM {$st} WHERE started_at >= %s AND (utm_source <> '' OR referrer <> '') GROUP BY s ORDER BY COUNT(*) DESC LIMIT 30", $dn ) );
		// phpcs:enable
		return array( $countries, $sources );
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
		$s       = self::summary( $days );
		$filters = self::filters_from_request();
		$pg      = isset( $_GET['pg'] ) ? max( 1, (int) $_GET['pg'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 50;
		list( $rows, $total ) = self::query_sessions( $days, $filters, $per, $pg );
		$pages   = max( 1, (int) ceil( $total / $per ) );
		$base    = admin_url( 'admin.php?page=mwd-analytics' );
		$keep    = array_filter( array_merge( array( 'p' => $days ), $filters ) );
		?>
		<div class="wrap mwd-an">
			<h1 class="mwd-an-title">Analitice</h1>

			<div class="mwd-an-period">
				<?php foreach ( $allowed as $opt ) : ?>
					<a class="mwd-an-pbtn<?php echo $opt === $days ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( $keep, array( 'p' => $opt ) ), $base ) ); ?>"><?php echo (int) $opt; ?> zile</a>
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

			<?php
			// Traseul fiecarei vizite (paginile in ordine), dintr-o singura interogare.
			$paths = array();
			if ( $rows ) {
				global $wpdb;
				$vt   = self::views_table();
				$sids = array_values( array_unique( wp_list_pluck( $rows, 'sid' ) ) );
				$ph   = implode( ',', array_fill( 0, count( $sids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$vrows = $wpdb->get_results( $wpdb->prepare( "SELECT sid, url, title, duration, created_at FROM {$vt} WHERE sid IN ($ph) ORDER BY id ASC", $sids ) );
				foreach ( (array) $vrows as $v ) {
					$paths[ $v->sid ][] = $v;
				}
			}
			$missing = 0;
			foreach ( (array) $rows as $r ) {
				$missing += '' === $r->country ? 1 : 0;
			}
			$opts      = MWD_AS_Defaults::get_options();
			$geo_done  = isset( $_GET['geo'] ) ? (int) $_GET['geo'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dev_icons = array( 'mobile' => 'dashicons-smartphone', 'tablet' => 'dashicons-tablet', 'desktop' => 'dashicons-desktop' );
			$dev_names = array( 'mobile' => 'Telefon', 'tablet' => 'Tabletă', 'desktop' => 'Desktop' );
			?>

			<div class="mwd-an-head">
				<h2 class="mwd-an-h2">Vizite <span class="mwd-an-total"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></h2>
				<div class="mwd-an-head-actions">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $keep, array( 'action' => 'mwd_as_export_visitors' ) ), admin_url( 'admin-post.php' ) ), 'mwd_as_export_visitors' ) ); ?>"><span class="dashicons dashicons-download" style="vertical-align:-4px"></span> Export CSV<?php echo count( $keep ) > 1 ? ' (filtrat)' : ''; ?></a>
				<?php if ( $missing ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_geo_backfill' ), 'mwd_as_geo_backfill' ) ); ?>"><span class="dashicons dashicons-location" style="vertical-align:-4px"></span> Completează locațiile lipsă (<?php echo (int) $missing; ?>)</a>
				<?php endif; ?>
				</div>
			</div>

			<?php
			list( $f_countries, $f_sources ) = self::filter_options( $days );
			$active_filters                  = count( array_filter( $filters ) );
			?>
			<form class="mwd-an-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="mwd-analytics" />
				<input type="hidden" name="p" value="<?php echo (int) $days; ?>" />
				<input type="search" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="Caută IP, pagină, oraș, model…" />
				<select name="country">
					<option value="">Toate țările</option>
					<?php foreach ( $f_countries as $cc ) : ?>
						<option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $filters['country'], $cc ); ?>><?php echo esc_html( trim( MWD_AS_Client::flag( $cc ) . ' ' . MWD_AS_Client::country_name( $cc ) ) ); ?></option>
					<?php endforeach; ?>
					<option value="NONE" <?php selected( $filters['country'], 'NONE' ); ?>>Necunoscută</option>
				</select>
				<select name="device">
					<option value="">Toate dispozitivele</option>
					<?php foreach ( $dev_names as $k => $n ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['device'], $k ); ?>><?php echo esc_html( $n ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="source">
					<option value="">Toate sursele</option>
					<option value="direct" <?php selected( $filters['source'], 'direct' ); ?>>Direct</option>
					<?php foreach ( $f_sources as $src_opt ) : ?>
						<option value="<?php echo esc_attr( $src_opt ); ?>" <?php selected( $filters['source'], $src_opt ); ?>><?php echo esc_html( $src_opt ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="type">
					<option value="">Noi și recurenți</option>
					<option value="new" <?php selected( $filters['type'], 'new' ); ?>>Doar noi</option>
					<option value="returning" <?php selected( $filters['type'], 'returning' ); ?>>Doar recurenți</option>
				</select>
				<button type="submit" class="button button-primary">Filtrează</button>
				<?php if ( $active_filters ) : ?>
					<a class="button-link" href="<?php echo esc_url( add_query_arg( 'p', $days, $base ) ); ?>">Resetează (<?php echo (int) $active_filters; ?>)</a>
				<?php endif; ?>
			</form>
			<?php if ( $geo_done >= 0 ) : ?>
				<div class="notice notice-<?php echo $geo_done ? 'success' : 'warning'; ?> inline"><p>
					<?php
					echo $geo_done
						? esc_html( sprintf( 'Am completat locația pentru %d adrese IP.', $geo_done ) )
						: esc_html( empty( $opts['geo_api'] ) ? 'Activează „Detectează țara / orașul prin servicii externe" din Admin Studio → Dashboard & date.' : 'Nu am putut obține locația acum (serviciile externe nu au răspuns). Se reîncearcă automat în fiecare oră.' );
					?>
				</p></div>
			<?php endif; ?>

			<div class="mwd-an-table-wrap">
			<table class="widefat mwd-an-table mwd-an-visits">
				<thead>
					<tr>
						<th>Data</th><th>IP</th><th>Locație</th><th>Dispozitiv</th><th>Browser / sistem</th><th>Sursă</th><th>Traseu</th><th class="num">Durată</th><th>Tip</th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( $rows ) :
						foreach ( $rows as $r ) :
							$model   = isset( $r->model ) ? $r->model : '';
							$screen  = isset( $r->screen ) ? $r->screen : '';
							$os      = trim( $r->os . ( ! empty( $r->os_ver ) ? ' ' . $r->os_ver : '' ) );
							$browser = trim( $r->browser . ( ! empty( $r->browser_ver ) ? ' ' . $r->browser_ver : '' ) );
							$place   = array_filter( array( isset( $r->city ) ? $r->city : '', isset( $r->region ) && $r->region !== $r->city ? $r->region : '' ) );
							$steps   = isset( $paths[ $r->sid ] ) ? $paths[ $r->sid ] : array();
							$src     = $r->utm_source ? $r->utm_source . ( $r->utm_campaign ? ' · ' . $r->utm_campaign : '' ) : ( $r->referrer ? $r->referrer : 'Direct' );
							?>
						<tr>
							<td class="num mwd-an-nowrap"><?php echo esc_html( date_i18n( 'j M, H:i', strtotime( $r->started_at ) ) ); ?></td>
							<td><code class="mwd-an-ip"><?php echo esc_html( $r->ip ? $r->ip : '—' ); ?></code></td>
							<td>
								<?php if ( $r->country ) : ?>
									<span class="mwd-an-place">
										<span class="mwd-an-flag" aria-hidden="true"><?php echo esc_html( MWD_AS_Client::flag( $r->country ) ); ?></span>
										<span>
											<strong><?php echo esc_html( MWD_AS_Client::country_name( $r->country ) ); ?></strong>
											<?php if ( $place ) : ?><small><?php echo esc_html( implode( ', ', $place ) ); ?></small><?php endif; ?>
										</span>
									</span>
								<?php else : ?>
									<span class="mwd-an-muted">necunoscută</span>
								<?php endif; ?>
							</td>
							<td>
								<span class="mwd-an-dev">
									<span class="dashicons <?php echo esc_attr( isset( $dev_icons[ $r->device ] ) ? $dev_icons[ $r->device ] : 'dashicons-desktop' ); ?>" aria-hidden="true"></span>
									<span>
										<strong><?php echo esc_html( $model ? $model : ( isset( $dev_names[ $r->device ] ) ? $dev_names[ $r->device ] : ucfirst( $r->device ) ) ); ?></strong>
										<?php if ( $screen ) : ?><small><?php echo esc_html( $screen ); ?></small><?php endif; ?>
									</span>
								</span>
							</td>
							<td>
								<span class="mwd-an-stack">
									<span><?php echo esc_html( $browser ); ?></span>
									<small><?php echo esc_html( $os ); ?><?php echo ! empty( $r->lang ) ? ' · ' . esc_html( $r->lang ) : ''; ?></small>
								</span>
							</td>
							<td class="mwd-an-ellip"><?php echo esc_html( $src ); ?></td>
							<td class="mwd-an-path">
								<?php if ( count( $steps ) > 1 ) : ?>
									<details>
										<summary><span class="mwd-an-ellip"><?php echo esc_html( $r->entry_url ); ?></span> <span class="mwd-an-count">+<?php echo (int) ( count( $steps ) - 1 ); ?></span></summary>
										<ol>
											<?php foreach ( $steps as $st ) : ?>
												<li><span class="mwd-an-time"><?php echo esc_html( date_i18n( 'H:i', strtotime( $st->created_at ) ) ); ?></span> <span class="mwd-an-ellip" title="<?php echo esc_attr( $st->title ); ?>"><?php echo esc_html( $st->url ); ?></span> <span class="mwd-an-muted"><?php echo esc_html( self::fmt_time( $st->duration ) ); ?></span></li>
											<?php endforeach; ?>
										</ol>
									</details>
								<?php else : ?>
									<span class="mwd-an-ellip"><?php echo esc_html( $r->entry_url ); ?></span>
								<?php endif; ?>
							</td>
							<td class="num"><?php echo esc_html( self::fmt_time( $r->duration ) ); ?></td>
							<td><?php echo $r->is_returning ? '<span class="mwd-an-tag">recurent</span>' : '<span class="mwd-an-muted">nou</span>'; ?></td>
						</tr>
							<?php
						endforeach;
					else :
						?>
						<tr><td colspan="9"><?php echo array_filter( $filters ) ? 'Nicio vizită nu corespunde filtrelor.' : 'Încă fără date pentru perioada selectată.'; ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			</div>
			<?php if ( $pages > 1 ) : ?>
				<nav class="mwd-an-pager" aria-label="Paginare">
					<span><?php echo esc_html( sprintf( '%s–%s din %s', number_format_i18n( ( $pg - 1 ) * $per + 1 ), number_format_i18n( min( $total, $pg * $per ) ), number_format_i18n( $total ) ) ); ?></span>
					<?php if ( $pg > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $keep, array( 'pg' => $pg - 1 ) ), $base ) ); ?>">‹ Anterioare</a>
					<?php endif; ?>
					<span class="mwd-an-muted"><?php echo (int) $pg; ?> / <?php echo (int) $pages; ?></span>
					<?php if ( $pg < $pages ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $keep, array( 'pg' => $pg + 1 ) ), $base ) ); ?>">Următoarele ›</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
			<?php if ( ! empty( $opts['anonymize_ip'] ) ) : ?>
				<p class="description">IP-urile sunt anonimizate (ultimul octet = 0). Pentru IP complet, dezactivează „Anonimizează IP-ul" din Admin Studio → Dashboard & date.</p>
			<?php endif; ?>

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
