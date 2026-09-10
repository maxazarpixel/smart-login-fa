<?php
/**
 * Requires login on selected WooCommerce pages (currently Cart and
 * Checkout, each independently toggleable), redirecting an anonymous
 * visitor to the configured login page with the originally-requested URL
 * preserved so they land back on it after logging in or verifying.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Page_Guard {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_require_login' ) );
	}

	/**
	 * Registry of guardable pages. Extending this to a new page later is
	 * just adding an entry here plus one toggle field in the admin panel —
	 * everything else (the redirect, the settings default) is generic.
	 *
	 * @return array<string,array{label:string,setting:string,check:callable}>
	 */
	public static function protected_pages() {
		return array(
			'cart'     => array(
				'label'   => __( 'Cart', 'smart-login' ),
				'setting' => 'require_login_cart',
				'check'   => function () {
					return function_exists( 'is_cart' ) && is_cart();
				},
			),
			'checkout' => array(
				'label'   => __( 'Checkout', 'smart-login' ),
				'setting' => 'require_login_checkout',
				'check'   => function () {
					return function_exists( 'is_checkout' ) && is_checkout();
				},
			),
		);
	}

	public static function maybe_require_login() {
		if ( is_user_logged_in() || is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		foreach ( self::protected_pages() as $page ) {
			if ( ! SML_Settings::get( $page['setting'] ) ) {
				continue;
			}
			if ( ! call_user_func( $page['check'] ) ) {
				continue;
			}

			self::redirect_to_login();
			return;
		}
	}

	protected static function redirect_to_login() {
		$login_page_id = (int) SML_Settings::get( 'login_page_id' );
		if ( ! $login_page_id ) {
			// Not configured — fail open rather than lock visitors out of
			// Cart/Checkout because of an incomplete setup.
			return;
		}

		$login_url = get_permalink( $login_page_id );
		if ( ! $login_url ) {
			return;
		}

		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
		$target      = add_query_arg( 'redirect_to', rawurlencode( $current_url ), $login_url );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Validates an untrusted redirect target (from $_GET or $_POST) down to
	 * either a safe local URL or an empty string — never passes through an
	 * external URL, so this can't be turned into an open redirect.
	 *
	 * @param string $raw
	 * @return string
	 */
	public static function validate_redirect( $raw ) {
		$raw = esc_url_raw( (string) $raw );
		if ( ! $raw ) {
			return '';
		}
		return wp_validate_redirect( $raw, '' );
	}
}
