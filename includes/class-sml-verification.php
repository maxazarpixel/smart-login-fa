<?php
/**
 * Verification core: code/token generation, hashing, storage, expiry,
 * attempt counting. Pure data-layer logic, no HTTP/AJAX concerns here.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Verification {

	/**
	 * @return string Table name with the $wpdb prefix.
	 */
	protected static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sml_verifications';
	}

	/**
	 * Deterministic, keyed hash for a code or token so it can be looked up
	 * by exact match without ever storing the plaintext value.
	 *
	 * @param string $value
	 * @return string
	 */
	protected static function hash( $value ) {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	protected static function generate_code( $length ) {
		$length = max( 4, min( 8, (int) $length ) );
		$code   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}
		return $code;
	}

	protected static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * @param int $user_id
	 * @return object|null
	 */
	public static function get_row( $user_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Checks the server-side resend cooldown for a user. Independent of any
	 * client-side disabled button state.
	 *
	 * @param int $user_id
	 * @return true|WP_Error
	 */
	public static function check_resend_cooldown( $user_id ) {
		$row = self::get_row( $user_id );
		if ( ! $row || ! $row->last_sent_at ) {
			return true;
		}

		$cooldown  = (int) SML_Settings::get( 'resend_cooldown_seconds', 60 );
		$elapsed   = time() - strtotime( $row->last_sent_at . ' UTC' );
		$remaining = $cooldown - $elapsed;

		if ( $remaining > 0 ) {
			return new WP_Error(
				'sml_resend_cooldown',
				sprintf(
					/* translators: %d: seconds remaining */
					__( 'Please wait %d seconds before requesting another code.', 'smart-login' ),
					$remaining
				),
				array( 'remaining' => $remaining )
			);
		}

		return true;
	}

	/**
	 * Generates and stores a fresh code + link token for a user, replacing
	 * any existing verification row. Does not send the email — the caller
	 * is responsible for that (see SML_Email).
	 *
	 * @param int $user_id
	 * @return array{code:string,token:string,code_expires_at:int,link_expires_at:int}
	 */
	public static function issue( $user_id ) {
		global $wpdb;
		$table = self::table();

		$code_length  = (int) SML_Settings::get( 'code_length', 6 );
		$code_expiry  = (int) SML_Settings::get( 'code_expiry_minutes', 10 );
		$link_expiry  = (int) SML_Settings::get( 'link_expiry_minutes', 30 );

		$code  = self::generate_code( $code_length );
		$token = self::generate_token();

		$now              = time();
		$code_expires_at  = $now + ( $code_expiry * MINUTE_IN_SECONDS );
		$link_expires_at  = $now + ( $link_expiry * MINUTE_IN_SECONDS );

		$data = array(
			'user_id'         => $user_id,
			'code_hash'       => self::hash( $code ),
			'link_token_hash' => self::hash( $token ),
			'code_expires_at' => gmdate( 'Y-m-d H:i:s', $code_expires_at ),
			'link_expires_at' => gmdate( 'Y-m-d H:i:s', $link_expires_at ),
			'attempts'        => 0,
			'last_sent_at'    => gmdate( 'Y-m-d H:i:s', $now ),
		);

		if ( self::get_row( $user_id ) ) {
			$wpdb->update( $table, $data, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		} else {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s', $now );
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		return array(
			'code'             => $code,
			'token'            => $token,
			'code_expires_at'  => $code_expires_at,
			'link_expires_at'  => $link_expires_at,
		);
	}

	/**
	 * @param int    $user_id
	 * @param string $submitted_code
	 * @return true|WP_Error
	 */
	public static function verify_code( $user_id, $submitted_code ) {
		$row = self::get_row( $user_id );

		if ( ! $row ) {
			return new WP_Error( 'sml_no_pending', __( 'No pending verification found. Please request a new code.', 'smart-login' ) );
		}

		$max_attempts = (int) SML_Settings::get( 'max_code_attempts', 5 );

		if ( (int) $row->attempts >= $max_attempts ) {
			return new WP_Error( 'sml_too_many_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'smart-login' ) );
		}

		if ( strtotime( $row->code_expires_at . ' UTC' ) < time() ) {
			return new WP_Error( 'sml_code_expired', __( 'This code has expired. Please request a new one.', 'smart-login' ) );
		}

		$submitted_hash = self::hash( (string) $submitted_code );

		if ( ! hash_equals( $row->code_hash, $submitted_hash ) ) {
			self::increment_attempts( $user_id );
			return new WP_Error( 'sml_invalid_code', __( 'That code is incorrect.', 'smart-login' ) );
		}

		self::complete( $user_id );

		return true;
	}

	/**
	 * @param string $token
	 * @return int|WP_Error User ID on success.
	 */
	public static function verify_link( $token ) {
		global $wpdb;
		$table = self::table();

		$hash = self::hash( (string) $token );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE link_token_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return new WP_Error( 'sml_invalid_link', __( 'This verification link is invalid or has already been used.', 'smart-login' ) );
		}

		if ( strtotime( $row->link_expires_at . ' UTC' ) < time() ) {
			return new WP_Error( 'sml_link_expired', __( 'This verification link has expired. Please request a new one.', 'smart-login' ) );
		}

		self::complete( (int) $row->user_id );

		return (int) $row->user_id;
	}

	protected static function increment_attempts( $user_id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Marks the account verified and removes the (now consumed) pending
	 * verification row — this invalidates the code and the link together,
	 * whichever path completed first.
	 *
	 * @param int $user_id
	 */
	protected static function complete( $user_id ) {
		global $wpdb;
		$table = self::table();

		$wpdb->delete( $table, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		update_user_meta( $user_id, 'sml_email_verified', 1 );

		do_action( 'sml_user_verified', $user_id );
	}

	public static function is_verified( $user_id ) {
		return (bool) get_user_meta( $user_id, 'sml_email_verified', true );
	}

	/**
	 * Distinguishes an account this plugin actually created (meta key set,
	 * to 0 or 1) from a pre-existing account that predates the plugin (no
	 * meta key at all). The "block until verified" setting only ever
	 * applies to the former — an existing site's whole user base should
	 * never be locked out just because Smart Login was installed.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function has_verification_record( $user_id ) {
		return metadata_exists( 'user', $user_id, 'sml_email_verified' );
	}

	/**
	 * Hooked to the hourly `sml_cleanup_expired_verifications` cron event.
	 */
	public static function cleanup_expired() {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE code_expires_at < %s AND link_expires_at < %s", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
