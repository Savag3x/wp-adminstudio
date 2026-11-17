<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identificarea vizitatorului: IP real, locatie (tara / regiune / oras) si dispozitiv exact.
 *
 * - IP: headere de CDN / proxy validate, cu preferinta pentru IP-uri publice.
 * - Locatie, in ordine: headere CDN/server -> baza MaxMind din WooCommerce (locala) -> servicii HTTPS.
 *   Rezultatele se memoreaza per IP.
 * - Dispozitiv: User-Agent + Client Hints (model real pe Android, versiuni reale de OS / browser,
 *   iPad care se prezinta drept Mac, browsere din aplicatii: Instagram, Facebook, TikTok).
 */
class MWD_AS_Client {

	/* =====================================================================
	 * IP
	 * ===================================================================== */

	private static function valid_ip( $ip, $public = false ) {
		$ip    = trim( (string) $ip );
		$flags = $public ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0;
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ? $ip : '';
	}

	/**
	 * IP-ul real al vizitatorului.
	 */
	public static function ip() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		// 1) Headere puse de CDN-uri / firewall-uri cunoscute (o singura adresa).
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_SUCURI_CLIENTIP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FASTLY_CLIENT_IP', 'HTTP_X_REAL_IP' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = self::valid_ip( wp_unslash( $_SERVER[ $h ] ), true );
				if ( $ip ) {
					return $ip;
				}
			}
		}
		// 2) X-Forwarded-For: primul IP public din lant (clientul original).
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			foreach ( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) as $part ) {
				$ip = self::valid_ip( $part, true );
				if ( $ip ) {
					return $ip;
				}
			}
		}
		// 3) Conexiunea directa.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// phpcs:enable
	}

	/* =====================================================================
	 * Locatie
	 * ===================================================================== */

	/**
	 * Tara (cod ISO 2), regiune si oras pentru un IP.
	 *
	 * @param string $ip      IP-ul (poate fi si anonimizat, ex. 86.120.4.0 — tara/orasul raman corecte).
	 * @param bool   $headers Foloseste headerele cererii curente (doar pentru IP-ul vizitatorului curent).
	 * @param bool   $remote  Permite apeluri catre servicii externe. Cu false se folosesc doar sursele
	 *                        rapide (headere, cache, MaxMind local) — pentru calea critica a cererii.
	 */
	public static function geo( $ip, $headers = true, $remote = true ) {
		$out = array( 'country' => '', 'region' => '', 'city' => '' );

		// 1) Headere CDN / modul GeoIP pe server.
		if ( $headers ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput
			foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$c = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
					if ( preg_match( '/^[A-Z]{2}$/', $c ) && ! in_array( $c, array( 'XX', 'T1', 'ZZ' ), true ) ) {
						$out['country'] = $c;
						break;
					}
				}
			}
			foreach ( array( 'HTTP_CF_IPCITY', 'HTTP_CLOUDFRONT_VIEWER_CITY', 'HTTP_X_APPENGINE_CITY', 'GEOIP_CITY' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$out['city'] = substr( sanitize_text_field( rawurldecode( wp_unslash( $_SERVER[ $h ] ) ) ), 0, 64 );
					break;
				}
			}
			// phpcs:enable
			if ( '' !== $out['country'] && '' !== $out['city'] ) {
				return $out;
			}
		}

		if ( ! self::valid_ip( $ip, true ) ) {
			return $out;
		}

		$key    = 'mwd_as_geo2_' . md5( $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return self::merge( $out, $cached );
		}

		$found = array( 'country' => '', 'region' => '', 'city' => '' );

		// 2) Baza MaxMind locala din WooCommerce (daca e configurata), fara apeluri externe.
		if ( class_exists( 'WC_Geolocation' ) ) {
			$wc = WC_Geolocation::geolocate_ip( $ip, false, false );
			if ( ! empty( $wc['country'] ) ) {
				$found['country'] = strtoupper( substr( $wc['country'], 0, 2 ) );
				$found['region']  = isset( $wc['state'] ) ? (string) $wc['state'] : '';
				$found['city']    = isset( $wc['city'] ) ? (string) $wc['city'] : '';
			}
		}

		// 3) Servicii externe (HTTPS), daca utilizatorul le permite.
		$opts = MWD_AS_Defaults::get_options();
		if ( $remote && ( '' === $found['country'] || '' === $found['city'] ) && ! empty( $opts['geo_api'] ) ) {
			$api   = self::geo_api( $ip );
			$found = self::merge( $found, $api );
		}

		$found = array_map(
			function ( $v ) {
				return substr( sanitize_text_field( (string) $v ), 0, 64 );
			},
			$found
		);
		// Cache lung pentru succes, scurt pentru esec (nu repetam cereri catre servicii picate).
		// Fara apel extern, un rezultat gol nu se memoreaza (ar bloca rezolvarea ulterioara).
		if ( $remote || '' !== $found['country'] ) {
			set_transient( $key, $found, '' !== $found['country'] ? 14 * DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}

		return self::merge( $out, $found );
	}

	private static function merge( $a, $b ) {
		foreach ( array( 'country', 'region', 'city' ) as $k ) {
			if ( '' === $a[ $k ] && ! empty( $b[ $k ] ) ) {
				$a[ $k ] = $b[ $k ];
			}
		}
		return $a;
	}

	/**
	 * Furnizorii de geolocatie, in ordine. Fiecare intoarce country/region/city din raspunsul JSON.
	 * Se pot schimba prin filtrul `mwd_as_geo_providers`.
	 */
	public static function providers() {
		$p = array(
			'freeipapi' => array(
				'url' => 'https://freeipapi.com/api/json/%s',
				'map' => array( 'country' => 'countryCode', 'region' => 'regionName', 'city' => 'cityName' ),
			),
			'ipwhois'   => array(
				'url' => 'https://ipwho.is/%s?fields=success,country_code,region,city',
				'map' => array( 'country' => 'country_code', 'region' => 'region', 'city' => 'city' ),
			),
			'countryis' => array(
				'url' => 'https://api.country.is/%s',
				'map' => array( 'country' => 'country' ),
			),
		);
		return apply_filters( 'mwd_as_geo_providers', $p );
	}

	private static function geo_api( $ip ) {
		$out = array( 'country' => '', 'region' => '', 'city' => '' );
		foreach ( self::providers() as $prov ) {
			$resp = wp_remote_get(
				sprintf( $prov['url'], rawurlencode( $ip ) ),
				array( 'timeout' => 3, 'headers' => array( 'Accept' => 'application/json' ) )
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) || ( isset( $body['success'] ) && ! $body['success'] ) ) {
				continue;
			}
			foreach ( $prov['map'] as $k => $field ) {
				if ( ! empty( $body[ $field ] ) && '-' !== $body[ $field ] ) {
					$out[ $k ] = 'country' === $k ? strtoupper( substr( (string) $body[ $field ], 0, 2 ) ) : (string) $body[ $field ];
				}
			}
			if ( '' !== $out['country'] && '' !== $out['city'] ) {
				break;
			}
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $out['country'] ) ) {
			$out['country'] = '';
		}
		return $out;
	}

	/**
	 * Numele tarii (in limba site-ului cand se poate) + steagul emoji.
	 */
	public static function country_name( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		static $names = null;
		if ( null === $names ) {
			$names = array();
			if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
				$names = (array) WC()->countries->get_countries();
			}
		}
		if ( isset( $names[ $cc ] ) ) {
			return html_entity_decode( $names[ $cc ], ENT_QUOTES, 'UTF-8' );
		}
		if ( class_exists( 'Locale' ) ) {
			$n = Locale::getDisplayRegion( '-' . $cc, get_locale() );
			if ( $n && $n !== $cc ) {
				return $n;
			}
		}
		return $cc;
	}

	public static function flag( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		// Indicatori regionali (U+1F1E6..), fara a depinde de extensia mbstring.
		return html_entity_decode( '&#' . ( 0x1F1E6 + ord( $cc[0] ) - 65 ) . ';&#' . ( 0x1F1E6 + ord( $cc[1] ) - 65 ) . ';', ENT_QUOTES, 'UTF-8' );
	}

	/* =====================================================================
	 * Dispozitiv
	 * ===================================================================== */

	/**
	 * Analizeaza User-Agent-ul + Client Hints trimise de beacon.
	 *
	 * @param string $ua    User-Agent.
	 * @param array  $hints Din beacon: m (model), pv (platformVersion), b (brands [{brand,version}]),
	 *                      mob (bool), tp (maxTouchPoints), sw/sh (ecran), dpr, lang.
	 * @return array device, model, browser, browser_ver, os, os_ver, screen, lang
	 */
	public static function parse( $ua, $hints = array() ) {
		$ua    = (string) $ua;
		$hints = is_array( $hints ) ? $hints : array();
		$out   = array(
			'device'      => 'desktop',
			'model'       => '',
			'browser'     => 'Altul',
			'browser_ver' => '',
			'os'          => 'Altul',
			'os_ver'      => '',
			'screen'      => '',
			'lang'        => '',
		);

		/* ---------- Sistem de operare ---------- */
		$touch = isset( $hints['tp'] ) ? (int) $hints['tp'] : 0;
		if ( preg_match( '/\((?:iPhone|iPod)[^)]*OS (\d+)[_.](\d+)/i', $ua, $m ) || preg_match( '/iPhone OS (\d+)[_.](\d+)/i', $ua, $m ) ) {
			$out['os']     = 'iOS';
			$out['os_ver'] = $m[1] . ( '0' !== $m[2] ? '.' . $m[2] : '' );
			$out['device'] = 'mobile';
			$out['model']  = false !== stripos( $ua, 'iPod' ) ? 'iPod' : 'iPhone';
		} elseif ( preg_match( '/iPad[^)]*OS (\d+)[_.](\d+)/i', $ua, $m ) ) {
			$out['os']     = 'iPadOS';
			$out['os_ver'] = $m[1] . ( '0' !== $m[2] ? '.' . $m[2] : '' );
			$out['device'] = 'tablet';
			$out['model']  = 'iPad';
		} elseif ( preg_match( '/Macintosh/i', $ua ) && $touch > 1 ) {
			// iPadOS 13+ se prezinta drept Mac; ecranul tactil il tradeaza.
			$out['os']     = 'iPadOS';
			$out['device'] = 'tablet';
			$out['model']  = 'iPad';
			if ( preg_match( '/Version\/(\d+(?:\.\d+)?)/', $ua, $m ) ) {
				$out['os_ver'] = $m[1];
			}
		} elseif ( preg_match( '/Android\s*([\d.]+)?/i', $ua, $m ) ) {
			$out['os']     = 'Android';
			$out['os_ver'] = isset( $m[1] ) ? self::short_ver( $m[1], 1 ) : '';
			// Chrome „ingheata" UA-ul la „Android 10; K": versiunea reala vine doar din Client Hints.
			if ( preg_match( '/Android 10; K\)/', $ua ) ) {
				$out['os_ver'] = '';
			}
			$out['device'] = preg_match( '/Mobile/i', $ua ) ? 'mobile' : 'tablet';
		} elseif ( preg_match( '/Windows NT ([\d.]+)/i', $ua, $m ) ) {
			$out['os']     = 'Windows';
			// „NT 10.0" acopera si Windows 11; distinctia vine doar din Client Hints.
			$map           = array( '10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7' );
			$out['os_ver'] = isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : '';
		} elseif ( preg_match( '/CrOS/i', $ua ) ) {
			$out['os'] = 'ChromeOS';
		} elseif ( preg_match( '/Mac OS X ([\d_]+)/i', $ua, $m ) || preg_match( '/Macintosh/i', $ua ) ) {
			$out['os'] = 'macOS';
			// 10_15_7 e „inghetat" de browsere; versiunea reala vine din Client Hints.
		} elseif ( preg_match( '/Linux/i', $ua ) ) {
			$out['os'] = 'Linux';
		}

		// Versiunea reala a OS-ului din Client Hints (Chromium).
		$pv = isset( $hints['pv'] ) ? preg_replace( '/[^0-9.]/', '', (string) $hints['pv'] ) : '';
		if ( '' !== $pv ) {
			$major = (int) $pv;
			if ( 'Windows' === $out['os'] ) {
				$out['os_ver'] = $major >= 13 ? '11' : ( $major > 0 ? '10' : $out['os_ver'] );
			} elseif ( 'macOS' === $out['os'] || 'Android' === $out['os'] ) {
				$out['os_ver'] = self::short_ver( $pv, 'macOS' === $out['os'] ? 2 : 1 );
			}
		}

		/* ---------- Model exact de iPhone (aplicatiile Instagram / Facebook il trimit: „iPhone15,3") ---------- */
		if ( 'iPhone' === $out['model'] && preg_match( '/iPhone(\d+),(\d+)/', $ua, $m ) ) {
			$name = self::iphone_name( (int) $m[1], (int) $m[2] );
			if ( $name ) {
				$out['model'] = $name;
			}
		}

		/* ---------- Model (Android) ---------- */
		if ( 'Android' === $out['os'] ) {
			$model = isset( $hints['m'] ) ? trim( (string) $hints['m'] ) : '';
			if ( '' === $model && preg_match( '/Android[^;)]*;\s*(?:[a-z]{2}[-_][a-z]{2};\s*)?([^;)]+)/i', $ua, $m ) ) {
				$model = trim( preg_replace( '/\s+Build\/.*$/i', '', $m[1] ) );
			}
			// „K" = model ascuns de reducerea User-Agent din Chrome.
			if ( '' !== $model && ! in_array( strtolower( $model ), array( 'k', 'mobile', 'tablet', 'wv' ), true ) ) {
				$out['model'] = self::friendly_model( $model );
			}
			if ( isset( $hints['mob'] ) ) {
				$out['device'] = $hints['mob'] ? 'mobile' : 'tablet';
			}
		}

		/* ---------- Browser ---------- */
		$checks = array(
			array( '/Instagram\s([\d.]+)/i', 'Instagram (în aplicație)' ),
			array( '/FBAV\/([\d.]+)/i', 'Facebook (în aplicație)' ),
			array( '/FBAN|FB_IAB/i', 'Facebook (în aplicație)' ),
			array( '/(?:musical_ly|BytedanceWebview|TikTok)[_\/]?([\d.]+)?/i', 'TikTok (în aplicație)' ),
			array( '/GSA\/([\d.]+)/i', 'Google App' ),
			array( '/Edg(?:e|A|iOS)?\/([\d.]+)/i', 'Edge' ),
			array( '/(?:OPR|Opera)\/([\d.]+)/i', 'Opera' ),
			array( '/SamsungBrowser\/([\d.]+)/i', 'Samsung Internet' ),
			array( '/YaBrowser\/([\d.]+)/i', 'Yandex' ),
			array( '/(?:Firefox|FxiOS)\/([\d.]+)/i', 'Firefox' ),
			array( '/CriOS\/([\d.]+)/i', 'Chrome' ),
			array( '/Chrome\/([\d.]+)/i', 'Chrome' ),
			array( '/Version\/([\d.]+).*Safari/i', 'Safari' ),
			array( '/MSIE ([\d.]+)|Trident\/.*rv:([\d.]+)/i', 'Internet Explorer' ),
		);
		foreach ( $checks as $c ) {
			if ( preg_match( $c[0], $ua, $m ) ) {
				$out['browser'] = $c[1];
				$ver            = isset( $m[1] ) && '' !== $m[1] ? $m[1] : ( isset( $m[2] ) ? $m[2] : '' );
				$out['browser_ver'] = 'Safari' === $c[1] || 'Samsung Internet' === $c[1] ? self::short_ver( $ver, 2 ) : self::short_ver( $ver, 1 );
				break;
			}
		}
		// Versiune exacta din Client Hints (lista de branduri, fara „Not A Brand").
		if ( ! empty( $hints['b'] ) && is_array( $hints['b'] ) && in_array( $out['browser'], array( 'Chrome', 'Edge', 'Opera' ), true ) ) {
			$want = array( 'Chrome' => 'Google Chrome', 'Edge' => 'Microsoft Edge', 'Opera' => 'Opera' );
			foreach ( $hints['b'] as $b ) {
				if ( is_array( $b ) && isset( $b['brand'], $b['version'] ) && $b['brand'] === $want[ $out['browser'] ] ) {
					$out['browser_ver'] = self::short_ver( (string) $b['version'], 1 );
				}
			}
		}

		/* ---------- Ecran si limba ---------- */
		$sw = isset( $hints['sw'] ) ? (int) $hints['sw'] : 0;
		$sh = isset( $hints['sh'] ) ? (int) $hints['sh'] : 0;
		if ( $sw > 0 && $sh > 0 && $sw < 20000 && $sh < 20000 ) {
			$out['screen'] = $sw . '×' . $sh;
		}
		if ( ! empty( $hints['lang'] ) ) {
			$out['lang'] = substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $hints['lang'] ), 0, 16 );
		}

		// Ecran mare fara „Mobile" in UA pe Android = tableta; ecran mic = telefon.
		if ( 'Android' === $out['os'] && ! isset( $hints['mob'] ) && $sw > 0 ) {
			$out['device'] = min( $sw, $sh ) >= 600 ? 'tablet' : 'mobile';
		}

		foreach ( $out as $k => $v ) {
			$out[ $k ] = substr( (string) $v, 0, 'model' === $k ? 80 : 40 );
		}
		return $out;
	}

	/**
	 * Identificator Apple -> nume comercial (iPhone 11 si mai noi).
	 */
	private static function iphone_name( $major, $minor ) {
		$map = array(
			'12,1' => 'iPhone 11', '12,3' => 'iPhone 11 Pro', '12,5' => 'iPhone 11 Pro Max', '12,8' => 'iPhone SE (2)',
			'13,1' => 'iPhone 12 mini', '13,2' => 'iPhone 12', '13,3' => 'iPhone 12 Pro', '13,4' => 'iPhone 12 Pro Max',
			'14,4' => 'iPhone 13 mini', '14,5' => 'iPhone 13', '14,2' => 'iPhone 13 Pro', '14,3' => 'iPhone 13 Pro Max', '14,6' => 'iPhone SE (3)',
			'14,7' => 'iPhone 14', '14,8' => 'iPhone 14 Plus', '15,2' => 'iPhone 14 Pro', '15,3' => 'iPhone 14 Pro Max',
			'15,4' => 'iPhone 15', '15,5' => 'iPhone 15 Plus', '16,1' => 'iPhone 15 Pro', '16,2' => 'iPhone 15 Pro Max',
			'17,3' => 'iPhone 16', '17,4' => 'iPhone 16 Plus', '17,1' => 'iPhone 16 Pro', '17,2' => 'iPhone 16 Pro Max', '17,5' => 'iPhone 16e',
		);
		$key = $major . ',' . $minor;
		return isset( $map[ $key ] ) ? $map[ $key ] : ( $major >= 12 ? 'iPhone (' . $key . ')' : '' );
	}

	private static function short_ver( $v, $parts ) {
		$p = array_filter( explode( '.', (string) $v ), 'strlen' );
		$p = array_slice( $p, 0, $parts );
		// Eliminam ",0" de la final (14.0 -> 14).
		while ( count( $p ) > 1 && '0' === end( $p ) ) {
			array_pop( $p );
		}
		return implode( '.', $p );
	}

	/**
	 * Nume comercial pentru codurile de model frecvente (ex. SM-S918B -> Samsung Galaxy S23 Ultra).
	 * Restul: producatorul dedus din prefix + codul modelului.
	 */
	public static function friendly_model( $model ) {
		$model = trim( preg_replace( '/^(SAMSUNG|samsung)\s+/', '', (string) $model ) );
		$code  = strtoupper( $model );

		$samsung = array(
			'SM-S93' => 'Galaxy S25', 'SM-S92' => 'Galaxy S24', 'SM-S91' => 'Galaxy S23', 'SM-S90' => 'Galaxy S22',
			'SM-G99' => 'Galaxy S21', 'SM-G98' => 'Galaxy S20', 'SM-G97' => 'Galaxy S10', 'SM-G96' => 'Galaxy S9',
			'SM-F95' => 'Galaxy Z Fold', 'SM-F94' => 'Galaxy Z Fold', 'SM-F93' => 'Galaxy Z Fold', 'SM-F92' => 'Galaxy Z Fold',
			'SM-F74' => 'Galaxy Z Flip', 'SM-F73' => 'Galaxy Z Flip', 'SM-F72' => 'Galaxy Z Flip', 'SM-F71' => 'Galaxy Z Flip',
			'SM-A05' => 'Galaxy A05', 'SM-A06' => 'Galaxy A06', 'SM-A12' => 'Galaxy A12', 'SM-A13' => 'Galaxy A13', 'SM-A14' => 'Galaxy A14',
			'SM-A15' => 'Galaxy A15', 'SM-A16' => 'Galaxy A16', 'SM-A25' => 'Galaxy A25', 'SM-A26' => 'Galaxy A26', 'SM-A32' => 'Galaxy A32',
			'SM-A33' => 'Galaxy A33', 'SM-A34' => 'Galaxy A34', 'SM-A35' => 'Galaxy A35', 'SM-A36' => 'Galaxy A36', 'SM-A51' => 'Galaxy A51',
			'SM-A52' => 'Galaxy A52', 'SM-A53' => 'Galaxy A53', 'SM-A54' => 'Galaxy A54', 'SM-A55' => 'Galaxy A55', 'SM-A56' => 'Galaxy A56',
			'SM-A71' => 'Galaxy A71', 'SM-A72' => 'Galaxy A72', 'SM-M' => 'Galaxy M', 'SM-X' => 'Galaxy Tab S', 'SM-T' => 'Galaxy Tab',
		);
		if ( 0 === strpos( $code, 'SM-' ) ) {
			// Seria S20+: cifra a 3-a a codului: 6 = Plus, 8 = Ultra (ex. SM-S918B = S23 Ultra).
			foreach ( $samsung as $prefix => $name ) {
				if ( 0 === strpos( $code, $prefix ) ) {
					if ( preg_match( '/^SM-(?:S9\d|G99|G98)(\d)/', $code, $m ) ) {
						$name .= '6' === $m[1] ? '+' : ( '8' === $m[1] ? ' Ultra' : '' );
					}
					return 'Samsung ' . $name . ' (' . $model . ')';
				}
			}
			return 'Samsung ' . $model;
		}

		$brands = array(
			'/^Pixel/i'                         => 'Google',
			'/^(Redmi|POCO|Mi |M\d{4}|2\d{6,}|Xiaomi)/i' => 'Xiaomi',
			'/^(ONEPLUS|(?:KB|LE|IN|HD|GM|NE|PJ)\d{4})/i' => 'OnePlus',
			'/^(CPH|PH[A-Z]\d)/i'                => 'OPPO / OnePlus',
			'/^RMX/i'                           => 'realme',
			'/^(moto|XT\d)/i'                   => 'Motorola',
			'/^(HUAWEI|[A-Z]{3}-(L|AL|LX|NX)\d)/i' => 'Huawei',
			'/^(Nokia|TA-\d)/i'                 => 'Nokia',
			'/^(V2\d{3}|vivo)/i'                => 'vivo',
			'/^LM-/i'                           => 'LG',
			'/^Infinix/i'                       => 'Infinix',
			'/^(TECNO)/i'                       => 'Tecno',
			'/^Nothing/i'                       => 'Nothing',
		);
		foreach ( $brands as $re => $brand ) {
			if ( preg_match( $re, $model ) ) {
				return 0 === stripos( $model, $brand ) ? $model : $brand . ' ' . $model;
			}
		}
		return $model;
	}
}
