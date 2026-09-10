<?php
/**
 * Transient-based, fixed-window IP rate limiter. Used to cap abuse of the
 * unauthenticated resend and forgot-password endpoints, independently of
 * the per-account cooldown those flows already enforce.
 *
 * Self-expiring — no cleanup job needed. Keyed by a caller-supplied bucket
 * name plus the client IP, so different endpoints never share a budget.
 *
 * IP SOURCE: like SML_Lockout, this reads $_SERVER['REMOTE_ADDR'] only and
 * never a client-supplied header such as X-Forwarded-For (which is
 * spoofable). Behind a reverse proxy or CDN, the hosting layer must be
 * configured so REMOTE_ADDR carries the real client address (e.g. Apache
 * mod_remoteip, nginx real_ip, or the platform's trusted-proxy setting) —
 * otherwise every visitor can appear to share one IP and the limit is
 * either diluted or, worse, trips for unrelated legitimate users.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Rate_Limit {

	/**
	 * Whether the current client IP is still within its budget for this
	 * bucket. Call before performing the action; pair with record() after.
	 *
	 * @param string $bucket Logical name for the limited action, e.g. 'resend'.
	 * @param int    $limit  Max allowed hits per window.
	 * @param int    $window Window length in seconds.
	 * @return true|WP_Error True when allowed; WP_Error 'sml_rate_limited' when over budget.
	 */
	public static function check( $bucket, $limit, $window ) {
		$state = get_transient( self::key( $bucket ) );
		$now   = time();

		if ( ! is_array( $state ) || empty( $state['reset'] ) || $state['reset'] <= $now ) {
			return true;
		}

		if ( (int) $state['count'] >= (int) $limit ) {
			return new WP_Error(
				'sml_rate_limited',
				sprintf(
					/* translators: %d: minutes remaining */
					__( 'Too many requests from your network. Please try again in %d minute(s).', 'smart-login' ),
					(int) ceil( ( $state['reset'] - $now ) / 60 )
				)
			);
		}

		return true;
	}

	/**
	 * Records one hit against the current client IP for this bucket. Starts
	 * a fresh fixed window if none is active.
	 *
	 * @param string $bucket
	 * @param int    $window Window length in seconds.
	 */
	public static function record( $bucket, $window ) {
		$key   = self::key( $bucket );
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || empty( $state['reset'] ) || $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + (int) $window,
			);
		}

		$state['count']++;

		set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
	}

	protected static function key( $bucket ) {
		return 'sml_rl_' . md5( (string) $bucket . '|' . self::client_ip() );
	}

	protected static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}
}
