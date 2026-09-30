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

			// Everything else — available, but off until an admin opts in.
			// Alphabetical by name within region; dial codes are the
			// standard ITU country calling codes.
			'ae' => array( 'name' => 'United Arab Emirates', 'dial' => '+971', 'default' => false ),
			'af' => array( 'name' => 'Afghanistan', 'dial' => '+93', 'default' => false ),
			'ag' => array( 'name' => 'Antigua and Barbuda', 'dial' => '+1268', 'default' => false ),
			'al' => array( 'name' => 'Albania', 'dial' => '+355', 'default' => false ),
			'am' => array( 'name' => 'Armenia', 'dial' => '+374', 'default' => false ),
			'ao' => array( 'name' => 'Angola', 'dial' => '+244', 'default' => false ),
			'ar' => array( 'name' => 'Argentina', 'dial' => '+54', 'default' => false ),
			'au' => array( 'name' => 'Australia', 'dial' => '+61', 'default' => false ),
			'az' => array( 'name' => 'Azerbaijan', 'dial' => '+994', 'default' => false ),
			'ba' => array( 'name' => 'Bosnia and Herzegovina', 'dial' => '+387', 'default' => false ),
			'bb' => array( 'name' => 'Barbados', 'dial' => '+1246', 'default' => false ),
			'bd' => array( 'name' => 'Bangladesh', 'dial' => '+880', 'default' => false ),
			'bf' => array( 'name' => 'Burkina Faso', 'dial' => '+226', 'default' => false ),
			'bg' => array( 'name' => 'Bulgaria', 'dial' => '+359', 'default' => false ),
			'bh' => array( 'name' => 'Bahrain', 'dial' => '+973', 'default' => false ),
			'bi' => array( 'name' => 'Burundi', 'dial' => '+257', 'default' => false ),
			'bj' => array( 'name' => 'Benin', 'dial' => '+229', 'default' => false ),
			'bn' => array( 'name' => 'Brunei', 'dial' => '+673', 'default' => false ),
			'bo' => array( 'name' => 'Bolivia', 'dial' => '+591', 'default' => false ),
			'br' => array( 'name' => 'Brazil', 'dial' => '+55', 'default' => false ),
			'bs' => array( 'name' => 'Bahamas', 'dial' => '+1242', 'default' => false ),
			'bt' => array( 'name' => 'Bhutan', 'dial' => '+975', 'default' => false ),
			'bw' => array( 'name' => 'Botswana', 'dial' => '+267', 'default' => false ),
			'by' => array( 'name' => 'Belarus', 'dial' => '+375', 'default' => false ),
			'bz' => array( 'name' => 'Belize', 'dial' => '+501', 'default' => false ),
			'cd' => array( 'name' => 'DR Congo', 'dial' => '+243', 'default' => false ),
			'cf' => array( 'name' => 'Central African Republic', 'dial' => '+236', 'default' => false ),
			'cg' => array( 'name' => 'Congo', 'dial' => '+242', 'default' => false ),
			'ci' => array( 'name' => 'Côte d\'Ivoire', 'dial' => '+225', 'default' => false ),
			'cl' => array( 'name' => 'Chile', 'dial' => '+56', 'default' => false ),
			'cm' => array( 'name' => 'Cameroon', 'dial' => '+237', 'default' => false ),
			'cn' => array( 'name' => 'China', 'dial' => '+86', 'default' => false ),
			'co' => array( 'name' => 'Colombia', 'dial' => '+57', 'default' => false ),
			'cr' => array( 'name' => 'Costa Rica', 'dial' => '+506', 'default' => false ),
			'cu' => array( 'name' => 'Cuba', 'dial' => '+53', 'default' => false ),
			'cv' => array( 'name' => 'Cabo Verde', 'dial' => '+238', 'default' => false ),
			'cy' => array( 'name' => 'Cyprus', 'dial' => '+357', 'default' => false ),
			'cz' => array( 'name' => 'Czechia', 'dial' => '+420', 'default' => false ),
			'dj' => array( 'name' => 'Djibouti', 'dial' => '+253', 'default' => false ),
			'dk' => array( 'name' => 'Denmark', 'dial' => '+45', 'default' => false ),
			'dm' => array( 'name' => 'Dominica', 'dial' => '+1767', 'default' => false ),
			'do' => array( 'name' => 'Dominican Republic', 'dial' => '+1809', 'default' => false ),
			'dz' => array( 'name' => 'Algeria', 'dial' => '+213', 'default' => false ),
			'ec' => array( 'name' => 'Ecuador', 'dial' => '+593', 'default' => false ),
			'ee' => array( 'name' => 'Estonia', 'dial' => '+372', 'default' => false ),
			'eg' => array( 'name' => 'Egypt', 'dial' => '+20', 'default' => false ),
			'er' => array( 'name' => 'Eritrea', 'dial' => '+291', 'default' => false ),
			'et' => array( 'name' => 'Ethiopia', 'dial' => '+251', 'default' => false ),
			'fj' => array( 'name' => 'Fiji', 'dial' => '+679', 'default' => false ),
			'fi' => array( 'name' => 'Finland', 'dial' => '+358', 'default' => false ),
			'fm' => array( 'name' => 'Micronesia', 'dial' => '+691', 'default' => false ),
			'ga' => array( 'name' => 'Gabon', 'dial' => '+241', 'default' => false ),
			'gd' => array( 'name' => 'Grenada', 'dial' => '+1473', 'default' => false ),
			'ge' => array( 'name' => 'Georgia', 'dial' => '+995', 'default' => false ),
			'gh' => array( 'name' => 'Ghana', 'dial' => '+233', 'default' => false ),
			'gm' => array( 'name' => 'Gambia', 'dial' => '+220', 'default' => false ),
			'gn' => array( 'name' => 'Guinea', 'dial' => '+224', 'default' => false ),
			'gq' => array( 'name' => 'Equatorial Guinea', 'dial' => '+240', 'default' => false ),
			'gr' => array( 'name' => 'Greece', 'dial' => '+30', 'default' => false ),
			'gt' => array( 'name' => 'Guatemala', 'dial' => '+502', 'default' => false ),
			'gw' => array( 'name' => 'Guinea-Bissau', 'dial' => '+245', 'default' => false ),
			'gy' => array( 'name' => 'Guyana', 'dial' => '+592', 'default' => false ),
			'hk' => array( 'name' => 'Hong Kong', 'dial' => '+852', 'default' => false ),
			'hn' => array( 'name' => 'Honduras', 'dial' => '+504', 'default' => false ),
			'hr' => array( 'name' => 'Croatia', 'dial' => '+385', 'default' => false ),
			'ht' => array( 'name' => 'Haiti', 'dial' => '+509', 'default' => false ),
			'hu' => array( 'name' => 'Hungary', 'dial' => '+36', 'default' => false ),
			'id' => array( 'name' => 'Indonesia', 'dial' => '+62', 'default' => false ),
			'il' => array( 'name' => 'Israel', 'dial' => '+972', 'default' => false ),
			'in' => array( 'name' => 'India', 'dial' => '+91', 'default' => false ),
			'iq' => array( 'name' => 'Iraq', 'dial' => '+964', 'default' => false ),
			'ir' => array( 'name' => 'Iran', 'dial' => '+98', 'default' => false ),
			'is' => array( 'name' => 'Iceland', 'dial' => '+354', 'default' => false ),
			'jm' => array( 'name' => 'Jamaica', 'dial' => '+1876', 'default' => false ),
			'jo' => array( 'name' => 'Jordan', 'dial' => '+962', 'default' => false ),
			'jp' => array( 'name' => 'Japan', 'dial' => '+81', 'default' => false ),
			'ke' => array( 'name' => 'Kenya', 'dial' => '+254', 'default' => false ),
			'kg' => array( 'name' => 'Kyrgyzstan', 'dial' => '+996', 'default' => false ),
			'kh' => array( 'name' => 'Cambodia', 'dial' => '+855', 'default' => false ),
			'ki' => array( 'name' => 'Kiribati', 'dial' => '+686', 'default' => false ),
			'km' => array( 'name' => 'Comoros', 'dial' => '+269', 'default' => false ),
			'kn' => array( 'name' => 'Saint Kitts and Nevis', 'dial' => '+1869', 'default' => false ),
			'kp' => array( 'name' => 'North Korea', 'dial' => '+850', 'default' => false ),
			'kr' => array( 'name' => 'South Korea', 'dial' => '+82', 'default' => false ),
			'kw' => array( 'name' => 'Kuwait', 'dial' => '+965', 'default' => false ),
			'kz' => array( 'name' => 'Kazakhstan', 'dial' => '+7', 'default' => false ),
			'la' => array( 'name' => 'Laos', 'dial' => '+856', 'default' => false ),
			'lb' => array( 'name' => 'Lebanon', 'dial' => '+961', 'default' => false ),
			'lc' => array( 'name' => 'Saint Lucia', 'dial' => '+1758', 'default' => false ),
			'li' => array( 'name' => 'Liechtenstein', 'dial' => '+423', 'default' => false ),
			'lk' => array( 'name' => 'Sri Lanka', 'dial' => '+94', 'default' => false ),
			'lr' => array( 'name' => 'Liberia', 'dial' => '+231', 'default' => false ),
			'ls' => array( 'name' => 'Lesotho', 'dial' => '+266', 'default' => false ),
			'lt' => array( 'name' => 'Lithuania', 'dial' => '+370', 'default' => false ),
			'lv' => array( 'name' => 'Latvia', 'dial' => '+371', 'default' => false ),
			'ly' => array( 'name' => 'Libya', 'dial' => '+218', 'default' => false ),
			'ma' => array( 'name' => 'Morocco', 'dial' => '+212', 'default' => false ),
			'mc' => array( 'name' => 'Monaco', 'dial' => '+377', 'default' => false ),
			'md' => array( 'name' => 'Moldova', 'dial' => '+373', 'default' => false ),
			'me' => array( 'name' => 'Montenegro', 'dial' => '+382', 'default' => false ),
			'mg' => array( 'name' => 'Madagascar', 'dial' => '+261', 'default' => false ),
			'mh' => array( 'name' => 'Marshall Islands', 'dial' => '+692', 'default' => false ),
			'mk' => array( 'name' => 'North Macedonia', 'dial' => '+389', 'default' => false ),
			'ml' => array( 'name' => 'Mali', 'dial' => '+223', 'default' => false ),
			'mm' => array( 'name' => 'Myanmar', 'dial' => '+95', 'default' => false ),
			'mn' => array( 'name' => 'Mongolia', 'dial' => '+976', 'default' => false ),
			'mr' => array( 'name' => 'Mauritania', 'dial' => '+222', 'default' => false ),
			'mt' => array( 'name' => 'Malta', 'dial' => '+356', 'default' => false ),
			'mu' => array( 'name' => 'Mauritius', 'dial' => '+230', 'default' => false ),
			'mv' => array( 'name' => 'Maldives', 'dial' => '+960', 'default' => false ),
			'mw' => array( 'name' => 'Malawi', 'dial' => '+265', 'default' => false ),
			'mx' => array( 'name' => 'Mexico', 'dial' => '+52', 'default' => false ),
			'my' => array( 'name' => 'Malaysia', 'dial' => '+60', 'default' => false ),
			'mz' => array( 'name' => 'Mozambique', 'dial' => '+258', 'default' => false ),
			'na' => array( 'name' => 'Namibia', 'dial' => '+264', 'default' => false ),
			'ne' => array( 'name' => 'Niger', 'dial' => '+227', 'default' => false ),
			'ng' => array( 'name' => 'Nigeria', 'dial' => '+234', 'default' => false ),
			'ni' => array( 'name' => 'Nicaragua', 'dial' => '+505', 'default' => false ),
			'no' => array( 'name' => 'Norway', 'dial' => '+47', 'default' => false ),
			'np' => array( 'name' => 'Nepal', 'dial' => '+977', 'default' => false ),
			'nr' => array( 'name' => 'Nauru', 'dial' => '+674', 'default' => false ),
			'nz' => array( 'name' => 'New Zealand', 'dial' => '+64', 'default' => false ),
			'om' => array( 'name' => 'Oman', 'dial' => '+968', 'default' => false ),
			'pa' => array( 'name' => 'Panama', 'dial' => '+507', 'default' => false ),
			'pe' => array( 'name' => 'Peru', 'dial' => '+51', 'default' => false ),
			'pg' => array( 'name' => 'Papua New Guinea', 'dial' => '+675', 'default' => false ),
			'ph' => array( 'name' => 'Philippines', 'dial' => '+63', 'default' => false ),
			'pk' => array( 'name' => 'Pakistan', 'dial' => '+92', 'default' => false ),
			'pl' => array( 'name' => 'Poland', 'dial' => '+48', 'default' => false ),
			'ps' => array( 'name' => 'Palestine', 'dial' => '+970', 'default' => false ),
			'py' => array( 'name' => 'Paraguay', 'dial' => '+595', 'default' => false ),
			'qa' => array( 'name' => 'Qatar', 'dial' => '+974', 'default' => false ),
			'ro' => array( 'name' => 'Romania', 'dial' => '+40', 'default' => false ),
			'rs' => array( 'name' => 'Serbia', 'dial' => '+381', 'default' => false ),
			'ru' => array( 'name' => 'Russia', 'dial' => '+7', 'default' => false ),
			'rw' => array( 'name' => 'Rwanda', 'dial' => '+250', 'default' => false ),
			'sa' => array( 'name' => 'Saudi Arabia', 'dial' => '+966', 'default' => false ),
			'sb' => array( 'name' => 'Solomon Islands', 'dial' => '+677', 'default' => false ),
			'sc' => array( 'name' => 'Seychelles', 'dial' => '+248', 'default' => false ),
			'sd' => array( 'name' => 'Sudan', 'dial' => '+249', 'default' => false ),
			'se' => array( 'name' => 'Sweden', 'dial' => '+46', 'default' => false ),
			'sg' => array( 'name' => 'Singapore', 'dial' => '+65', 'default' => false ),
			'si' => array( 'name' => 'Slovenia', 'dial' => '+386', 'default' => false ),
			'sk' => array( 'name' => 'Slovakia', 'dial' => '+421', 'default' => false ),
			'sl' => array( 'name' => 'Sierra Leone', 'dial' => '+232', 'default' => false ),
			'sm' => array( 'name' => 'San Marino', 'dial' => '+378', 'default' => false ),
			'sn' => array( 'name' => 'Senegal', 'dial' => '+221', 'default' => false ),
			'so' => array( 'name' => 'Somalia', 'dial' => '+252', 'default' => false ),
			'sr' => array( 'name' => 'Suriname', 'dial' => '+597', 'default' => false ),
			'ss' => array( 'name' => 'South Sudan', 'dial' => '+211', 'default' => false ),
			'st' => array( 'name' => 'São Tomé and Príncipe', 'dial' => '+239', 'default' => false ),
			'sv' => array( 'name' => 'El Salvador', 'dial' => '+503', 'default' => false ),
			'sy' => array( 'name' => 'Syria', 'dial' => '+963', 'default' => false ),
			'sz' => array( 'name' => 'Eswatini', 'dial' => '+268', 'default' => false ),
			'td' => array( 'name' => 'Chad', 'dial' => '+235', 'default' => false ),
			'tg' => array( 'name' => 'Togo', 'dial' => '+228', 'default' => false ),
			'th' => array( 'name' => 'Thailand', 'dial' => '+66', 'default' => false ),
			'tj' => array( 'name' => 'Tajikistan', 'dial' => '+992', 'default' => false ),
			'tl' => array( 'name' => 'Timor-Leste', 'dial' => '+670', 'default' => false ),
			'tm' => array( 'name' => 'Turkmenistan', 'dial' => '+993', 'default' => false ),
			'tn' => array( 'name' => 'Tunisia', 'dial' => '+216', 'default' => false ),
			'to' => array( 'name' => 'Tonga', 'dial' => '+676', 'default' => false ),
			'tr' => array( 'name' => 'Türkiye', 'dial' => '+90', 'default' => false ),
			'tt' => array( 'name' => 'Trinidad and Tobago', 'dial' => '+1868', 'default' => false ),
			'tv' => array( 'name' => 'Tuvalu', 'dial' => '+688', 'default' => false ),
			'tw' => array( 'name' => 'Taiwan', 'dial' => '+886', 'default' => false ),
			'tz' => array( 'name' => 'Tanzania', 'dial' => '+255', 'default' => false ),
			'ua' => array( 'name' => 'Ukraine', 'dial' => '+380', 'default' => false ),
			'ug' => array( 'name' => 'Uganda', 'dial' => '+256', 'default' => false ),
			'uy' => array( 'name' => 'Uruguay', 'dial' => '+598', 'default' => false ),
			'uz' => array( 'name' => 'Uzbekistan', 'dial' => '+998', 'default' => false ),
			'vc' => array( 'name' => 'Saint Vincent and the Grenadines', 'dial' => '+1784', 'default' => false ),
			've' => array( 'name' => 'Venezuela', 'dial' => '+58', 'default' => false ),
			'vn' => array( 'name' => 'Vietnam', 'dial' => '+84', 'default' => false ),
			'vu' => array( 'name' => 'Vanuatu', 'dial' => '+678', 'default' => false ),
			'ws' => array( 'name' => 'Samoa', 'dial' => '+685', 'default' => false ),
			'ye' => array( 'name' => 'Yemen', 'dial' => '+967', 'default' => false ),
			'za' => array( 'name' => 'South Africa', 'dial' => '+27', 'default' => false ),
			'zm' => array( 'name' => 'Zambia', 'dial' => '+260', 'default' => false ),
			'zw' => array( 'name' => 'Zimbabwe', 'dial' => '+263', 'default' => false ),
		);
	}

	/**
	 * Country name in the current locale. Uses the PHP intl extension's CLDR
	 * data when available and falls back to the English name otherwise, so
	 * no ~200-entry list has to be translated by hand.
	 *
	 * @param string $id Registry id (lower-case ISO 3166-1 alpha-2).
	 * @return string
	 */
	public static function name( $id ) {
		static $cache = array();

		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return '';
		}

		$locale = determine_locale();
		$key    = $locale . '|' . $id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$name = $all[ $id ]['name'];
		if ( class_exists( 'Locale' ) && 0 !== strpos( $locale, 'en_' ) ) {
			$local = Locale::getDisplayRegion( 'und_' . strtoupper( $id ), $locale );
			if ( is_string( $local ) && '' !== $local && strtoupper( $id ) !== $local ) {
				$name = $local;
			}
		}

		$cache[ $key ] = $name;
		return $name;
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
