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

		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( ! $username || ! $email || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all fields.', 'smart-login' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'smart-login' ) ) );
		}

		if ( username_exists( $username ) ) {
			wp_send_json_error( array( 'message' => __( 'That username is already taken.', 'smart-login' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with that email already exists.', 'smart-login' ) ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => SML_Settings::get( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		update_user_meta( $user_id, 'sml_email_verified', 0 );

		$issued = SML_Verification::issue( $user_id );
		$user   = get_user_by( 'id', $user_id );
		SML_Email::send_verification( $user, $issued['code'], $issued['token'], false );

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

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_send_json_success( array( 'message' => __( 'Your email has been verified.', 'smart-login' ) ) );
	}

	public static function ajax_resend() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing user.', 'smart-login' ) ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Account not found.', 'smart-login' ) ) );
		}

		if ( SML_Verification::is_verified( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This account is already verified.', 'smart-login' ) ) );
		}

		$cooldown_check = SML_Verification::check_resend_cooldown( $user_id );
		if ( is_wp_error( $cooldown_check ) ) {
			wp_send_json_error(
				array(
					'message'   => $cooldown_check->get_error_message(),
					'remaining' => $cooldown_check->get_error_data()['remaining'],
				)
			);
		}

		$issued = SML_Verification::issue( $user_id );
		SML_Email::send_verification( $user, $issued['code'], $issued['token'], true );

		wp_send_json_success(
			array(
				'code_expires_at'  => $issued['code_expires_at'] * 1000,
				'resend_available' => ( time() + (int) SML_Settings::get( 'resend_cooldown_seconds', 60 ) ) * 1000,
			)
		);
	}

	/**
	 * Honeypot (hidden field + time-trap) and, if configured, the selected
	 * bot-protection provider. Always evaluated server-side.
	 *
	 * @return true|WP_Error
	 */
	public static function check_bot_protection() {
		if ( SML_Settings::get( 'enable_honeypot' ) ) {
			$hp = isset( $_POST['sml_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_hp'] ) ) : '';
			if ( '' !== $hp ) {
				return new WP_Error( 'sml_bot_detected', __( 'Submission rejected.', 'smart-login' ) );
			}

			$rendered_at = isset( $_POST['sml_ts'] ) ? absint( $_POST['sml_ts'] ) : 0;
			if ( ! $rendered_at || ( time() - (int) round( $rendered_at / 1000 ) ) < self::HONEYPOT_MIN_SECONDS ) {
				return new WP_Error( 'sml_bot_detected', __( 'Submission rejected.', 'smart-login' ) );
			}
		}

		$provider_key = SML_Settings::get( 'bot_protection_provider', 'none' );
		if ( 'none' === $provider_key ) {
			return true;
		}

		$provider = SML_Loader::bot_provider( $provider_key );
		if ( ! $provider ) {
			return true;
		}

		$token = isset( $_POST['sml_bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_bot_token'] ) ) : '';
		if ( ! $provider->verify( $token ) ) {
			return new WP_Error( 'sml_bot_detected', __( 'Bot verification failed. Please try again.', 'smart-login' ) );
		}

		return true;
	}
}
