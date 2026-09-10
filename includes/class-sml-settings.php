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
			'login_redirect_page_id'   => 0,
			'allowed_countries'        => SML_Countries::default_allowed_csv(),
			'button_bg_color'          => '#111114',
			'button_text_color'        => '#ffffff',
			'block_disposable_emails'  => 1,
			'disposable_email_domains' => implode( "\n", SML_Disposable_Email::default_domains() ),
			'allowed_login_roles'      => '',

			// Verification.
			'code_length'              => 6,
			'code_expiry_minutes'      => 10,
			'link_expiry_minutes'      => 30,
			'resend_cooldown_seconds'  => 120,
			'block_until_verified'     => 'block',
			'grace_period_days'        => 0,
			'verify_intro_text'        => __( 'We sent a verification code to your email. Enter it below, or click the link in the email.', 'smart-login' ),

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
			'verify_body'              => __( "Hi {user},\n\nUse the code below to verify your email address. It expires in {expiry_minutes} minutes.\n\n{code}\n\nOr verify instantly with the button below.\n\n{link}", 'smart-login' ),
			'resend_subject'           => __( 'Your new verification code for {site_name}', 'smart-login' ),
			'resend_body'              => __( "Hi {user},\n\nHere is your new verification code. It expires in {expiry_minutes} minutes.\n\n{code}\n\nOr verify instantly with the button below.\n\n{link}", 'smart-login' ),
			'welcome_subject'          => __( 'Welcome to {site_name}', 'smart-login' ),
			'welcome_body'             => __( 'Hi {user},\n\nYour email has been verified and your account is now active.', 'smart-login' ),
			'reset_subject'            => __( 'Reset your password for {site_name}', 'smart-login' ),
			'reset_body'               => __( "Hi {user},\n\nWe received a request to reset your password. This link can only be used once and expires soon.\n\n{link}\n\nIf you didn't request this, you can safely ignore this email — your password won't be changed.", 'smart-login' ),
			'email_footer_name'        => '',
			'email_footer_text'        => '',

			// WooCommerce (only relevant if WooCommerce is active).
			'wc_replace_login'         => 0,
			'wc_replace_register'      => 0,
			'login_page_id'            => 0,
			'require_login_cart'       => 1,
			'require_login_checkout'   => 1,

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

	/**
	 * The configured default role for self-registered users, but never one
	 * that can administer the site. A mis-set `default_role` (misconfig, or
	 * a tampered option row) would otherwise turn open registration into
	 * instant privilege escalation, so the admin dropdown hides privileged
	 * roles and this getter is the matching enforcement at point of use.
	 * Falls back to 'subscriber'.
	 *
	 * @return string
	 */
	public static function safe_default_role() {
		$role_key = (string) self::get( 'default_role', 'subscriber' );
		$role     = get_role( $role_key );

		if ( ! $role || $role->has_cap( 'manage_options' ) || $role->has_cap( 'edit_users' ) ) {
			return 'subscriber';
		}

		return $role_key;
	}

	/**
	 * Where to send a user after a login / email-verification / password
	 * reset that didn't carry an explicit `redirect_to` of its own. Resolves
	 * the configured "redirect after login" page to its permalink; returns
	 * an empty string when unset or the page no longer exists, so callers
	 * can fall back to the site home.
	 *
	 * @return string
	 */
	public static function post_login_redirect_url() {
		$page_id = (int) self::get( 'login_redirect_page_id' );
		if ( ! $page_id ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? $url : '';
	}

	/**
	 * URL of the page that actually holds the `[smart_login_form]` shortcode
	 * — the landing page for password-reset and email-verification links, so
	 * they open the form instead of a bare home page.
	 *
	 * Prefers the configured "Login page" (Settings → General). If that's
	 * unset, scans published pages for the shortcode once and caches the
	 * result for a day. Falls back to the site home.
	 *
	 * @return string
	 */
	public static function form_page_url() {
		$page_id = (int) self::get( 'login_page_id' );
		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		$found = get_transient( 'sml_form_page_url' );
		if ( false === $found ) {
			$found = '';
			$ids   = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'posts_per_page'   => 50,
					's'                => 'smart_login_form',
					'fields'           => 'ids',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
			foreach ( $ids as $pid ) {
				if ( has_shortcode( (string) get_post_field( 'post_content', $pid ), 'smart_login_form' ) ) {
					$found = (string) get_permalink( $pid );
					break;
				}
			}
			set_transient( 'sml_form_page_url', $found, DAY_IN_SECONDS );
		}

		return $found ? $found : home_url( '/' );
	}
}
