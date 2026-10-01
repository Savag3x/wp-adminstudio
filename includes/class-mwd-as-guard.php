<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocheaza real accesul la paginile ascunse din meniu (pe rol).
 * "Ascuns" devine si "interzis" - nu doar nevizibil in meniu.
 */
class MWD_AS_Guard {

	// Pagini care nu se blocheaza niciodata (anti-lockout).
	private $safe = array( 'index.php', 'profile.php', 'admin-post.php', 'admin-ajax.php', 'mwd-admin-studio' );

	public function hooks() {
		add_action( 'admin_init', array( $this, 'maybe_block' ), 1 );
	}

	public function maybe_block() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Pagini sigure.
		if ( in_array( $pagenow, $this->safe, true ) || ( $page && in_array( $page, $this->safe, true ) ) ) {
			return;
		}

		$role    = MWD_AS_Defaults::user_role();
		$blocked = $this->blocked_slugs( $role );
		if ( empty( $blocked ) ) {
			return;
		}

		foreach ( $this->candidates( $pagenow, $page ) as $cand ) {
			if ( in_array( $cand, $blocked, true ) ) {
				wp_safe_redirect( admin_url() );
				exit;
			}
		}
	}

	/**
	 * Slug-urile interzise pentru rol (meniu + sub-meniu ascunse).
	 */
	private function blocked_slugs( $role ) {
		$blocked = array();

		$menu = MWD_AS_Defaults::menu_for_role( $role );
		foreach ( $menu as $slug => $d ) {
			if ( ! empty( $d['hidden'] ) ) {
				$blocked[] = $slug;
			}
		}

		$sub = MWD_AS_Defaults::submenu_for_role( $role );
		foreach ( $sub as $parent => $children ) {
			if ( ! is_array( $children ) ) {
				continue;
			}
			foreach ( $children as $cslug => $d ) {
				if ( ! empty( $d['hidden'] ) ) {
					$blocked[] = $cslug;
				}
			}
		}

		// Extinde parintii ascunsi catre copiii lor curenti.
		global $submenu;
		if ( ! empty( $submenu ) && is_array( $submenu ) ) {
			foreach ( $menu as $slug => $d ) {
				if ( ! empty( $d['hidden'] ) && isset( $submenu[ $slug ] ) ) {
					foreach ( $submenu[ $slug ] as $s ) {
						if ( isset( $s[2] ) ) {
							$blocked[] = $s[2];
						}
					}
				}
			}
		}

		// Scoate paginile sigure din lista de blocate.
		return array_diff( array_unique( $blocked ), $this->safe );
	}

	/**
	 * Identificatori posibili ai paginii curente.
	 */
	private function candidates( $pagenow, $page ) {
		$c   = array();
		$pt  = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $page ) {
			$c[] = $page;
			$c[] = 'admin.php?page=' . $page;
			$c[] = $pagenow . '?page=' . $page;
		}
		if ( $pt ) {
			$c[] = $pagenow . '?post_type=' . $pt;
		}
		if ( $tax ) {
			$c[] = $pagenow . '?taxonomy=' . $tax;
			if ( $pt ) {
				$c[] = $pagenow . '?taxonomy=' . $tax . '&post_type=' . $pt;
			}
		}
		if ( '' === $page && '' === $pt && '' === $tax ) {
			$c[] = $pagenow;
		}
		return $c;
	}
}
