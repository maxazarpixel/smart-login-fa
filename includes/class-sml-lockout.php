<?php
/**
 * Transient-based login attempt lockout, keyed by IP + username. Self
 * expiring — no cleanup job needed.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Lockout {

	protected static function key( $username ) {
		$ip_hash = md5( self::client_ip() );
		return 'sml_lockout_' . $ip_hash . '_' . md5( strtolower( $username ) );
	}

	protected static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	/**
	 * @param string $username
	 * @return true|WP_Error
	 */
	public static function check( $username ) {
		$state = get_transient( self::key( $username ) );

		if ( is_array( $state ) && ! empty( $state['locked_until'] ) && $state['locked_until'] > time() ) {
			return new WP_Error(
				'sml_locked_out',
				sprintf(
					/* translators: %d: minutes remaining */
					__( 'Too many failed login attempts. Please try again in %d minute(s).', 'smart-login' ),
					(int) ceil( ( $state['locked_until'] - time() ) / 60 )
				)
			);
		}

		return true;
	}

	public static function register_failure( $username ) {
		$key       = self::key( $username );
		$state     = get_transient( $key );
		$count     = is_array( $state ) ? (int) $state['count'] + 1 : 1;
		$threshold = (int) SML_Settings::get( 'login_lockout_threshold', 5 );
		$duration  = (int) SML_Settings::get( 'lockout_duration_minutes', 15 ) * MINUTE_IN_SECONDS;

		$new_state = array( 'count' => $count );

		if ( $count >= $threshold ) {
			$new_state['locked_until'] = time() + $duration;
		}

		set_transient( $key, $new_state, $duration );
	}

	public static function clear( $username ) {
		delete_transient( self::key( $username ) );
	}
}
