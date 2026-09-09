<?php
/**
 * Google reCAPTCHA v3 provider.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Recaptcha_Provider implements SML_Bot_Protection_Provider {

	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	public function verify( $response_token ) {
		if ( empty( $response_token ) ) {
			return false;
		}

		$secret = SML_Settings::get( 'bot_secret_key' );
		if ( empty( $secret ) ) {
			return false;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $response_token,
					'remoteip' => self::client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return false;
		}

		$threshold = (float) SML_Settings::get( 'recaptcha_threshold', 0.5 );

		return isset( $body['score'] ) && (float) $body['score'] >= $threshold;
	}

	public function enqueue() {
		$site_key = SML_Settings::get( 'bot_site_key' );
		if ( ! $site_key ) {
			return;
		}
		wp_enqueue_script(
			'sml-recaptcha',
			'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			true
		);
	}

	protected static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
