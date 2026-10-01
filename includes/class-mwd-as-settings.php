<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagina de setari a plugin-ului + salvarea optiunilor.
 */
class MWD_AS_Settings {

	const SLUG = 'mwd-admin-studio';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	public function add_page() {
		$o    = MWD_AS_Defaults::get_options();
		$name = ! empty( $o['brand_menu_label'] ) ? $o['brand_menu_label'] : 'Admin Studio';
		add_menu_page(
			$name,
			$name,
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-art',
			3
		);
	}

	public function maybe_notice() {
		if ( empty( $_GET['mwd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$msg = sanitize_key( wp_unslash( $_GET['mwd_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'imported' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>Configurația a fost importată cu succes.</p></div>';
		} elseif ( 'import_error' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>Fișierul de import nu este valid.</p></div>';
		}
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'mwd-as-settings',
			MWD_AS_URL . 'assets/js/settings.js',
			array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
			MWD_AS_VERSION,
			true
		);
		wp_enqueue_style(
			'mwd-as-settings',
			MWD_AS_URL . 'assets/css/settings.css',
			array(),
			MWD_AS_VERSION
		);
	}

	/**
	 * Salveaza optiunile (cu verificare nonce + capabilitate).
	 */
	public function maybe_save() {
		if ( empty( $_POST['mwd_as_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mwd_as_nonce'] ) ), 'mwd_as_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$d   = MWD_AS_Defaults::defaults();
		$out = array();

		$out['enabled']        = empty( $_POST['enabled'] ) ? 0 : 1;
		$out['style_login']    = empty( $_POST['style_login'] ) ? 0 : 1;
		$out['style_adminbar'] = empty( $_POST['style_adminbar'] ) ? 0 : 1;
		$out['custom_dashboard'] = empty( $_POST['custom_dashboard'] ) ? 0 : 1;
		$out['collapse_menu']    = empty( $_POST['collapse_menu'] ) ? 0 : 1;
		$out['auto_group']       = empty( $_POST['auto_group'] ) ? 0 : 1;
		$out['track_visitors']   = empty( $_POST['track_visitors'] ) ? 0 : 1;
		$out['woo_cards']        = empty( $_POST['woo_cards'] ) ? 0 : 1;
		$out['anonymize_ip']     = empty( $_POST['anonymize_ip'] ) ? 0 : 1;
		$out['geo_lookup']       = empty( $_POST['geo_lookup'] ) ? 0 : 1;
		$out['exclude_ips']      = isset( $_POST['exclude_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_ips'] ) ) : '';
		$out['exclude_paths']    = isset( $_POST['exclude_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_paths'] ) ) : '';
		$out['dashboard_title']  = isset( $_POST['dashboard_title'] ) ? sanitize_text_field( wp_unslash( $_POST['dashboard_title'] ) ) : '';

		foreach ( array( 'sidebar_bg', 'sidebar_text', 'accent', 'accent_hover', 'content_bg', 'link', 'login_bg' ) as $c ) {
			$val         = isset( $_POST[ $c ] ) ? sanitize_hex_color( wp_unslash( $_POST[ $c ] ) ) : '';
			$out[ $c ]   = $val ? $val : $d[ $c ];
		}

		$fonts        = MWD_AS_Defaults::fonts();
		$font         = isset( $_POST['font'] ) ? sanitize_text_field( wp_unslash( $_POST['font'] ) ) : $d['font'];
		$out['font']  = isset( $fonts[ $font ] ) ? $font : $d['font'];

		$out['radius'] = isset( $_POST['radius'] ) ? max( 0, min( 28, (int) $_POST['radius'] ) ) : $d['radius'];

		$out['login_logo_url'] = isset( $_POST['login_logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['login_logo_url'] ) ) : '';

		// White-label / acces.
		$out['brand_menu_label']  = isset( $_POST['brand_menu_label'] ) && '' !== trim( (string) wp_unslash( $_POST['brand_menu_label'] ) )
			? sanitize_text_field( wp_unslash( $_POST['brand_menu_label'] ) ) : 'Admin Studio';
		$out['admin_footer_text'] = isset( $_POST['admin_footer_text'] ) ? wp_kses_post( wp_unslash( $_POST['admin_footer_text'] ) ) : '';
		$out['hide_wp_version']   = empty( $_POST['hide_wp_version'] ) ? 0 : 1;
		$out['hide_wp_logo']      = empty( $_POST['hide_wp_logo'] ) ? 0 : 1;
		$out['hide_notices']      = empty( $_POST['hide_notices'] ) ? 0 : 1;
		$out['block_access']      = empty( $_POST['block_access'] ) ? 0 : 1;

		// Roluri carora li se aplica skin-ul.
		$all_roles  = array_keys( MWD_AS_Defaults::roles() );
		$skin_roles = array();
		if ( ! empty( $_POST['skin_roles'] ) && is_array( $_POST['skin_roles'] ) ) {
			foreach ( wp_unslash( $_POST['skin_roles'] ) as $r ) {
				$r = sanitize_key( $r );
				if ( in_array( $r, $all_roles, true ) ) {
					$skin_roles[] = $r;
				}
			}
		}
		// Daca sunt bifate toate, stocam gol (= toate rolurile).
		if ( count( $skin_roles ) === count( $all_roles ) ) {
			$skin_roles = array();
		}
		$out['skin_roles'] = $skin_roles;

		// Manager meniu PE ROL: pastram configul celorlalte roluri, suprascriem doar rolul editat.
		$existing      = MWD_AS_Defaults::get_options();
		$out['menu']   = isset( $existing['menu'] ) && is_array( $existing['menu'] ) ? $existing['menu'] : array();
		$editing_role  = isset( $_POST['menu_role'] ) ? sanitize_key( wp_unslash( $_POST['menu_role'] ) ) : 'default';
		$valid_roles   = array_merge( array( 'default' ), $all_roles );
		if ( ! in_array( $editing_role, $valid_roles, true ) ) {
			$editing_role = 'default';
		}

		$role_menu = array();
		if ( ! empty( $_POST['menu_slug'] ) && is_array( $_POST['menu_slug'] ) ) {
			$slugs  = wp_unslash( $_POST['menu_slug'] );
			$titles = isset( $_POST['menu_title'] ) ? wp_unslash( $_POST['menu_title'] ) : array();
			$hidden = isset( $_POST['menu_hidden'] ) ? (array) wp_unslash( $_POST['menu_hidden'] ) : array();
			$groups_in = isset( $_POST['menu_group'] ) ? (array) wp_unslash( $_POST['menu_group'] ) : array();

			$order = 0;
			foreach ( $slugs as $slug ) {
				$slug = sanitize_text_field( $slug );
				if ( '' === $slug ) {
					continue;
				}
				$role_menu[ $slug ] = array(
					'order'  => $order,
					'hidden' => in_array( $slug, $hidden, true ) ? 1 : 0,
					'title'  => isset( $titles[ $slug ] ) ? sanitize_text_field( $titles[ $slug ] ) : '',
					'group'  => isset( $groups_in[ $slug ] ) ? sanitize_text_field( $groups_in[ $slug ] ) : '',
				);
				$order++;
			}
		}
		$out['menu'][ $editing_role ] = $role_menu;

		// Manager SUB-meniu PE ROL.
		$out['submenu'] = isset( $existing['submenu'] ) && is_array( $existing['submenu'] ) ? $existing['submenu'] : array();

		$role_sub = array();
		$titles_s = isset( $_POST['submenu_title'] ) ? (array) wp_unslash( $_POST['submenu_title'] ) : array();
		$hidden_s = isset( $_POST['submenu_hidden'] ) ? (array) wp_unslash( $_POST['submenu_hidden'] ) : array();

		// Redenumiri (parinte => copil => titlu).
		foreach ( $titles_s as $parent => $children ) {
			$parent = sanitize_text_field( $parent );
			if ( ! is_array( $children ) ) {
				continue;
			}
			foreach ( $children as $child => $title ) {
				$child = sanitize_text_field( $child );
				$title = sanitize_text_field( $title );
				if ( '' === $child ) {
					continue;
				}
				$role_sub[ $parent ][ $child ] = array(
					'hidden' => 0,
					'title'  => $title,
				);
			}
		}

		// Ascunderi (parinte => lista de copii bifati).
		foreach ( $hidden_s as $parent => $children ) {
			$parent = sanitize_text_field( $parent );
			if ( ! is_array( $children ) ) {
				continue;
			}
			foreach ( $children as $child ) {
				$child = sanitize_text_field( $child );
				if ( '' === $child ) {
					continue;
				}
				if ( ! isset( $role_sub[ $parent ][ $child ] ) ) {
					$role_sub[ $parent ][ $child ] = array( 'hidden' => 1, 'title' => '' );
				} else {
					$role_sub[ $parent ][ $child ]['hidden'] = 1;
				}
			}
		}

		$out['submenu'][ $editing_role ] = $role_sub;

		// Manager COLOANE PE ROL.
		$out['columns'] = isset( $existing['columns'] ) && is_array( $existing['columns'] ) ? $existing['columns'] : array();
		$role_cols = array();
		if ( ! empty( $_POST['columns'] ) && is_array( $_POST['columns'] ) ) {
			foreach ( wp_unslash( $_POST['columns'] ) as $screen => $cols ) {
				$screen = sanitize_text_field( $screen );
				if ( ! is_array( $cols ) ) {
					continue;
				}
				foreach ( $cols as $col ) {
					$col = sanitize_text_field( $col );
					if ( '' !== $col ) {
						$role_cols[ $screen ][ $col ] = 1;
					}
				}
			}
		}
		$out['columns'][ $editing_role ] = $role_cols;

		update_option( MWD_AS_OPTION, $out );

		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>Setarile MWD Admin Studio au fost salvate.</p></div>';
		} );
	}

	/**
	 * Citeste meniul de admin curent ca sa-l afiseze in manager, pre-completat
	 * cu configurarea rolului dat.
	 */
	private function current_menu( $role = 'default' ) {
		global $menu, $submenu;
		$opts    = MWD_AS_Defaults::get_options();
		$config  = isset( $opts['menu'][ $role ] ) && is_array( $opts['menu'][ $role ] ) ? $opts['menu'][ $role ] : array();
		$subcfg  = isset( $opts['submenu'][ $role ] ) && is_array( $opts['submenu'][ $role ] ) ? $opts['submenu'][ $role ] : array();
		$items   = array();

		if ( ! empty( $menu ) && is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( empty( $item[2] ) ) {
					continue;
				}
				// Sari separatoarele.
				if ( false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) {
					continue;
				}
				$slug  = $item[2];
				$label = wp_strip_all_tags( $item[0] );
				if ( '' === trim( $label ) ) {
					continue;
				}

				// Colecteaza sub-paginile parintelui.
				$children = array();
				if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
					foreach ( $submenu[ $slug ] as $sub ) {
						if ( empty( $sub[2] ) ) {
							continue;
						}
						$cslug  = $sub[2];
						$clabel = wp_strip_all_tags( $sub[0] );
						if ( '' === trim( $clabel ) ) {
							continue;
						}
						$children[] = array(
							'slug'   => $cslug,
							'label'  => $clabel,
							'hidden' => isset( $subcfg[ $slug ][ $cslug ]['hidden'] ) ? (int) $subcfg[ $slug ][ $cslug ]['hidden'] : 0,
							'title'  => isset( $subcfg[ $slug ][ $cslug ]['title'] ) ? $subcfg[ $slug ][ $cslug ]['title'] : '',
						);
					}
				}

				$items[ $slug ] = array(
					'slug'     => $slug,
					'label'    => $label,
					'hidden'   => isset( $config[ $slug ]['hidden'] ) ? (int) $config[ $slug ]['hidden'] : 0,
					'title'    => isset( $config[ $slug ]['title'] ) ? $config[ $slug ]['title'] : '',
					'group'    => isset( $config[ $slug ]['group'] ) ? $config[ $slug ]['group'] : '',
					'order'    => isset( $config[ $slug ]['order'] ) ? (int) $config[ $slug ]['order'] : 999,
					'children' => $children,
				);
			}
		}

		// Sorteaza dupa ordinea salvata.
		uasort( $items, function ( $a, $b ) {
			return $a['order'] <=> $b['order'];
		} );

		return $items;
	}

	public function render() {
		$o     = MWD_AS_Defaults::get_options();
		$fonts = MWD_AS_Defaults::fonts();
		$roles = MWD_AS_Defaults::roles();

		// Rolul editat in managerul de meniu (din POST dupa salvare, altfel din GET).
		$edit_role = 'default';
		if ( isset( $_POST['menu_role'] ) ) {
			$edit_role = sanitize_key( wp_unslash( $_POST['menu_role'] ) );
		} elseif ( isset( $_GET['role'] ) ) {
			$edit_role = sanitize_key( wp_unslash( $_GET['role'] ) );
		}
		if ( 'default' !== $edit_role && ! isset( $roles[ $edit_role ] ) ) {
			$edit_role = 'default';
		}

		$items = $this->current_menu( $edit_role );
		?>
		<div class="wrap mwd-as-wrap">
			<h1>MWD Admin Studio</h1>
			<p class="mwd-as-sub">Reskin complet al adminului in stilul tau. Toate modificarile sunt doar cosmetice (culori, font, radius) si manager de meniu pe roluri — nimic structural.</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'mwd_as_save', 'mwd_as_nonce' ); ?>

				<div class="mwd-as-grid">

					<div class="mwd-as-card">
						<h2>General</h2>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="enabled" value="1" <?php checked( $o['enabled'], 1 ); ?> />
							<span>Activeaza skin-ul de admin</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="style_adminbar" value="1" <?php checked( $o['style_adminbar'], 1 ); ?> />
							<span>Stilizeaza bara de admin</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="style_login" value="1" <?php checked( $o['style_login'], 1 ); ?> />
							<span>Stilizeaza pagina de login</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="custom_dashboard" value="1" <?php checked( $o['custom_dashboard'], 1 ); ?> />
							<span>Inlocuieste dashboard-ul implicit</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="collapse_menu" value="1" <?php checked( $o['collapse_menu'], 1 ); ?> />
							<span>Colapsează meniul lateral (grupează item-ele ne-esențiale)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="auto_group" value="1" <?php checked( $o['auto_group'], 1 ); ?> />
							<span>Grupare automată (Design, Marketing, Magazin, Conținut, Sistem)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="track_visitors" value="1" <?php checked( $o['track_visitors'], 1 ); ?> />
							<span>Tracker vizitatori (analitice + dashboard)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="anonymize_ip" value="1" <?php checked( $o['anonymize_ip'], 1 ); ?> />
							<span>Anonimizează IP-ul (GDPR)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="geo_lookup" value="1" <?php checked( $o['geo_lookup'], 1 ); ?> />
							<span>Detectează țara/orașul din IP (ip-api)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="woo_cards" value="1" <?php checked( $o['woo_cards'], 1 ); ?> />
							<span>Carduri WooCommerce (dacă e instalat)</span>
						</label>
						<p style="margin:8px 0 0;">
							<label for="dashboard_title" style="font-size:13px;">Titlu dashboard (optional)</label><br>
							<input type="text" class="regular-text" name="dashboard_title" id="dashboard_title" value="<?php echo esc_attr( $o['dashboard_title'] ); ?>" placeholder="Gol = „Bună, [nume]&#8221;" />
						</p>

						<h2 style="margin-top:16px;">Skin pe roluri</h2>
						<p class="description">Bifeaza rolurile carora li se aplica skin-ul (vizual). Toate bifate = pentru toata lumea.</p>
						<?php
						$skin_roles = (array) $o['skin_roles'];
						$all_checked = empty( $skin_roles );
						foreach ( $roles as $slug => $name ) :
							$checked = $all_checked || in_array( $slug, $skin_roles, true );
							?>
							<label class="mwd-as-toggle">
								<input type="checkbox" name="skin_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked, true ); ?> />
								<span><?php echo esc_html( $name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="mwd-as-card">
						<h2>Tipografie &amp; forma</h2>
						<p>
							<label for="font"><strong>Font</strong></label><br>
							<select name="font" id="font">
								<?php foreach ( $fonts as $key => $f ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o['font'], $key ); ?>><?php echo esc_html( $f[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="radius"><strong>Border radius:</strong> <span id="radius-val"><?php echo (int) $o['radius']; ?></span>px</label><br>
							<input type="range" name="radius" id="radius" min="0" max="28" value="<?php echo (int) $o['radius']; ?>" />
						</p>
					</div>

					<div class="mwd-as-card">
						<h2>Paleta de culori</h2>
						<div class="mwd-as-presets">
							<span class="mwd-as-presets-lbl">Preseturi:</span>
							<button type="button" class="button mwd-as-preset" data-preset="mwd">MyWebDesign</button>
							<button type="button" class="button mwd-as-preset" data-preset="veedo">Veedo</button>
							<button type="button" class="button mwd-as-preset" data-preset="light">Neutru light</button>
							<button type="button" class="button mwd-as-preset" data-preset="dark">Dark</button>
						</div>
						<?php
						$colors = array(
							'sidebar_bg'   => 'Fundal sidebar',
							'sidebar_text' => 'Text sidebar',
							'accent'       => 'Accent (butoane / activ)',
							'accent_hover' => 'Accent hover',
							'content_bg'   => 'Fundal continut',
							'link'         => 'Culoare linkuri',
						);
						foreach ( $colors as $key => $label ) : ?>
							<p class="mwd-as-color-row">
								<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<input type="text" class="mwd-as-color" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $o[ $key ] ); ?>" />
							</p>
						<?php endforeach; ?>
					</div>

					<div class="mwd-as-card">
						<h2>Branding login</h2>
						<p>
							<label for="login_logo_url"><strong>URL logo</strong></label><br>
							<input type="url" class="regular-text" name="login_logo_url" id="login_logo_url" placeholder="https://..." value="<?php echo esc_attr( $o['login_logo_url'] ); ?>" />
						</p>
						<p class="mwd-as-color-row">
							<label for="login_bg">Fundal pagina login</label>
							<input type="text" class="mwd-as-color" name="login_bg" id="login_bg" value="<?php echo esc_attr( $o['login_bg'] ); ?>" />
						</p>
					</div>

					<div class="mwd-as-card">
						<h2>White-label &amp; acces</h2>
						<p>
							<label for="brand_menu_label"><strong>Nume meniu plugin</strong></label><br>
							<input type="text" class="regular-text" name="brand_menu_label" id="brand_menu_label" value="<?php echo esc_attr( $o['brand_menu_label'] ); ?>" placeholder="Admin Studio" />
						</p>
						<p>
							<label for="admin_footer_text"><strong>Text footer admin</strong></label><br>
							<input type="text" class="regular-text" name="admin_footer_text" id="admin_footer_text" value="<?php echo esc_attr( $o['admin_footer_text'] ); ?>" placeholder="Întreținut de MyWebDesign.ro" />
						</p>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="hide_wp_version" value="1" <?php checked( $o['hide_wp_version'], 1 ); ?> />
							<span>Ascunde versiunea WordPress</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="hide_wp_logo" value="1" <?php checked( $o['hide_wp_logo'], 1 ); ?> />
							<span>Ascunde logo-ul WP din bară</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="hide_notices" value="1" <?php checked( $o['hide_notices'], 1 ); ?> />
							<span>Ascunde notificările pentru clienți (ne-admini)</span>
						</label>
						<label class="mwd-as-toggle">
							<input type="checkbox" name="block_access" value="1" <?php checked( $o['block_access'], 1 ); ?> />
							<span>Blochează accesul la paginile ascunse (pe rol)</span>
						</label>
						<p class="description">„Ascuns" devine și „interzis": accesul direct prin URL e redirecționat. Dashboard-ul, profilul și această pagină nu se blochează niciodată.</p>
					</div>

					<div class="mwd-as-card">
						<h2>Excluderi analitice</h2>
						<p class="description">IP-ul tău acum: <code id="mwd-myip"><?php echo esc_html( MWD_AS_Tracker::current_ip() ); ?></code> <button type="button" class="button button-small" id="mwd-add-ip">Adaugă la excluse</button></p>
						<p>
							<label for="exclude_ips"><strong>IP-uri excluse</strong> (unul pe linie; poți folosi <code>85.120.*</code>)</label><br>
							<textarea name="exclude_ips" id="exclude_ips" rows="3" class="large-text code"><?php echo esc_textarea( $o['exclude_ips'] ); ?></textarea>
						</p>
						<p>
							<label for="exclude_paths"><strong>Căi excluse</strong> (ex: <code>/cos</code>, <code>/checkout*</code>)</label><br>
							<textarea name="exclude_paths" id="exclude_paths" rows="3" class="large-text code"><?php echo esc_textarea( $o['exclude_paths'] ); ?></textarea>
						</p>
						<p class="description">Vizitele de la aceste IP-uri sau pe aceste căi nu sunt înregistrate în analitice.</p>
					</div>

				</div>

				<div class="mwd-as-card mwd-as-menu-card">
					<h2>Manager meniu pe roluri</h2>

					<div class="mwd-as-role-tabs">
						<?php
						$base = admin_url( 'admin.php?page=' . self::SLUG );
						$tabs = array( 'default' => 'Implicit (toate rolurile)' ) + $roles;
						foreach ( $tabs as $slug => $name ) :
							$url    = ( 'default' === $slug ) ? $base : add_query_arg( 'role', $slug, $base );
							$active = ( $slug === $edit_role ) ? ' is-active' : '';
							?>
							<a class="mwd-as-role-tab<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
						<?php endforeach; ?>
					</div>

					<input type="hidden" name="menu_role" value="<?php echo esc_attr( $edit_role ); ?>" />

					<p class="description">
						Editezi: <strong><?php echo esc_html( $tabs[ $edit_role ] ); ?></strong>.
						Trage de elemente pentru reordonare, bifeaza pentru a ascunde, scrie un nume nou pentru redenumire.
						<?php if ( 'default' !== $edit_role ) : ?>
							Daca lasi totul neatins aici, rolul mosteneste configurarea „Implicit".
						<?php endif; ?>
						<br><em>Nota: lista de mai jos e meniul tau de admin curent; pentru un rol cu mai putine drepturi, unele pagini pot oricum sa nu apara.</em>
						<br><em>Câmpul <strong>Grup</strong>: elementele cu același nume de grup devin o secțiune pliabilă în bara laterală (ex. „Design", „Marketing", „Sistem").</em>
					</p>

					<?php
					$existing_groups = array( 'Design', 'Marketing & SEO', 'Magazin', 'Conținut', 'Sistem' );
					foreach ( $items as $gi ) {
						if ( ! empty( $gi['group'] ) && ! in_array( $gi['group'], $existing_groups, true ) ) {
							$existing_groups[] = $gi['group'];
						}
					}
					$existing_groups[] = 'Fără grup';
					?>
					<datalist id="mwd-as-grouplist">
						<?php foreach ( $existing_groups as $gname ) : ?>
							<option value="<?php echo esc_attr( $gname ); ?>"></option>
						<?php endforeach; ?>
					</datalist>

					<div class="mwd-as-menu-toolbar">
						<a href="#" id="mwd-as-expand-all">Extinde tot</a>
						<span>·</span>
						<a href="#" id="mwd-as-collapse-all">Restrânge tot</a>
						<span>·</span>
						<a href="#" id="mwd-as-suggest-groups">Sugerează grupuri</a>
					</div>

					<ul class="mwd-as-menu-list" id="mwd-as-menu-list">
						<?php foreach ( $items as $it ) :
							$has_children = ! empty( $it['children'] );
							$hidden_subs  = 0;
							if ( $has_children ) {
								foreach ( $it['children'] as $c ) {
									$hidden_subs += $c['hidden'] ? 1 : 0;
								}
							}
							?>
							<li class="mwd-as-menu-item<?php echo $has_children ? ' has-subs is-collapsed' : ''; ?>">
								<div class="mwd-as-menu-main">
									<span class="mwd-as-drag dashicons dashicons-menu"></span>
									<input type="hidden" name="menu_slug[]" value="<?php echo esc_attr( $it['slug'] ); ?>" />
									<span class="mwd-as-menu-orig"><?php echo esc_html( $it['label'] ); ?></span>
									<input type="text" class="mwd-as-rename" name="menu_title[<?php echo esc_attr( $it['slug'] ); ?>]" value="<?php echo esc_attr( $it['title'] ); ?>" placeholder="Nume nou (optional)" />
									<input type="text" class="mwd-as-group" name="menu_group[<?php echo esc_attr( $it['slug'] ); ?>]" value="<?php echo esc_attr( $it['group'] ); ?>" placeholder="Grup (ex: Design)" list="mwd-as-grouplist" data-mwd-suggest="<?php echo esc_attr( MWD_AS_MenuCollapse::suggest_group( $it['slug'], $it['label'] ) ); ?>" />
									<label class="mwd-as-hide">
										<input type="checkbox" name="menu_hidden[]" value="<?php echo esc_attr( $it['slug'] ); ?>" <?php checked( $it['hidden'], 1 ); ?> />
										Ascunde
									</label>
									<code class="mwd-as-slug"><?php echo esc_html( $it['slug'] ); ?></code>
									<?php if ( $has_children ) : ?>
										<button type="button" class="mwd-as-toggle-sub" aria-label="Extinde sub-pagini">
											<span class="mwd-as-sub-count">
												<?php echo (int) count( $it['children'] ); ?> sub
												<?php if ( $hidden_subs ) : ?><em><?php echo (int) $hidden_subs; ?> ascunse</em><?php endif; ?>
											</span>
											<span class="dashicons dashicons-arrow-down-alt2"></span>
										</button>
									<?php endif; ?>
								</div>

								<?php if ( $has_children ) : ?>
									<ul class="mwd-as-submenu">
										<?php foreach ( $it['children'] as $ch ) : ?>
											<li class="mwd-as-subitem">
												<span class="mwd-as-sub-arrow dashicons dashicons-subdirectory"></span>
												<span class="mwd-as-sub-label"><?php echo esc_html( $ch['label'] ); ?></span>
												<input type="text" class="mwd-as-rename" name="submenu_title[<?php echo esc_attr( $it['slug'] ); ?>][<?php echo esc_attr( $ch['slug'] ); ?>]" value="<?php echo esc_attr( $ch['title'] ); ?>" placeholder="Nume nou (optional)" />
												<label class="mwd-as-hide">
													<input type="checkbox" name="submenu_hidden[<?php echo esc_attr( $it['slug'] ); ?>][]" value="<?php echo esc_attr( $ch['slug'] ); ?>" <?php checked( $ch['hidden'], 1 ); ?> />
													Ascunde
												</label>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<?php
				$col_cache = get_option( MWD_AS_Columns::CACHE, array() );
				if ( ! empty( $col_cache ) && is_array( $col_cache ) ) :
					$opts_full = MWD_AS_Defaults::get_options();
					$col_cfg   = isset( $opts_full['columns'][ $edit_role ] ) && is_array( $opts_full['columns'][ $edit_role ] ) ? $opts_full['columns'][ $edit_role ] : array();
					?>
					<div class="mwd-as-card mwd-as-menu-card">
						<h2>Manager coloane pe roluri</h2>
						<p class="description">
							Editezi: <strong><?php echo esc_html( $tabs[ $edit_role ] ); ?></strong>. Bifează coloanele pe care vrei să le ascunzi în tabelele de liste.
							<br><em>Coloanele apar aici pe măsură ce vizitezi tabelele respective (produse, comenzi, articole…). Dacă lipsește una, deschide o dată lista respectivă.</em>
						</p>
						<?php foreach ( $col_cache as $screen => $cols ) :
							if ( empty( $cols ) || ! is_array( $cols ) ) {
								continue;
							}
							?>
							<div class="mwd-as-colgroup">
								<div class="mwd-as-colgroup-h"><?php echo esc_html( MWD_AS_Columns::screen_label( $screen ) ); ?> <code><?php echo esc_html( $screen ); ?></code></div>
								<div class="mwd-as-colgrid">
									<?php foreach ( $cols as $col_id => $col_label ) :
										$is_hidden = isset( $col_cfg[ $screen ][ $col_id ] ) && $col_cfg[ $screen ][ $col_id ];
										?>
										<label class="mwd-as-colitem">
											<input type="checkbox" name="columns[<?php echo esc_attr( $screen ); ?>][]" value="<?php echo esc_attr( $col_id ); ?>" <?php checked( $is_hidden, true ); ?> />
											<?php echo esc_html( $col_label ); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="mwd-as-card">
					<h2>Configurație &amp; export</h2>
					<p class="description">Mută rapid setările între site-uri și exportă datele.</p>
					<div class="mwd-as-export-row">
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_config' ), 'mwd_as_export_config' ) ); ?>"><span class="dashicons dashicons-download" style="vertical-align:middle"></span> Exportă configurația (JSON)</a>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_visitors' ), 'mwd_as_export_visitors' ) ); ?>"><span class="dashicons dashicons-chart-area" style="vertical-align:middle"></span> Export vizitatori (CSV)</a>
						<?php if ( MWD_AS_Woo::active() ) : ?>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_orders' ), 'mwd_as_export_orders' ) ); ?>"><span class="dashicons dashicons-cart" style="vertical-align:middle"></span> Export comenzi (CSV)</a>
						<?php endif; ?>
					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-hero">Salveaza modificarile</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="mwd-as-card" style="margin-top:16px;">
				<input type="hidden" name="action" value="mwd_as_import_config" />
				<?php wp_nonce_field( 'mwd_as_import_config' ); ?>
				<h2>Importă configurația</h2>
				<p class="description">Încarcă un fișier JSON exportat din alt site. Înlocuiește setările curente.</p>
				<p>
					<input type="file" name="mwd_import" accept="application/json,.json" required />
					<button type="submit" class="button">Importă</button>
				</p>
			</form>
		</div>
		<?php
	}
}
