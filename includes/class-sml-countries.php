<?php
/**
 * Country registry for the mobile-number field: id, display name, dial
 * code, and whether it's enabled for registration by default. Single
 * source of truth shared by the front-end selector, the admin "Allowed
 * Countries" checklist, and server-side registration validation.
 *
 * Keyed by country id rather than dial code because several countries
 * share a dial code (US/Canada are both +1) and still need to be toggled
 * independently in the admin setting.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Countries {

	/**
	 * @return array<string,array{name:string,dial:string,default:bool}>
	 */
	public static function all() {
		return array(
			// United States, Canada, Western Europe — enabled by default.
			'us' => array( 'name' => 'United States', 'dial' => '+1', 'default' => true ),
			'ca' => array( 'name' => 'Canada', 'dial' => '+1', 'default' => true ),
			'gb' => array( 'name' => 'United Kingdom', 'dial' => '+44', 'default' => true ),
			'ie' => array( 'name' => 'Ireland', 'dial' => '+353', 'default' => true ),
			'fr' => array( 'name' => 'France', 'dial' => '+33', 'default' => true ),
			'de' => array( 'name' => 'Germany', 'dial' => '+49', 'default' => true ),
			'nl' => array( 'name' => 'Netherlands', 'dial' => '+31', 'default' => true ),
			'be' => array( 'name' => 'Belgium', 'dial' => '+32', 'default' => true ),
			'lu' => array( 'name' => 'Luxembourg', 'dial' => '+352', 'default' => true ),
			'ch' => array( 'name' => 'Switzerland', 'dial' => '+41', 'default' => true ),
			'at' => array( 'name' => 'Austria', 'dial' => '+43', 'default' => true ),
			'es' => array( 'name' => 'Spain', 'dial' => '+34', 'default' => true ),
			'it' => array( 'name' => 'Italy', 'dial' => '+39', 'default' => true ),
			'pt' => array( 'name' => 'Portugal', 'dial' => '+351', 'default' => true ),

			// Available, but off until an admin opts in.
			'ae' => array( 'name' => 'UAE', 'dial' => '+971', 'default' => false ),
			'sa' => array( 'name' => 'Saudi Arabia', 'dial' => '+966', 'default' => false ),
			'ir' => array( 'name' => 'Iran', 'dial' => '+98', 'default' => false ),
			'tr' => array( 'name' => 'Türkiye', 'dial' => '+90', 'default' => false ),
			'in' => array( 'name' => 'India', 'dial' => '+91', 'default' => false ),
			'au' => array( 'name' => 'Australia', 'dial' => '+61', 'default' => false ),
			'cn' => array( 'name' => 'China', 'dial' => '+86', 'default' => false ),
			'jp' => array( 'name' => 'Japan', 'dial' => '+81', 'default' => false ),
			'kr' => array( 'name' => 'South Korea', 'dial' => '+82', 'default' => false ),
			'sg' => array( 'name' => 'Singapore', 'dial' => '+65', 'default' => false ),
			'mx' => array( 'name' => 'Mexico', 'dial' => '+52', 'default' => false ),
			'br' => array( 'name' => 'Brazil', 'dial' => '+55', 'default' => false ),
			'za' => array( 'name' => 'South Africa', 'dial' => '+27', 'default' => false ),
		);
	}

	/**
	 * @return string[] Country ids enabled out of the box.
	 */
	public static function default_allowed_ids() {
		return array_keys( array_filter( self::all(), function ( $c ) {
			return ! empty( $c['default'] );
		} ) );
	}

	/**
	 * @return string Comma-separated default-allowed ids, for the settings default.
	 */
	public static function default_allowed_csv() {
		return implode( ',', self::default_allowed_ids() );
	}

	/**
	 * The ids an admin has actually enabled, filtered against the known
	 * registry (so a stale id left over from a registry change can't sneak
	 * through) and falling back to the full default set if the stored
	 * value is somehow empty.
	 *
	 * @return string[]
	 */
	public static function allowed_ids() {
		$csv     = (string) SML_Settings::get( 'allowed_countries', self::default_allowed_csv() );
		$ids     = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
		$known   = self::all();
		$allowed = array_values( array_intersect( $ids, array_keys( $known ) ) );

		return $allowed ? $allowed : self::default_allowed_ids();
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	public static function is_allowed( $id ) {
		return in_array( $id, self::allowed_ids(), true );
	}

	/**
	 * @return array<string,array{name:string,dial:string,default:bool}> Only the currently allowed countries.
	 */
	public static function allowed() {
		return array_intersect_key( self::all(), array_flip( self::allowed_ids() ) );
	}
}
