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
	 * @param string $country_id
	 * @return int
	 */
	public static function max_length( $country_id ) {
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
		$digits = preg_replace( '/\D/', '', (string) $digits );
		$max    = self::max_length( $country_id );

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
