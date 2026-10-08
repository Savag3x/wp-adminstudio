<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inlocuieste dashboard-ul implicit cu unul personalizat, in stilul MyWebDesign.
 * Carduri: KPI-uri, WooCommerce, Vizitatori, Stoc & top produse, actiuni, continut, sistem.
 */
class MWD_AS_Dashboard {

	public function hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'setup' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function setup() {
		global $wp_meta_boxes;
		$wp_meta_boxes['dashboard'] = array();
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
		wp_add_dashboard_widget( 'mwd_dashboard', 'MWD', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mwd-as-dashboard', MWD_AS_URL . 'assets/css/dashboard.css', array(), MWD_AS_VERSION );
		wp_enqueue_script( 'mwd-as-chart', MWD_AS_URL . 'assets/js/dashboard-chart.js', array(), MWD_AS_VERSION, true );

		$opts = MWD_AS_Defaults::get_options();
		if ( ! empty( $opts['track_visitors'] ) ) {
			wp_enqueue_script( 'mwd-as-online', MWD_AS_URL . 'assets/js/dashboard-online.js', array(), MWD_AS_VERSION, true );
			wp_localize_script(
				'mwd-as-online',
				'MWDOnline',
				array(
					'url'      => esc_url_raw( rest_url( MWD_AS_Tracker::NS . '/online' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'interval' => 20000,
				)
			);
		}
	}

	private function price_plain( $amount ) {
		return html_entity_decode( wp_strip_all_tags( MWD_AS_Woo::price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Mini-grafic (tendinta) pentru cardurile KPI: linie 1.5px + arie discreta, SVG pur.
	 */
	private function sparkline( $vals ) {
		$vals = array_map( 'floatval', array_values( (array) $vals ) );
		$n    = count( $vals );
		if ( $n < 2 ) {
			return '';
		}
		$max = max( $vals );
		$min = min( $vals );
		$rng = $max - $min > 0 ? $max - $min : 1;
		$pts = array();
		foreach ( $vals as $i => $v ) {
			$pts[] = round( $i * 100 / ( $n - 1 ), 2 ) . ',' . round( 28 - ( ( $v - $min ) / $rng ) * 24 - 2, 2 );
		}
		$line = 'M' . implode( ' L', $pts );
		return '<svg class="mwd-dash-spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<path class="a" d="' . esc_attr( $line . ' L100,28 L0,28 Z' ) . '"/>'
			. '<path class="l" d="' . esc_attr( $line ) . '"/></svg>';
	}

	/**
	 * Numar compact pentru KPI-uri: 1.284 / 12,9K / 4,2M.
	 */
	private function compact( $n ) {
		$n = (float) $n;
		if ( abs( $n ) >= 1000000 ) {
			return number_format_i18n( $n / 1000000, 1 ) . 'M';
		}
		if ( abs( $n ) >= 10000 ) {
			return number_format_i18n( $n / 1000, 1 ) . 'K';
		}
		return number_format_i18n( $n );
	}

	/**
	 * Perioada selectata (7/14/30 zile), retinuta per utilizator.
	 */
	private function resolve_period() {
		$allowed = array( 7, 14, 30 );
		$uid     = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mwd_period'] ) ) {
			$p = (int) $_GET['mwd_period'];
			if ( in_array( $p, $allowed, true ) ) {
				update_user_meta( $uid, 'mwd_as_period', $p );
				return $p;
			}
		}
		$saved = (int) get_user_meta( $uid, 'mwd_as_period', true );
		return in_array( $saved, $allowed, true ) ? $saved : 7;
	}

	public function render() {
		$opts  = MWD_AS_Defaults::get_options();
		$user  = wp_get_current_user();
		$name  = $user->first_name ? $user->first_name : $user->display_name;
		$title = ! empty( $opts['dashboard_title'] ) ? $opts['dashboard_title'] : sprintf( 'Bună, %s', $name );

		$days  = $this->resolve_period();
		$track = ! empty( $opts['track_visitors'] );
		$woo   = ( ! empty( $opts['woo_cards'] ) && MWD_AS_Woo::active() ) ? MWD_AS_Woo::summary( $days ) : null;
		$vis   = $track ? MWD_AS_Tracker::summary( $days ) : null;
		?>
		<div class="mwd-dash">

			<svg class="mwd-dash-defs" width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">
				<defs>
					<linearGradient id="mwd-g-area" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0" style="stop-color:var(--mwd-accent);stop-opacity:.32"/>
						<stop offset="1" style="stop-color:var(--mwd-accent);stop-opacity:0"/>
					</linearGradient>
					<linearGradient id="mwd-g-spark" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0" style="stop-color:var(--mwd-accent);stop-opacity:.22"/>
						<stop offset="1" style="stop-color:var(--mwd-accent);stop-opacity:0"/>
					</linearGradient>
					<linearGradient id="mwd-g-bar" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0" style="stop-color:var(--mwd-accent);stop-opacity:1"/>
						<stop offset="1" style="stop-color:var(--mwd-accent-hover);stop-opacity:.55"/>
					</linearGradient>
				</defs>
			</svg>

			<div class="mwd-dash-head">
				<div>
					<h1 class="mwd-dash-title"><?php echo esc_html( $title ); ?></h1>
					<p class="mwd-dash-sub"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( date_i18n( 'l, j F Y' ) ); ?></p>
				</div>
				<div class="mwd-dash-head-right">
					<?php if ( $track ) : $online_now = MWD_AS_Tracker::online_count(); ?>
						<span class="mwd-dash-online<?php echo $online_now > 0 ? ' is-live' : ''; ?>" title="Vizitatori activi în ultimele 5 minute">
							<span class="mwd-dash-online-dot"></span><span id="mwd-online-count"><?php echo (int) $online_now; ?></span> online
						</span>
					<?php endif; ?>
					<div class="mwd-dash-period">
						<?php foreach ( array( 7, 14, 30 ) as $opt ) : ?>
							<a class="mwd-dash-period-btn<?php echo $opt === $days ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'index.php?mwd_period=' . $opt ) ); ?>"><?php echo (int) $opt; ?> zile</a>
						<?php endforeach; ?>
					</div>
					<a class="mwd-dash-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">Vezi site-ul <span aria-hidden="true">↗</span></a>
				</div>
			</div>

			<div class="mwd-dash-stats">
				<?php foreach ( $this->kpis( $woo, $vis, $days ) as $s ) : ?>
					<a class="mwd-dash-stat" href="<?php echo esc_url( $s['url'] ); ?>">
						<span class="mwd-dash-stat-ico"><span class="dashicons <?php echo esc_attr( $s['icon'] ); ?>"></span></span>
						<span class="mwd-dash-stat-label"><?php echo esc_html( $s['label'] ); ?></span>
						<span class="mwd-dash-stat-num<?php echo ! empty( $s['accent'] ) ? ' is-accent' : ''; ?>"><?php echo esc_html( $s['value'] ); ?></span>
						<span class="mwd-dash-stat-foot">
							<?php if ( ! empty( $s['delta'] ) ) : ?>
								<?php echo MWD_AS_Tracker::delta_badge( $s['delta'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="mwd-dash-stat-vs">vs. anterior</span>
							<?php endif; ?>
						</span>
						<?php if ( ! empty( $s['spark'] ) ) : ?>
							<?php echo $this->sparkline( $s['spark'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php $this->card_trend( $woo, $vis, $days ); ?>

			<div class="mwd-dash-grid">
				<?php
				if ( $vis ) {
					$this->card_live();
				}
				if ( $woo ) {
					$this->card_woo( $woo, $days );
				}
				if ( $vis ) {
					$this->card_visitors( $vis, $days );
				}
				if ( $woo ) {
					$this->card_stock();
					$this->card_attribution( $woo, $days );
					$this->card_recent_orders();
				} else {
					$this->card_recent_content();
				}
				$this->card_actions();
				$this->card_health();
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * KPI-urile de sus, adaptate la Woo / tracker si perioada.
	 */
	private function kpis( $woo, $vis, $days ) {
		$k      = array();
		$orders = admin_url( MWD_AS_Woo::hpos() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' );

		if ( $woo ) {
			$k[] = array( 'label' => 'Vânzări', 'value' => $this->price_plain( $woo['sales_period'] ), 'icon' => 'dashicons-chart-line', 'url' => $orders, 'delta' => MWD_AS_Tracker::delta( $woo['sales_period'], $woo['prev']['sales_period'] ), 'spark' => $woo['sales_series'] );
			$k[] = array( 'label' => 'Comenzi', 'value' => $this->compact( $woo['orders_period'] ), 'icon' => 'dashicons-cart', 'url' => $orders, 'delta' => MWD_AS_Tracker::delta( $woo['orders_period'], $woo['prev']['orders_period'] ), 'spark' => $woo['orders_series'] );
			$k[] = array( 'label' => 'Valoare medie comandă', 'value' => $this->price_plain( $woo['aov'] ), 'icon' => 'dashicons-tag', 'url' => $orders, 'delta' => MWD_AS_Tracker::delta( $woo['aov'], $woo['prev']['aov'] ) );
		} else {
			$p   = wp_count_posts( 'post' );
			$pg  = wp_count_posts( 'page' );
			$k[] = array( 'label' => 'Articole publicate', 'value' => $this->compact( isset( $p->publish ) ? $p->publish : 0 ), 'icon' => 'dashicons-admin-post', 'url' => admin_url( 'edit.php' ) );
			$k[] = array( 'label' => 'Pagini', 'value' => $this->compact( isset( $pg->publish ) ? $pg->publish : 0 ), 'icon' => 'dashicons-admin-page', 'url' => admin_url( 'edit.php?post_type=page' ) );
			$c       = wp_count_comments();
			$pending = isset( $c->moderated ) ? (int) $c->moderated : 0;
			$k[]     = array( 'label' => $pending ? 'Comentarii · ' . $pending . ' de aprobat' : 'Comentarii', 'value' => $this->compact( isset( $c->approved ) ? $c->approved : 0 ), 'icon' => 'dashicons-admin-comments', 'url' => admin_url( 'edit-comments.php' . ( $pending ? '?comment_status=moderated' : '' ) ) );
		}

		if ( $vis ) {
			$k[] = array( 'label' => 'Vizite', 'value' => $this->compact( $vis['sessions'] ), 'icon' => 'dashicons-chart-area', 'url' => admin_url( 'admin.php?page=mwd-analytics&p=' . $days ), 'delta' => MWD_AS_Tracker::delta( $vis['sessions'], $vis['prev']['sessions'] ), 'spark' => $vis['spark'] );
			if ( $woo && $vis['sessions'] > 0 ) {
				$rate = $woo['orders_period'] / $vis['sessions'] * 100;
				$prev = $vis['prev']['sessions'] > 0 ? $woo['prev']['orders_period'] / $vis['prev']['sessions'] * 100 : 0;
				$k[]  = array( 'label' => 'Rată de conversie', 'value' => number_format_i18n( $rate, 2 ) . '%', 'icon' => 'dashicons-performance', 'url' => admin_url( 'admin.php?page=mwd-analytics&p=' . $days ), 'delta' => MWD_AS_Tracker::delta( $rate, $prev ) );
			}
		} elseif ( ! $woo ) {
			$u   = function_exists( 'get_user_count' ) ? get_user_count() : count_users()['total_users'];
			$k[] = array( 'label' => 'Utilizatori', 'value' => $this->compact( $u ), 'icon' => 'dashicons-admin-users', 'url' => admin_url( 'users.php' ) );
		}

		return $k;
	}

	/**
	 * Graficul principal: o singura metrica odata (fara axe duble), comutabila din tab-uri.
	 */
	private function card_trend( $woo, $vis, $days ) {
		$metrics = array();
		$dates   = array();
		if ( $vis ) {
			$dates     = $vis['dates'];
			$metrics[] = array( 'key' => 'sessions', 'label' => 'Vizite', 'type' => 'area', 'values' => $vis['spark'], 'total' => $vis['sessions'], 'delta' => MWD_AS_Tracker::delta( $vis['sessions'], $vis['prev']['sessions'] ) );
			$metrics[] = array( 'key' => 'views', 'label' => 'Afișări', 'type' => 'area', 'values' => $vis['views_spark'], 'total' => $vis['views'], 'delta' => MWD_AS_Tracker::delta( $vis['views'], $vis['prev']['views'] ) );
		}
		if ( $woo ) {
			$dates     = $woo['dates'];
			$metrics[] = array( 'key' => 'sales', 'label' => 'Vânzări', 'type' => 'bar', 'money' => true, 'values' => $woo['sales_series'], 'total' => $woo['sales_period'], 'delta' => MWD_AS_Tracker::delta( $woo['sales_period'], $woo['prev']['sales_period'] ) );
			$metrics[] = array( 'key' => 'orders', 'label' => 'Comenzi', 'type' => 'bar', 'values' => $woo['orders_series'], 'total' => $woo['orders_period'], 'delta' => MWD_AS_Tracker::delta( $woo['orders_period'], $woo['prev']['orders_period'] ) );
		}
		if ( ! $metrics ) {
			return;
		}
		// Magazinul conduce cu vanzarile; altfel vizitele.
		if ( $woo ) {
			$metrics = array_merge( array_slice( $metrics, -2 ), array_slice( $metrics, 0, -2 ) );
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$data     = array(
			'dates'    => $dates,
			'metrics'  => $metrics,
			'locale'   => str_replace( '_', '-', get_user_locale() ),
			'currency' => $currency,
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
			'prevText' => sprintf( 'față de %d zile anterioare', $days ),
		);
		?>
		<div class="mwd-dash-card mwd-dash-trend" data-mwd-chart="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
			<div class="mwd-dash-trend-head">
				<div class="mwd-dash-trend-tabs" role="tablist" aria-label="Metrică">
					<?php foreach ( $metrics as $i => $m ) : ?>
						<button type="button" role="tab" class="mwd-dash-trend-tab<?php echo 0 === $i ? ' is-active' : ''; ?>" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>" data-metric="<?php echo esc_attr( $m['key'] ); ?>"><?php echo esc_html( $m['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="mwd-dash-trend-hero">
					<span class="mwd-dash-trend-total" data-role="total"></span>
					<span data-role="delta"></span>
					<span class="mwd-dash-trend-sub" data-role="sub"></span>
				</div>
			</div>
			<div class="mwd-dash-trend-plot" data-role="plot" aria-hidden="true"></div>
			<table class="screen-reader-text" data-role="table"><caption>Valori pe zile</caption></table>
			<noscript><p class="mwd-dash-empty">Graficul necesită JavaScript.</p></noscript>
		</div>
		<?php
	}

	/**
	 * Vizitatori activi acum (actualizat automat).
	 */
	private function card_live() {
		$list = MWD_AS_Tracker::online_list();
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Acum pe site <span class="mwd-dash-live-badge"><span class="mwd-dash-online-dot"></span> live</span></div>
			<div id="mwd-live-list">
				<?php if ( $list ) : foreach ( $list as $v ) : ?>
					<div class="mwd-dash-row">
						<span class="mwd-dash-row-t mwd-dash-ellip"><span class="dashicons <?php echo esc_attr( 'mobile' === $v['device'] ? 'dashicons-smartphone' : ( 'tablet' === $v['device'] ? 'dashicons-tablet' : 'dashicons-desktop' ) ); ?>"></span> <?php echo esc_html( $v['page'] ); ?></span>
						<span class="mwd-dash-row-m"><?php echo esc_html( $v['country'] ? $v['country'] : '—' ); ?></span>
					</div>
				<?php endforeach; else : ?>
					<p class="mwd-dash-empty">Niciun vizitator în ultimele 5 minute.</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanatatea site-ului: actualizari, PHP, vizibilitate, debug. Doar pentru administratori.
	 */
	private function card_health() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$items = array();

		if ( current_user_can( 'update_plugins' ) ) {
			$plugins = get_site_transient( 'update_plugins' );
			$themes  = get_site_transient( 'update_themes' );
			$core    = get_site_transient( 'update_core' );
			$np      = isset( $plugins->response ) ? count( (array) $plugins->response ) : 0;
			$nt      = isset( $themes->response ) ? count( (array) $themes->response ) : 0;
			$nc      = 0;
			if ( isset( $core->updates ) && is_array( $core->updates ) ) {
				foreach ( $core->updates as $u ) {
					if ( isset( $u->response ) && 'upgrade' === $u->response ) {
						$nc = 1;
						break;
					}
				}
			}
			$total   = $np + $nt + $nc;
			$parts   = array();
			if ( $nc ) {
				$parts[] = 'WordPress';
			}
			if ( $np ) {
				$parts[] = $np . ( 1 === $np ? ' plugin' : ' plugin-uri' );
			}
			if ( $nt ) {
				$parts[] = $nt . ( 1 === $nt ? ' temă' : ' teme' );
			}
			$items[] = $total
				? array( 'warn', 'Actualizări disponibile', implode( ', ', $parts ), admin_url( 'update-core.php' ) )
				: array( 'ok', 'Totul este actualizat', '', admin_url( 'update-core.php' ) );
		}

		$items[] = version_compare( PHP_VERSION, '8.1', '<' )
			? array( 'warn', 'PHP ' . PHP_VERSION, 'Versiune veche — recomandat 8.1+ pentru viteză și securitate.', '' )
			: array( 'ok', 'PHP ' . PHP_VERSION, '', '' );

		if ( ! get_option( 'blog_public' ) ) {
			$items[] = array( 'warn', 'Ascuns de motoarele de căutare', 'Bifa „Descurajează indexarea" este activă.', admin_url( 'options-reading.php' ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
			$items[] = array( 'warn', 'Erorile PHP sunt afișate', 'WP_DEBUG_DISPLAY e activ — dezactivează-l în producție.', '' );
		}
		if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) {
			$items[] = array( 'warn', 'Site fără HTTPS', 'Adresa site-ului nu folosește https://.', admin_url( 'options-general.php' ) );
		}
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Sănătate site <a class="mwd-dash-card-link" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>">detalii →</a></div>
			<?php foreach ( $items as $it ) : $tag = $it[3] ? 'a' : 'div'; ?>
				<<?php echo $tag; // phpcs:ignore ?> class="mwd-dash-row mwd-dash-health is-<?php echo esc_attr( $it[0] ); ?>"<?php echo $it[3] ? ' href="' . esc_url( $it[3] ) . '"' : ''; ?>>
					<span class="mwd-dash-health-ico dashicons <?php echo 'ok' === $it[0] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
					<span class="mwd-dash-health-txt">
						<span class="mwd-dash-row-t"><?php echo esc_html( $it[1] ); ?></span>
						<?php if ( $it[2] ) : ?><span class="mwd-dash-muted"><?php echo esc_html( $it[2] ); ?></span><?php endif; ?>
					</span>
					<span class="screen-reader-text"><?php echo 'ok' === $it[0] ? 'în regulă' : 'atenție'; ?></span>
				</<?php echo $tag; // phpcs:ignore ?>>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function card_woo( $w, $days ) {
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">WooCommerce</div>
			<div class="mwd-dash-woo-hero">
				<?php if ( $w['to_process'] > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( MWD_AS_Woo::hpos() ? 'admin.php?page=wc-orders&status=wc-processing' : 'edit.php?post_status=wc-processing&post_type=shop_order' ) ); ?>" class="mwd-dash-woo-alert">
						<span class="mwd-dash-woo-big"><?php echo esc_html( number_format_i18n( $w['to_process'] ) ); ?></span>
						<span>comenzi de procesat</span>
					</a>
				<?php else : ?>
					<div class="mwd-dash-woo-ok"><span class="dashicons dashicons-yes-alt"></span> Nicio comandă nouă</div>
				<?php endif; ?>
			</div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Comenzi azi</span><span class="mwd-dash-row-m num"><?php echo esc_html( number_format_i18n( $w['orders_today'] ) ); ?></span></div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Vânzări azi</span><span class="mwd-dash-row-m num"><?php echo esc_html( $this->price_plain( $w['sales_today'] ) ); ?></span></div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Vânzări <?php echo (int) $days; ?> zile</span><span class="mwd-dash-row-m num"><?php echo esc_html( $this->price_plain( $w['sales_period'] ) ); ?></span></div>
		</div>
		<?php
	}

	private function card_visitors( $v, $days ) {
		$total = max( 1, array_sum( $v['devices'] ) );
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Vizitatori (<?php echo (int) $days; ?> zile) <a class="mwd-dash-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=mwd-analytics' ) ); ?>">detalii →</a></div>
			<div class="mwd-dash-vis-top">
				<div class="mwd-dash-vis-nums">
					<div><span class="num"><?php echo esc_html( number_format_i18n( $v['sessions'] ) ); ?></span> vizite · <span class="num"><?php echo esc_html( number_format_i18n( $v['views'] ) ); ?></span> afișări</div>
					<div class="mwd-dash-muted"><span class="num"><?php echo esc_html( number_format_i18n( $v['today'] ) ); ?></span> azi · <span class="num"><?php echo esc_html( number_format_i18n( $v['unique'] ) ); ?></span> unici</div>
				</div>
			</div>

			<div class="mwd-dash-vis-extra">
				<span>Timp mediu: <strong class="num"><?php echo esc_html( MWD_AS_Tracker::fmt_time( $v['avg_time'] ) ); ?></strong></span>
				<span>Respingere: <strong class="num"><?php echo esc_html( $v['bounce'] ); ?>%</strong></span>
			</div>

			<div class="mwd-dash-dev">
				<?php
				$labels = array( 'desktop' => 'Desktop', 'mobile' => 'Mobil', 'tablet' => 'Tabletă' );
				foreach ( $labels as $key => $lab ) :
					$pct = round( $v['devices'][ $key ] / $total * 100 );
					?>
					<div class="mwd-dash-dev-row">
						<span class="mwd-dash-dev-lab"><?php echo esc_html( $lab ); ?></span>
						<span class="mwd-dash-dev-bar"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></span>
						<span class="mwd-dash-dev-pct num"><?php echo esc_html( $pct ); ?>%</span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mwd-dash-mini-h">Top pagini</div>
			<?php if ( $v['pages'] ) : foreach ( $v['pages'] as $p ) : ?>
				<div class="mwd-dash-row"><span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $p->url ); ?></span><span class="mwd-dash-row-m num"><?php echo esc_html( number_format_i18n( $p->c ) ); ?></span></div>
			<?php endforeach; else : ?>
				<p class="mwd-dash-empty">Încă fără date.</p>
			<?php endif; ?>

			<div class="mwd-dash-mini-h">Top surse</div>
			<?php if ( $v['sources'] ) : foreach ( $v['sources'] as $r ) : ?>
				<div class="mwd-dash-row"><span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $r->referrer ); ?></span><span class="mwd-dash-row-m num"><?php echo esc_html( number_format_i18n( $r->c ) ); ?></span></div>
			<?php endforeach; else : ?>
				<div class="mwd-dash-row"><span class="mwd-dash-row-t">Direct / fără referrer</span><span class="mwd-dash-row-m">—</span></div>
			<?php endif; ?>

			<?php if ( ! empty( $v['countries'] ) ) : ?>
				<div class="mwd-dash-mini-h">Top țări</div>
				<?php foreach ( $v['countries'] as $ct ) : ?>
					<div class="mwd-dash-row"><span class="mwd-dash-row-t"><?php echo esc_html( $ct->country ); ?></span><span class="mwd-dash-row-m num"><?php echo esc_html( number_format_i18n( $ct->c ) ); ?></span></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function card_attribution( $w, $days ) {
		if ( empty( $w['attribution'] ) ) {
			return;
		}
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Atribuire vânzări (<?php echo (int) $days; ?> zile)</div>
			<div class="mwd-dash-mini-h">Sursă · comenzi · venit</div>
			<?php foreach ( $w['attribution'] as $a ) : ?>
				<div class="mwd-dash-row">
					<span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $a['source'] ); ?> <span class="mwd-dash-muted">(<?php echo esc_html( number_format_i18n( $a['orders'] ) ); ?>)</span></span>
					<span class="mwd-dash-row-m num"><?php echo esc_html( $this->price_plain( $a['revenue'] ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function card_stock() {
		$low  = MWD_AS_Woo::low_stock();
		$best = MWD_AS_Woo::best_sellers();
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Stoc & top produse</div>
			<div class="mwd-dash-mini-h">Stoc redus</div>
			<?php if ( $low ) : foreach ( $low as $p ) : ?>
				<a class="mwd-dash-row" href="<?php echo esc_url( $p['url'] ); ?>">
					<span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $p['name'] ); ?></span>
					<span class="mwd-dash-row-m num <?php echo $p['stock'] <= 0 ? 'is-zero' : ''; ?>"><?php echo esc_html( $p['stock'] ); ?> buc</span>
				</a>
			<?php endforeach; else : ?>
				<div class="mwd-dash-row"><span class="mwd-dash-row-t"><span class="dashicons dashicons-yes-alt" style="color:var(--mwd-accent,#10b981)"></span> Stoc ok</span></div>
			<?php endif; ?>

			<div class="mwd-dash-mini-h">Cele mai vândute</div>
			<?php if ( $best ) : foreach ( $best as $p ) : ?>
				<a class="mwd-dash-row" href="<?php echo esc_url( $p['url'] ); ?>">
					<span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $p['name'] ); ?></span>
					<span class="mwd-dash-row-m num"><?php echo esc_html( number_format_i18n( $p['sales'] ) ); ?> vândute</span>
				</a>
			<?php endforeach; else : ?>
				<p class="mwd-dash-empty">Încă fără vânzări.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function card_actions() {
		$actions = array(
			array( 'post-new.php', 'dashicons-edit', 'Articol nou', 'edit_posts' ),
			array( 'post-new.php?post_type=page', 'dashicons-admin-page', 'Pagină nouă', 'edit_pages' ),
			array( 'media-new.php', 'dashicons-admin-media', 'Încarcă media', 'upload_files' ),
			array( 'edit-comments.php', 'dashicons-admin-comments', 'Comentarii', 'moderate_comments' ),
			array( 'themes.php', 'dashicons-admin-appearance', 'Aspect', 'switch_themes' ),
			array( 'admin.php?page=mwd-admin-studio', 'dashicons-art', 'Admin Studio', 'manage_options' ),
		);
		$actions = array_filter(
			$actions,
			function ( $a ) {
				return current_user_can( $a[3] );
			}
		);
		if ( ! $actions ) {
			return;
		}
		$opts = MWD_AS_Defaults::get_options();
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Acțiuni rapide
				<?php if ( ! empty( $opts['cmd_palette'] ) ) : ?>
					<a href="#" class="mwd-dash-card-link" data-mwd-cmdk>Caută <kbd class="mwd-cmdk-kbd">⌘K</kbd></a>
				<?php endif; ?>
			</div>
			<div class="mwd-dash-actions">
				<?php foreach ( $actions as $a ) : ?>
					<a class="mwd-dash-action" href="<?php echo esc_url( admin_url( $a[0] ) ); ?>"><span class="dashicons <?php echo esc_attr( $a[1] ); ?>"></span><?php echo esc_html( $a[2] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function card_recent_content() {
		$recent = wp_get_recent_posts( array( 'numberposts' => 6, 'post_status' => 'publish' ), OBJECT );
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Conținut recent</div>
			<?php if ( $recent ) : foreach ( $recent as $p ) : ?>
				<a class="mwd-dash-row" href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>">
					<span class="mwd-dash-row-t mwd-dash-ellip"><?php echo esc_html( $p->post_title ? $p->post_title : '(fără titlu)' ); ?></span>
					<span class="mwd-dash-row-m num"><?php echo esc_html( get_the_date( 'j M', $p ) ); ?></span>
				</a>
			<?php endforeach; else : ?>
				<p class="mwd-dash-empty">Niciun articol încă.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function card_recent_orders() {
		$orders = MWD_AS_Woo::recent_orders();
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Comenzi recente</div>
			<?php if ( $orders ) : foreach ( $orders as $o ) :
				$accent = in_array( $o['status_key'], array( 'processing', 'on-hold', 'pending' ), true ); ?>
				<a class="mwd-dash-row" href="<?php echo esc_url( $o['url'] ); ?>">
					<span class="mwd-dash-row-t mwd-dash-ellip"><strong>#<?php echo esc_html( $o['number'] ); ?></strong> <?php echo esc_html( $o['name'] ); ?></span>
					<span class="mwd-dash-ord-right">
						<span class="mwd-dash-opill<?php echo $accent ? ' is-accent' : ''; ?>"><?php echo esc_html( $o['status'] ); ?></span>
						<span class="mwd-dash-row-m num"><?php echo esc_html( $o['total'] ); ?></span>
					</span>
				</a>
			<?php endforeach; else : ?>
				<p class="mwd-dash-empty">Nicio comandă încă.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
