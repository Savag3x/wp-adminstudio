<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Colapsează automat item-ele ne-esențiale din bara laterală sub un buton „Mai multe".
 * Totul se face în JS (mutare DOM + toggle), deci nu intră în conflict cu managerul de meniu
 * și nu atinge structura #adminmenuwrap / #adminmenuback.
 */
class MWD_AS_MenuCollapse {

	private $groups = array();

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'mark_groups' ), 9998 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Slug-uri esentiale care nu se grupeaza niciodata automat.
	 */
	public static function essential_slugs() {
		return array(
			'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page',
			'edit-comments.php', 'themes.php', 'plugins.php', 'users.php',
			'tools.php', 'options-general.php', 'edit.php?post_type=product',
			'woocommerce', 'mwd-admin-studio',
		);
	}

	/**
	 * Reguli de grupare automata: grup => cuvinte-cheie (in slug sau titlu).
	 */
	public static function auto_rules() {
		$rules = array(
			'Design'          => array( 'elementor', 'betheme', 'wpbakery', 'js_composer', 'vc-', 'templates', 'slider', 'revslider', 'modula', 'slides', 'brizy', 'divi', 'beaver', 'oxygen', 'fusion', 'themify', 'gallery' ),
			'Marketing & SEO' => array( 'seo', 'yoast', 'rank-math', 'rankmath', 'analytify', 'sitekit', 'googlesitekit', 'site-kit', 'gtm', 'mailchimp', 'marketing', 'newsletter', 'pixel', 'taboola', 'analytics' ),
			'Magazin'         => array( 'getwooplugins', 'custom-product', 'order-notifier', 'wpclever', 'points', 'rewards', 'wcpos', 'shipping', 'checkout', 'invoice' ),
			'Conținut'        => array( 'portfolio', 'testimonial', 'invitat', 'offer', 'client', 'contact', 'wpforms', 'cf7', 'ninja-forms', 'forminator', 'faq', 'chatbot' ),
			'Sistem'          => array( 'staging', 'updraft', 'backup', 'complianz', 'cookie', 'security', 'wordfence', 'cache', 'litespeed', 'wpcode', 'redirection', 'health', 'smtp', 'wp-mail', 'migrate', 'duplicator', 'log' ),
		);
		return apply_filters( 'mwd_as_auto_group_rules', $rules );
	}

	/**
	 * Sugestia de grup pentru un item (esentialele nu se grupeaza).
	 */
	public static function suggest_group( $slug, $title ) {
		if ( in_array( $slug, self::essential_slugs(), true ) ) {
			return '';
		}
		$hay = strtolower( $slug . ' ' . wp_strip_all_tags( $title ) );
		foreach ( self::auto_rules() as $group => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $hay, $kw ) ) {
					return $group;
				}
			}
		}
		return '';
	}

	/**
	 * Valori care inseamna „fara grup" (tine item-ul sus, ignora auto).
	 */
	private static function is_none( $label ) {
		return in_array( strtolower( trim( $label ) ), array( '-', '—', 'fara grup', 'fără grup', 'niciunul', 'none' ), true );
	}

	/**
	 * Marcheaza fiecare item de meniu cu o clasa de grup, conform configurarii rolului.
	 */
	public function mark_groups() {
		global $menu;
		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}
		$cfg  = MWD_AS_Defaults::menu_for_role( MWD_AS_Defaults::user_role() );
		$opts = MWD_AS_Defaults::get_options();
		$auto = ! empty( $opts['auto_group'] );

		foreach ( $menu as $i => $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$slug   = $item[2];
			$manual = isset( $cfg[ $slug ]['group'] ) ? trim( (string) $cfg[ $slug ]['group'] ) : '';

			// „Fara grup" explicit -> ramane sus, fara auto.
			if ( '' !== $manual && self::is_none( $manual ) ) {
				continue;
			}

			$label = $manual;
			if ( '' === $label && $auto ) {
				$label = self::suggest_group( $slug, isset( $item[0] ) ? $item[0] : '' );
			}
			if ( '' === $label ) {
				continue;
			}

			$key           = 'g' . substr( md5( $label ), 0, 8 );
			$menu[ $i ][4] = ( isset( $item[4] ) ? $item[4] : '' ) . ' mwd-grp ' . $key;
			if ( ! isset( $this->groups[ $key ] ) ) {
				$this->groups[ $key ] = $label;
			}
		}
	}

	/**
	 * Slug-urile (id-urile de <li>) considerate esențiale, mereu vizibile.
	 */
	public static function essentials() {
		$e = array(
			'menu-dashboard',
			'menu-posts',
			'menu-media',
			'menu-pages',
			'menu-comments',
			'menu-appearance',
			'menu-plugins',
			'menu-users',
			'menu-tools',
			'menu-settings',
			'menu-posts-product',
			'toplevel_page_woocommerce',
			'toplevel_page_mwd-admin-studio',
		);
		return apply_filters( 'mwd_as_menu_essentials', $e );
	}

	public function assets() {
		$groups = array();
		foreach ( $this->groups as $key => $label ) {
			$groups[] = array( 'key' => $key, 'label' => $label );
		}

		wp_enqueue_style( 'mwd-as-menu-collapse', MWD_AS_URL . 'assets/css/menu-collapse.css', array(), MWD_AS_VERSION );
		wp_enqueue_script( 'mwd-as-menu-collapse', MWD_AS_URL . 'assets/js/menu-collapse.js', array(), MWD_AS_VERSION, true );
		wp_localize_script(
			'mwd-as-menu-collapse',
			'MWDMenuCollapse',
			array(
				'essentials' => array_values( self::essentials() ),
				'label'      => 'Mai multe',
				'groups'     => $groups,
			)
		);
	}
}
