<?php
/**
 * Settings accessor. Wraps the single `sml_login_settings` option.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Settings {

	const OPTION_NAME = 'sml_login_settings';

	/**
	 * Default values for every field declared in the admin settings schema.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// General.
			'enable_registration'      => 1,
			'enable_login_form'        => 1,
			'default_role'             => 'subscriber',

			// Verification.
			'code_length'              => 6,
			'code_expiry_minutes'      => 10,
			'link_expiry_minutes'      => 30,
			'resend_cooldown_seconds'  => 60,
			'block_until_verified'     => 'block',
			'grace_period_days'        => 0,

			// Security.
			'max_code_attempts'        => 5,
			'login_lockout_threshold'  => 5,
			'lockout_duration_minutes' => 15,
			'enable_honeypot'          => 1,
			'bot_protection_provider'  => 'none',
			'bot_site_key'             => '',
			'bot_secret_key'           => '',
			'recaptcha_threshold'      => 0.5,

			// Emails.
			'from_name'                => get_bloginfo( 'name' ),
			'from_email'               => get_bloginfo( 'admin_email' ),
			'verify_subject'           => __( 'Verify your email for {site_name}', 'smart-login' ),
			'verify_body'              => __( "Hi {user},\n\nYour verification code is: {code}\nThis code expires in {expiry_minutes} minutes.\n\nOr click the link below to verify instantly:\n{link}\n\n— {site_name}", 'smart-login' ),
			'resend_subject'           => __( 'Your new verification code for {site_name}', 'smart-login' ),
			'resend_body'              => __( "Hi {user},\n\nHere is your new verification code: {code}\nThis code expires in {expiry_minutes} minutes.\n\nOr click the link below to verify instantly:\n{link}\n\n— {site_name}", 'smart-login' ),
			'welcome_subject'          => __( 'Welcome to {site_name}', 'smart-login' ),
			'welcome_body'             => __( "Hi {user},\n\nYour email has been verified and your account is now active.\n\n— {site_name}", 'smart-login' ),

			// WooCommerce (only relevant if WooCommerce is active).
			'wc_replace_login'         => 0,
			'wc_replace_register'      => 0,

			// Advanced.
			'delete_data_on_uninstall' => 0,
			'enable_debug_log'         => 0,
		);
	}

	/**
	 * @return array Full, defaults-merged settings array.
	 */
	public static function all() {
		$saved = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key
	 * @param mixed  $fallback
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}
}
