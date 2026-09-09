<?php
/**
 * WooCommerce integration — only loaded when WooCommerce is active.
 * Optionally swaps the My Account login/register form template for the
 * Smart Login shortcode output.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_WooCommerce {

	public static function init() {
		if ( SML_Settings::get( 'wc_replace_login' ) || SML_Settings::get( 'wc_replace_register' ) ) {
			add_filter( 'wc_get_template', array( __CLASS__, 'swap_login_template' ), 10, 2 );
		}
	}

	/**
	 * WooCommerce's form-login.php template renders both the login form and
	 * (if enabled) the register form in one file, so a single toggle check
	 * against either replace_* setting is enough to redirect it — the
	 * template itself still respects `woocommerce_registration_enabled` for
	 * whether to show the register half.
	 *
	 * @param string $template
	 * @param string $template_name
	 * @return string
	 */
	public static function swap_login_template( $template, $template_name ) {
		if ( 'myaccount/form-login.php' === $template_name ) {
			return SML_PLUGIN_DIR . 'public/views/wc-form-login.php';
		}
		return $template;
	}
}
