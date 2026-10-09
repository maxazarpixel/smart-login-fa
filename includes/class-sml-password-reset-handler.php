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
		add_action( 'wp_ajax_nopriv_sml_reset_with_code', array( __CLASS__, 'ajax_reset_with_code' ) );
	}

	const SMS_META          = 'sml_reset_sms';
	const CODE_IP_LIMIT     = 10;
	const CODE_IP_WINDOW    = 900; // 15 minutes.

	protected static function code_hash( $code ) {
		return hash_hmac( 'sha256', (string) $code, wp_salt( 'auth' ) );
	}

	/**
	 * "Forgot password" for a mobile number: texts a one-time code. Like the
	 * email path the answer never says whether the number belongs to an
	 * account.
	 *
	 * @param string $e164
	 */
	protected static function forgot_by_sms( $e164 ) {
		if ( ! SML_SMS::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Resetting by mobile number is not available. Please use your email address.', 'smart-login' ) ) );
		}

		$user = SML_Phone::primary_user_for( $e164 );

		if ( $user ) {
			$state    = get_user_meta( $user->ID, self::SMS_META, true );
			$cooldown = (int) SML_Settings::get( 'resend_cooldown_seconds', 120 );
			$recent   = is_array( $state ) && ! empty( $state['sent'] ) && ( time() - (int) $state['sent'] ) < $cooldown;

			if ( ! $recent ) {
				$length = max( 4, min( 8, (int) SML_Settings::get( 'code_length', 6 ) ) );
				$code   = '';
				for ( $i = 0; $i < $length; $i++ ) {
					$code .= (string) random_int( 0, 9 );
				}

				update_user_meta(
					$user->ID,
					self::SMS_META,
					array(
						'hash'     => self::code_hash( $code ),
						'expires'  => time() + (int) SML_Settings::get( 'code_expiry_minutes', 10 ) * MINUTE_IN_SECONDS,
						'attempts' => 0,
						'sent'     => time(),
					)
				);

				// A failed send is logged by SML_SMS; the visitor still gets the
				// uniform answer below so the response reveals nothing.
				$sent = SML_SMS::send_code( $e164, $code, true, (string) SML_Settings::get( 'sms_reset_template' ) );
				if ( is_wp_error( $sent ) ) {
					delete_user_meta( $user->ID, self::SMS_META );
				}

				update_user_meta( $user->ID, 'sml_reset_requests', (int) get_user_meta( $user->ID, 'sml_reset_requests', true ) + 1 );
				update_user_meta( $user->ID, 'sml_reset_requested_at', time() );
			}
		}

		wp_send_json_success(
			array(
				'mode'    => 'sms',
				'message' => __( "If an account exists for that mobile number, we've sent a code by SMS.", 'smart-login' ),
			)
		);
	}

	/**
	 * Second step of the SMS reset: code + new password.
	 */
	public static function ajax_reset_with_code() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$login   = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
		$code    = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', SML_Iran::to_latin_digits( wp_unslash( $_POST['code'] ) ) ) : '';
		$pass    = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

		if ( ! $login || ! $code || ! $pass ) {
			wp_send_json_error( array( 'message' => __( 'Missing reset information. Please request a new code.', 'smart-login' ) ) );
		}

		$bot_check = SML_Registration_Handler::check_bot_provider();
		if ( is_wp_error( $bot_check ) ) {
			wp_send_json_error( array( 'message' => $bot_check->get_error_message() ) );
		}

		$rate_check = SML_Rate_Limit::check( 'reset_code', self::CODE_IP_LIMIT, self::CODE_IP_WINDOW );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}
		SML_Rate_Limit::record( 'reset_code', self::CODE_IP_WINDOW );

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

		// One message for every failure (unknown number, no pending code,
		// expired, too many tries, wrong code) so none of them can be told apart.
		$generic = __( 'That code is incorrect or has expired. Please request a new one.', 'smart-login' );
		$e164    = SML_Phone::parse_e164( $login );
		$user    = '' !== $e164 ? SML_Phone::primary_user_for( $e164 ) : null;
		$state   = $user ? get_user_meta( $user->ID, self::SMS_META, true ) : null;

		if ( ! $user || ! is_array( $state ) || empty( $state['hash'] )
			|| (int) $state['expires'] < time()
			|| (int) $state['attempts'] >= (int) SML_Settings::get( 'max_code_attempts', 5 ) ) {
			wp_send_json_error( array( 'message' => $generic ) );
		}

		if ( ! hash_equals( (string) $state['hash'], self::code_hash( $code ) ) ) {
			$state['attempts'] = (int) $state['attempts'] + 1;
			update_user_meta( $user->ID, self::SMS_META, $state );
			wp_send_json_error( array( 'message' => $generic ) );
		}

		delete_user_meta( $user->ID, self::SMS_META );
		reset_password( $user, $pass );

		// Receiving the code proves the phone number. That only counts as
		// verification when the site verifies by mobile.
		if ( 'mobile' === SML_Verification::method() && ! SML_Verification::is_verified( $user->ID ) ) {
			SML_Verification::complete( $user->ID );
		}

		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

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

	public static function ajax_forgot_password() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';

		if ( ! $login ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your email address or mobile number.', 'smart-login' ) ) );
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

		$e164 = SML_Phone::parse_e164( $login );
		if ( '' !== $e164 ) {
			self::forgot_by_sms( $e164 );
		}

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
