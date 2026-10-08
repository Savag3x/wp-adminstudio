<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valori implicite + acces centralizat la optiuni.
 */
class MWD_AS_Defaults {

	/**
	 * Optiunile procesate, memorate pe durata cererii (get_options() e apelat de zeci de ori).
	 */
	private static $cache = null;

	/**
	 * Invalideaza cache-ul (la orice add/update/delete al optiunii).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Paleta + setarile implicite (stil MyWebDesign / SaaS premium).
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'style_login'    => 1,
			'style_adminbar' => 1,
			'custom_dashboard' => 1,
			'dashboard_title'  => '', // gol = salut implicit cu numele userului
			'track_visitors'   => 1,
			'woo_cards'        => 1,
			'anonymize_ip'     => 1,
			'geo_lookup'       => 0,
			'exclude_ips'      => '',
			'exclude_paths'    => '',
			'retention_days'   => 120, // pastrarea datelor de analitice (30..730 zile)
			'delete_on_uninstall' => 0, // sterge tabelele + optiunile la dezinstalare

			// Paleta
			// Preset „Onyx" (premium) pentru instalari noi; instalarile existente isi pastreaza paleta salvata.
			'sidebar_bg'     => '#0b0b0f',
			'sidebar_text'   => '#a1a1aa',
			'accent'         => '#6d5efc',
			'accent_hover'   => '#5b4bf0',
			'content_bg'     => '#f7f7f8',
			'link'           => '#4f46e5',

			// Tipografie & forma
			'font'           => 'Inter',
			'radius'         => 12, // px
			'density'        => 'comfortable', // comfortable | compact
			'finish'         => 'glossy', // glossy (sticla, luciu, gradiente) | flat (mat)
			'layout_canvas'  => 1, // continut pe panou rotunjit, incadrat de sidebar + bara de sus

			// Branding login
			'login_logo_url' => '',
			'login_bg'       => '#0b0b0f',
			'login_layout'   => 'split', // split | center
			'login_tagline'  => '',

			// Rolurile carora li se aplica skin-ul. Gol = toate rolurile.
			'skin_roles'     => array(),

			// White-label / branding
			'brand_menu_label' => 'Admin Studio',
			'admin_footer_text' => '',
			'hide_wp_version'  => 0,
			'hide_wp_logo'     => 0,

			// Ascunde notificarile admin pentru ne-admini (clienti)
			'hide_notices'     => 0,
			'collapse_menu'    => 1,
			'auto_group'       => 1,
			'menu_accordion'   => 0, // o singura sectiune deschisa la un moment dat
			'hide_separators'  => 1,
			// Iconite pentru grupuri: eticheta => clasa dashicons.
			'group_icons'      => array(),

			// Paleta de comenzi (Ctrl/⌘ + K): cautare in meniu, actiuni si continut.
			'cmd_palette'      => 1,

			// Blocheaza real accesul la paginile ascunse (pe rol)
			'block_access'     => 1,
			// Administratorii (manage_options) nu sunt blocati niciodata (anti-lockout).
			'guard_exempt_admins' => 1,

			// Manager coloane PE ROLURI:
			// array rol => array( screen_id => array( col_id => 1 ) )  (1 = ascuns)
			'columns'          => array(),

			// Manager meniu PE ROLURI:
			// array rol => array( slug => array('order'=>int,'hidden'=>0/1,'title'=>string) )
			// Rolul special 'default' se aplica oricarui rol fara configurare proprie.
			'menu'           => array(),

			// Manager SUB-meniu PE ROLURI:
			// array rol => array( parent_slug => array( child_slug => array('hidden'=>0/1,'title'=>string) ) )
			'submenu'        => array(),
		);
	}

	/**
	 * Optiuni curente, completate cu valorile implicite lipsa.
	 * Migreaza automat formatul vechi de meniu (plat, fara roluri) la 'default'.
	 */
	public static function get_options() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( MWD_AS_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts = wp_parse_args( $saved, self::defaults() );

		// Migrare format vechi: menu[slug] = array('order'=>...,'hidden'=>...,'title'=>...)
		if ( ! empty( $opts['menu'] ) && is_array( $opts['menu'] ) ) {
			$first = reset( $opts['menu'] );
			if ( is_array( $first ) && ( isset( $first['order'] ) || isset( $first['hidden'] ) || isset( $first['title'] ) ) ) {
				$opts['menu'] = array( 'default' => $opts['menu'] );
			}
		}

		self::$cache = $opts;
		return $opts;
	}

	/**
	 * Toate rolurile inregistrate: slug => nume afisat.
	 */
	public static function roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$names = array();
		$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		foreach ( $roles as $slug => $data ) {
			$names[ $slug ] = isset( $data['name'] ) ? translate_user_role( $data['name'] ) : $slug;
		}
		return $names;
	}

	/**
	 * Rolul principal al unui utilizator (primul din lista de roluri).
	 */
	public static function user_role( $user = null ) {
		if ( null === $user ) {
			$user = wp_get_current_user();
		}
		if ( ! $user || empty( $user->roles ) ) {
			return '';
		}
		return (string) reset( $user->roles );
	}

	/**
	 * Returneaza configurarea de meniu pentru un rol, cu fallback la 'default'.
	 */
	public static function menu_for_role( $role ) {
		$opts = self::get_options();
		$menu = isset( $opts['menu'] ) && is_array( $opts['menu'] ) ? $opts['menu'] : array();

		if ( '' !== $role && ! empty( $menu[ $role ] ) ) {
			return $menu[ $role ];
		}
		if ( ! empty( $menu['default'] ) ) {
			return $menu['default'];
		}
		return array();
	}

	/**
	 * Returneaza configurarea de sub-meniu pentru un rol, cu fallback la 'default'.
	 */
	public static function submenu_for_role( $role ) {
		$opts = self::get_options();
		$sub  = isset( $opts['submenu'] ) && is_array( $opts['submenu'] ) ? $opts['submenu'] : array();

		if ( '' !== $role && ! empty( $sub[ $role ] ) ) {
			return $sub[ $role ];
		}
		if ( ! empty( $sub['default'] ) ) {
			return $sub['default'];
		}
		return array();
	}

	/**
	 * Verifica daca skin-ul se aplica rolului dat. Lista goala = toate rolurile.
	 */
	public static function skin_applies_to( $role ) {
		$opts  = self::get_options();
		$roles = isset( $opts['skin_roles'] ) && is_array( $opts['skin_roles'] ) ? $opts['skin_roles'] : array();
		if ( empty( $roles ) ) {
			return true;
		}
		return in_array( $role, $roles, true );
	}

	/**
	 * Coloanele ascunse pentru un rol (cu fallback la 'default').
	 */
	public static function columns_for_role( $role ) {
		$opts = self::get_options();
		$cols = isset( $opts['columns'] ) && is_array( $opts['columns'] ) ? $opts['columns'] : array();

		$merged = array();
		if ( ! empty( $cols['default'] ) && is_array( $cols['default'] ) ) {
			$merged = $cols['default'];
		}
		if ( '' !== $role && ! empty( $cols[ $role ] ) && is_array( $cols[ $role ] ) ) {
			// Rolul specific completeaza/suprascrie default.
			foreach ( $cols[ $role ] as $screen => $list ) {
				$merged[ $screen ] = isset( $merged[ $screen ] ) ? array_merge( $merged[ $screen ], $list ) : $list;
			}
		}
		return $merged;
	}

	/**
	 * Iconita (dashicons) pentru un grup de meniu.
	 */
	public static function group_icon( $label ) {
		$opts  = self::get_options();
		$icons = isset( $opts['group_icons'] ) && is_array( $opts['group_icons'] ) ? $opts['group_icons'] : array();
		if ( ! empty( $icons[ $label ] ) ) {
			return $icons[ $label ];
		}
		$builtin = array(
			'Design'          => 'dashicons-layout',
			'Marketing & SEO' => 'dashicons-megaphone',
			'Magazin'         => 'dashicons-cart',
			'Conținut'        => 'dashicons-format-aside',
			'Sistem'          => 'dashicons-shield',
		);
		return isset( $builtin[ $label ] ) ? $builtin[ $label ] : 'dashicons-category';
	}

	/**
	 * Are rolul o configurare de meniu proprie (altfel mosteneste 'default')?
	 */
	public static function role_has_menu( $role ) {
		$opts = self::get_options();
		return 'default' === $role || ( isset( $opts['menu'][ $role ] ) && ! empty( $opts['menu'][ $role ] ) );
	}

	/**
	 * Fonturile disponibile: cheie => array(label, google_family|null).
	 */
	public static function fonts() {
		return array(
			'Outfit'             => array( 'Outfit', 'Outfit:wght@300;400;500;600;700' ),
			'Inter'              => array( 'Inter', 'Inter:wght@300;400;500;600;700' ),
			'Poppins'            => array( 'Poppins', 'Poppins:wght@300;400;500;600;700' ),
			'Plus Jakarta Sans'  => array( 'Plus Jakarta Sans', 'Plus+Jakarta+Sans:wght@300;400;500;600;700' ),
			'Manrope'            => array( 'Manrope', 'Manrope:wght@400;500;600;700;800' ),
			'Geist'              => array( 'Geist', 'Geist:wght@300;400;500;600;700' ),
			'system'             => array( 'System (fara Google Fonts)', null ),
		);
	}
}
