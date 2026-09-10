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

		$notice = '';

		if ( ! SML_Verification::is_verified( $user->ID ) ) {
			if ( SML_Verification::has_verification_record( $user->ID ) ) {
				// A real Smart Login registration — the configured policy applies.
				$branch = self::verification_branch( $user->ID );

				if ( 'block' === $branch ) {
					wp_logout();
					wp_send_json_error(
						array(
							'message'    => __( 'Please verify your email address before logging in.', 'smart-login' ),
							'unverified' => true,
							'user_id'    => $user->ID,
						)
					);
				}
			} else {
				// A pre-existing account from before this plugin was active.
				// Never block someone out of an account they already had —
				// just nudge them to verify, quietly, after they're in.
				if ( self::maybe_send_legacy_reminder( $user ) ) {
					$notice = __( "We noticed your email address isn't verified yet — we've sent you a verification email.", 'smart-login' );
				}
			}
		}

		wp_send_json_success(
			array(
				'redirect' => home_url( '/' ),
				'message'  => __( 'Login successful.', 'smart-login' ),
				'notice'   => $notice,
			)
		);
	}

	/**
	 * Sends a pre-existing, never-verified user a verification email after
	 * a successful login — at most once per day, so logging in repeatedly
	 * doesn't spam their inbox.
	 *
	 * @param WP_User $user
	 * @return bool True if an email was actually sent this time.
	 */
	protected static function maybe_send_legacy_reminder( WP_User $user ) {
		update_user_meta( $user->ID, 'sml_email_verified', 0 );

		$throttle_key = 'sml_legacy_reminder_' . $user->ID;
		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		$issued = SML_Verification::issue( $user->ID );
		SML_Email::send_verification( $user, $issued['code'], $issued['token'], true );
		set_transient( $throttle_key, 1, DAY_IN_SECONDS );

		return true;
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
