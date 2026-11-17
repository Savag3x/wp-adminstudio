<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raport saptamanal pe email: vizite, vanzari (WooCommerce), top pagini / surse / tari,
 * comparat cu saptamana anterioara. Trimis prin WP-Cron, in ziua si la ora alese.
 */
class MWD_AS_Report {

	const HOOK = 'mwd_as_weekly_report';

	public function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( 'admin_post_mwd_as_report_test', array( $this, 'send_test' ) );
		add_action( 'admin_post_mwd_as_report_preview', array( $this, 'preview' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * (Re)programeaza raportul conform setarilor; il opreste cand e dezactivat.
	 */
	public static function schedule() {
		$o    = MWD_AS_Defaults::get_options();
		$next = wp_next_scheduled( self::HOOK );
		if ( empty( $o['report_enabled'] ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		$want = self::next_run( (int) $o['report_day'] );
		// Reprogramam doar daca ziua s-a schimbat (o diferenta de ore = alt fus / ora de vara).
		if ( $next && abs( $next - $want ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( $want, 'weekly', self::HOOK );
	}

	/**
	 * Urmatoarea zi aleasa (1 = luni ... 7 = duminica), la 08:00 ora site-ului, ca timestamp UTC.
	 */
	private static function next_run( $day ) {
		$day = max( 1, min( 7, $day ) );
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );
		$run = new DateTime( 'today 08:00', $tz );
		$add = ( $day - (int) $now->format( 'N' ) + 7 ) % 7;
		if ( 0 === $add && $run <= $now ) {
			$add = 7;
		}
		$run->modify( '+' . $add . ' days' );
		return $run->getTimestamp();
	}

	public static function recipients() {
		$o    = MWD_AS_Defaults::get_options();
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $o['report_to'] ) ), 'is_email' );
		return $list ? array_values( $list ) : array( get_option( 'admin_email' ) );
	}

	public static function send( $to = null ) {
		$to      = $to ? (array) $to : self::recipients();
		$subject = sprintf( 'Raport săptămânal — %s (%s)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::range_label() );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, $subject, self::build(), $headers );
	}

	public function send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( 'mwd_as_report_test' );
		$ok = self::send();
		wp_safe_redirect( MWD_AS_Settings::url( array( 'mwd_msg' => $ok ? 'report_sent' : 'report_failed', 'tab' => 'dashboard' ) ) );
		exit;
	}

	public function preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( 'mwd_as_report_preview' );
		echo self::build(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construit si escapat in build().
		exit;
	}

	private static function range_label() {
		$end   = current_time( 'timestamp' );
		$start = $end - 6 * DAY_IN_SECONDS;
		return date_i18n( 'j M', $start ) . ' – ' . date_i18n( 'j M Y', $end );
	}

	private static function delta_html( $cur, $prev, $good_up = true ) {
		$d = MWD_AS_Tracker::delta( $cur, $prev );
		if ( ! $d ) {
			return '';
		}
		if ( ! empty( $d['new'] ) ) {
			return '<span style="color:#047857;font-size:12px;font-weight:600;">▲ nou</span>';
		}
		if ( 'flat' === $d['dir'] ) {
			return '<span style="color:#71717a;font-size:12px;">0%</span>';
		}
		$good  = ( 'up' === $d['dir'] ) === $good_up;
		$color = $good ? '#047857' : '#b91c1c';
		return '<span style="color:' . $color . ';font-size:12px;font-weight:600;">' . ( 'up' === $d['dir'] ? '▲' : '▼' ) . ' ' . (int) $d['pct'] . '%</span>';
	}

	private static function price( $v ) {
		return html_entity_decode( wp_strip_all_tags( MWD_AS_Woo::price( $v ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * HTML-ul raportului (stiluri inline, compatibil cu clientii de email).
	 */
	public static function build() {
		$o      = MWD_AS_Defaults::get_options();
		$accent = sanitize_hex_color( $o['accent'] ) ? $o['accent'] : '#10b981';
		$vis    = ! empty( $o['track_visitors'] ) ? MWD_AS_Tracker::summary( 7 ) : null;
		$woo    = MWD_AS_Woo::active() ? MWD_AS_Woo::summary( 7 ) : null;
		$name   = get_bloginfo( 'name' );

		$kpis = array();
		if ( $woo ) {
			$kpis[] = array( 'Vânzări', self::price( $woo['sales_period'] ), self::delta_html( $woo['sales_period'], $woo['prev']['sales_period'] ) );
			$kpis[] = array( 'Comenzi', number_format_i18n( $woo['orders_period'] ), self::delta_html( $woo['orders_period'], $woo['prev']['orders_period'] ) );
		}
		if ( $vis ) {
			$kpis[] = array( 'Vizite', number_format_i18n( $vis['sessions'] ), self::delta_html( $vis['sessions'], $vis['prev']['sessions'] ) );
			$kpis[] = array( 'Vizitatori unici', number_format_i18n( $vis['unique'] ), self::delta_html( $vis['unique'], $vis['prev']['unique'] ) );
			$kpis[] = array( 'Afișări', number_format_i18n( $vis['views'] ), self::delta_html( $vis['views'], $vis['prev']['views'] ) );
			$kpis[] = array( 'Timp mediu', MWD_AS_Tracker::fmt_time( $vis['avg_time'] ), self::delta_html( $vis['avg_time'], $vis['prev']['avg_time'] ) );
		}

		$lists = array();
		if ( $vis ) {
			$rows = array();
			foreach ( (array) $vis['pages'] as $p ) {
				$rows[] = array( $p->url, number_format_i18n( $p->c ) );
			}
			$lists['Top pagini'] = $rows;
			$rows                = array();
			foreach ( (array) $vis['sources'] as $p ) {
				$rows[] = array( $p->referrer, number_format_i18n( $p->c ) );
			}
			$lists['Top surse'] = $rows;
			$rows               = array();
			foreach ( (array) $vis['countries'] as $p ) {
				$rows[] = array( trim( MWD_AS_Client::flag( $p->country ) . ' ' . MWD_AS_Client::country_name( $p->country ) ), number_format_i18n( $p->c ) );
			}
			$lists['Țări'] = $rows;
		}
		if ( $woo && ! empty( $woo['attribution'] ) ) {
			$rows = array();
			foreach ( $woo['attribution'] as $a ) {
				$rows[] = array( $a['source'] . ' (' . number_format_i18n( $a['orders'] ) . ')', self::price( $a['revenue'] ) );
			}
			$lists['Vânzări pe surse'] = $rows;
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $name ); ?></title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e4e4e7;">
	<tr><td style="padding:24px 28px;background:<?php echo esc_attr( $accent ); ?>;color:#ffffff;">
		<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;">Raport săptămânal</div>
		<div style="font-size:22px;font-weight:700;margin-top:4px;"><?php echo esc_html( $name ); ?></div>
		<div style="font-size:13px;opacity:.9;margin-top:2px;"><?php echo esc_html( self::range_label() ); ?> · față de săptămâna anterioară</div>
	</td></tr>
	<?php if ( $kpis ) : ?>
	<tr><td style="padding:20px 20px 4px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
		<?php foreach ( $kpis as $i => $k ) : ?>
			<?php if ( $i && 0 === $i % 3 ) : ?></tr><tr><?php endif; ?>
			<td width="33%" style="padding:8px;vertical-align:top;">
				<div style="border:1px solid #e4e4e7;border-radius:10px;padding:12px 14px;">
					<div style="font-size:12px;color:#71717a;"><?php echo esc_html( $k[0] ); ?></div>
					<div style="font-size:20px;font-weight:700;margin-top:4px;"><?php echo esc_html( $k[1] ); ?></div>
					<div style="margin-top:2px;"><?php echo wp_kses_post( $k[2] ); ?></div>
				</div>
			</td>
		<?php endforeach; ?>
		</tr></table>
	</td></tr>
	<?php endif; ?>
	<?php foreach ( $lists as $title => $rows ) : ?>
	<tr><td style="padding:12px 28px 4px;">
		<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#a1a1aa;font-weight:700;margin-bottom:6px;"><?php echo esc_html( $title ); ?></div>
		<?php if ( $rows ) : ?>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
			<?php foreach ( $rows as $r ) : ?>
				<tr><td style="padding:7px 0;border-bottom:1px solid #f4f4f5;font-size:13px;"><?php echo esc_html( $r[0] ); ?></td><td align="right" style="padding:7px 0;border-bottom:1px solid #f4f4f5;font-size:13px;color:#52525b;white-space:nowrap;"><?php echo esc_html( $r[1] ); ?></td></tr>
			<?php endforeach; ?>
			</table>
		<?php else : ?>
			<div style="font-size:13px;color:#a1a1aa;padding:4px 0 8px;">Fără date.</div>
		<?php endif; ?>
	</td></tr>
	<?php endforeach; ?>
	<tr><td style="padding:20px 28px 26px;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=mwd-analytics&p=7' ) ); ?>" style="display:inline-block;background:<?php echo esc_attr( $accent ); ?>;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:8px;">Vezi analiticele complete</a>
	</td></tr>
</table>
<div style="font-size:11px;color:#a1a1aa;margin-top:12px;">Trimis automat de <?php echo esc_html( $o['brand_menu_label'] ); ?> · <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
</td></tr></table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
