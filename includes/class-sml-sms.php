<?php
/**
 * SMS delivery of verification codes through an Iranian SMS gateway.
 *
 * Most providers are used in their "template / pattern" mode, where the gateway
 * owns the message text and Smart Login only supplies the code. The IPPanel
 * web service sends the text written in Settings → SMS gateway instead.
 *
 * Every gateway here reaches Iranian numbers; the SMS settings only apply
 * while Iran is an allowed country.
 *
 * Providers (request formats taken from each gateway's own documentation or
 * official SDK):
 *   ippanel_ws   POST edge.ippanel.com/v1/api/send                  (Authorization header, free text)
 *   kavenegar    POST api.kavenegar.com/v1/{key}/verify/lookup.json
 *   farazsms     POST api.iranpayamak.com/ws/v1/sms/pattern        (Api-Key header)
 *   ippanel      POST api2.ippanel.com/api/v1/sms/pattern/normal/send (legacy IPPanel API, apikey header)
 *   melipayamak  POST rest.payamak-panel.com/api/SendSMS/BaseServiceNumber
 *
 * Third-party code can take over delivery with the `sml_sms_send` filter
 * (return true, or a WP_Error, to short-circuit) and signal that it is ready
 * with the `sml_sms_configured` filter.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_SMS {

	const PHONE_LIMIT  = 3;
	const PHONE_WINDOW = HOUR_IN_SECONDS;

	/** Gateways that can only deliver to Iranian (+98) numbers. */
	const IRAN_ONLY = array( 'ippanel_ws', 'ippanel', 'farazsms', 'melipayamak' );

	/**
	 * @return string[] provider key => label
	 */
	public static function providers() {
		return array(
			'none'        => __( 'None', 'smart-login' ),
			'ippanel_ws'  => __( 'IPPanel (web service)', 'smart-login' ),
			'kavenegar'   => __( 'Kavenegar', 'smart-login' ),
			'farazsms'    => __( 'FarazSMS (new API)', 'smart-login' ),
			'ippanel'     => __( 'IPPanel (legacy API)', 'smart-login' ),
			'melipayamak' => __( 'Melipayamak', 'smart-login' ),
		);
	}

	/**
	 * @return string Selected provider key, or '' when none.
	 */
	public static function provider() {
		$key = (string) SML_Settings::get( 'sms_provider', 'none' );

		return ( 'none' !== $key && isset( self::providers()[ $key ] ) ) ? $key : '';
	}

	/**
	 * Whether Iran is one of the countries visitors can register from. The SMS
	 * gateways are Iranian services, so they are only in play then.
	 *
	 * @return bool
	 */
	public static function iran_allowed() {
		return in_array( SML_Iran::COUNTRY_ID, SML_Countries::allowed_ids(), true );
	}

	/**
	 * True when Iran is allowed, a provider is chosen and every credential it
	 * needs is set.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider = self::provider();
		$ready    = false;

		if ( '' !== $provider && self::iran_allowed() && '' !== (string) SML_Settings::get( 'sms_api_key' ) ) {
			$sender = trim( (string) SML_Settings::get( 'sms_sender' ) );

			// The web service needs a sender line (its text has a default); the
			// others need the approved template / pattern.
			$ready = ( 'ippanel_ws' === $provider )
				? '' !== $sender
				: '' !== trim( (string) SML_Settings::get( 'sms_template' ) );

			if ( 'melipayamak' === $provider && '' === trim( (string) SML_Settings::get( 'sms_username' ) ) ) {
				$ready = false;
			}

			if ( in_array( $provider, array( 'farazsms', 'ippanel' ), true ) && '' === $sender ) {
				$ready = false;
			}
		}

		return (bool) apply_filters( 'sml_sms_configured', $ready );
	}

	/**
	 * Whether the configured gateway can reach this number. Iranian gateways
	 * only deliver to +98; a gateway supplied through the `sml_sms_send` filter
	 * is trusted.
	 *
	 * @param string $e164 e.g. +989123456789
	 * @return bool
	 */
	public static function can_send_to( $e164 ) {
		$provider = self::provider();

		if ( '' === $provider ) {
			return (bool) apply_filters( 'sml_sms_configured', false );
		}

		if ( in_array( $provider, self::IRAN_ONLY, true ) ) {
			return 0 === strpos( (string) $e164, '+98' );
		}

		return true;
	}

	/**
	 * The site address printed where a message uses {site}: the one set in
	 * Settings → SMS gateway, otherwise this site's own host.
	 *
	 * @return string e.g. example.com
	 */
	public static function site_address() {
		$site = trim( (string) SML_Settings::get( 'sms_site' ) );

		if ( '' === $site ) {
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		return preg_replace( '#^https?://#i', '', rtrim( $site, '/' ) );
	}

	/**
	 * The text sent by the web-service gateway: the message from Settings with
	 * {code} (or %code%), {site}, {site_name} and {expiry_minutes} filled in.
	 *
	 * The template is treated as plain text (no markup), is cut to a length one
	 * SMS can carry, and always contains the code — a message that forgot the
	 * placeholder gets it appended rather than going out without a code.
	 *
	 * @param string $code
	 * @param string $context 'verify' (sign-up / login) or 'reset' (password reset).
	 * @return string
	 */
	public static function render_message( $code, $context = 'verify' ) {
		$text = '';

		if ( 'reset' === $context ) {
			$text = trim( (string) SML_Settings::get( 'sms_reset_message' ) );
		}
		if ( '' === $text ) {
			$text = trim( (string) SML_Settings::get( 'sms_message' ) );
		}
		if ( '' === $text ) {
			$text = (string) SML_Settings::defaults()['sms_message'];
		}

		$text = wp_strip_all_tags( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		$text = mb_substr( $text, 0, 280 );

		if ( false === strpos( $text, '{code}' ) && false === strpos( $text, '%code%' ) ) {
			$text .= "\n{code}";
		}

		return strtr(
			$text,
			array(
				'{code}'           => $code,
				'%code%'           => $code,
				'{site}'           => self::site_address(),
				'{site_name}'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{expiry_minutes}' => (string) (int) SML_Settings::get( 'code_expiry_minutes', 10 ),
			)
		);
	}

	/**
	 * Sender line in the international form the IPPanel web service expects
	 * (+983000505). A line typed without a country code is taken as Iranian.
	 *
	 * @param string $line
	 * @return string
	 */
	protected static function ippanel_line( $line ) {
		$digits = preg_replace( '/\D/', '', (string) $line );

		if ( 0 === strpos( $digits, '0098' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '98' ) || 0 === strpos( trim( (string) $line ), '+' ) ) {
			return '+' . $digits;
		}

		return '+98' . ltrim( $digits, '0' );
	}

	/**
	 * Gateway-reported reason for a failed IPPanel web-service call.
	 *
	 * @param int   $http
	 * @param array $json
	 * @return string
	 */
	protected static function ippanel_detail( $http, array $json ) {
		$meta  = isset( $json['meta'] ) && is_array( $json['meta'] ) ? $json['meta'] : array();
		$parts = array( 'HTTP ' . $http );

		if ( ! empty( $meta['message_code'] ) ) {
			$parts[] = (string) $meta['message_code'];
		}
		if ( ! empty( $meta['message'] ) && is_string( $meta['message'] ) ) {
			$parts[] = $meta['message'];
		}
		if ( ! empty( $meta['errors'] ) && is_array( $meta['errors'] ) ) {
			$first = reset( $meta['errors'] );
			$first = is_array( $first ) ? reset( $first ) : $first;
			if ( is_string( $first ) && '' !== $first ) {
				$parts[] = '(' . $first . ')';
			}
		}

		return mb_substr( implode( ' ', $parts ), 0, 240 );
	}

	/**
	 * Recipient in the form gateways expect: 09123456789 for Iranian
	 * numbers, 00<country code><number> for everything else.
	 *
	 * @param string $e164 e.g. +989123456789
	 * @return string
	 */
	public static function local_receptor( $e164 ) {
		$digits = preg_replace( '/\D/', '', (string) $e164 );

		if ( 0 === strpos( $digits, '98' ) ) {
			return '0' . substr( $digits, 2 );
		}

		return '00' . $digits;
	}

	/**
	 * Display-safe form of a number for logs and screens: 0912 *** 6789.
	 *
	 * @param string $e164
	 * @return string
	 */
	public static function mask( $e164 ) {
		$local = self::local_receptor( $e164 );
		$len   = strlen( $local );

		if ( $len < 8 ) {
			return str_repeat( '*', $len );
		}

		return substr( $local, 0, 4 ) . ' *** ' . substr( $local, -4 );
	}

	/**
	 * Sends a verification code. Callers must not log or display the code.
	 *
	 * @param string $e164   Recipient, +989123456789.
	 * @param string $code   Digits only.
	 * @param bool   $throttle Apply the per-number budget (off for the admin test).
	 * @param string $template Optional template / pattern code overriding the configured one.
	 * @param string $context  'verify' (default) or 'reset', which picks the message text.
	 * @return true|WP_Error WP_Error code 'sml_sms_failed' carries the gateway's
	 *                       reason in data['detail'] for the admin screen.
	 */
	public static function send_code( $e164, $code, $throttle = true, $template = '', $context = 'verify' ) {
		$e164 = (string) $e164;
		$code = preg_replace( '/\D/', '', (string) $code );

		if ( '' === $e164 || '' === $code ) {
			return new WP_Error( 'sml_sms_failed', self::generic_error() );
		}

		if ( $throttle ) {
			$budget = self::check_phone_budget( $e164 );
			if ( is_wp_error( $budget ) ) {
				return $budget;
			}
		}

		$receptor = self::local_receptor( $e164 );
		$short    = apply_filters( 'sml_sms_send', null, $receptor, $code );

		if ( null !== $short ) {
			$result = ( true === $short ) ? true : ( is_wp_error( $short ) ? $short : new WP_Error( 'sml_sms_failed', self::generic_error() ) );
		} elseif ( ! self::is_configured() ) {
			$result = new WP_Error( 'sml_sms_failed', self::generic_error(), array( 'detail' => __( 'SMS sending is not configured.', 'smart-login' ) ) );
		} elseif ( ! self::can_send_to( $e164 ) ) {
			$result = new WP_Error(
				'sml_sms_failed',
				__( 'SMS verification is only available for Iranian mobile numbers.', 'smart-login' ),
				array( 'detail' => 'The number is outside +98 and this gateway only reaches Iranian numbers.' )
			);
		} else {
			$result = self::dispatch( self::provider(), $e164, $receptor, $code, trim( (string) $template ), $context );
		}

		if ( $throttle ) {
			self::record_phone_send( $e164 );
		}

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			SML_Loader::log(
				sprintf(
					'SMS (%s) to %s: FAILED (%s)',
					self::provider() ? self::provider() : 'filter',
					self::mask( $e164 ),
					is_array( $data ) && ! empty( $data['detail'] ) ? $data['detail'] : $result->get_error_message()
				)
			);
		} elseif ( SML_Settings::get( 'enable_debug_log' ) ) {
			SML_Loader::log( sprintf( 'SMS (%s) to %s: sent', self::provider() ? self::provider() : 'filter', self::mask( $e164 ) ) );
		}

		return $result;
	}

	protected static function generic_error() {
		return __( 'We could not send the SMS. Please try again in a few minutes.', 'smart-login' );
	}

	/**
	 * SMS costs money and lands on someone's phone, so a single number can
	 * only be sent a few codes per hour no matter which account or IP asks.
	 *
	 * @param string $e164
	 * @return true|WP_Error
	 */
	protected static function check_phone_budget( $e164 ) {
		$count = (int) get_transient( self::phone_key( $e164 ) );

		if ( $count >= self::PHONE_LIMIT ) {
			return new WP_Error( 'sml_sms_throttled', __( 'Too many codes have been requested for this number. Please try again later.', 'smart-login' ) );
		}

		return true;
	}

	protected static function record_phone_send( $e164 ) {
		$key   = self::phone_key( $e164 );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::PHONE_WINDOW );
	}

	protected static function phone_key( $e164 ) {
		return 'sml_sms_' . md5( preg_replace( '/\D/', '', $e164 ) );
	}

	/**
	 * @return true|WP_Error
	 */
	protected static function dispatch( $provider, $e164, $receptor, $code, $template_override = '', $context = 'verify' ) {
		$api_key  = (string) SML_Settings::get( 'sms_api_key' );
		$template = '' !== $template_override ? $template_override : trim( (string) SML_Settings::get( 'sms_template' ) );
		$sender   = trim( (string) SML_Settings::get( 'sms_sender' ) );
		$var      = trim( (string) SML_Settings::get( 'sms_code_variable' ) );
		$var      = '' !== $var ? $var : 'code';

		switch ( $provider ) {
			case 'ippanel_ws':
				$response = wp_remote_post(
					'https://edge.ippanel.com/v1/api/send',
					array(
						'timeout' => 15,
						'headers' => array(
							'Authorization' => $api_key,
							'Content-Type'  => 'application/json',
							'Accept'        => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'sending_type' => 'webservice',
								'from_number'  => self::ippanel_line( $sender ),
								'message'      => self::render_message( $code, $context ),
								'params'       => array( 'recipients' => array( '+' . preg_replace( '/\D/', '', $e164 ) ) ),
							)
						),
					)
				);

				return self::interpret(
					$response,
					function ( $http, $json ) {
						$ok = $http >= 200 && $http < 300 && ! empty( $json['meta']['status'] );

						return array( $ok, self::ippanel_detail( $http, $json ) );
					}
				);

			case 'kavenegar':
				$response = wp_remote_post(
					'https://api.kavenegar.com/v1/' . rawurlencode( $api_key ) . '/verify/lookup.json',
					array(
						'timeout' => 15,
						'body'    => array(
							'receptor' => $receptor,
							'token'    => $code,
							'template' => $template,
						),
					)
				);

				return self::interpret(
					$response,
					function ( $http, $json ) {
						$status = isset( $json['return']['status'] ) ? (int) $json['return']['status'] : 0;
						$ok     = 200 === $status;
						$detail = isset( $json['return']['message'] ) ? $json['return']['message'] : '';

						return array( $ok, $status ? $status . ( $detail ? ' ' . $detail : '' ) : 'HTTP ' . $http );
					}
				);

			case 'farazsms':
				$response = wp_remote_post(
					'https://api.iranpayamak.com/ws/v1/sms/pattern',
					array(
						'timeout' => 15,
						'headers' => array(
							'Api-Key'      => $api_key,
							'Content-Type' => 'application/json',
							'Accept'       => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'code'          => $template,
								'attributes'    => array( $var => $code ),
								'recipient'     => $receptor,
								'line_number'   => $sender,
								'number_format' => 'english',
							)
						),
					)
				);

				return self::interpret(
					$response,
					function ( $http, $json ) {
						return array( 201 === $http || ( 200 === $http && ! empty( $json['data']['id'] ) ), 'HTTP ' . $http . self::json_message( $json ) );
					}
				);

			case 'ippanel':
				$response = wp_remote_post(
					'https://api2.ippanel.com/api/v1/sms/pattern/normal/send',
					array(
						'timeout' => 15,
						'headers' => array(
							'apikey'       => $api_key,
							'Content-Type' => 'application/json',
							'Accept'       => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'code'      => $template,
								'sender'    => $sender,
								'recipient' => ltrim( preg_replace( '/\D/', '', $e164 ), '0' ),
								'variable'  => array( $var => $code ),
							)
						),
					)
				);

				return self::interpret(
					$response,
					function ( $http, $json ) {
						return array( ! empty( $json['data']['message_id'] ), 'HTTP ' . $http . self::json_message( $json ) );
					}
				);

			case 'melipayamak':
				$response = wp_remote_post(
					'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
					array(
						'timeout' => 15,
						'body'    => array(
							'username' => trim( (string) SML_Settings::get( 'sms_username' ) ),
							'password' => $api_key,
							'text'     => $code,
							'to'       => $receptor,
							'bodyId'   => $template,
						),
					)
				);

				return self::interpret(
					$response,
					function ( $http, $json ) {
						$value = isset( $json['Value'] ) ? (string) $json['Value'] : '';
						$ok    = ( isset( $json['RetStatus'] ) && 1 === (int) $json['RetStatus'] )
							|| ( ctype_digit( $value ) && strlen( $value ) > 15 );
						$text  = isset( $json['StrRetStatus'] ) ? $json['StrRetStatus'] : $value;

						return array( $ok, 'HTTP ' . $http . ( '' !== (string) $text ? ' ' . $text : '' ) );
					}
				);
		}

		return new WP_Error( 'sml_sms_failed', self::generic_error() );
	}

	/**
	 * Turns a wp_remote_* response into true|WP_Error using a provider
	 * specific success test.
	 *
	 * @param array|WP_Error $response
	 * @param callable       $judge fn( int $http, array $json ): array( bool $ok, string $detail )
	 * @return true|WP_Error
	 */
	protected static function interpret( $response, $judge ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sml_sms_failed', self::generic_error(), array( 'detail' => $response->get_error_message() ) );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : array();

		list( $ok, $detail ) = call_user_func( $judge, $http, $json );

		if ( $ok ) {
			return true;
		}

		return new WP_Error( 'sml_sms_failed', self::generic_error(), array( 'detail' => $detail ) );
	}

	protected static function json_message( array $json ) {
		foreach ( array( 'message', 'error', 'meta' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) && '' !== $json[ $key ] ) {
				return ' ' . mb_substr( $json[ $key ], 0, 160 );
			}
		}

		return '';
	}
}
