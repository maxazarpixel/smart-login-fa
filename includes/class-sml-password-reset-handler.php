<?php
/**
 * Forgot/reset password AJAX endpoints. Deliberately built on WordPress
 * core's own reset-key primitives (get_password_reset_key(),
 * check_password_reset_key(), reset_password()) rather than reimplementing
 * that crypto — this plugin only supplies the request/response layer and
 * the branded front end around it.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Password_Reset_Handler {

	const NONCE_ACTION = 'sml_password_reset';

	/** @var int Minimum length for a new password. */
	const MIN_PASSWORD_LENGTH = 8;

	/**
	 * IP-scoped ceiling on reset-link requests. WordPress core does not
	 * meaningfully throttle get_password_reset_key(); the enumeration-safe
	 * response defends against discovery, not against volume abuse.
	 */
	const FORGOT_IP_LIMIT  = 5;
	const FORGOT_IP_WINDOW = 900; // 15 minutes.

	public static function init() {
		add_action( 'wp_ajax_nopriv_sml_forgot_password', array( __CLASS__, 'ajax_forgot_password' ) );
		add_action( 'wp_ajax_nopriv_sml_reset_password', array( __CLASS__, 'ajax_reset_password' ) );
	}

	public static function ajax_forgot_password() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';

		if ( ! $login ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your email address.', 'smart-login' ) ) );
		}

		$bot_check = SML_Registration_Handler::check_bot_provider();
		if ( is_wp_error( $bot_check ) ) {
			wp_send_json_error( array( 'message' => $bot_check->get_error_message() ) );
		}

		$rate_check = SML_Rate_Limit::check( 'forgot', self::FORGOT_IP_LIMIT, self::FORGOT_IP_WINDOW );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}
		SML_Rate_Limit::record( 'forgot', self::FORGOT_IP_WINDOW );

		$user        = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

		// Whether or not an account exists, the response is identical —
		// confirming/denying account existence here would let anyone probe
		// which emails are registered.
		if ( $user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				// `sml_key` / `sml_login` (not the bare `key` / `login`)
				// because WooCommerce's My Account page intercepts the bare
				// names on `template_redirect`, stashes them in a cookie and
				// bounces to its own lost-password screen before this
				// plugin's shortcode ever renders.
				$args = array(
					'sml_reset' => '1',
					'sml_key'   => rawurlencode( $key ),
					'sml_login' => rawurlencode( $user->user_login ),
				);
				if ( $redirect_to ) {
					$args['redirect_to'] = rawurlencode( $redirect_to );
				}
				// Land on the page that actually renders [smart_login_form],
				// not a bare home page that may not contain it.
				$reset_url = add_query_arg( $args, SML_Settings::form_page_url() );
				SML_Email::send_password_reset( $user, $reset_url );

				// Per-account counter for the dashboard's "Most password-reset
				// requests" table (helps spot a confused user or abuse).
				update_user_meta(
					$user->ID,
					'sml_reset_requests',
					(int) get_user_meta( $user->ID, 'sml_reset_requests', true ) + 1
				);
				update_user_meta( $user->ID, 'sml_reset_requested_at', time() );
			}
		}

		wp_send_json_success(
			array(
				'message' => __( "If an account exists for that email, we've sent a link to reset the password.", 'smart-login' ),
			)
		);
	}

	public static function ajax_reset_password() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$login   = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$pass    = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

		if ( ! $login || ! $key || ! $pass ) {
			wp_send_json_error( array( 'message' => __( 'Missing reset information. Please request a new link.', 'smart-login' ) ) );
		}

		$bot_check = SML_Registration_Handler::check_bot_provider();
		if ( is_wp_error( $bot_check ) ) {
			wp_send_json_error( array( 'message' => $bot_check->get_error_message() ) );
		}

		if ( $pass !== $confirm ) {
			wp_send_json_error( array( 'message' => __( 'Those passwords do not match.', 'smart-login' ) ) );
		}

		if ( strlen( $pass ) < self::MIN_PASSWORD_LENGTH ) {
			wp_send_json_error(
				array(
					/* translators: %d: minimum password length */
					'message' => sprintf( __( 'Password must be at least %d characters.', 'smart-login' ), self::MIN_PASSWORD_LENGTH ),
				)
			);
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'This password reset link is invalid or has expired. Please request a new one.', 'smart-login' ) ) );
		}

		reset_password( $user, $pass );

		// Successfully using a single-use link mailed to the account's own
		// address is itself proof of owning that inbox — the same guarantee
		// the code/link verification flow exists to establish — so this also
		// completes email verification if it hadn't already: mark verified,
		// clear any pending code/link row, and fire `sml_user_verified`.
		// When the site verifies by mobile, an inbox says nothing about the
		// phone number, so the reset must not count as verification.
		if ( 'email' === SML_Verification::method() && ! SML_Verification::is_verified( $user->ID ) ) {
			SML_Verification::complete( $user->ID );
		}

		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

		// Mirror ajax_login()/ajax_verify_code(): the password change stands,
		// but a role excluded by "Roles allowed to log in" is not signed in
		// through this form.
		if ( ! SML_Login_Handler::role_allowed( $user ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Your password has been reset, but your account type is not permitted to sign in through this form.', 'smart-login' ),
					'redirect' => $redirect_to ?: wp_login_url(),
				)
			);
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		wp_send_json_success(
			array(
				'redirect' => $redirect_to ?: ( SML_Settings::post_login_redirect_url() ?: home_url( '/' ) ),
				'message'  => __( 'Your password has been reset. You are now logged in.', 'smart-login' ),
			)
		);
	}
}
