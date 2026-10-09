<?php
/**
 * Iranian registration rules: mobile-number format and national ID (کد ملی)
 * validation. Everything here is inert unless the "Iranian mobile numbers &
 * national ID" setting is on.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Iran {

	const COUNTRY_ID   = 'ir';
	const NATIONAL_META = 'sml_national_id';

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) SML_Settings::get( 'enable_iran_fields' );
	}

	/**
	 * @return bool Whether an empty national ID is rejected (otherwise it is optional).
	 */
	public static function national_id_required() {
		return (bool) SML_Settings::get( 'iran_national_id_required' );
	}

	/**
	 * @param string $country_id
	 * @return bool True when the Iranian rules apply to this country selection.
	 */
	public static function applies_to( $country_id ) {
		return self::enabled() && self::COUNTRY_ID === $country_id;
	}

	/**
	 * Persian (۰-۹) and Arabic-Indic (٠-٩) digits to ASCII.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function to_latin_digits( $value ) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Drops the single trunk-prefix zero people type in front of an Iranian
	 * mobile number (0912… -> 912…).
	 *
	 * @param string $digits ASCII digits only.
	 * @return string
	 */
	public static function normalize_mobile( $digits ) {
		return preg_replace( '/^0/', '', (string) $digits );
	}

	/**
	 * Iranian mobile numbers are 10 digits starting with 9 once the leading
	 * zero is removed.
	 *
	 * @param string $digits ASCII digits only.
	 * @return bool
	 */
	public static function is_valid_mobile( $digits ) {
		return 1 === preg_match( '/^9\d{9}$/', self::normalize_mobile( $digits ) );
	}

	/**
	 * Official check-digit algorithm for the 10-digit Iranian national ID.
	 *
	 * @param string $id ASCII digits only.
	 * @return bool
	 */
	public static function is_valid_national_id( $id ) {
		if ( 1 !== preg_match( '/^\d{10}$/', $id ) || 1 === preg_match( '/^(\d)\1{9}$/', $id ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $id[ $i ] * ( 10 - $i );
		}

		$remainder = $sum % 11;
		$check     = (int) $id[9];

		return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
	}

	/**
	 * @param string $id              ASCII digits only.
	 * @param int    $exclude_user_id Account to ignore (the one being edited).
	 * @return bool
	 */
	public static function national_id_exists( $id, $exclude_user_id = 0 ) {
		$found = get_users(
			array(
				'meta_key'    => self::NATIONAL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'exclude'     => $exclude_user_id ? array( (int) $exclude_user_id ) : array(),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		return ! empty( $found );
	}
}
