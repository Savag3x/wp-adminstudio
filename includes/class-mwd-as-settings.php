<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagina de setari a plugin-ului + salvarea / sanitizarea optiunilor.
 */
class MWD_AS_Settings {

	const SLUG = 'mwd-admin-studio';

	/**
	 * Optiunile on/off (checkbox-uri) din formular.
	 */
	const BOOLEANS = array(
		'enabled', 'layout_canvas', 'style_login', 'style_adminbar', 'custom_dashboard', 'collapse_menu', 'auto_group',
		'menu_accordion', 'hide_separators', 'cmd_palette', 'track_visitors', 'woo_cards', 'anonymize_ip',
		'geo_lookup', 'hide_wp_version', 'hide_wp_logo', 'hide_notices', 'block_access', 'guard_exempt_admins',
		'delete_on_uninstall',
	);

	const COLORS = array( 'sidebar_bg', 'sidebar_text', 'accent', 'accent_hover', 'content_bg', 'link', 'login_bg' );

	const TABS = array(
		'aspect'    => array( 'Aspect', 'dashicons-art' ),
		'meniu'     => array( 'Meniu', 'dashicons-menu-alt' ),
		'coloane'   => array( 'Coloane', 'dashicons-columns' ),
		'dashboard' => array( 'Dashboard & date', 'dashicons-chart-area' ),
		'acces'     => array( 'Acces & brand', 'dashicons-lock' ),
		'login'     => array( 'Login', 'dashicons-admin-network' ),
		'config'    => array( 'Import / export', 'dashicons-migrate' ),
	);

	/**
	 * Copie a meniului inainte ca MWD_AS_Menu sa ascunda / redenumeasca elemente.
	 */
	private $snapshot = null;

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_menu', array( $this, 'snapshot' ), 9990 );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_mwd_as_reset_menu', array( $this, 'reset_menu' ) );
		add_action( 'admin_post_mwd_as_copy_menu', array( $this, 'copy_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MWD_AS_FILE ), array( $this, 'action_links' ) );
	}

	public function add_page() {
		$o    = MWD_AS_Defaults::get_options();
		$name = ! empty( $o['brand_menu_label'] ) ? $o['brand_menu_label'] : 'Admin Studio';
		add_menu_page( $name, $name, 'manage_options', self::SLUG, array( $this, 'render' ), 'dashicons-art', 3 );
		// Primul sub-item poarta numele "Setari" (nu repeta numele brandului).
		add_submenu_page( self::SLUG, $name, 'Setări', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Pe pagina noastra retinem meniul original (etichete originale + elemente care vor fi ascunse),
	 * altfel un element ascuns ar disparea din manager si nu ar mai putea fi re-afisat.
	 */
	public function snapshot() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		global $menu, $submenu;
		$m = is_array( $menu ) ? $menu : array();
		// WP sorteaza meniul abia dupa admin_menu; facem la fel ca ordinea sa fie cea nativa.
		uksort( $m, 'strnatcasecmp' );
		$this->snapshot = array(
			'menu'    => $m,
			'submenu' => is_array( $submenu ) ? $submenu : array(),
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Setări</a>' );
		return $links;
	}

	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'mwd-as-settings', MWD_AS_URL . 'assets/js/settings.js', array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ), MWD_AS_VERSION, true );
		wp_enqueue_style( 'mwd-as-settings', MWD_AS_URL . 'assets/css/settings.css', array( 'wp-color-picker' ), MWD_AS_VERSION );
	}

	/* =====================================================================
	 * Salvare
	 * ===================================================================== */

	/**
	 * Rolul editat (validat). 'default' = configurarea implicita.
	 */
	private static function valid_role( $role ) {
		$role = sanitize_key( (string) $role );
		return ( 'default' === $role || array_key_exists( $role, MWD_AS_Defaults::roles() ) ) ? $role : 'default';
	}

	private static function valid_tab( $tab ) {
		$tab = sanitize_key( (string) $tab );
		return array_key_exists( $tab, self::TABS ) ? $tab : 'aspect';
	}

	/**
	 * Salveaza optiunile (nonce + capabilitate), apoi redirect (PRG) ca refresh-ul sa nu retrimita formularul.
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

		$post = wp_unslash( $_POST ); // Sanitizarea se face in sanitize_options().
		$out  = MWD_AS_Defaults::get_options();

		foreach ( self::BOOLEANS as $k ) {
			$out[ $k ] = empty( $post[ $k ] ) ? 0 : 1;
		}
		foreach ( array_merge( self::COLORS, array( 'font', 'radius', 'density', 'login_layout', 'login_logo_url', 'login_tagline', 'dashboard_title', 'brand_menu_label', 'admin_footer_text', 'exclude_ips', 'exclude_paths', 'retention_days' ) ) as $k ) {
			if ( isset( $post[ $k ] ) ) {
				$out[ $k ] = $post[ $k ];
			}
		}

		// Roluri carora li se aplica skin-ul. Toate bifate = gol (= toate rolurile).
		$all_roles         = array_keys( MWD_AS_Defaults::roles() );
		$skin              = isset( $post['skin_roles'] ) ? array_intersect( array_map( 'sanitize_key', (array) $post['skin_roles'] ), $all_roles ) : array();
		$out['skin_roles'] = count( $skin ) === count( $all_roles ) ? array() : array_values( $skin );

		// Iconite grupuri.
		$out['group_icons'] = isset( $post['group_icon'] ) ? (array) $post['group_icon'] : array();

		// Manager meniu / sub-meniu PE ROL: suprascriem doar rolul editat.
		$role    = self::valid_role( isset( $post['menu_role'] ) ? $post['menu_role'] : 'default' );
		$inherit = 'default' !== $role && ! empty( $post['menu_inherit'] );

		if ( $inherit ) {
			unset( $out['menu'][ $role ], $out['submenu'][ $role ] );
		} else {
			$out['menu'][ $role ]    = $this->menu_from_post( $post );
			$out['submenu'][ $role ] = $this->submenu_from_post( $post );
		}

		// Manager coloane PE ROL.
		$cols = array();
		if ( ! empty( $post['columns'] ) && is_array( $post['columns'] ) ) {
			foreach ( $post['columns'] as $screen => $list ) {
				foreach ( (array) $list as $col ) {
					$cols[ $screen ][ $col ] = 1;
				}
			}
		}
		$out['columns'][ $role ] = $cols;

		update_option( MWD_AS_OPTION, self::sanitize_options( $out ) );

		$args = array(
			'mwd_msg' => 'saved',
			'tab'     => self::valid_tab( isset( $post['mwd_tab'] ) ? $post['mwd_tab'] : '' ),
		);
		if ( 'default' !== $role ) {
			$args['role'] = $role;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private function menu_from_post( $post ) {
		$out = array();
		if ( empty( $post['menu_slug'] ) || ! is_array( $post['menu_slug'] ) ) {
			return $out;
		}
		$titles = isset( $post['menu_title'] ) ? (array) $post['menu_title'] : array();
		$hidden = isset( $post['menu_hidden'] ) ? (array) $post['menu_hidden'] : array();
		$groups = isset( $post['menu_group'] ) ? (array) $post['menu_group'] : array();

		$order = 0;
		foreach ( $post['menu_slug'] as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'order'  => $order++,
				'hidden' => in_array( $slug, $hidden, true ) ? 1 : 0,
				'title'  => isset( $titles[ $slug ] ) ? $titles[ $slug ] : '',
				'group'  => isset( $groups[ $slug ] ) ? $groups[ $slug ] : '',
			);
		}
		return $out;
	}

	private function submenu_from_post( $post ) {
		$out    = array();
		$titles = isset( $post['submenu_title'] ) ? (array) $post['submenu_title'] : array();
		$hidden = isset( $post['submenu_hidden'] ) ? (array) $post['submenu_hidden'] : array();

		foreach ( $titles as $parent => $children ) {
			foreach ( (array) $children as $child => $title ) {
				if ( '' !== trim( (string) $title ) ) {
					$out[ $parent ][ $child ] = array( 'hidden' => 0, 'title' => $title );
				}
			}
		}
		foreach ( $hidden as $parent => $children ) {
			foreach ( (array) $children as $child ) {
				if ( ! isset( $out[ $parent ][ $child ] ) ) {
					$out[ $parent ][ $child ] = array( 'hidden' => 1, 'title' => '' );
				} else {
					$out[ $parent ][ $child ]['hidden'] = 1;
				}
			}
		}
		return $out;
	}

	/**
	 * Sanitizeaza un set complet de optiuni. Folosit la salvare SI la import
	 * (un JSON importat trece prin exact aceleasi reguli ca formularul).
	 */
	public static function sanitize_options( $in ) {
		$d   = MWD_AS_Defaults::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		foreach ( self::BOOLEANS as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		foreach ( self::COLORS as $k ) {
			$c         = isset( $in[ $k ] ) ? sanitize_hex_color( (string) $in[ $k ] ) : '';
			$out[ $k ] = $c ? $c : $d[ $k ];
		}

		$fonts        = MWD_AS_Defaults::fonts();
		$font         = isset( $in['font'] ) ? (string) $in['font'] : '';
		$out['font']  = isset( $fonts[ $font ] ) ? $font : $d['font'];
		$out['radius'] = isset( $in['radius'] ) ? max( 0, min( 28, (int) $in['radius'] ) ) : $d['radius'];
		$out['retention_days'] = isset( $in['retention_days'] ) ? max( 30, min( 730, (int) $in['retention_days'] ) ) : $d['retention_days'];
		$out['density']      = isset( $in['density'] ) && 'compact' === $in['density'] ? 'compact' : 'comfortable';
		$out['login_layout'] = isset( $in['login_layout'] ) && 'center' === $in['login_layout'] ? 'center' : 'split';

		$out['login_logo_url']   = isset( $in['login_logo_url'] ) ? esc_url_raw( (string) $in['login_logo_url'] ) : '';
		$out['login_tagline']    = isset( $in['login_tagline'] ) ? sanitize_text_field( (string) $in['login_tagline'] ) : '';
		$out['dashboard_title']  = isset( $in['dashboard_title'] ) ? sanitize_text_field( (string) $in['dashboard_title'] ) : '';
		$label                   = isset( $in['brand_menu_label'] ) ? sanitize_text_field( (string) $in['brand_menu_label'] ) : '';
		$out['brand_menu_label'] = '' !== trim( $label ) ? $label : $d['brand_menu_label'];
		$out['admin_footer_text'] = isset( $in['admin_footer_text'] ) ? wp_kses_post( (string) $in['admin_footer_text'] ) : '';
		$out['exclude_ips']      = isset( $in['exclude_ips'] ) ? sanitize_textarea_field( (string) $in['exclude_ips'] ) : '';
		$out['exclude_paths']    = isset( $in['exclude_paths'] ) ? sanitize_textarea_field( (string) $in['exclude_paths'] ) : '';

		$out['skin_roles'] = array();
		if ( ! empty( $in['skin_roles'] ) && is_array( $in['skin_roles'] ) ) {
			$out['skin_roles'] = array_values( array_filter( array_map( 'sanitize_key', $in['skin_roles'] ) ) );
		}

		$out['group_icons'] = array();
		if ( ! empty( $in['group_icons'] ) && is_array( $in['group_icons'] ) ) {
			foreach ( $in['group_icons'] as $g => $icon ) {
				$g    = sanitize_text_field( (string) $g );
				$icon = sanitize_html_class( (string) $icon );
				if ( '' !== $g && 0 === strpos( $icon, 'dashicons-' ) ) {
					$out['group_icons'][ $g ] = $icon;
				}
			}
		}

		// Meniu: rol => slug => {order, hidden, title, group}.
		$out['menu'] = array();
		if ( ! empty( $in['menu'] ) && is_array( $in['menu'] ) ) {
			foreach ( $in['menu'] as $role => $items ) {
				$role = sanitize_key( (string) $role );
				if ( '' === $role || ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $slug => $data ) {
					$slug = sanitize_text_field( (string) $slug );
					if ( '' === $slug || ! is_array( $data ) ) {
						continue;
					}
					$out['menu'][ $role ][ $slug ] = array(
						'order'  => isset( $data['order'] ) && '' !== $data['order'] ? (int) $data['order'] : '',
						'hidden' => empty( $data['hidden'] ) ? 0 : 1,
						'title'  => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
						'group'  => isset( $data['group'] ) ? sanitize_text_field( (string) $data['group'] ) : '',
					);
				}
			}
		}

		// Sub-meniu: rol => parinte => copil => {hidden, title}.
		$out['submenu'] = array();
		if ( ! empty( $in['submenu'] ) && is_array( $in['submenu'] ) ) {
			foreach ( $in['submenu'] as $role => $parents ) {
				$role = sanitize_key( (string) $role );
				if ( '' === $role || ! is_array( $parents ) ) {
					continue;
				}
				foreach ( $parents as $parent => $children ) {
					$parent = sanitize_text_field( (string) $parent );
					if ( '' === $parent || ! is_array( $children ) ) {
						continue;
					}
					foreach ( $children as $child => $data ) {
						$child = sanitize_text_field( (string) $child );
						if ( '' === $child || ! is_array( $data ) ) {
							continue;
						}
						$out['submenu'][ $role ][ $parent ][ $child ] = array(
							'hidden' => empty( $data['hidden'] ) ? 0 : 1,
							'title'  => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
						);
					}
				}
			}
		}

		// Coloane: rol => ecran => coloana => 1.
		$out['columns'] = array();
		if ( ! empty( $in['columns'] ) && is_array( $in['columns'] ) ) {
			foreach ( $in['columns'] as $role => $screens ) {
				$role = sanitize_key( (string) $role );
				if ( '' === $role || ! is_array( $screens ) ) {
					continue;
				}
				$out['columns'][ $role ] = array();
				foreach ( $screens as $screen => $cols ) {
					$screen = sanitize_text_field( (string) $screen );
					if ( '' === $screen || ! is_array( $cols ) ) {
						continue;
					}
					foreach ( $cols as $col => $on ) {
						$col = sanitize_text_field( (string) $col );
						if ( '' !== $col && $on ) {
							$out['columns'][ $role ][ $screen ][ $col ] = 1;
						}
					}
				}
			}
		}

		return $out;
	}

	/* =====================================================================
	 * Actiuni rapide pe roluri (reset / copiere)
	 * ===================================================================== */

	public function reset_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( 'mwd_as_reset_menu' );
		$role = self::valid_role( isset( $_GET['role'] ) ? wp_unslash( $_GET['role'] ) : '' );

		$opts = MWD_AS_Defaults::get_options();
		unset( $opts['menu'][ $role ], $opts['submenu'][ $role ], $opts['columns'][ $role ] );
		update_option( MWD_AS_OPTION, self::sanitize_options( $opts ) );

		wp_safe_redirect( self::url( array( 'mwd_msg' => 'reset', 'tab' => 'meniu', 'role' => 'default' === $role ? null : $role ) ) );
		exit;
	}

	public function copy_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acces interzis.' );
		}
		check_admin_referer( 'mwd_as_copy_menu' );
		$to   = self::valid_role( isset( $_GET['role'] ) ? wp_unslash( $_GET['role'] ) : '' );
		$from = self::valid_role( isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '' );

		$opts = MWD_AS_Defaults::get_options();
		if ( $from !== $to ) {
			$opts['menu'][ $to ]    = MWD_AS_Defaults::menu_for_role( $from );
			$opts['submenu'][ $to ] = MWD_AS_Defaults::submenu_for_role( $from );
			update_option( MWD_AS_OPTION, self::sanitize_options( $opts ) );
		}

		wp_safe_redirect( self::url( array( 'mwd_msg' => 'copied', 'tab' => 'meniu', 'role' => 'default' === $to ? null : $to ) ) );
		exit;
	}

	/* =====================================================================
	 * Date pentru managerul de meniu
	 * ===================================================================== */

	/**
	 * Meniul de admin curent, pre-completat cu configurarea rolului
	 * (sau cu cea implicita, daca rolul o mosteneste).
	 */
	private function current_menu( $role = 'default' ) {
		if ( null !== $this->snapshot ) {
			$menu    = $this->snapshot['menu'];
			$submenu = $this->snapshot['submenu'];
		} else {
			global $menu, $submenu;
		}
		$opts   = MWD_AS_Defaults::get_options();
		$src    = MWD_AS_Defaults::role_has_menu( $role ) ? $role : 'default';
		$config = isset( $opts['menu'][ $src ] ) && is_array( $opts['menu'][ $src ] ) ? $opts['menu'][ $src ] : array();
		$subcfg = isset( $opts['submenu'][ $src ] ) && is_array( $opts['submenu'][ $src ] ) ? $opts['submenu'][ $src ] : array();
		$items  = array();
		$pos    = 0;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return $items;
		}

		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) || false !== strpos( (string) ( isset( $item[4] ) ? $item[4] : '' ), 'wp-menu-separator' ) ) {
				continue;
			}
			// Snapshot-ul e luat inainte ca WP sa elimine paginile fara drepturi (ex. „Links").
			if ( ! empty( $item[1] ) && ! current_user_can( $item[1] ) ) {
				continue;
			}
			$slug  = $item[2];
			$label = trim( wp_strip_all_tags( MWD_AS_Menu::strip_bubble( $item[0] ) ) );
			if ( '' === $label ) {
				continue;
			}

			$children = array();
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( empty( $sub[2] ) || ( ! empty( $sub[1] ) && ! current_user_can( $sub[1] ) ) ) {
						continue;
					}
					$clabel = trim( wp_strip_all_tags( MWD_AS_Menu::strip_bubble( $sub[0] ) ) );
					if ( '' === $clabel ) {
						continue;
					}
					$cslug      = $sub[2];
					$children[] = array(
						'slug'   => $cslug,
						'label'  => $clabel,
						'hidden' => ! empty( $subcfg[ $slug ][ $cslug ]['hidden'] ) ? 1 : 0,
						'title'  => isset( $subcfg[ $slug ][ $cslug ]['title'] ) ? $subcfg[ $slug ][ $cslug ]['title'] : '',
					);
				}
			}

			$items[ $slug ] = array(
				'slug'     => $slug,
				'label'    => $label,
				'icon'     => isset( $item[6] ) ? (string) $item[6] : '',
				'hidden'   => ! empty( $config[ $slug ]['hidden'] ) ? 1 : 0,
				'title'    => isset( $config[ $slug ]['title'] ) ? $config[ $slug ]['title'] : '',
				'group'    => isset( $config[ $slug ]['group'] ) ? $config[ $slug ]['group'] : '',
				'order'    => isset( $config[ $slug ]['order'] ) && '' !== $config[ $slug ]['order'] ? (int) $config[ $slug ]['order'] : 1000 + $pos,
				'children' => $children,
			);
			$pos++;
		}

		// Elementele ascunse au fost deja scoase din $menu de MWD_AS_Menu: le readaugam din config,
		// altfel nu ar mai putea fi re-afisate din manager.
		foreach ( $config as $slug => $data ) {
			if ( isset( $items[ $slug ] ) || empty( $data['hidden'] ) ) {
				continue;
			}
			$items[ $slug ] = array(
				'slug'     => $slug,
				'label'    => ! empty( $data['title'] ) ? $data['title'] : $slug,
				'icon'     => '',
				'hidden'   => 1,
				'title'    => isset( $data['title'] ) ? $data['title'] : '',
				'group'    => isset( $data['group'] ) ? $data['group'] : '',
				'order'    => isset( $data['order'] ) && '' !== $data['order'] ? (int) $data['order'] : 2000,
				'children' => array(),
			);
		}

		uasort(
			$items,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		return $items;
	}

	/* =====================================================================
	 * Randare
	 * ===================================================================== */

	private function switch_row( $name, $title, $desc, $checked ) {
		?>
		<label class="mwd-switch">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $checked ); ?> />
			<span class="mwd-switch-ui" aria-hidden="true"></span>
			<span class="mwd-switch-txt">
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( $desc ) : ?><small><?php echo esc_html( $desc ); ?></small><?php endif; ?>
			</span>
		</label>
		<?php
	}

	private function menu_icon( $icon ) {
		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			return '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
		}
		if ( 0 === strpos( $icon, 'data:image/svg+xml;base64,' ) || 0 === strpos( $icon, 'http' ) ) {
			return '<img src="' . esc_attr( $icon ) . '" alt="" />';
		}
		return '<span class="dashicons dashicons-admin-generic"></span>';
	}

	private function role_tabs( $tabs, $edit_role, $tab ) {
		?>
		<div class="mwd-as-roles" role="tablist">
			<?php
			foreach ( $tabs as $slug => $name ) :
				$url    = self::url( array( 'role' => 'default' === $slug ? null : $slug, 'tab' => $tab ) );
				$custom = 'default' !== $slug && MWD_AS_Defaults::role_has_menu( $slug );
				?>
				<a class="mwd-as-role<?php echo $slug === $edit_role ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-keep-tab>
					<?php echo esc_html( $name ); ?>
					<?php if ( $custom ) : ?><span class="mwd-as-role-dot" title="Configurare proprie"></span><?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function render() {
		$o     = MWD_AS_Defaults::get_options();
		$fonts = MWD_AS_Defaults::fonts();
		$roles = MWD_AS_Defaults::roles();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_role = self::valid_role( isset( $_GET['role'] ) ? wp_unslash( $_GET['role'] ) : 'default' );
		$tab       = self::valid_tab( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'aspect' );
		$msg       = isset( $_GET['mwd_msg'] ) ? sanitize_key( wp_unslash( $_GET['mwd_msg'] ) ) : '';
		// phpcs:enable

		$tabs      = array( 'default' => 'Implicit (toate rolurile)' ) + $roles;
		$inherits  = 'default' !== $edit_role && ! MWD_AS_Defaults::role_has_menu( $edit_role );
		$items     = $this->current_menu( $edit_role );
		$hidden_ct = 0;
		foreach ( $items as $it ) {
			$hidden_ct += $it['hidden'];
		}

		$messages = array(
			'saved'        => array( 'success', 'Setările au fost salvate.' ),
			'imported'     => array( 'success', 'Configurația a fost importată cu succes.' ),
			'import_error' => array( 'error', 'Fișierul de import nu este valid.' ),
			'reset'        => array( 'success', 'Configurația de meniu a rolului a fost resetată.' ),
			'copied'       => array( 'success', 'Configurația de meniu a fost copiată.' ),
		);

		$groups = array( 'Design', 'Marketing & SEO', 'Magazin', 'Conținut', 'Sistem' );
		foreach ( $items as $gi ) {
			if ( '' !== trim( $gi['group'] ) && ! in_array( $gi['group'], $groups, true ) && ! in_array( strtolower( $gi['group'] ), array( '-', '—', 'fara grup', 'fără grup', 'niciunul', 'none' ), true ) ) {
				$groups[] = $gi['group'];
			}
		}
		?>
		<div class="wrap mwd-as-wrap">
			<h1 class="screen-reader-text">Admin Studio</h1>

			<header class="mwd-as-header">
				<div class="mwd-as-brand">
					<span class="mwd-as-logo"><span class="dashicons dashicons-art"></span></span>
					<div>
						<div class="mwd-as-title">Admin Studio <span class="mwd-as-ver">v<?php echo esc_html( MWD_AS_VERSION ); ?></span></div>
						<div class="mwd-as-subtitle">Design, navigare și control al accesului pentru panoul tău WordPress.</div>
					</div>
				</div>
				<div class="mwd-as-header-actions">
					<?php if ( ! empty( $o['cmd_palette'] ) ) : ?>
						<button type="button" class="mwd-as-btn mwd-as-btn-ghost" data-mwd-cmdk><span class="dashicons dashicons-search"></span> Caută <kbd class="mwd-cmdk-kbd">⌘K</kbd></button>
					<?php endif; ?>
					<button type="submit" form="mwd-as-form" class="mwd-as-btn mwd-as-btn-primary">Salvează</button>
				</div>
			</header>

			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="mwd-as-toast is-<?php echo esc_attr( $messages[ $msg ][0] ); ?>" role="status">
					<span class="dashicons <?php echo 'success' === $messages[ $msg ][0] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
					<?php echo esc_html( $messages[ $msg ][1] ); ?>
				</div>
			<?php endif; ?>

			<form id="mwd-as-form" method="post" action="<?php echo esc_url( self::url( array( 'role' => 'default' === $edit_role ? null : $edit_role ) ) ); ?>">
				<?php wp_nonce_field( 'mwd_as_save', 'mwd_as_nonce' ); ?>
				<input type="hidden" name="mwd_tab" id="mwd_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<input type="hidden" name="menu_role" value="<?php echo esc_attr( $edit_role ); ?>" />

				<div class="mwd-as-layout">
					<nav class="mwd-as-nav" aria-label="Secțiuni setări">
						<?php foreach ( self::TABS as $id => $t ) : ?>
							<button type="button" class="mwd-as-navbtn<?php echo $id === $tab ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $id ); ?>">
								<span class="dashicons <?php echo esc_attr( $t[1] ); ?>"></span><?php echo esc_html( $t[0] ); ?>
							</button>
						<?php endforeach; ?>
						<div class="mwd-as-nav-foot">
							<span class="mwd-as-status<?php echo ! empty( $o['enabled'] ) ? ' is-on' : ''; ?>"></span>
							Skin <?php echo ! empty( $o['enabled'] ) ? 'activ' : 'oprit'; ?>
						</div>
					</nav>

					<div class="mwd-as-panels">

						<?php /* ============================ ASPECT ============================ */ ?>
						<section class="mwd-as-panel" data-panel="aspect">
							<div class="mwd-as-panel-head">
								<h2>Aspect</h2>
								<p>Paletă, tipografie și densitate. Modificările sunt doar vizuale.</p>
							</div>

							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>Skin</h3>
									<?php
									$this->switch_row( 'enabled', 'Activează skin-ul de admin', 'Aplică designul Admin Studio în tot panoul.', $o['enabled'] );
									$this->switch_row( 'style_adminbar', 'Stilizează bara de admin', 'Bara de sus preia culorile sidebar-ului.', $o['style_adminbar'] );
									$this->switch_row( 'layout_canvas', 'Layout „canvas"', 'Conținutul stă pe un panou rotunjit, încadrat de sidebar și bara de sus — aspect de aplicație.', $o['layout_canvas'] );
									?>
									<div class="mwd-as-field">
										<span class="mwd-as-label">Densitate</span>
										<div class="mwd-as-seg">
											<label><input type="radio" name="density" value="comfortable" <?php checked( $o['density'], 'comfortable' ); ?> /><span>Aerisit</span></label>
											<label><input type="radio" name="density" value="compact" <?php checked( $o['density'], 'compact' ); ?> /><span>Compact</span></label>
										</div>
									</div>
								</div>

								<div class="mwd-as-card">
									<h3>Tipografie & formă</h3>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="font">Font</label>
										<select name="font" id="font">
											<?php foreach ( $fonts as $key => $f ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o['font'], $key ); ?>><?php echo esc_html( $f[0] ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="radius">Colțuri rotunjite <output id="radius-val"><?php echo (int) $o['radius']; ?>px</output></label>
										<input type="range" class="mwd-as-range" name="radius" id="radius" min="0" max="28" value="<?php echo (int) $o['radius']; ?>" />
									</div>
								</div>
							</div>

							<div class="mwd-as-card">
								<h3>Paletă de culori</h3>
								<div class="mwd-as-presets" role="group" aria-label="Preseturi">
									<?php
									$presets = array(
										'onyx'     => array( 'Onyx', '#0b0b0f', '#6d5efc' ),
										'mono'     => array( 'Mono', '#f4f4f5', '#18181b' ),
										'mwd'      => array( 'MyWebDesign', '#0f2744', '#10b981' ),
										'midnight' => array( 'Midnight', '#0b0f19', '#6366f1' ),
										'graphite' => array( 'Graphite', '#18181b', '#f97316' ),
										'ocean'    => array( 'Ocean', '#0c1e3a', '#0ea5e9' ),
										'violet'   => array( 'Violet', '#1e1036', '#a855f7' ),
										'veedo'    => array( 'Veedo', '#16161a', '#e8380d' ),
										'light'    => array( 'Light', '#ffffff', '#2563eb' ),
									);
									foreach ( $presets as $key => $p ) :
										?>
										<button type="button" class="mwd-as-preset" data-preset="<?php echo esc_attr( $key ); ?>">
											<span class="mwd-as-swatch" style="--a:<?php echo esc_attr( $p[1] ); ?>;--b:<?php echo esc_attr( $p[2] ); ?>"></span>
											<?php echo esc_html( $p[0] ); ?>
										</button>
									<?php endforeach; ?>
								</div>

								<div class="mwd-as-palette">
									<div class="mwd-as-colors">
										<?php
										$colors = array(
											'sidebar_bg'   => 'Fundal sidebar',
											'sidebar_text' => 'Text sidebar',
											'accent'       => 'Accent',
											'accent_hover' => 'Accent (hover)',
											'content_bg'   => 'Fundal conținut',
											'link'         => 'Linkuri',
										);
										foreach ( $colors as $key => $label ) :
											?>
											<div class="mwd-as-color-row">
												<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
												<input type="text" class="mwd-as-color" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $o[ $key ] ); ?>" data-default-color="<?php echo esc_attr( MWD_AS_Defaults::defaults()[ $key ] ); ?>" />
											</div>
										<?php endforeach; ?>
									</div>

									<div class="mwd-prev" id="mwd-prev" aria-hidden="true">
										<div class="mwd-prev-bar"></div>
										<div class="mwd-prev-body">
											<div class="mwd-prev-side">
												<i class="mwd-prev-logo"></i>
												<i class="mwd-prev-item is-active"></i>
												<i class="mwd-prev-item"></i>
												<i class="mwd-prev-item"></i>
												<i class="mwd-prev-sec"></i>
												<i class="mwd-prev-item"></i>
												<i class="mwd-prev-item"></i>
											</div>
											<div class="mwd-prev-main">
												<b class="mwd-prev-h"></b>
												<div class="mwd-prev-cards"><i></i><i></i><i></i></div>
												<div class="mwd-prev-card">
													<span class="mwd-prev-line"></span>
													<span class="mwd-prev-line is-short"></span>
													<span class="mwd-prev-actions"><span class="mwd-prev-btn">Salvează</span><span class="mwd-prev-link">Link</span></span>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

							<div class="mwd-as-card">
								<h3>Skin pe roluri</h3>
								<p class="mwd-as-help">Rolurile cărora li se aplică designul. Toate bifate = toată lumea.</p>
								<div class="mwd-as-chips">
									<?php
									$skin_roles = (array) $o['skin_roles'];
									foreach ( $roles as $slug => $name ) :
										$checked = empty( $skin_roles ) || in_array( $slug, $skin_roles, true );
										?>
										<label class="mwd-as-chip"><input type="checkbox" name="skin_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?> /><span><?php echo esc_html( $name ); ?></span></label>
									<?php endforeach; ?>
								</div>
							</div>
						</section>

						<?php /* ============================ MENIU ============================ */ ?>
						<section class="mwd-as-panel" data-panel="meniu">
							<div class="mwd-as-panel-head">
								<h2>Meniu</h2>
								<p>Organizează sidebar-ul pe secțiuni, redenumește, reordonează și ascunde pagini pe roluri.</p>
							</div>

							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>Navigare</h3>
									<?php
									$this->switch_row( 'collapse_menu', 'Secțiuni pliabile în sidebar', 'Grupează elementele ne-esențiale în secțiuni care se deschid la click.', $o['collapse_menu'] );
									$this->switch_row( 'auto_group', 'Grupare automată', 'Plugin-urile cunoscute merg singure în Design, Marketing & SEO, Magazin, Conținut, Sistem.', $o['auto_group'] );
									$this->switch_row( 'menu_accordion', 'Mod acordeon', 'O singură secțiune deschisă la un moment dat.', $o['menu_accordion'] );
									$this->switch_row( 'hide_separators', 'Ascunde separatoarele', 'Sidebar mai curat, fără spații goale între blocuri.', $o['hide_separators'] );
									$this->switch_row( 'cmd_palette', 'Paletă de comenzi (Ctrl/⌘ + K)', 'Caută și deschide orice pagină, acțiune sau conținut de la tastatură.', $o['cmd_palette'] );
									?>
								</div>

								<div class="mwd-as-card">
									<h3>Iconițe secțiuni</h3>
									<p class="mwd-as-help">Iconița afișată când meniul e restrâns. Folosește o clasă <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">Dashicons</a>.</p>
									<div class="mwd-as-icons">
										<?php foreach ( $groups as $g ) : $ic = MWD_AS_Defaults::group_icon( $g ); ?>
											<div class="mwd-as-icon-row">
												<span class="mwd-as-icon-prev dashicons <?php echo esc_attr( $ic ); ?>"></span>
												<span class="mwd-as-icon-name"><?php echo esc_html( $g ); ?></span>
												<input type="text" class="mwd-as-icon-input" name="group_icon[<?php echo esc_attr( $g ); ?>]" value="<?php echo esc_attr( $ic ); ?>" list="mwd-as-dashicons" spellcheck="false" />
											</div>
										<?php endforeach; ?>
									</div>
									<datalist id="mwd-as-dashicons">
										<?php foreach ( array( 'layout', 'art', 'megaphone', 'cart', 'format-aside', 'shield', 'category', 'admin-tools', 'admin-generic', 'chart-bar', 'chart-pie', 'email', 'groups', 'store', 'products', 'portfolio', 'images-alt2', 'admin-site', 'performance', 'database', 'cloud', 'lock', 'star-filled', 'heart', 'lightbulb', 'welcome-learn-more', 'calendar-alt', 'location', 'translation', 'superhero', 'buddicons-replies' ) as $di ) : ?>
											<option value="dashicons-<?php echo esc_attr( $di ); ?>"></option>
										<?php endforeach; ?>
									</datalist>
								</div>
							</div>

							<div class="mwd-as-card mwd-as-builder">
								<div class="mwd-as-card-head">
									<div>
										<h3>Manager meniu pe roluri</h3>
										<p class="mwd-as-help">Trage pentru reordonare · scrie un nume nou pentru redenumire · ochiul ascunde · câmpul <strong>Grup</strong> creează secțiuni pliabile.</p>
									</div>
									<div class="mwd-as-stats">
										<span><strong><?php echo (int) count( $items ); ?></strong> elemente</span>
										<span><strong><?php echo (int) $hidden_ct; ?></strong> ascunse</span>
									</div>
								</div>

								<?php $this->role_tabs( $tabs, $edit_role, 'meniu' ); ?>

								<?php if ( 'default' !== $edit_role ) : ?>
									<div class="mwd-as-inherit<?php echo $inherits ? ' is-inheriting' : ''; ?>">
										<?php $this->switch_row( 'menu_inherit', 'Moștenește configurarea „Implicit"', 'Orice modificare de mai jos creează automat o configurare proprie pentru acest rol.', $inherits ); ?>
									</div>
								<?php endif; ?>

								<div class="mwd-as-toolbar">
									<div class="mwd-as-search">
										<span class="dashicons dashicons-search"></span>
										<input type="search" id="mwd-as-filter" placeholder="Filtrează elementele…" autocomplete="off" />
									</div>
									<div class="mwd-as-toolbar-actions">
										<button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-as-expand-all">Extinde tot</button>
										<button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-as-collapse-all">Restrânge tot</button>
										<button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-as-suggest-groups"><span class="dashicons dashicons-lightbulb"></span> Sugerează grupuri</button>
										<details class="mwd-as-more">
											<summary class="mwd-as-btn mwd-as-btn-sm" aria-label="Mai multe acțiuni"><span class="dashicons dashicons-ellipsis"></span></summary>
											<div class="mwd-as-more-menu">
												<div class="mwd-as-copy">
													<label for="mwd-as-copy-from">Copiază configurarea din</label>
													<select id="mwd-as-copy-from">
														<?php foreach ( $tabs as $slug => $name ) : if ( $slug === $edit_role ) { continue; } ?>
															<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
														<?php endforeach; ?>
													</select>
													<a class="mwd-as-btn mwd-as-btn-sm" id="mwd-as-copy-go" data-confirm="Configurarea de meniu a rolului curent va fi înlocuită. Continui?" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_copy_menu&role=' . $edit_role ), 'mwd_as_copy_menu' ) ); ?>">Copiază</a>
												</div>
												<a class="mwd-as-danger" data-confirm="Resetezi meniul, sub-meniul și coloanele pentru acest rol?" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_reset_menu&role=' . $edit_role ), 'mwd_as_reset_menu' ) ); ?>"><span class="dashicons dashicons-image-rotate"></span> Resetează rolul</a>
											</div>
										</details>
									</div>
								</div>

								<datalist id="mwd-as-grouplist">
									<?php foreach ( array_merge( $groups, array( 'Fără grup' ) ) as $gname ) : ?>
										<option value="<?php echo esc_attr( $gname ); ?>"></option>
									<?php endforeach; ?>
								</datalist>

								<ul class="mwd-mi-list" id="mwd-as-menu-list">
									<?php
									foreach ( $items as $it ) :
										$has_children = ! empty( $it['children'] );
										$hidden_subs  = 0;
										foreach ( $it['children'] as $c ) {
											$hidden_subs += $c['hidden'];
										}
										$cls = 'mwd-mi' . ( $has_children ? ' has-subs is-collapsed' : '' ) . ( $it['hidden'] ? ' is-hidden' : '' );
										?>
										<li class="<?php echo esc_attr( $cls ); ?>" data-search="<?php echo esc_attr( strtolower( $it['label'] . ' ' . $it['slug'] . ' ' . $it['title'] ) ); ?>">
											<div class="mwd-mi-main">
												<span class="mwd-mi-drag dashicons dashicons-menu" title="Trage pentru reordonare"></span>
												<span class="mwd-mi-ico"><?php echo $this->menu_icon( $it['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<input type="hidden" name="menu_slug[]" value="<?php echo esc_attr( $it['slug'] ); ?>" />
												<span class="mwd-mi-name">
													<strong><?php echo esc_html( $it['label'] ); ?></strong>
													<code><?php echo esc_html( $it['slug'] ); ?></code>
												</span>
												<input type="text" class="mwd-mi-rename" name="menu_title[<?php echo esc_attr( $it['slug'] ); ?>]" value="<?php echo esc_attr( $it['title'] ); ?>" placeholder="Redenumește…" aria-label="Nume nou" />
												<span class="mwd-mi-group-wrap">
													<span class="dashicons dashicons-category"></span>
													<input type="text" class="mwd-as-group" name="menu_group[<?php echo esc_attr( $it['slug'] ); ?>]" value="<?php echo esc_attr( $it['group'] ); ?>" placeholder="Grup" list="mwd-as-grouplist" aria-label="Grup" data-mwd-suggest="<?php echo esc_attr( MWD_AS_MenuCollapse::suggest_group( $it['slug'], $it['label'] ) ); ?>" />
												</span>
												<label class="mwd-eye" title="Ascunde / afișează">
													<input type="checkbox" name="menu_hidden[]" value="<?php echo esc_attr( $it['slug'] ); ?>" <?php checked( $it['hidden'], 1 ); ?> />
													<span class="screen-reader-text">Ascunde</span>
												</label>
												<?php if ( $has_children ) : ?>
													<button type="button" class="mwd-mi-toggle" aria-expanded="false">
														<span><?php echo (int) count( $it['children'] ); ?></span>
														<?php if ( $hidden_subs ) : ?><em><?php echo (int) $hidden_subs; ?> ascunse</em><?php endif; ?>
														<span class="dashicons dashicons-arrow-down-alt2"></span>
													</button>
												<?php else : ?>
													<span class="mwd-mi-toggle-spacer"></span>
												<?php endif; ?>
											</div>

											<?php if ( $has_children ) : ?>
												<ul class="mwd-mi-subs">
													<?php foreach ( $it['children'] as $ch ) : ?>
														<li class="mwd-si<?php echo $ch['hidden'] ? ' is-hidden' : ''; ?>">
															<span class="mwd-si-name"><?php echo esc_html( $ch['label'] ); ?></span>
															<input type="text" class="mwd-mi-rename" name="submenu_title[<?php echo esc_attr( $it['slug'] ); ?>][<?php echo esc_attr( $ch['slug'] ); ?>]" value="<?php echo esc_attr( $ch['title'] ); ?>" placeholder="Redenumește…" aria-label="Nume nou" />
															<label class="mwd-eye" title="Ascunde / afișează">
																<input type="checkbox" name="submenu_hidden[<?php echo esc_attr( $it['slug'] ); ?>][]" value="<?php echo esc_attr( $ch['slug'] ); ?>" <?php checked( $ch['hidden'], 1 ); ?> />
																<span class="screen-reader-text">Ascunde</span>
															</label>
														</li>
													<?php endforeach; ?>
												</ul>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="mwd-as-empty" id="mwd-as-filter-empty" hidden>Niciun element nu corespunde filtrului.</p>
								<p class="mwd-as-help"><em>Lista este meniul tău curent de administrator; pentru roluri cu mai puține drepturi unele pagini oricum nu apar.</em></p>
							</div>
						</section>

						<?php /* ============================ COLOANE ============================ */ ?>
						<section class="mwd-as-panel" data-panel="coloane">
							<div class="mwd-as-panel-head">
								<h2>Coloane</h2>
								<p>Ascunde coloane din tabelele de liste (produse, comenzi, articole…) pe roluri.</p>
							</div>
							<div class="mwd-as-card">
								<?php $this->role_tabs( $tabs, $edit_role, 'coloane' ); ?>
								<?php
								$col_cache = get_option( MWD_AS_Columns::CACHE, array() );
								$col_cfg   = isset( $o['columns'][ $edit_role ] ) && is_array( $o['columns'][ $edit_role ] ) ? $o['columns'][ $edit_role ] : array();
								if ( empty( $col_cache ) || ! is_array( $col_cache ) ) :
									?>
									<div class="mwd-as-empty-state">
										<span class="dashicons dashicons-columns"></span>
										<strong>Încă nu am descoperit tabele</strong>
										<p>Coloanele apar aici pe măsură ce deschizi listele din admin (articole, produse, comenzi…).</p>
									</div>
								<?php else : ?>
									<p class="mwd-as-help">Bifează coloanele de <strong>ascuns</strong>. Coloanele rolului se adaugă peste cele din „Implicit".</p>
									<div class="mwd-as-colgroups">
										<?php
										foreach ( $col_cache as $screen => $cols ) :
											if ( empty( $cols ) || ! is_array( $cols ) ) {
												continue;
											}
											?>
											<div class="mwd-as-colgroup">
												<div class="mwd-as-colgroup-h"><?php echo esc_html( MWD_AS_Columns::screen_label( $screen ) ); ?> <code><?php echo esc_html( $screen ); ?></code></div>
												<div class="mwd-as-chips">
													<?php foreach ( $cols as $col_id => $col_label ) : ?>
														<label class="mwd-as-chip is-danger"><input type="checkbox" name="columns[<?php echo esc_attr( $screen ); ?>][]" value="<?php echo esc_attr( $col_id ); ?>" <?php checked( ! empty( $col_cfg[ $screen ][ $col_id ] ) ); ?> /><span><?php echo esc_html( $col_label ); ?></span></label>
													<?php endforeach; ?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						</section>

						<?php /* ============================ DASHBOARD ============================ */ ?>
						<section class="mwd-as-panel" data-panel="dashboard">
							<div class="mwd-as-panel-head">
								<h2>Dashboard & date</h2>
								<p>Panou de start personalizat, analitice integrate și WooCommerce.</p>
							</div>
							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>Dashboard</h3>
									<?php
									$this->switch_row( 'custom_dashboard', 'Înlocuiește dashboard-ul implicit', 'KPI-uri, grafice, comenzi și conținut recent.', $o['custom_dashboard'] );
									$this->switch_row( 'woo_cards', 'Carduri WooCommerce', 'Vânzări, stoc, atribuire (dacă WooCommerce e activ).', $o['woo_cards'] );
									?>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="dashboard_title">Titlu dashboard</label>
										<input type="text" name="dashboard_title" id="dashboard_title" value="<?php echo esc_attr( $o['dashboard_title'] ); ?>" placeholder="Gol = „Bună, [nume]”" />
									</div>
								</div>
								<div class="mwd-as-card">
									<h3>Analitice</h3>
									<?php
									$this->switch_row( 'track_visitors', 'Tracker vizitatori', 'Sesiuni, afișări, surse și timp pe pagină — fără servicii externe.', $o['track_visitors'] );
									$this->switch_row( 'anonymize_ip', 'Anonimizează IP-ul (GDPR)', 'Ultimul octet al IP-ului nu se stochează.', $o['anonymize_ip'] );
									$this->switch_row( 'geo_lookup', 'Detectează țara / orașul', 'Folosește ip-api.com când Cloudflare nu trimite țara.', $o['geo_lookup'] );
									?>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="retention_days">Păstrează datele <small>(30–730 zile)</small></label>
										<select name="retention_days" id="retention_days">
											<?php foreach ( array( 30, 60, 90, 120, 180, 365, 730 ) as $rd ) : ?>
												<option value="<?php echo (int) $rd; ?>" <?php selected( (int) $o['retention_days'], $rd ); ?>><?php echo (int) $rd; ?> zile</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="mwd-as-card">
								<h3>Excluderi analitice</h3>
								<p class="mwd-as-help">IP-ul tău acum: <code id="mwd-myip"><?php echo esc_html( MWD_AS_Tracker::current_ip() ); ?></code> <button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-add-ip">Adaugă la excluse</button></p>
								<div class="mwd-as-grid-2 is-tight">
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="exclude_ips">IP-uri excluse <small>(unul pe linie, ex. <code>85.120.*</code>)</small></label>
										<textarea name="exclude_ips" id="exclude_ips" rows="4" class="code"><?php echo esc_textarea( $o['exclude_ips'] ); ?></textarea>
									</div>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="exclude_paths">Căi excluse <small>(ex. <code>/cos</code>, <code>/checkout*</code>)</small></label>
										<textarea name="exclude_paths" id="exclude_paths" rows="4" class="code"><?php echo esc_textarea( $o['exclude_paths'] ); ?></textarea>
									</div>
								</div>
							</div>
						</section>

						<?php /* ============================ ACCES ============================ */ ?>
						<section class="mwd-as-panel" data-panel="acces">
							<div class="mwd-as-panel-head">
								<h2>Acces & brand</h2>
								<p>White-label pentru clienți și blocarea paginilor ascunse.</p>
							</div>
							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>White-label</h3>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="brand_menu_label">Nume meniu plugin</label>
										<input type="text" name="brand_menu_label" id="brand_menu_label" value="<?php echo esc_attr( $o['brand_menu_label'] ); ?>" placeholder="Admin Studio" />
									</div>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="admin_footer_text">Text footer admin</label>
										<input type="text" name="admin_footer_text" id="admin_footer_text" value="<?php echo esc_attr( $o['admin_footer_text'] ); ?>" placeholder="Întreținut de MyWebDesign.ro" />
									</div>
									<?php
									$this->switch_row( 'hide_wp_version', 'Ascunde versiunea WordPress', '', $o['hide_wp_version'] );
									$this->switch_row( 'hide_wp_logo', 'Ascunde logo-ul WP din bară', '', $o['hide_wp_logo'] );
									$this->switch_row( 'hide_notices', 'Ascunde notificările pentru clienți', 'Utilizatorii fără drept de administrare nu mai văd mesajele plugin-urilor.', $o['hide_notices'] );
									?>
								</div>
								<div class="mwd-as-card">
									<h3>Control acces</h3>
									<?php
									$this->switch_row( 'block_access', 'Blochează paginile ascunse', '„Ascuns" devine și „interzis": accesul direct prin URL e redirecționat la Dashboard.', $o['block_access'] );
									$this->switch_row( 'guard_exempt_admins', 'Administratorii nu sunt blocați niciodată', 'Protecție anti-lockout: rolurile cu drept de administrare au acces peste tot.', $o['guard_exempt_admins'] );
									?>
									<div class="mwd-as-note"><span class="dashicons dashicons-info-outline"></span> Dashboard-ul, profilul și această pagină nu se blochează niciodată. Blocarea acoperă și editarea / crearea de conținut pentru tipurile ascunse.</div>
								</div>
							</div>
						</section>

						<?php /* ============================ LOGIN ============================ */ ?>
						<section class="mwd-as-panel" data-panel="login">
							<div class="mwd-as-panel-head">
								<h2>Login</h2>
								<p>Pagina de autentificare <code>wp-login.php</code> în stilul brandului.</p>
							</div>
							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>Pagina de login</h3>
									<?php $this->switch_row( 'style_login', 'Stilizează pagina de login', '', $o['style_login'] ); ?>
									<div class="mwd-as-field">
										<span class="mwd-as-label">Layout</span>
										<div class="mwd-as-seg">
											<label><input type="radio" name="login_layout" value="split" <?php checked( $o['login_layout'], 'split' ); ?> /><span>Split (brand + formular)</span></label>
											<label><input type="radio" name="login_layout" value="center" <?php checked( $o['login_layout'], 'center' ); ?> /><span>Centrat</span></label>
										</div>
									</div>
									<div class="mwd-as-field">
										<label class="mwd-as-label" for="login_tagline">Mesaj panou brand</label>
										<input type="text" name="login_tagline" id="login_tagline" value="<?php echo esc_attr( $o['login_tagline'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : 'Gol = descrierea site-ului' ); ?>" />
									</div>
									<div class="mwd-as-color-row">
										<label for="login_bg">Fundal brand</label>
										<input type="text" class="mwd-as-color" name="login_bg" id="login_bg" value="<?php echo esc_attr( $o['login_bg'] ); ?>" data-default-color="#0b0b0f" />
									</div>
								</div>
								<div class="mwd-as-card">
									<h3>Logo</h3>
									<div class="mwd-as-logo-pick">
										<div class="mwd-as-logo-prev" id="mwd-logo-prev"<?php echo $o['login_logo_url'] ? ' style="background-image:url(' . esc_url( $o['login_logo_url'] ) . ')"' : ''; ?>><?php echo $o['login_logo_url'] ? '' : '<span class="dashicons dashicons-format-image"></span>'; ?></div>
										<div class="mwd-as-field">
											<input type="url" name="login_logo_url" id="login_logo_url" placeholder="https://…" value="<?php echo esc_attr( $o['login_logo_url'] ); ?>" />
											<div class="mwd-as-row-btns">
												<button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-logo-pick"><span class="dashicons dashicons-admin-media"></span> Alege din Media</button>
												<button type="button" class="mwd-as-btn mwd-as-btn-sm" id="mwd-logo-clear">Elimină</button>
											</div>
										</div>
									</div>
									<a class="mwd-as-link" href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener">Previzualizează pagina de login ↗</a>
								</div>
							</div>
						</section>

						<?php /* ============================ CONFIG ============================ */ ?>
						<section class="mwd-as-panel" data-panel="config">
							<div class="mwd-as-panel-head">
								<h2>Import / export</h2>
								<p>Mută configurația între site-uri și exportă datele colectate.</p>
							</div>
							<div class="mwd-as-grid-2">
								<div class="mwd-as-card">
									<h3>Export</h3>
									<div class="mwd-as-export">
										<a class="mwd-as-tile" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_config' ), 'mwd_as_export_config' ) ); ?>"><span class="dashicons dashicons-media-code"></span><strong>Configurație</strong><small>JSON</small></a>
										<a class="mwd-as-tile" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_visitors' ), 'mwd_as_export_visitors' ) ); ?>"><span class="dashicons dashicons-chart-area"></span><strong>Vizitatori</strong><small>CSV</small></a>
										<?php if ( MWD_AS_Woo::active() ) : ?>
											<a class="mwd-as-tile" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwd_as_export_orders' ), 'mwd_as_export_orders' ) ); ?>"><span class="dashicons dashicons-cart"></span><strong>Comenzi</strong><small>CSV</small></a>
										<?php endif; ?>
									</div>
								</div>
								<div class="mwd-as-card">
									<h3>Import configurație</h3>
									<p class="mwd-as-help">Încarcă un JSON exportat din alt site. Înlocuiește setările curente; datele trec prin aceeași validare ca formularul.</p>
									<div class="mwd-as-import">
										<input type="file" name="mwd_import" accept="application/json,.json" form="mwd-as-import" required />
										<button type="submit" form="mwd-as-import" class="mwd-as-btn">Importă</button>
									</div>
								</div>
								<div class="mwd-as-card">
									<h3>Dezinstalare</h3>
									<?php $this->switch_row( 'delete_on_uninstall', 'Șterge toate datele la dezinstalare', 'Setările, tabelele de analitice și cache-urile se șterg definitiv când pluginul e șters din pagina Plugin-uri.', $o['delete_on_uninstall'] ); ?>
								</div>
							</div>
						</section>

					</div>
				</div>

				<div class="mwd-as-savebar" id="mwd-as-savebar">
					<span class="mwd-as-savebar-msg"><span class="mwd-as-savebar-dot"></span> Ai modificări nesalvate</span>
					<div>
						<a href="<?php echo esc_url( self::url( array( 'role' => 'default' === $edit_role ? null : $edit_role, 'tab' => $tab ) ) ); ?>" class="mwd-as-btn mwd-as-btn-ghost" id="mwd-as-discard">Renunță</a>
						<button type="submit" class="mwd-as-btn mwd-as-btn-primary">Salvează modificările</button>
					</div>
				</div>
			</form>

			<form id="mwd-as-import" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" hidden>
				<input type="hidden" name="action" value="mwd_as_import_config" />
				<?php wp_nonce_field( 'mwd_as_import_config' ); ?>
			</form>
		</div>
		<?php
	}
}
