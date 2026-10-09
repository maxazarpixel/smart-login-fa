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

		// An email address typed with Persian digits is stored with ASCII
		// ones, so look it up that way. (Plain usernames are left as typed.)
		if ( false !== strpos( $username, '@' ) ) {
			$username = SML_Iran::to_latin_digits( $username );
		}

		if ( ! $username || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your username/email and password.', 'smart-login' ) ) );
		}

		$bot_check = SML_Registration_Handler::check_bot_provider();
		if ( is_wp_error( $bot_check ) ) {
			wp_send_json_error( array( 'message' => $bot_check->get_error_message() ) );
		}

		// A mobile number can stand in for the username. Lockout counts by the
		// normalised number, so 0912…, +98912… and Persian digits share one
		// counter instead of each format getting its own attempts.
		$e164     = SML_Phone::parse_e164( $username );
		$lock_key = '' !== $e164 ? $e164 : $username;
		if ( '' !== $e164 ) {
			$matched = SML_Phone::login_for( $e164, $password );
			if ( '' !== $matched ) {
				$username = $matched;
			}
		}

		$lock_check = SML_Lockout::check( $lock_key );
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

		// Passwords are stored with ASCII digits (see registration and reset),
		// so a password typed with Persian digits is retried converted. The
		// as-typed attempt goes first so older passwords that really contain
		// Persian digits keep working.
		if ( is_wp_error( $user ) ) {
			$latin_password = SML_Iran::to_latin_digits( $password );
			if ( $latin_password !== $password ) {
				$user = wp_signon(
					array(
						'user_login'    => $username,
						'user_password' => $latin_password,
						'remember'      => true,
					),
					is_ssl()
				);
			}
		}

		if ( is_wp_error( $user ) ) {
			SML_Lockout::register_failure( $lock_key );
			wp_send_json_error( array( 'message' => __( 'Incorrect username/email or password.', 'smart-login' ) ) );
		}

		SML_Lockout::clear( $lock_key );

		if ( ! self::role_allowed( $user ) ) {
			wp_logout();
			wp_send_json_error( array( 'message' => __( 'Your account type is not permitted to log in through this form.', 'smart-login' ) ) );
		}

		$redirect_to = SML_Page_Guard::validate_redirect( isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '' );
		$notice      = '';

		if ( ! SML_Verification::is_verified( $user->ID ) ) {
			if ( SML_Verification::has_verification_record( $user->ID ) ) {
				// A real Smart Login registration — the configured policy applies.
				$branch = self::verification_branch( $user->ID );

				if ( 'block' === $branch ) {
					wp_logout();

					// Rather than leaving the user on the login form with an
					// error, drop them straight onto the verify-code screen —
					// issuing a fresh code (respecting the resend cooldown,
					// so repeated login attempts can't spam their inbox) so
					// there's always something valid waiting for them there.
					$cooldown_check = SML_Verification::check_resend_cooldown( $user->ID );
					if ( ! is_wp_error( $cooldown_check ) ) {
						$issued = SML_Verification::issue( $user->ID );
						SML_Verification::deliver( $user, $issued, true, $redirect_to );
					}

					$row              = SML_Verification::get_row( $user->ID );
					$cooldown_seconds = (int) SML_Settings::get( 'resend_cooldown_seconds', 120 );
					$resend_available = ( $row && $row->last_sent_at )
						? ( strtotime( $row->last_sent_at . ' UTC' ) + $cooldown_seconds ) * 1000
						: ( time() + $cooldown_seconds ) * 1000;

					wp_send_json_error(
						array(
							'message'          => 'sms' === SML_Verification::channel_for( $user->ID )
								? __( 'Please verify your mobile number to continue. We just sent you a verification code by SMS.', 'smart-login' )
								: __( 'Please verify your email address to continue. We just sent you a verification code.', 'smart-login' ),
							'unverified'       => true,
							'user_id'          => $user->ID,
							'resend_available' => $resend_available,
						)
					);
				}
			} else {
				// A pre-existing account from before this plugin was active.
				// It is never blocked, and by default nothing at all happens —
				// only new registrations are ever asked to verify. The two
				// other modes are opt-in.
				$legacy_mode = SML_Settings::get( 'legacy_unverified_prompt', 'none' );

				if ( 'prompt' === $legacy_mode ) {
					// They're already signed in (wp_signon succeeded). Show
					// the verify screen with a fresh code, plus a "Not now"
					// escape hatch the front end wires to the redirect.
					$cooldown_check = SML_Verification::check_resend_cooldown( $user->ID );
					if ( ! is_wp_error( $cooldown_check ) ) {
						$issued = SML_Verification::issue( $user->ID );
						SML_Verification::deliver( $user, $issued, true, $redirect_to );
					}

					$row              = SML_Verification::get_row( $user->ID );
					$cooldown_seconds = (int) SML_Settings::get( 'resend_cooldown_seconds', 120 );
					$resend_available = ( $row && $row->last_sent_at )
						? ( strtotime( $row->last_sent_at . ' UTC' ) + $cooldown_seconds ) * 1000
						: ( time() + $cooldown_seconds ) * 1000;

					wp_send_json_success(
						array(
							'redirect'         => $redirect_to ?: ( SML_Settings::post_login_redirect_url() ?: home_url( '/' ) ),
							'message'          => __( 'Login successful.', 'smart-login' ),
							'soft_verify'      => true,
							'user_id'          => $user->ID,
							'resend_available' => $resend_available,
						)
					);
				} elseif ( 'email_only' === $legacy_mode && self::maybe_send_legacy_reminder( $user ) ) {
					$notice = __( "We noticed your email address isn't verified yet — we've sent you a verification email.", 'smart-login' );
				}
				// 'none': nothing — they just log in.
			}
		}

		wp_send_json_success(
			array(
				'redirect' => $redirect_to ?: ( SML_Settings::post_login_redirect_url() ?: home_url( '/' ) ),
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
		// Deliberately does NOT set the `sml_email_verified` meta key: doing
		// so would make has_verification_record() treat this pre-existing
		// account as a real Smart Login registration on its next login and
		// subject it to the "block until verified" policy. is_verified()
		// already reads a missing key as unverified, so the key isn't
		// needed until the account actually verifies (which sets it to 1).

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
	 * Whether this user's role is permitted to log in through the Smart
	 * Login form. An empty setting means no restriction — every role is
	 * allowed — so an unconfigured site never locks anyone out by accident.
	 *
	 * Shared with the verification and password-reset handlers so the
	 * restriction covers every code path that opens a session, not only
	 * this one.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	public static function role_allowed( WP_User $user ) {
		$allowed = array_filter( array_map( 'trim', explode( ',', (string) SML_Settings::get( 'allowed_login_roles', '' ) ) ) );
		if ( ! $allowed ) {
			return true;
		}

		return (bool) array_intersect( $allowed, (array) $user->roles );
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
