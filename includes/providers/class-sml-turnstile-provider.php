<?php
/**
 * Cloudflare Turnstile provider (pass/fail, no score).
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Turnstile_Provider implements SML_Bot_Protection_Provider {

	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

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

		return ! empty( $body['success'] );
	}

	public function enqueue() {
		// Explicit rendering (not the default auto-scan for `.cf-turnstile`):
		// the form has up to four panels — login, register, forgot, reset —
		// and smart-login.js renders / resets a widget for whichever one is
		// on screen. `onload` fires once the API is ready.
		wp_enqueue_script(
			'sml-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=smlTurnstileOnload',
			array( 'smart-login' ),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			true
		);
	}

	protected static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
