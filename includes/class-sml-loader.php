<?php
/**
 * Loads plugin files and wires up all hooks. Entry point called on
 * `plugins_loaded` from the main plugin file.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Loader {

	public static function init() {
		self::load_files();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'sml_cleanup_expired_verifications', array( 'SML_Verification', 'cleanup_expired' ) );

		// Not gated by is_admin(): the settings page's Save button calls the
		// REST route this class registers (via rest_api_init), and REST API
		// requests are not admin requests — gating construction to admin
		// context meant that route never got registered, so saving from the
		// browser always failed. The menu/enqueue pieces inside the class
		// are still only reachable through admin-only hooks, so constructing
		// it here has no front-end effect beyond registering the route.
		new SML_Admin_Page();

		SML_Registration_Handler::init();
		SML_Login_Handler::init();
		SML_Shortcode::init();

		if ( class_exists( 'WooCommerce' ) ) {
			SML_WooCommerce::init();
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'smart-login', false, dirname( SML_PLUGIN_BASENAME ) . '/languages' );
	}

	protected static function load_files() {
		require_once SML_PLUGIN_DIR . 'includes/class-sml-settings.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-verification.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-email.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-lockout.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-login-handler.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-registration-handler.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-shortcode.php';

		require_once SML_PLUGIN_DIR . 'includes/providers/interface-sml-bot-protection-provider.php';
		require_once SML_PLUGIN_DIR . 'includes/providers/class-sml-recaptcha-provider.php';
		require_once SML_PLUGIN_DIR . 'includes/providers/class-sml-turnstile-provider.php';

		require_once SML_PLUGIN_DIR . 'admin/class-sml-admin-page.php';

		if ( class_exists( 'WooCommerce' ) ) {
			require_once SML_PLUGIN_DIR . 'includes/class-sml-woocommerce.php';
		}
	}

	/**
	 * @param string $key 'recaptcha'|'turnstile'.
	 * @return SML_Bot_Protection_Provider|null
	 */
	public static function bot_provider( $key ) {
		switch ( $key ) {
			case 'recaptcha':
				return new SML_Recaptcha_Provider();
			case 'turnstile':
				return new SML_Turnstile_Provider();
			default:
				return null;
		}
	}

	/**
	 * Writes to a dedicated log file under wp-content/uploads/smart-login-logs/
	 * — never to PHP's error_log directly, and never with secrets/codes in
	 * plaintext (callers are responsible for redacting before logging).
	 *
	 * @param string $message
	 */
	public static function log( $message ) {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'smart-login-logs';

		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			file_put_contents( $log_dir . '/.htaccess', 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$line = sprintf( '[%s] %s' . PHP_EOL, gmdate( 'Y-m-d H:i:s' ), $message );
		file_put_contents( $log_dir . '/debug.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
