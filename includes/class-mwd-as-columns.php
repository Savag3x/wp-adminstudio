<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager de coloane pentru tabelele de liste (produse, comenzi, articole, etc).
 * Descopera coloanele cand vizitezi un ecran si le ascunde pe rol.
 */
class MWD_AS_Columns {

	const CACHE = 'mwd_as_columns_cache';

	public function hooks() {
		add_action( 'current_screen', array( $this, 'attach' ), 20 );
	}

	/**
	 * Pe ecranele de tip listă, ataseaza filtrul de coloane.
	 */
	public function attach( $screen ) {
		if ( ! $screen || empty( $screen->id ) ) {
			return;
		}
		// Doar liste (au baza WP_List_Table) - filtrul manage_{id}_columns exista.
		add_filter( 'manage_' . $screen->id . '_columns', array( $this, 'filter_columns' ), 9999 );
	}

	/**
	 * Inregistreaza coloanele in cache + ascunde ce e ascuns pentru rol.
	 */
	public function filter_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$screen = get_current_screen();
		$id     = $screen ? $screen->id : '';
		if ( '' === $id ) {
			return $columns;
		}

		$this->remember( $id, $columns );

		$role   = MWD_AS_Defaults::user_role();
		$hidden = MWD_AS_Defaults::columns_for_role( $role );
		if ( ! empty( $hidden[ $id ] ) && is_array( $hidden[ $id ] ) ) {
			foreach ( $hidden[ $id ] as $col => $on ) {
				if ( $on && isset( $columns[ $col ] ) ) {
					unset( $columns[ $col ] );
				}
			}
		}

		return $columns;
	}

	/**
	 * Salveaza etichetele coloanelor descoperite (doar daca s-au schimbat).
	 */
	private function remember( $id, $columns ) {
		$cache = get_option( self::CACHE, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$labels = array();
		foreach ( $columns as $col => $label ) {
			// 'cb' = checkbox de selectie, nu il oferim spre ascundere.
			if ( 'cb' === $col ) {
				continue;
			}
			$labels[ $col ] = trim( wp_strip_all_tags( is_string( $label ) ? $label : $col ) );
			if ( '' === $labels[ $col ] ) {
				$labels[ $col ] = $col;
			}
		}

		if ( ! isset( $cache[ $id ] ) || $cache[ $id ] !== $labels ) {
			$cache[ $id ] = $labels;
			update_option( self::CACHE, $cache, false );
		}
	}

	/**
	 * Eticheta prietenoasa pentru un screen id.
	 */
	public static function screen_label( $id ) {
		$map = array(
			'edit-post'       => 'Articole',
			'edit-page'       => 'Pagini',
			'edit-product'    => 'Produse',
			'edit-shop_order' => 'Comenzi',
			'users'           => 'Utilizatori',
			'plugins'         => 'Plugin-uri',
			'upload'          => 'Media',
		);
		if ( isset( $map[ $id ] ) ) {
			return $map[ $id ];
		}
		if ( 0 === strpos( $id, 'edit-' ) ) {
			return ucfirst( str_replace( array( 'edit-', '_', '-' ), array( '', ' ', ' ' ), $id ) );
		}
		return $id;
	}
}
