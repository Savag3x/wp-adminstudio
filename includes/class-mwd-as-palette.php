<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paleta de comenzi (Ctrl/⌘ + K): navigare rapida in tot adminul.
 * Indexeaza meniul lateral randat (deci respecta ascunderile pe rol), actiuni rapide
 * si cauta in continut (articole, pagini, produse) prin REST /wp/v2/search.
 */
class MWD_AS_Palette {

	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 5 );
	}

	public function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'read' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'mwd-cmdk',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">Caută</span><kbd class="mwd-cmdk-kbd">⌘K</kbd>',
				'href'   => '#',
				'meta'   => array( 'class' => 'mwd-cmdk-trigger', 'title' => 'Caută în admin (Ctrl/⌘ + K)' ),
			)
		);
	}

	/**
	 * Actiuni rapide filtrate pe capabilitati.
	 */
	private function actions() {
		$list = array(
			array( 'edit_posts', 'post-new.php', 'dashicons-edit', 'Articol nou', 'creează scrie post' ),
			array( 'edit_pages', 'post-new.php?post_type=page', 'dashicons-admin-page', 'Pagină nouă', 'creează page' ),
			array( 'upload_files', 'media-new.php', 'dashicons-upload', 'Încarcă fișiere media', 'upload imagine' ),
			array( 'install_plugins', 'plugin-install.php', 'dashicons-admin-plugins', 'Adaugă plugin', 'instalează' ),
			array( 'create_users', 'user-new.php', 'dashicons-admin-users', 'Utilizator nou', 'adaugă cont' ),
			array( 'update_core', 'update-core.php', 'dashicons-update', 'Actualizări', 'update' ),
			array( 'read', 'profile.php', 'dashicons-id', 'Profilul meu', 'cont parolă' ),
			array( 'manage_options', 'admin.php?page=mwd-admin-studio', 'dashicons-art', 'Admin Studio — setări', 'design skin meniu' ),
		);
		if ( MWD_AS_Woo::active() ) {
			$list[] = array( 'edit_products', 'post-new.php?post_type=product', 'dashicons-products', 'Produs nou', 'woocommerce adaugă' );
		}

		$out = array();
		foreach ( $list as $a ) {
			if ( ! current_user_can( $a[0] ) ) {
				continue;
			}
			$out[] = array(
				'url'      => admin_url( $a[1] ),
				'icon'     => $a[2],
				'label'    => $a[3],
				'keywords' => $a[4],
			);
		}
		$out[] = array(
			'url'      => home_url( '/' ),
			'icon'     => 'dashicons-external',
			'label'    => 'Vezi site-ul',
			'keywords' => 'front deschide',
			'external' => true,
		);
		return $out;
	}

	public function assets() {
		wp_enqueue_style( 'mwd-as-palette', MWD_AS_URL . 'assets/css/palette.css', array( 'dashicons' ), MWD_AS_VERSION );
		wp_enqueue_script( 'mwd-as-palette', MWD_AS_URL . 'assets/js/palette.js', array(), MWD_AS_VERSION, true );
		wp_localize_script(
			'mwd-as-palette',
			'MWDPalette',
			array(
				'actions'    => $this->actions(),
				'search'     => current_user_can( 'edit_posts' ) ? esc_url_raw( rest_url( 'wp/v2/search' ) ) : '',
				'editBase'   => admin_url( 'post.php?action=edit&post=' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'storageKey' => 'mwdCmdk:' . get_current_user_id(),
				'i18n'       => array(
					'placeholder' => 'Caută pagini, setări, acțiuni sau conținut…',
					'pages'       => 'Navigare',
					'actions'     => 'Acțiuni',
					'recent'      => 'Recente',
					'content'     => 'Conținut',
					'empty'       => 'Niciun rezultat',
					'searching'   => 'Caut în conținut…',
					'hint'        => '↑↓ navighează · ↵ deschide · Esc închide',
				),
			)
		);
	}
}
