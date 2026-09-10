<?php
/**
 * Email templating and sending. Everything goes through wp_mail() so any
 * SMTP plugin already configured on the site picks it up transparently.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Email {

	/** @var bool Guards the content-type filter so it only applies to our own sends. */
	protected static $sending = false;

	/**
	 * @param WP_User $user
	 * @param string  $code
	 * @param string  $token
	 * @param bool    $is_resend
	 * @return bool
	 */
	public static function send_verification( WP_User $user, $code, $token, $is_resend = false ) {
		$expiry_minutes = (int) SML_Settings::get( 'code_expiry_minutes', 10 );

		$subject_key = $is_resend ? 'resend_subject' : 'verify_subject';
		$body_key    = $is_resend ? 'resend_body' : 'verify_body';

		$subject = self::render(
			SML_Settings::get( $subject_key ),
			$user,
			array( 'code' => $code, 'link' => self::verify_link( $token ), 'expiry_minutes' => $expiry_minutes )
		);
		$body = self::render(
			SML_Settings::get( $body_key ),
			$user,
			array( 'code' => $code, 'link' => self::verify_link( $token ), 'expiry_minutes' => $expiry_minutes )
		);

		return self::send( $user->user_email, $subject, $body );
	}

	/**
	 * @param WP_User $user
	 * @return bool
	 */
	public static function send_welcome( WP_User $user ) {
		$subject = self::render( SML_Settings::get( 'welcome_subject' ), $user );
		$body    = self::render( SML_Settings::get( 'welcome_body' ), $user );

		return self::send( $user->user_email, $subject, $body );
	}

	protected static function verify_link( $token ) {
		return add_query_arg( 'sml_verify', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * @param string  $template
	 * @param WP_User $user
	 * @param array   $extra Extra {placeholder} => value pairs.
	 * @return string
	 */
	protected static function render( $template, WP_User $user, array $extra = array() ) {
		$replacements = array_merge(
			array(
				'{user}'            => $user->display_name,
				'{site_name}'       => get_bloginfo( 'name' ),
			),
			array_combine(
				array_map(
					function ( $k ) {
						return '{' . $k . '}';
					},
					array_keys( $extra )
				),
				array_values( $extra )
			)
		);

		return strtr( (string) $template, $replacements );
	}

	protected static function send( $to, $subject, $body ) {
		self::$sending = true;

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );

		// Subject is always plain text; body may contain admin-authored basic
		// HTML, which is why the plugin sends as text/html — but it still
		// goes through wp_kses_post() so no script/unsafe markup survives.
		$subject = wp_strip_all_tags( $subject );
		$body    = nl2br( wp_kses_post( $body ) );

		$sent = wp_mail( $to, $subject, $body );

		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		remove_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );

		self::$sending = false;

		// Failures always get logged (they're diagnostic-critical); a
		// success is only logged when debug mode is on. wp_mail_failed
		// (logged separately in SML_Loader) carries the actual WP_Error
		// reason — this line just ties that failure back to which of our
		// own sends triggered it.
		if ( ! $sent || SML_Settings::get( 'enable_debug_log' ) ) {
			SML_Loader::log( sprintf( 'Email to %s ("%s"): %s', $to, $subject, $sent ? 'sent' : 'FAILED' ) );
		}

		return $sent;
	}

	public static function content_type() {
		return 'text/html';
	}

	public static function from_email() {
		$email = SML_Settings::get( 'from_email' );
		return is_email( $email ) ? $email : get_bloginfo( 'admin_email' );
	}

	public static function from_name() {
		$name = SML_Settings::get( 'from_name' );
		return $name ? $name : get_bloginfo( 'name' );
	}
}
