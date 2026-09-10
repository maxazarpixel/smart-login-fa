<?php
/**
 * Email templating and sending. Everything goes through wp_mail() so any
 * SMTP plugin already configured on the site picks it up transparently.
 *
 * Subject/body are still stored (and admin-editable) as plain text with
 * {placeholder} tokens, exactly as before — only how the result gets
 * *rendered* changed: the whole message is wrapped in a branded HTML shell,
 * and the {code}/{link} tokens specifically are swapped for a styled code
 * badge and a real button instead of appearing as raw text, so the email
 * looks like a normal transactional email rather than a plain-text note.
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
	 * @param string  $redirect_to Optional. Where to send the user after
	 *                verifying — e.g. back to Cart/Checkout if that's what
	 *                sent them here. Already validated by the caller.
	 * @return bool
	 */
	public static function send_verification( WP_User $user, $code, $token, $is_resend = false, $redirect_to = '' ) {
		$expiry_minutes = (int) SML_Settings::get( 'code_expiry_minutes', 10 );
		$link           = self::verify_link( $token, $redirect_to );

		$subject_key = $is_resend ? 'resend_subject' : 'verify_subject';
		$body_key    = $is_resend ? 'resend_body' : 'verify_body';

		$subject = self::substitute(
			SML_Settings::get( $subject_key ),
			$user,
			array( 'expiry_minutes' => $expiry_minutes )
		);

		// {code} and {link} are deliberately left as literal tokens here —
		// send() swaps them for styled HTML after the rest of the body has
		// been through wp_kses_post(), so they always render as a proper
		// badge/button regardless of what an admin has written around them.
		$body_text = self::substitute(
			SML_Settings::get( $body_key ),
			$user,
			array( 'expiry_minutes' => $expiry_minutes )
		);

		return self::send( $user->user_email, $subject, $body_text, array( 'code' => $code, 'link' => $link ) );
	}

	/**
	 * @param WP_User $user
	 * @return bool
	 */
	public static function send_welcome( WP_User $user ) {
		$subject = self::substitute( SML_Settings::get( 'welcome_subject' ), $user );
		$body    = self::substitute( SML_Settings::get( 'welcome_body' ), $user );

		return self::send( $user->user_email, $subject, $body );
	}

	/**
	 * @param WP_User $user
	 * @param string  $reset_url
	 * @return bool
	 */
	public static function send_password_reset( WP_User $user, $reset_url ) {
		$subject   = self::substitute( SML_Settings::get( 'reset_subject' ), $user );
		$body_text = self::substitute( SML_Settings::get( 'reset_body' ), $user );

		return self::send(
			$user->user_email,
			$subject,
			$body_text,
			array(
				'link'       => $reset_url,
				'link_label' => __( 'Reset Password', 'smart-login' ),
			)
		);
	}

	protected static function verify_link( $token, $redirect_to = '' ) {
		$args = array( 'sml_verify' => rawurlencode( $token ) );
		if ( $redirect_to ) {
			$args['redirect_to'] = rawurlencode( $redirect_to );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * @param string  $template
	 * @param WP_User $user
	 * @param array   $extra Extra {placeholder} => value pairs.
	 * @return string
	 */
	protected static function substitute( $template, WP_User $user, array $extra = array() ) {
		$replacements = array_merge(
			array(
				'{user}'      => $user->display_name,
				'{site_name}' => get_bloginfo( 'name' ),
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

	/**
	 * @param string $to
	 * @param string $subject
	 * @param string $body_text  Plain-text-with-placeholders body (already had
	 *                           {user}/{site_name}/{expiry_minutes} substituted).
	 * @param array  $rich       Optional 'code' and/or 'link' raw values, swapped
	 *                           in as styled HTML after sanitisation. An optional
	 *                           'link_label' sets the CTA button text (defaults
	 *                           to "Verify Email").
	 * @return bool
	 */
	protected static function send( $to, $subject, $body_text, array $rich = array() ) {
		self::$sending = true;

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );

		// Subject is always plain text; body may contain admin-authored basic
		// HTML, which is why the plugin sends as text/html — but it still
		// goes through wp_kses_post() so no script/unsafe markup survives.
		$subject = wp_strip_all_tags( $subject );
		$body    = nl2br( wp_kses_post( $body_text ) );

		if ( isset( $rich['code'] ) ) {
			$body = str_replace( '{code}', self::code_badge( $rich['code'] ), $body );
		}
		if ( isset( $rich['link'] ) ) {
			$link_label = isset( $rich['link_label'] ) ? (string) $rich['link_label'] : '';
			$body       = str_replace( '{link}', self::cta_button( $rich['link'], $link_label ), $body );
		}

		$html = self::wrap_shell( $body );

		$sent = wp_mail( $to, $subject, $html );

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

	/**
	 * A large, letter-spaced code display instead of a bare number in the
	 * middle of a sentence.
	 *
	 * @param string $code
	 * @return string
	 */
	protected static function code_badge( $code ) {
		return '<div style="text-align:center;margin:22px 0;">'
			. '<div style="display:inline-block;background:#f7f7f8;border:1.5px solid #e6e6ea;border-radius:8px;padding:16px 26px;">'
			. '<span style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:30px;font-weight:700;letter-spacing:8px;color:#111114;">'
			. esc_html( $code )
			. '</span></div></div>';
	}

	/**
	 * A real button instead of a bare, easy-to-mistrust URL.
	 *
	 * @param string $url
	 * @param string $label Button text. Falls back to "Verify Email" when empty.
	 * @return string
	 */
	protected static function cta_button( $url, $label = '' ) {
		$label = '' !== trim( (string) $label ) ? $label : __( 'Verify Email', 'smart-login' );

		// Match the front-end form's button colours (Settings → General →
		// "Button appearance"), falling back to the plugin's near-black
		// default when either isn't a valid hex value.
		$bg = sanitize_hex_color( (string) SML_Settings::get( 'button_bg_color' ) );
		$fg = sanitize_hex_color( (string) SML_Settings::get( 'button_text_color' ) );
		$bg = $bg ? $bg : '#111114';
		$fg = $fg ? $fg : '#ffffff';

		return '<div style="text-align:center;margin:12px 0 6px;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;text-decoration:none;padding:12px 30px;border-radius:6px;">'
			. esc_html( $label )
			. '</a></div>'
			. '<p style="text-align:center;margin:10px 0 0;font-size:12px;color:#9a9aa2;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<a href="' . esc_url( $url ) . '" style="color:#9a9aa2;word-break:break-all;">' . esc_html( $url ) . '</a>'
			. '</p>';
	}

	/**
	 * Wraps rendered body HTML in a branded, table-based shell (max
	 * compatibility with email clients) — site name header, card body,
	 * automated-message footer.
	 *
	 * @param string $inner_html
	 * @return string
	 */
	protected static function wrap_shell( $inner_html ) {
		// Header/footer branding name — admin-overridable, falls back to the
		// WordPress site title.
		$footer_name = trim( (string) SML_Settings::get( 'email_footer_name', '' ) );
		$raw_name    = '' !== $footer_name ? $footer_name : get_bloginfo( 'name' );
		$site_name   = esc_html( $raw_name );
		$font        = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

		// Optional extra footer block (company address, contact info, legal
		// line, etc.). Admin-authored, so basic HTML is allowed; {site_name}
		// is substituted for convenience.
		$footer_text  = trim( (string) SML_Settings::get( 'email_footer_text', '' ) );
		$footer_extra = '';
		if ( '' !== $footer_text ) {
			$footer_extra = '<p style="font-family:' . $font . ';font-size:12px;color:#9a9aa2;text-align:center;margin:10px 0 0;line-height:1.5;">'
				. nl2br( wp_kses_post( str_replace( '{site_name}', $site_name, $footer_text ) ) )
				. '</p>';
		}

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $site_name . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f5f5f5;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px;font-family:' . $font . ';">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:10px;">'
			. '<tr><td style="padding:26px 32px 18px;text-align:center;border-bottom:1px solid #f0f0f1;">'
			. '<span style="font-family:' . $font . ';font-size:16px;font-weight:700;color:#111114;">' . $site_name . '</span>'
			. '</td></tr>'
			. '<tr><td style="padding:28px 32px 6px;font-family:' . $font . ';font-size:14.5px;line-height:1.6;color:#33333a;">'
			. $inner_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</td></tr>'
			. '<tr><td style="padding:18px 32px 28px;">'
			. '<p style="font-family:' . $font . ';font-size:12px;color:#9a9aa2;text-align:center;margin:0;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'This is an automated message from %s. If you did not request this, you can safely ignore it.', 'smart-login' ),
				$site_name
			)
			. '</p>'
			. $footer_extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</td></tr>'
			. '</table></td></tr></table>'
			. '</body></html>';
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
