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

		// Translations must be ready before the admin page config is built
		// (its constructor runs on this same hook and calls __() directly).
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'locale_fallback_mofile' ), 10, 2 );
		self::load_textdomain();
		add_action( 'sml_cleanup_expired_verifications', array( 'SML_Verification', 'cleanup_expired' ) );

		// "Settings" link on the Plugins screen row.
		add_filter( 'plugin_action_links_' . SML_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );

		// The auto-detected form-page URL (used for reset / verification
		// links when no Login page is configured) is cached for a day —
		// drop it whenever settings are saved or a page is edited so a
		// freshly-placed shortcode is picked up without waiting.
		add_action( 'apx_settings_saved', array( __CLASS__, 'flush_form_page_cache' ) );
		add_action( 'save_post_page', array( __CLASS__, 'flush_form_page_cache' ) );

		// Always on (not gated by the debug-log setting): a failed send is
		// exactly the kind of thing an admin needs to see when "the email
		// never arrived" gets reported, and wp_mail() itself gives no other
		// signal beyond a bare `false` return value.
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_mail_failure' ) );

		// Powers the admin dashboard's "recent logins" table. Fires on every
		// successful login regardless of entry point (this plugin's own AJAX
		// handler, wp-login.php, WooCommerce, etc.), since wp_signon()/
		// wp_authenticate() always trigger it.
		add_action( 'wp_login', array( __CLASS__, 'track_last_login' ), 10, 2 );

		// Not gated by is_admin(): the settings page's Save button calls the
		// REST route this class registers (via rest_api_init), and REST API
		// requests are not admin requests — gating construction to admin
		// context meant that route never got registered, so saving from the
		// browser always failed. The menu/enqueue pieces inside the class
		// are still only reachable through admin-only hooks, so constructing
		// it here has no front-end effect beyond registering the route.
		new SML_Admin_Page();

		if ( is_admin() ) {
			SML_Users_List::init();
		}

		SML_Registration_Handler::init();
		SML_Login_Handler::init();
		SML_Password_Reset_Handler::init();
		SML_Page_Guard::init();
		SML_Shortcode::init();
		SML_Google_Auth::init();

		if ( class_exists( 'WooCommerce' ) ) {
			SML_WooCommerce::init();
		}
	}

	/**
	 * Tells page-cache plugins and, where the response headers reach it, a
	 * reverse proxy/CDN in front of the site not to cache the current
	 * response. Call this before any output on a request whose result is
	 * specific to the visitor or the moment — a login/registration redirect,
	 * the OAuth callback, or a page rendering `[smart_login_form]` with a
	 * `redirect_to`/reset/verify query string baked into hidden fields.
	 *
	 * A full-page cache that serves a stale HTML response here doesn't just
	 * show outdated content: it can replay someone else's `redirect_to`
	 * destination, or serve an anonymous view of a page load that actually
	 * just logged the visitor in — the auth cookie reached the browser fine,
	 * but the next page they see was generated before it existed. Every
	 * major caching plugin (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP
	 * Super Cache, Breeze, Cache Enabler, SiteGround/WP Engine/Kinsta's own)
	 * honours the DONOTCACHEPAGE convention this sets.
	 */
	public static function bypass_page_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'smart-login', false, dirname( SML_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Regional variants without a file of their own borrow the closest
	 * shipped translation (es_MX / es_AR / … -> es_ES, fa_AF -> fa_IR).
	 *
	 * @param string $mofile
	 * @param string $domain
	 * @return string
	 */
	public static function locale_fallback_mofile( $mofile, $domain ) {
		if ( 'smart-login' !== $domain || file_exists( $mofile ) ) {
			return $mofile;
		}

		$fallbacks = array(
			'es_' => 'es_ES',
			'fa_' => 'fa_IR',
		);
		$locale    = determine_locale();

		foreach ( $fallbacks as $prefix => $target ) {
			if ( 0 === strpos( $locale, $prefix ) ) {
				$candidate = SML_PLUGIN_DIR . 'languages/smart-login-' . $target . '.mo';
				if ( file_exists( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return $mofile;
	}

	public static function flush_form_page_cache() {
		delete_transient( 'sml_form_page_url' );
	}

	/**
	 * Prepends a "Settings" link to the plugin's row on Plugins → Installed
	 * Plugins.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=smart-login' ) ),
			esc_html__( 'Settings', 'smart-login' )
		);
		array_unshift( $links, $settings );

		return $links;
	}

	protected static function load_files() {
		require_once SML_PLUGIN_DIR . 'includes/class-sml-disposable-email.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-settings.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-countries.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-flags.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-iran.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-phone.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-verification.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-email.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-lockout.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-rate-limit.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-login-handler.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-registration-handler.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-password-reset-handler.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-page-guard.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-shortcode.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-users-list.php';
		require_once SML_PLUGIN_DIR . 'includes/class-sml-google-auth.php';

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
	 * Fired for every failed wp_mail() call site-wide, not just this
	 * plugin's own sends — that's deliberate: if the site's mailer (e.g.
	 * WP Mail SMTP) is misconfigured, every plugin's mail fails the same
	 * way, and seeing that clearly here is often the fastest way to tell
	 * "this is a Smart Login bug" from "this is an SMTP/server problem".
	 *
	 * @param WP_Error $error
	 */
	public static function log_mail_failure( $error ) {
		self::log(
			sprintf(
				'wp_mail() failed: %s',
				is_wp_error( $error ) ? $error->get_error_message() : 'unknown error'
			)
		);
	}

	/**
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public static function track_last_login( $user_login, $user ) {
		update_user_meta( $user->ID, 'sml_last_login', time() );
	}

	/** Rotate once the log passes this size; one previous file is kept. */
	const LOG_MAX_BYTES = 2097152; // 2 MB.

	/**
	 * Writes to a dedicated log file under wp-content/uploads/smart-login-logs/
	 * — never to PHP's error_log directly, and never with secrets/codes in
	 * plaintext (callers are responsible for redacting before logging).
	 *
	 * @param string $message
	 */
	public static function log( $message ) {
		$log_dir = self::log_dir();
		if ( ! $log_dir ) {
			return;
		}

		$file = trailingslashit( $log_dir ) . self::log_filename();

		self::maybe_rotate( $file );

		$line = sprintf( '[%s] %s' . PHP_EOL, gmdate( 'Y-m-d H:i:s' ), $message );
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * The log directory, created on first use with deny rules for both
	 * Apache (.htaccess) and IIS (web.config).
	 *
	 * @return string Absolute path, or '' when it could not be created.
	 */
	protected static function log_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}

		$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'smart-login-logs';

		if ( ! file_exists( $log_dir ) && ! wp_mkdir_p( $log_dir ) ) {
			return '';
		}

		if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
			file_put_contents( $log_dir . '/.htaccess', "Order deny,allow\nDeny from all" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		// nginx ignores .htaccess entirely and IIS needs its own rule, which
		// is why the filename below is unguessable rather than trusting any
		// single server's deny directive to be honoured.
		if ( ! file_exists( $log_dir . '/web.config' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$log_dir . '/web.config',
				'<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization>'
					. '<deny users="*" /></authorization></system.webServer></configuration>'
			);
		}
		if ( ! file_exists( $log_dir . '/index.php' ) ) {
			file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $log_dir;
	}

	/**
	 * Log filename carrying a per-site random suffix, so the file is not
	 * guessable from the outside even on a server that serves it happily.
	 * The suffix is generated once and stored alongside the settings.
	 *
	 * @return string
	 */
	protected static function log_filename() {
		$key = get_option( 'sml_log_key' );

		if ( ! $key || ! is_string( $key ) ) {
			$key = wp_generate_password( 20, false, false );
			update_option( 'sml_log_key', $key, false );
		}

		return 'debug-' . sanitize_key( $key ) . '.log';
	}

	/**
	 * @param string $file
	 */
	protected static function maybe_rotate( $file ) {
		if ( ! file_exists( $file ) || filesize( $file ) < self::LOG_MAX_BYTES ) {
			return;
		}

		$previous = $file . '.1';
		if ( file_exists( $previous ) ) {
			wp_delete_file( $previous );
		}

		@rename( $file, $previous ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
