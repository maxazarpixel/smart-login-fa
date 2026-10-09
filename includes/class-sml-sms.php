<?php
/**
 * SMS delivery of verification codes through an Iranian SMS gateway.
 *
 * Every provider is used in its "template / pattern" mode, where the gateway
 * owns the message text and Smart Login only supplies the code. That is what
 * these gateways require for OTP traffic and it keeps arbitrary text out of
 * the request.
 *
 * Providers (request formats taken from each gateway's own documentation or
 * official SDK):
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

	/**
	 * @return string[] provider key => label
	 */
	public static function providers() {
		return array(
			'none'        => __( 'None', 'smart-login' ),
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
	 * True when a provider is chosen and every credential it needs is set.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider = self::provider();
		$ready    = false;

		if ( '' !== $provider
			&& '' !== (string) SML_Settings::get( 'sms_api_key' )
			&& '' !== trim( (string) SML_Settings::get( 'sms_template' ) ) ) {
			$ready = true;

			if ( 'melipayamak' === $provider && '' === trim( (string) SML_Settings::get( 'sms_username' ) ) ) {
				$ready = false;
			}

			if ( in_array( $provider, array( 'farazsms', 'ippanel' ), true ) && '' === trim( (string) SML_Settings::get( 'sms_sender' ) ) ) {
				$ready = false;
			}
		}

		return (bool) apply_filters( 'sml_sms_configured', $ready );
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
	 * @return true|WP_Error WP_Error code 'sml_sms_failed' carries the gateway's
	 *                       reason in data['detail'] for the admin screen.
	 */
	public static function send_code( $e164, $code, $throttle = true, $template = '' ) {
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
		} else {
			$result = self::dispatch( self::provider(), $e164, $receptor, $code, trim( (string) $template ) );
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
	protected static function dispatch( $provider, $e164, $receptor, $code, $template_override = '' ) {
		$api_key  = (string) SML_Settings::get( 'sms_api_key' );
		$template = '' !== $template_override ? $template_override : trim( (string) SML_Settings::get( 'sms_template' ) );
		$sender   = trim( (string) SML_Settings::get( 'sms_sender' ) );
		$var      = trim( (string) SML_Settings::get( 'sms_code_variable' ) );
		$var      = '' !== $var ? $var : 'code';

		switch ( $provider ) {
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
