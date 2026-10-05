<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reorganizeaza meniul de admin: reordonare, redenumire, ascundere.
 * Configurarea vine din optiuni (cheie 'menu').
 */
class MWD_AS_Menu {

	/**
	 * Bula de notificari de la finalul unui titlu de meniu. Poate contine span-uri imbricate
	 * (ex. Comentarii: awaiting-mod > pending-count + screen-reader-text), deci luam tot pana la final.
	 */
	const BUBBLE_RE = '#\s*<span[^>]*class="[^"]*(awaiting-mod|update-plugins|plugin-count|menu-counter)[^"]*".*$#s';

	/**
	 * Titlul fara bula de notificari, ca text simplu.
	 */
	public static function strip_bubble( $title ) {
		return trim( wp_strip_all_tags( preg_replace( self::BUBBLE_RE, '', (string) $title ) ) );
	}

	public function hooks() {
		// Reordonare top-level.
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( $this, 'reorder' ) );

		// Redenumire + ascundere (tarziu, dupa ce s-au inregistrat toate paginile).
		add_action( 'admin_menu', array( $this, 'rename_and_hide' ), 9999 );

		// Re-calculeaza pozitionarea nativa a meniului cand i se schimba inaltimea (vezi menu-pin.js).
		add_action( 'admin_enqueue_scripts', array( $this, 'pin_script' ) );
	}

	public function pin_script() {
		wp_enqueue_script( 'mwd-as-menu-pin', MWD_AS_URL . 'assets/js/menu-pin.js', array( 'jquery', 'common' ), MWD_AS_VERSION, true );
	}

	private function config() {
		// Configul de meniu pentru rolul principal al utilizatorului curent
		// (cu fallback automat la rolul 'default').
		$role = MWD_AS_Defaults::user_role();
		return MWD_AS_Defaults::menu_for_role( $role );
	}

	private function subconfig() {
		$role = MWD_AS_Defaults::user_role();
		return MWD_AS_Defaults::submenu_for_role( $role );
	}

	/**
	 * Returneaza ordinea dorita a slug-urilor de meniu.
	 */
	public function reorder( $menu_order ) {
		$config = $this->config();
		if ( empty( $config ) ) {
			return $menu_order;
		}

		// Slug-urile care au o ordine setata, sortate dupa 'order'.
		$ordered = array();
		foreach ( $config as $slug => $data ) {
			if ( isset( $data['order'] ) && '' !== $data['order'] ) {
				$ordered[ $slug ] = (int) $data['order'];
			}
		}
		if ( empty( $ordered ) ) {
			return $menu_order;
		}
		asort( $ordered );

		$result = array();
		// Intai elementele ordonate explicit, in ordinea ceruta, daca exista in meniul curent.
		foreach ( array_keys( $ordered ) as $slug ) {
			if ( in_array( $slug, $menu_order, true ) ) {
				$result[] = $slug;
			}
		}
		// Apoi restul, in ordinea originala.
		foreach ( $menu_order as $slug ) {
			if ( ! in_array( $slug, $result, true ) ) {
				$result[] = $slug;
			}
		}

		return $result;
	}

	/**
	 * Redenumeste si ascunde elementele de top-level conform configurarii.
	 */
	public function rename_and_hide() {
		global $menu;

		$config = $this->config();
		if ( empty( $config ) || empty( $menu ) ) {
			return;
		}

		foreach ( $menu as $key => $item ) {
			if ( ! isset( $item[2] ) ) {
				continue;
			}
			$slug = $item[2];

			if ( ! isset( $config[ $slug ] ) ) {
				continue;
			}
			$data = $config[ $slug ];

			// Ascundere.
			if ( ! empty( $data['hidden'] ) ) {
				remove_menu_page( $slug );
				continue;
			}

			// Redenumire (pastram eventualul bubble de notificari).
			if ( isset( $data['title'] ) && '' !== trim( $data['title'] ) ) {
				// Pastreaza span-ul de count daca exista in titlul original.
				$bubble = '';
				if ( preg_match( self::BUBBLE_RE, $item[0], $m ) ) {
					$bubble = ' ' . $m[0];
				}
				$menu[ $key ][0] = esc_html( $data['title'] ) . $bubble;
			}
		}

		$this->apply_submenu();
	}

	/**
	 * Ascunde / redenumeste elemente din sub-meniuri conform configurarii rolului.
	 */
	private function apply_submenu() {
		global $submenu;

		$config = $this->subconfig();
		if ( empty( $config ) || empty( $submenu ) ) {
			return;
		}

		foreach ( $config as $parent => $children ) {
			if ( ! is_array( $children ) ) {
				continue;
			}
			foreach ( $children as $child_slug => $data ) {
				// Ascundere.
				if ( ! empty( $data['hidden'] ) ) {
					remove_submenu_page( $parent, $child_slug );
					continue;
				}
				// Redenumire (cauta intrarea dupa slug in sub-meniul parintelui).
				if ( isset( $data['title'] ) && '' !== trim( $data['title'] ) && isset( $submenu[ $parent ] ) ) {
					foreach ( $submenu[ $parent ] as $k => $sub ) {
						if ( isset( $sub[2] ) && $sub[2] === $child_slug ) {
							$submenu[ $parent ][ $k ][0] = esc_html( $data['title'] );
						}
					}
				}
			}
		}
	}
}
