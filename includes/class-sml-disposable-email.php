<?php
/**
 * Disposable/temporary email domain blocking. The domain list is a plain,
 * admin-editable setting (not a registry class like SML_Countries) since
 * its whole point is that an admin can add, edit, or remove entries
 * themselves — there's no fixed authoritative list of these, they change
 * constantly.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Disposable_Email {

	/**
	 * Seed list for the default setting value on a fresh install. Not
	 * exhaustive — disposable-email services come and go constantly, which
	 * is exactly why the list is admin-editable rather than hardcoded logic.
	 *
	 * @return string[]
	 */
	public static function default_domains() {
		return array(
			'mailinator.com',
			'guerrillamail.com',
			'guerrillamail.info',
			'guerrillamail.biz',
			'guerrillamail.de',
			'guerrillamail.net',
			'guerrillamail.org',
			'sharklasers.com',
			'grr.la',
			'pokemail.net',
			'spam4.me',
			'10minutemail.com',
			'10minutemail.net',
			'20minutemail.com',
			'tempmail.com',
			'temp-mail.org',
			'temp-mail.io',
			'throwawaymail.com',
			'yopmail.com',
			'yopmail.net',
			'yopmail.fr',
			'trashmail.com',
			'trashmail.net',
			'trash-mail.com',
			'dispostable.com',
			'maildrop.cc',
			'mintemail.com',
			'mailnesia.com',
			'getnada.com',
			'fakeinbox.com',
			'fakemailgenerator.com',
			'mohmal.com',
			'moakt.com',
			'emailondeck.com',
			'spamgourmet.com',
			'mailcatch.com',
			'discard.email',
			'mail-temporaire.fr',
			'jetable.org',
			'mytemp.email',
			'tempinbox.com',
			'mailnull.com',
			'tempr.email',
			'tempmailaddress.com',
			'incognitomail.com',
			'anonymbox.com',
			'mailexpire.com',
			'spambog.com',
			'spamex.com',
			'tmpmail.org',
			'tmpmail.net',
			'dropmail.me',
			'harakirimail.com',
			'mailsac.com',
			'burnermail.io',
			'mail.tm',
			'inboxbear.com',
		);
	}

	/**
	 * @return string[] Lower-cased, deduplicated domains currently configured.
	 */
	public static function configured_domains() {
		$raw = (string) SML_Settings::get( 'disposable_email_domains', '' );

		$domains = array_filter(
			array_map(
				function ( $line ) {
					return strtolower( trim( $line ) );
				},
				preg_split( '/\R/', $raw )
			)
		);

		return array_values( array_unique( $domains ) );
	}

	/**
	 * @param string $email
	 * @return bool
	 */
	public static function is_disposable( $email ) {
		if ( ! SML_Settings::get( 'block_disposable_emails' ) ) {
			return false;
		}

		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}

		$domain = strtolower( substr( $email, $at + 1 ) );

		return in_array( $domain, self::configured_domains(), true );
	}
}
