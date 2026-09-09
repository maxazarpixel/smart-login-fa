<?php
/**
 * Shared interface for bot-protection providers. Switching providers should
 * only ever touch settings — never form markup or JS beyond which widget
 * script is enqueued.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SML_Bot_Protection_Provider {

	/**
	 * Verifies a client-supplied response token against the provider's API.
	 *
	 * @param string $response_token
	 * @return bool
	 */
	public function verify( $response_token );

	/**
	 * Enqueues whatever script the provider's widget needs on the front end.
	 */
	public function enqueue();
}
