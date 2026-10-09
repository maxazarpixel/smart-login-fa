<?php
/**
 * Mobile phone number validation and display formatting, keyed off the
 * selected country's dial code.
 *
 * There is no bundled per-country numbering-plan database here (that would
 * mean fabricating rules for ~195 countries we can't verify), so validation
 * is deliberately limited to what's actually knowable from the dial code
 * alone: ITU-T E.164 caps a full international number at 15 digits, so the
 * national number (what the visitor types) can be at most 15 minus the
 * dial code's own digit count. A conservative minimum of 4 digits guards
 * against empty/garbage input without rejecting genuinely short numbering
 * plans. Display formatting is a generic "group digits in 3s" layout, not a
 * claim of the country's official format.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Phone {

	const MIN_DIGITS = 4;
	const MAX_E164_DIGITS = 15;

	/**
	 * Reads a typed login / reset identifier as a phone number when it
	 * looks like one: 09123456789 (Iran), or +<country code><number> /
	 * 00<country code><number> for anything else. Persian and Arabic digits
	 * are accepted. Email addresses and ordinary usernames never match.
	 *
	 * @param string $input
	 * @return string E.164 (+989123456789) or '' when it is not a phone number.
	 */
	public static function parse_e164( $input ) {
		$s = SML_Iran::to_latin_digits( trim( (string) $input ) );
		$s = preg_replace( '/[\s\-().]/', '', $s );

		if ( 1 === preg_match( '/^(?:\+|00)([1-9]\d{7,14})$/', $s, $m ) ) {
			return '+' . $m[1];
		}

		if ( 1 === preg_match( '/^09\d{9}$/', $s ) ) {
			return '+98' . substr( $s, 1 );
		}

		return '';
	}

	/**
	 * Accounts registered with this number, verified ones first.
	 *
	 * @param string $e164
	 * @return WP_User[]
	 */
	public static function users_for( $e164 ) {
		$users = get_users(
			array(
				'meta_key'   => 'sml_phone_e164', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $e164, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 10,
				'orderby'    => 'ID',
				'order'      => 'DESC',
			)
		);

		usort(
			$users,
			function ( $a, $b ) {
				return (int) SML_Verification::is_verified( $b->ID ) - (int) SML_Verification::is_verified( $a->ID );
			}
		);

		return $users;
	}

	/**
	 * The one account a number-based request (password reset) acts on.
	 *
	 * @param string $e164
	 * @return WP_User|null
	 */
	public static function primary_user_for( $e164 ) {
		$users = self::users_for( $e164 );

		return $users ? $users[0] : null;
	}

	/**
	 * Which account's password was typed for this number, so a login with a
	 * phone number still lands on the right account when stale unverified
	 * ones share it.
	 *
	 * @param string $e164
	 * @param string $password
	 * @return string user_login, or '' when none matches.
	 */
	public static function login_for( $e164, $password ) {
		foreach ( self::users_for( $e164 ) as $user ) {
			if ( wp_check_password( $password, $user->user_pass, $user->ID ) ) {
				return $user->user_login;
			}
		}

		return '';
	}

	/**
	 * @param string $country_id
	 * @return int
	 */
	public static function max_length( $country_id ) {
		// Room for the optional leading zero (0912…).
		if ( SML_Iran::applies_to( $country_id ) ) {
			return 11;
		}

		$countries = SML_Countries::all();
		if ( ! isset( $countries[ $country_id ] ) ) {
			return self::MAX_E164_DIGITS - 1;
		}

		$dial_digits = strlen( preg_replace( '/\D/', '', $countries[ $country_id ]['dial'] ) );

		return max( self::MIN_DIGITS, self::MAX_E164_DIGITS - $dial_digits );
	}

	/**
	 * @param string $country_id
	 * @param string $digits Already stripped to [0-9] by the caller.
	 * @return true|WP_Error
	 */
	public static function validate( $country_id, $digits ) {
		$digits = preg_replace( '/\D/', '', SML_Iran::to_latin_digits( $digits ) );
		$max    = self::max_length( $country_id );

		if ( SML_Iran::applies_to( $country_id ) ) {
			if ( ! SML_Iran::is_valid_mobile( $digits ) ) {
				return new WP_Error( 'sml_invalid_phone', __( 'Please enter a valid Iranian mobile number, e.g. 0912 345 6789.', 'smart-login' ) );
			}

			return true;
		}

		if ( strlen( $digits ) < self::MIN_DIGITS || strlen( $digits ) > $max ) {
			return new WP_Error( 'sml_invalid_phone', __( 'Please enter a valid mobile number.', 'smart-login' ) );
		}

		return true;
	}

	/**
	 * Generic display grouping (e.g. "555 123 4567") — not a country-specific
	 * mask, just a readable chunking of digits in 3s with a final group of
	 * 2-4.
	 *
	 * @param string $digits
	 * @return string
	 */
	public static function format( $digits ) {
		$digits = preg_replace( '/\D/', '', (string) $digits );
		if ( '' === $digits ) {
			return '';
		}

		$groups = array();
		$remaining = $digits;
		while ( strlen( $remaining ) > 4 ) {
			$groups[]  = substr( $remaining, 0, 3 );
			$remaining = substr( $remaining, 3 );
		}
		$groups[] = $remaining;

		return implode( ' ', $groups );
	}
}
