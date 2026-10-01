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
	 * Grafic de arie (linie + umplutura) din valori, SVG pur.
	 */
	private function svg_area( $vals ) {
		$vals = array_map( 'floatval', array_values( $vals ) );
		if ( count( $vals ) < 2 ) {
			$vals = array( 0, 0 );
		}
		$n    = count( $vals );
		$max  = max( $vals );
		$max  = $max > 0 ? $max : 1;
		$w    = 100;
		$h    = 40;
		$pts  = array();
		foreach ( $vals as $i => $v ) {
			$x     = round( $i * ( $w / ( $n - 1 ) ), 2 );
			$y     = round( $h - ( $v / $max ) * ( $h - 4 ) - 2, 2 );
			$pts[] = $x . ',' . $y;
		}
		$line = 'M' . implode( ' L', $pts );
		$area = $line . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z';

		return '<svg class="mwd-dash-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
			. '<path class="area" d="' . esc_attr( $area ) . '"/>'
			. '<path class="line" d="' . esc_attr( $line ) . '"/></svg>';
	}

	/**
	 * Grafic cu bare din valori, SVG pur.
	 */
	private function svg_bars( $vals ) {
		$vals = array_map( 'floatval', array_values( $vals ) );
		if ( empty( $vals ) ) {
			$vals = array( 0 );
		}
		$n    = count( $vals );
		$max  = max( $vals );
		$max  = $max > 0 ? $max : 1;
		$w    = 100;
		$h    = 40;
		$slot = $w / $n;
		$bw   = $slot * 0.6;
		$svg  = '<svg class="mwd-dash-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">';
		foreach ( $vals as $i => $v ) {
			$bh = round( ( $v / $max ) * ( $h - 2 ), 2 );
			$x  = round( $i * $slot + ( $slot - $bw ) / 2, 2 );
			$y  = round( $h - $bh, 2 );
			$svg .= '<rect class="bar" x="' . $x . '" y="' . $y . '" width="' . round( $bw, 2 ) . '" height="' . $bh . '" rx="0.6"/>';
		}
		return $svg . '</svg>';
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
					<a class="mwd-dash-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">Vezi site-ul</a>
				</div>
			</div>

			<div class="mwd-dash-stats">
				<?php foreach ( $this->kpis( $woo, $vis, $days ) as $s ) : ?>
					<a class="mwd-dash-stat" href="<?php echo esc_url( $s['url'] ); ?>">
						<span class="mwd-dash-stat-ico"><span class="dashicons <?php echo esc_attr( $s['icon'] ); ?>"></span></span>
						<span class="mwd-dash-stat-label"><?php echo esc_html( $s['label'] ); ?></span>
						<span class="mwd-dash-stat-num<?php echo ! empty( $s['accent'] ) ? ' is-accent' : ''; ?>"><?php echo esc_html( $s['value'] ); ?></span>
						<?php if ( ! empty( $s['delta'] ) ) : ?>
							<?php echo MWD_AS_Tracker::delta_badge( $s['delta'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="mwd-dash-grid">
				<?php
				if ( $woo ) {
					$this->card_woo( $woo, $days );
				}
				if ( $vis ) {
					$this->card_visitors( $vis, $days );
				}
				if ( $woo ) {
					$this->card_stock();
				}
				if ( $woo ) {
					$this->card_attribution( $woo, $days );
				}
				if ( $woo ) {
					$this->card_recent_orders();
				} else {
					$this->card_recent_content();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * KPI-urile de sus (4), adaptate la Woo / tracker si perioada.
	 */
	private function kpis( $woo, $vis, $days ) {
		$k = array();

		if ( $woo ) {
			$k[] = array( 'label' => 'Vânzări (' . $days . ' zile)', 'value' => $this->price_plain( $woo['sales_period'] ), 'icon' => 'dashicons-chart-line', 'url' => admin_url( 'edit.php?post_type=shop_order' ), 'accent' => true, 'delta' => MWD_AS_Tracker::delta( $woo['sales_period'], $woo['prev']['sales_period'] ) );
			$k[] = array( 'label' => 'Comenzi (' . $days . ' zile)', 'value' => number_format_i18n( $woo['orders_period'] ), 'icon' => 'dashicons-cart', 'url' => admin_url( 'edit.php?post_type=shop_order' ), 'delta' => MWD_AS_Tracker::delta( $woo['orders_period'], $woo['prev']['orders_period'] ) );
		} else {
			$p   = wp_count_posts( 'post' );
			$pg  = wp_count_posts( 'page' );
			$k[] = array( 'label' => 'Articole', 'value' => number_format_i18n( isset( $p->publish ) ? $p->publish : 0 ), 'icon' => 'dashicons-admin-post', 'url' => admin_url( 'edit.php' ) );
			$k[] = array( 'label' => 'Pagini', 'value' => number_format_i18n( isset( $pg->publish ) ? $pg->publish : 0 ), 'icon' => 'dashicons-admin-page', 'url' => admin_url( 'edit.php?post_type=page' ) );
		}

		$c       = wp_count_comments();
		$pending = isset( $c->moderated ) ? (int) $c->moderated : 0;
		$k[]     = array( 'label' => $pending ? 'Comentarii (' . $pending . ' noi)' : 'Comentarii', 'value' => number_format_i18n( isset( $c->approved ) ? $c->approved : 0 ), 'icon' => 'dashicons-admin-comments', 'url' => admin_url( 'edit-comments.php' ), 'accent' => $pending > 0 );

		if ( $vis ) {
			$k[] = array( 'label' => 'Vizite (' . $days . ' zile)', 'value' => number_format_i18n( $vis['sessions'] ), 'icon' => 'dashicons-chart-area', 'url' => admin_url( 'admin.php?page=mwd-analytics' ), 'accent' => true, 'delta' => MWD_AS_Tracker::delta( $vis['sessions'], $vis['prev']['sessions'] ) );
		} else {
			$u   = count_users();
			$k[] = array( 'label' => 'Utilizatori', 'value' => number_format_i18n( isset( $u['total_users'] ) ? $u['total_users'] : 0 ), 'icon' => 'dashicons-admin-users', 'url' => admin_url( 'users.php' ) );
		}

		return $k;
	}

	private function card_woo( $w, $days ) {
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">WooCommerce</div>
			<div class="mwd-dash-woo-hero">
				<?php if ( $w['to_process'] > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>" class="mwd-dash-woo-alert">
						<span class="mwd-dash-woo-big"><?php echo esc_html( number_format_i18n( $w['to_process'] ) ); ?></span>
						<span>comenzi de procesat</span>
					</a>
				<?php else : ?>
					<div class="mwd-dash-woo-ok"><span class="dashicons dashicons-yes-alt"></span> Nicio comandă nouă</div>
				<?php endif; ?>
			</div>
			<div class="mwd-dash-mini-h">Vânzări <?php echo (int) $days; ?> zile</div>
			<div class="mwd-dash-chart-box"><?php echo $this->svg_bars( $w['sales_series'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
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
			<div class="mwd-dash-chart-box"><?php echo $this->svg_area( $v['spark'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

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
			array( 'post-new.php', 'dashicons-edit', 'Articol nou' ),
			array( 'post-new.php?post_type=page', 'dashicons-admin-page', 'Pagină nouă' ),
			array( 'media-new.php', 'dashicons-admin-media', 'Încarcă media' ),
			array( 'edit-comments.php', 'dashicons-admin-comments', 'Comentarii' ),
			array( 'themes.php', 'dashicons-admin-appearance', 'Aspect' ),
			array( 'admin.php?page=mwd-admin-studio', 'dashicons-art', 'Admin Studio' ),
		);
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Acțiuni rapide</div>
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

	private function card_system() {
		?>
		<div class="mwd-dash-card">
			<div class="mwd-dash-card-h">Sistem</div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Versiune WordPress</span><span class="mwd-dash-row-m num"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Temă activă</span><span class="mwd-dash-row-m"><?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?></span></div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Versiune PHP</span><span class="mwd-dash-row-m num"><?php echo esc_html( PHP_VERSION ); ?></span></div>
			<div class="mwd-dash-row"><span class="mwd-dash-row-t">Vizibil motoare căutare</span><span class="mwd-dash-row-m"><?php echo get_option( 'blog_public' ) ? 'Da' : 'Nu'; ?></span></div>
		</div>
		<?php
	}
}
