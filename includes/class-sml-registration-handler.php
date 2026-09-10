<?php
/**
 * Registration AJAX endpoints: register, verify-code, resend.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Registration_Handler {

	const NONCE_ACTION = 'sml_registration';

	/** Minimum seconds between form render and submit — anything faster is treated as a bot. */
	const HONEYPOT_MIN_SECONDS = 3;

	/**
	 * IP-scoped ceiling on verification-email resends, on top of the
	 * per-account cooldown. Stops one client from cycling through user IDs
	 * to email-bomb arbitrary inboxes every cooldown window.
	 */
	const RESEND_IP_LIMIT  = 5;
	const RESEND_IP_WINDOW = 600; // 10 minutes.

	public static function init() {
		add_action( 'wp_ajax_nopriv_sml_register', array( __CLASS__, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_sml_verify_code', array( __CLASS__, 'ajax_verify_code' ) );
		add_action( 'wp_ajax_nopriv_sml_resend', array( __CLASS__, 'ajax_resend' ) );
		add_action( 'wp_ajax_sml_resend', array( __CLASS__, 'ajax_resend' ) );
	}

	public static function ajax_register() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! SML_Settings::get( 'enable_registration' ) ) {
			wp_send_json_error( array( 'message' => __( 'Registration is currently disabled.', 'smart-login' ) ) );
		}

		$bot_check = self::check_bot_protection();
		if ( is_wp_error( $bot_check ) ) {
			wp_send_json_error( array( 'message' => $bot_check->get_error_message() ) );
		}

		$first_name    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$country_id    = isset( $_POST['phone_country'] ) ? sanitize_key( wp_unslash( $_POST['phone_country'] ) ) : '';
		$phone_number  = isset( $_POST['phone_number'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['phone_number'] ) ) : '';
		$password      = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( ! $first_name || ! $last_name || ! $email || ! $phone_number || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all fields.', 'smart-login' ) ) );
		}

		// Same minimum the password-reset flow enforces — a form that gates
		// Cart and Checkout must not accept a one-character password.
		if ( strlen( $password ) < SML_Password_Reset_Handler::MIN_PASSWORD_LENGTH ) {
			wp_send_json_error(
				array(
					/* translators: %d: minimum password length */
					'message' => sprintf( __( 'Password must be at least %d characters.', 'smart-login' ), SML_Password_Reset_Handler::MIN_PASSWORD_LENGTH ),
				)
			);
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'smart-login' ) ) );
		}

		if ( SML_Disposable_Email::is_disposable( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Temporary or disposable email addresses are not allowed. Please register with a permanent email address.', 'smart-login' ) ) );
		}

		$countries = SML_Countries::all();

		if ( ! isset( $countries[ $country_id ] ) || ! SML_Countries::is_allowed( $country_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Registration from the selected country is not available.', 'smart-login' ) ) );
		}

		$phone_dial = $countries[ $country_id ]['dial'];

		$phone_check = SML_Phone::validate( $country_id, $phone_number );
		if ( is_wp_error( $phone_check ) ) {
			wp_send_json_error( array( 'message' => $phone_check->get_error_message() ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with that email already exists.', 'smart-login' ) ) );
		}

		$username = self::generate_username( $first_name, $last_name, $email );

		// wp_insert_user() itself never emails anyone, but WooCommerce (and
		// some themes) hook `user_register` to fire their own "welcome to
		// the site" email synchronously inside it. That races our own
		// verification-gated flow — the account isn't verified yet, so no
		// welcome email should go out until SML_Email::send_welcome() does
		// after the code/link is confirmed. Short-circuiting wp_mail() for
		// the duration of this one call blocks it regardless of which
		// plugin or hook is actually sending it.
		add_filter( 'pre_wp_mail', '__return_true' );
		$user_id = wp_insert_user(
			array(
				'user_login'  => $username,
				'user_email'  => $email,
				'user_pass'   => $password,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'        => SML_Settings::safe_default_role(),
			)
		);
		remove_filter( 'pre_wp_mail', '__return_true' );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		update_user_meta( $user_id, 'sml_email_verified', 0 );
		update_user_meta( $user_id, 'sml_phone', $phone_dial . ' ' . SML_Phone::format( $phone_number ) );

		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

		$issued = SML_Verification::issue( $user_id );
		$user   = get_user_by( 'id', $user_id );
		SML_Email::send_verification( $user, $issued['code'], $issued['token'], false, $redirect_to );

		wp_send_json_success(
			array(
				'user_id'          => $user_id,
				'code_expires_at'  => $issued['code_expires_at'] * 1000,
				'resend_available' => ( time() + (int) SML_Settings::get( 'resend_cooldown_seconds', 60 ) ) * 1000,
				'code_length'      => (int) SML_Settings::get( 'code_length', 6 ),
			)
		);
	}

	public static function ajax_verify_code() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( ! $user_id || ! $code ) {
			wp_send_json_error( array( 'message' => __( 'Missing verification data.', 'smart-login' ) ) );
		}

		$result = SML_Verification::verify_code( $user_id, $code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$user = get_user_by( 'id', $user_id );
		SML_Email::send_welcome( $user );

		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

		// "Roles allowed to log in" must gate every path that opens a
		// session, not just ajax_login() — the email is now verified, but a
		// disallowed role is not signed in through this form.
		if ( ! SML_Login_Handler::role_allowed( $user ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Your email has been verified, but your account type is not permitted to sign in through this form.', 'smart-login' ),
					'redirect' => $redirect_to ?: wp_login_url(),
				)
			);
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_send_json_success(
			array(
				'message'  => __( 'Your email has been verified.', 'smart-login' ),
				'redirect' => $redirect_to ?: ( SML_Settings::post_login_redirect_url() ?: home_url( '/' ) ),
			)
		);
	}

	public static function ajax_resend() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id          = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$cooldown_seconds = (int) SML_Settings::get( 'resend_cooldown_seconds', 120 );
		$code_expiry      = (int) SML_Settings::get( 'code_expiry_minutes', 10 );

		// Default payload for every non-issuing outcome — a missing account,
		// an already-verified account, and a genuine resend all return the
		// same shape so the endpoint can't be used to probe account state
		// (the forgot-password flow is enumeration-safe the same way).
		$response = array(
			'code_expires_at'  => ( time() + $code_expiry * MINUTE_IN_SECONDS ) * 1000,
			'resend_available' => ( time() + $cooldown_seconds ) * 1000,
		);

		// IP-scoped throttle, independent of the per-account cooldown below.
		$rate_check = SML_Rate_Limit::check( 'resend', self::RESEND_IP_LIMIT, self::RESEND_IP_WINDOW );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}
		SML_Rate_Limit::record( 'resend', self::RESEND_IP_WINDOW );

		$user = $user_id ? get_user_by( 'id', $user_id ) : false;

		// Only a real, still-unverified account actually gets another email.
		if ( $user && ! SML_Verification::is_verified( $user_id ) ) {
			$cooldown_check = SML_Verification::check_resend_cooldown( $user_id );
			if ( is_wp_error( $cooldown_check ) ) {
				wp_send_json_error(
					array(
						'message'   => $cooldown_check->get_error_message(),
						'remaining' => $cooldown_check->get_error_data()['remaining'],
					)
				);
			}

			$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );

			$issued = SML_Verification::issue( $user_id );
			SML_Email::send_verification( $user, $issued['code'], $issued['token'], true, $redirect_to );

			$response['code_expires_at']  = $issued['code_expires_at'] * 1000;
			$response['resend_available'] = ( time() + $cooldown_seconds ) * 1000;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Registration's full bot gate: honeypot + configured provider. The two
	 * halves are also callable on their own — the login / forgot-password /
	 * reset forms run only the provider half (they have no honeypot fields).
	 *
	 * @return true|WP_Error
	 */
	public static function check_bot_protection() {
		$honeypot = self::check_honeypot();
		if ( is_wp_error( $honeypot ) ) {
			return $honeypot;
		}

		return self::check_bot_provider();
	}

	/**
	 * Hidden-field + time-trap honeypot. Only the registration form carries
	 * the `sml_hp` / `sml_ts` inputs this reads.
	 *
	 * @return true|WP_Error
	 */
	public static function check_honeypot() {
		if ( ! SML_Settings::get( 'enable_honeypot' ) ) {
			return true;
		}

		$hp = isset( $_POST['sml_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_hp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $hp ) {
			return new WP_Error( 'sml_bot_detected', __( 'Submission rejected.', 'smart-login' ) );
		}

		$rendered_at = isset( $_POST['sml_ts'] ) ? absint( $_POST['sml_ts'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $rendered_at || ( time() - (int) round( $rendered_at / 1000 ) ) < self::HONEYPOT_MIN_SECONDS ) {
			return new WP_Error( 'sml_bot_detected', __( 'Submission rejected.', 'smart-login' ) );
		}

		return true;
	}

	/**
	 * The configured bot-protection provider (Google reCAPTCHA v3 /
	 * Cloudflare Turnstile), if any. Reads the `sml_bot_token` field that
	 * every form now includes. Safe to call when no provider is configured.
	 *
	 * @return true|WP_Error
	 */
	public static function check_bot_provider() {
		$provider_key = SML_Settings::get( 'bot_protection_provider', 'none' );
		if ( 'none' === $provider_key ) {
			return true;
		}

		$provider = SML_Loader::bot_provider( $provider_key );
		if ( ! $provider ) {
			return true;
		}

		$token = isset( $_POST['sml_bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_bot_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $provider->verify( $token ) ) {
			return new WP_Error( 'sml_bot_detected', __( 'Bot verification failed. Please try again.', 'smart-login' ) );
		}

		return true;
	}

	/**
	 * The registration form collects name/email/phone, not a username, so
	 * one is derived here — from the name first, falling back to the email
	 * local part — and de-duplicated with a numeric suffix if taken.
	 *
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 * @return string
	 */
	protected static function generate_username( $first_name, $last_name, $email ) {
		$base = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );

		if ( '' === $base ) {
			$base = sanitize_user( strtolower( substr( $email, 0, strpos( $email, '@' ) ) ), true );
		}

		if ( '' === $base ) {
			$base = 'user';
		}

		$username = $base;
		$suffix   = 1;

		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			$suffix++;
		}

		return $username;
	}
}
