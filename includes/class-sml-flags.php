<?php
/**
 * Inline SVG country flags for the mobile-number country selector. Native
 * <select><option> elements can't render images, so the flag for the
 * currently selected country is shown as a real SVG icon layered next to
 * the select (see public/js/smart-login.js); the option list itself falls
 * back to a flag emoji + dial code, which is the best that native list
 * rendering allows.
 *
 * Simplified, not heraldically exact — recognizable color/layout at the
 * ~16px size they're actually shown at is the goal, not pixel-perfect
 * detail. No external requests: every flag is drawn from basic shapes.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Flags {

	/**
	 * @return array<string,string> country id => inner SVG markup (viewBox 0 0 20 14)
	 */
	protected static function shapes() {
		return array(
			'us' => '<rect width="20" height="14" fill="#fff"/><g fill="#B22234"><rect y="0" width="20" height="1.08"/><rect y="2.15" width="20" height="1.08"/><rect y="4.31" width="20" height="1.08"/><rect y="6.46" width="20" height="1.08"/><rect y="8.62" width="20" height="1.08"/><rect y="10.77" width="20" height="1.08"/><rect y="12.92" width="20" height="1.08"/></g><rect width="9" height="7.54" fill="#3C3B6E"/>',
			'ca' => '<rect width="20" height="14" fill="#fff"/><rect width="5" height="14" fill="#D80621"/><rect x="15" width="5" height="14" fill="#D80621"/><path d="M10 3l1 2.2 2-1-.6 2.2L14 7l-1.6 1.4.9 1.1-1.8-.2.1 1.3-.6-1-.6 1 .1-1.3-1.8.2.9-1.1L8 7l1.6-.6-.6-2.2 2 1z" fill="#D80621"/>',
			'gb' => '<rect width="20" height="14" fill="#00247D"/><path d="M0 0l20 14M20 0L0 14" stroke="#fff" stroke-width="2.4"/><path d="M0 0l20 14M20 0L0 14" stroke="#CF142B" stroke-width="0.9"/><path d="M10 0v14M0 7h20" stroke="#fff" stroke-width="3.6"/><path d="M10 0v14M0 7h20" stroke="#CF142B" stroke-width="1.6"/>',
			'ie' => '<rect width="20" height="14" fill="#fff"/><rect width="6.67" height="14" fill="#169B62"/><rect x="13.33" width="6.67" height="14" fill="#FF883E"/>',
			'fr' => '<rect width="20" height="14" fill="#fff"/><rect width="6.67" height="14" fill="#0055A4"/><rect x="13.33" width="6.67" height="14" fill="#EF4135"/>',
			'de' => '<rect width="20" height="14" fill="#FFCC00"/><rect width="20" height="4.67" fill="#000"/><rect y="4.67" width="20" height="4.67" fill="#DD0000"/>',
			'nl' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="4.67" fill="#AE1C28"/><rect y="9.33" width="20" height="4.67" fill="#21468B"/>',
			'be' => '<rect width="20" height="14" fill="#FDDA24"/><rect width="6.67" height="14" fill="#000"/><rect x="13.33" width="6.67" height="14" fill="#EF3340"/>',
			'lu' => '<rect width="20" height="14" fill="#00A1DE"/><rect width="20" height="4.67" fill="#ED2939"/><rect y="9.33" width="20" height="4.67" fill="#fff"/>',
			'ch' => '<rect width="20" height="14" fill="#D52B1E"/><rect x="8.3" y="3.5" width="3.4" height="7" fill="#fff"/><rect x="5.3" y="6.5" width="9.4" height="3" fill="#fff"/>',
			'at' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="4.67" fill="#ED2939"/><rect y="9.33" width="20" height="4.67" fill="#ED2939"/>',
			'es' => '<rect width="20" height="14" fill="#AA151B"/><rect y="3.5" width="20" height="7" fill="#F1BF00"/>',
			'it' => '<rect width="20" height="14" fill="#fff"/><rect width="6.67" height="14" fill="#009246"/><rect x="13.33" width="6.67" height="14" fill="#CE2B37"/>',
			'pt' => '<rect width="20" height="14" fill="#FF0000"/><rect width="8" height="14" fill="#046A38"/><circle cx="8" cy="7" r="2.6" fill="#FFCC00" stroke="#fff" stroke-width="0.4"/>',
			'ae' => '<rect width="20" height="14" fill="#00732F"/><rect y="4.67" width="20" height="4.67" fill="#fff"/><rect y="9.33" width="20" height="4.67" fill="#000"/><rect width="5" height="14" fill="#FF0000"/>',
			'sa' => '<rect width="20" height="14" fill="#006C35"/><rect x="4" y="6" width="12" height="1.6" fill="#fff"/>',
			'ir' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="4.67" fill="#239F40"/><rect y="9.33" width="20" height="4.67" fill="#DA0000"/>',
			'tr' => '<rect width="20" height="14" fill="#E30A17"/><circle cx="8" cy="7" r="3" fill="#fff"/><circle cx="9" cy="7" r="2.4" fill="#E30A17"/><path d="M12 7l2.6-.8-1.6 2.2v-2.8l1.6 2.2z" fill="#fff"/>',
			'in' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="4.67" fill="#FF9933"/><rect y="9.33" width="20" height="4.67" fill="#138808"/><circle cx="10" cy="7" r="1.8" fill="none" stroke="#000080" stroke-width="0.4"/>',
			'au' => '<rect width="20" height="14" fill="#00008B"/><rect width="10" height="7" fill="#00008B"/><path d="M0 0l10 7M10 0L0 7" stroke="#fff" stroke-width="1.2"/><path d="M0 0l10 7M10 0L0 7" stroke="#E4002B" stroke-width="0.5"/><path d="M5 0v7M0 3.5h10" stroke="#fff" stroke-width="1.8"/><path d="M5 0v7M0 3.5h10" stroke="#E4002B" stroke-width="0.8"/><g fill="#fff"><circle cx="15" cy="3" r="0.6"/><circle cx="17" cy="6" r="0.6"/><circle cx="15" cy="9" r="0.6"/><circle cx="17.5" cy="10.5" r="0.6"/><circle cx="13" cy="11" r="0.5"/></g>',
			'cn' => '<rect width="20" height="14" fill="#DE2910"/><g fill="#FFDE00"><path d="M4 3l.6 1.8h1.9l-1.5 1.1.6 1.8L4 6.6l-1.6 1.1.6-1.8-1.5-1.1h1.9z"/><circle cx="8" cy="1.5" r="0.5"/><circle cx="9" cy="3" r="0.5"/><circle cx="9" cy="5" r="0.5"/><circle cx="8" cy="6.5" r="0.5"/></g>',
			'jp' => '<rect width="20" height="14" fill="#fff"/><circle cx="10" cy="7" r="4" fill="#BC002D"/>',
			'kr' => '<rect width="20" height="14" fill="#fff"/><circle cx="10" cy="7" r="3.2" fill="#CD2E3A"/><path d="M10 3.8a3.2 3.2 0 0 0 0 6.4 1.6 1.6 0 0 1 0-3.2 1.6 1.6 0 0 0 0-3.2z" fill="#0047A0"/>',
			'sg' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="7" fill="#ED2939"/><circle cx="5" cy="3.5" r="2" fill="#fff"/><circle cx="5.8" cy="3.5" r="1.7" fill="#ED2939"/>',
			'mx' => '<rect width="20" height="14" fill="#fff"/><rect width="6.67" height="14" fill="#006847"/><rect x="13.33" width="6.67" height="14" fill="#CE1126"/>',
			'br' => '<rect width="20" height="14" fill="#009739"/><path d="M10 2l8 5-8 5-8-5z" fill="#FEDD00"/><circle cx="10" cy="7" r="2.3" fill="#012169"/>',
			'za' => '<rect width="20" height="14" fill="#fff"/><rect width="20" height="4.67" fill="#000C8A"/><rect y="9.33" width="20" height="4.67" fill="#DE3831"/><path d="M0 4.67l7 2.33-7 2.33z" fill="#007A4D"/><path d="M0 4.2l8 2.8-8 2.8z" fill="#FFB612"/><path d="M0 3.7l9 3.3-9 3.3z" fill="#000"/>',
		);
	}

	/**
	 * Prints the hidden sprite. Call once per page.
	 */
	public static function sprite() {
		echo '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">'; // phpcs:ignore
		foreach ( self::shapes() as $id => $markup ) {
			printf( '<symbol id="sml-flag-%s" viewBox="0 0 20 14">%s</symbol>', esc_attr( $id ), $markup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</svg>';
	}

	/**
	 * @param string $id    Country id.
	 * @param string $class Extra CSS class(es) for the wrapping element.
	 * @return string SVG markup — a real flag when one is hand-authored in
	 *                 shapes(), otherwise a neutral ISO-code badge rather than
	 *                 a guessed/inaccurate flag design.
	 */
	public static function icon( $id, $class = '' ) {
		$shapes = self::shapes();
		if ( isset( $shapes[ $id ] ) ) {
			return sprintf(
				'<svg class="%s" viewBox="0 0 20 14" aria-hidden="true"><use href="#sml-flag-%s"></use></svg>',
				esc_attr( trim( 'sml-flag-icon ' . $class ) ),
				esc_attr( $id )
			);
		}

		return self::fallback_badge( $id, $class );
	}

	/**
	 * A plain ISO-code badge for any country without a hand-authored flag in
	 * shapes() — used instead of fabricating a flag design we can't verify.
	 *
	 * @param string $id
	 * @param string $class
	 * @return string
	 */
	protected static function fallback_badge( $id, $class = '' ) {
		return sprintf(
			'<svg class="%s" viewBox="0 0 20 14" aria-hidden="true"><rect width="20" height="14" rx="2" fill="#e2e2e6"/><text x="10" y="9.8" text-anchor="middle" font-size="6.2" font-weight="700" font-family="-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif" fill="#5a5a63">%s</text></svg>',
			esc_attr( trim( 'sml-flag-icon sml-flag-icon--fallback ' . $class ) ),
			esc_html( strtoupper( $id ) )
		);
	}

	/**
	 * @return string[] Country ids with a hand-authored flag shape (i.e. that
	 *                   can be rendered via the <symbol> sprite). Countries
	 *                   outside this list still get an icon via icon() — the
	 *                   neutral fallback badge — just not from the sprite.
	 */
	public static function ids() {
		return array_keys( self::shapes() );
	}
}
