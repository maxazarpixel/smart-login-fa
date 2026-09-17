<?php
/**
 * "Continue with Google" (OAuth 2.0 Authorization Code flow).
 *
 * Deliberately self-contained: this is the only class that knows about
 * Google's endpoints, tokens or profile format. It touches the rest of the
 * plugin at exactly four points — SML_Settings (its own `google_*` /
 * `enable_google_login` keys), SML_Page_Guard::validate_redirect(),
 * SML_Login_Handler::role_allowed(), and SML_Verification/SML_Email (to
 * mark a linked account verified and send the same welcome email a normal
 * registration gets). Everything else — the OAuth dance, token exchange,
 * profile fetch, user resolution — lives here.
 *
 * No SDK/Composer dependency: two plain wp_remote_post()/wp_remote_get()
 * calls against Google's documented REST endpoints.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Google_Auth {

	const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
	const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

	/** How long a start->callback round trip has to complete. */
	const STATE_TTL = 10 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
	}

	/**
	 * On, and both credentials filled in. Everything else in this class is
	 * a no-op when this is false.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) SML_Settings::get( 'enable_google_login' )
			&& '' !== trim( (string) SML_Settings::get( 'google_client_id' ) )
			&& '' !== trim( (string) SML_Settings::get( 'google_client_secret' ) );
	}

	/**
	 * The exact URL to register as an "Authorized redirect URI" in the
	 * Google Cloud Console. Fixed and query-string-based (not tied to the
	 * Login page) so it keeps working if that page changes.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( 'sml_google', 'callback', home_url( '/' ) );
	}

	/**
	 * "Continue with Google" button + divider, or '' when the feature is
	 * off. $redirect_to is carried through the whole round trip.
	 *
	 * @param string $redirect_to Already validated by the caller.
	 * @return string
	 */
	public static function button_html( $redirect_to ) {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'sml_google'  => 'start',
				'redirect_to' => rawurlencode( (string) $redirect_to ),
			),
			home_url( '/' )
		);

		// The official Google "G" mark, as required by Google's branding
		// guidelines for a "Sign in with Google" button.
		$logo = '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
			. '<path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"/>'
			. '<path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.583-5.036-3.71H.957v2.332A8.997 8.997 0 0 0 9 18z"/>'
			. '<path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>'
			. '<path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"/>'
			. '</svg>';

		return '<a class="sml-btn sml-btn--google" href="' . esc_url( $url ) . '">'
			. $logo
			. '<span>' . esc_html__( 'Continue with Google', 'smart-login' ) . '</span>'
			. '</a>'
			. '<div class="sml-divider"><span>' . esc_html__( 'or', 'smart-login' ) . '</span></div>';
	}

	/**
	 * @return string|null A short error code for the front end, or null.
	 */
	public static function error_from_request() {
		if ( ! isset( $_GET['sml_google_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		return sanitize_key( wp_unslash( $_GET['sml_google_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* ── request routing ─────────────────────────────────────────────── */

	public static function handle_request() {
		if ( ! isset( $_GET['sml_google'] ) || ! self::is_enabled() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['sml_google'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'start' === $action ) {
			self::start();
		} elseif ( 'callback' === $action ) {
			self::callback();
		}
	}

	/**
	 * Stashes the (already-validated-on-the-way-in) redirect target behind
	 * a one-time state token, then 302s to Google's consent screen.
	 */
	protected static function start() {
		$redirect_to = SML_Page_Guard::validate_redirect(
			isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		$state = wp_generate_password( 32, false, false );
		set_transient( 'sml_google_state_' . $state, array( 'redirect_to' => $redirect_to ), self::STATE_TTL );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( (string) SML_Settings::get( 'google_client_id' ) ),
				'redirect_uri'  => rawurlencode( self::callback_url() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'access_type'   => 'online',
				'prompt'        => 'select_account',
			),
			self::AUTH_ENDPOINT
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- deliberately off-site, to Google's own auth endpoint.
		exit;
	}

	/**
	 * Google's return trip: verify state, exchange the code, resolve a WP
	 * user, sign them in, and land on the original destination.
	 */
	protected static function callback() {
		$state_key = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stashed   = $state_key ? get_transient( 'sml_google_state_' . $state_key ) : false;
		if ( $state_key ) {
			delete_transient( 'sml_google_state_' . $state_key ); // one-time use either way
		}

		$redirect_to = ( is_array( $stashed ) && ! empty( $stashed['redirect_to'] ) ) ? $stashed['redirect_to'] : '';
		$landing     = $redirect_to ? $redirect_to : ( SML_Settings::post_login_redirect_url() ?: home_url( '/' ) );

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::fail( $landing, 'denied' );
		}
		if ( ! $stashed ) {
			self::fail( $landing, 'state' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			self::fail( $landing, 'state' );
		}

		$token = self::exchange_code( $code );
		if ( is_wp_error( $token ) ) {
			SML_Loader::log( 'Google OAuth: ' . $token->get_error_message() );
			self::fail( $landing, 'token' );
		}

		$profile = self::fetch_profile( $token['access_token'] );
		if ( is_wp_error( $profile ) ) {
			SML_Loader::log( 'Google OAuth: ' . $profile->get_error_message() );
			self::fail( $landing, 'profile' );
		}

		$user = self::find_or_create_user( $profile );
		if ( is_wp_error( $user ) ) {
			self::fail( $landing, $user->get_error_code() );
		}

		if ( ! SML_Login_Handler::role_allowed( $user ) ) {
			self::fail( $landing, 'role' );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		// Not fired automatically by wp_set_auth_cookie() — other plugins
		// (WooCommerce cart merge, this plugin's own "recent logins") hook
		// wp_login, same as every other sign-in path in this plugin.
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( $landing );
		exit;
	}

	/**
	 * @param string $landing
	 * @param string $code Short reason, read back by the shortcode.
	 */
	protected static function fail( $landing, $code ) {
		wp_safe_redirect( add_query_arg( 'sml_google_error', $code, $landing ) );
		exit;
	}

	/* ── Google API calls ────────────────────────────────────────────── */

	/**
	 * @param string $code
	 * @return array|WP_Error Decoded token response, or an error.
	 */
	protected static function exchange_code( $code ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => (string) SML_Settings::get( 'google_client_id' ),
					'client_secret' => (string) SML_Settings::get( 'google_client_secret' ),
					'redirect_uri'  => self::callback_url(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'sml_google_token', 'Token exchange failed: ' . wp_remote_retrieve_body( $response ) );
		}

		return $body;
	}

	/**
	 * @param string $access_token
	 * @return array|WP_Error Decoded profile (sub, email, email_verified, name, …), or an error.
	 */
	protected static function fetch_profile( $access_token ) {
		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['sub'] ) || empty( $body['email'] ) ) {
			return new WP_Error( 'sml_google_profile', 'Could not read the Google profile.' );
		}

		return $body;
	}

	/* ── user resolution ─────────────────────────────────────────────── */

	/**
	 * Finds the WP user this Google profile belongs to — by a previously
	 * linked subject id, then by matching email — or creates one. Google
	 * already proved the email is real and owned by this visitor, so a
	 * newly created or newly linked account is marked verified outright.
	 *
	 * @param array $profile
	 * @return WP_User|WP_Error
	 */
	protected static function find_or_create_user( array $profile ) {
		if ( empty( $profile['email_verified'] ) ) {
			return new WP_Error( 'sml_google_unverified', __( "Your Google account's email is not verified.", 'smart-login' ) );
		}

		$email = sanitize_email( (string) $profile['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'sml_google_email', __( 'Google did not return a usable email address.', 'smart-login' ) );
		}

		$sub = sanitize_text_field( (string) $profile['sub'] );

		$linked = get_users(
			array(
				'meta_key'   => 'sml_google_sub', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( $linked ) {
			return get_userdata( (int) $linked[0] );
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			update_user_meta( $existing->ID, 'sml_google_sub', $sub );
			if ( ! SML_Verification::is_verified( $existing->ID ) ) {
				SML_Verification::complete( $existing->ID );
			}
			return $existing;
		}

		if ( ! (bool) SML_Settings::get( 'enable_registration', 1 ) ) {
			return new WP_Error( 'sml_google_registration_disabled', __( 'New account registration is currently disabled.', 'smart-login' ) );
		}

		if ( SML_Settings::get( 'block_disposable_emails' ) && SML_Disposable_Email::is_disposable( $email ) ) {
			return new WP_Error( 'sml_google_disposable', __( 'Temporary or disposable email addresses are not allowed.', 'smart-login' ) );
		}

		$first_name = isset( $profile['given_name'] ) ? sanitize_text_field( (string) $profile['given_name'] ) : '';
		$last_name  = isset( $profile['family_name'] ) ? sanitize_text_field( (string) $profile['family_name'] ) : '';
		$full_name  = isset( $profile['name'] ) ? sanitize_text_field( (string) $profile['name'] ) : trim( $first_name . ' ' . $last_name );

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::generate_username( $first_name, $last_name, $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32 ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $full_name ? $full_name : $email,
				'role'         => SML_Settings::safe_default_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'sml_google_sub', $sub );
		update_user_meta( $user_id, 'sml_email_verified', 1 );

		$user = get_userdata( $user_id );
		SML_Email::send_welcome( $user );

		return $user;
	}

	/**
	 * Same derivation as the password-registration flow (name first, email
	 * local-part fallback, numeric de-dup) — kept as its own copy so this
	 * class doesn't reach into SML_Registration_Handler's internals.
	 *
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 * @return string
	 */
	protected static function generate_username( $first_name, $last_name, $email ) {
		$base = sanitize_user( strtolower( trim( $first_name . '.' . $last_name, '.' ) ), true );

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
			++$suffix;
		}

		return $username;
	}
}
