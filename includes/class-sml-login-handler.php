<?php
/**
 * Login AJAX endpoint: wp_signon wrapper with lockout tracking and
 * "blocked until verified" branching.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Login_Handler {

	const NONCE_ACTION = 'sml_login';

	public static function init() {
		add_action( 'wp_ajax_nopriv_sml_login', array( __CLASS__, 'ajax_login' ) );
	}

	public static function ajax_login() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( ! $username || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your username/email and password.', 'smart-login' ) ) );
		}

		$lock_check = SML_Lockout::check( $username );
		if ( is_wp_error( $lock_check ) ) {
			wp_send_json_error( array( 'message' => $lock_check->get_error_message() ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			SML_Lockout::register_failure( $username );
			wp_send_json_error( array( 'message' => __( 'Incorrect username/email or password.', 'smart-login' ) ) );
		}

		SML_Lockout::clear( $username );

		if ( ! SML_Verification::is_verified( $user->ID ) ) {
			$branch = self::verification_branch( $user->ID );

			if ( 'block' === $branch ) {
				wp_logout();
				wp_send_json_error(
					array(
						'message'          => __( 'Please verify your email address before logging in.', 'smart-login' ),
						'unverified'       => true,
						'user_id'          => $user->ID,
					)
				);
			}
		}

		wp_send_json_success(
			array(
				'redirect' => home_url( '/' ),
				'message'  => __( 'Login successful.', 'smart-login' ),
			)
		);
	}

	/**
	 * Resolves the "block until verified" setting (including the grace
	 * period) into a simple 'block' or 'allow' decision for this login.
	 *
	 * @param int $user_id
	 * @return string 'block'|'allow'
	 */
	protected static function verification_branch( $user_id ) {
		$mode = SML_Settings::get( 'block_until_verified', 'block' );

		if ( 'notice' === $mode ) {
			return 'allow';
		}

		if ( 'grace' === $mode ) {
			$grace_days = (int) SML_Settings::get( 'grace_period_days', 0 );
			if ( $grace_days <= 0 ) {
				return 'block';
			}
			$user = get_userdata( $user_id );
			$registered = strtotime( $user->user_registered . ' UTC' );
			$deadline   = $registered + ( $grace_days * DAY_IN_SECONDS );
			return time() < $deadline ? 'allow' : 'block';
		}

		return 'block';
	}
}
