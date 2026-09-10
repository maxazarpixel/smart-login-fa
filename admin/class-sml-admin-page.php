<?php
/**
 * Smart Login settings page, built on the AzarPixel WP Admin UI Kit.
 *
 * Extends the kit's schema-driven renderer with a few field types the kit
 * doesn't ship out of the box (textarea, decimal, secret password) and with
 * secret-masking behaviour for the bot-protection secret key.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SML_PLUGIN_DIR . 'admin/ui-kit/includes/class-apx-icons.php';
require_once SML_PLUGIN_DIR . 'admin/ui-kit/includes/class-apx-admin-page.php';

class SML_Admin_Page extends APX_Admin_Page {

	/** Placeholder written into the DOM in place of a saved secret. */
	const SECRET_PLACEHOLDER = '__sml_secret_unchanged__';

	public function __construct() {
		$wc_active = class_exists( 'WooCommerce' );

		$nav = array(
			array(
				'group' => __( 'Overview', 'smart-login' ),
				'items' => array(
					array( 'key' => 'dashboard', 'label' => __( 'Dashboard', 'smart-login' ), 'icon' => 'gauge' ),
					array( 'key' => 'users', 'label' => __( 'Users', 'smart-login' ), 'icon' => 'users' ),
				),
			),
			array(
				'group' => __( 'Setup', 'smart-login' ),
				'items' => array(
					array( 'key' => 'general', 'label' => __( 'General', 'smart-login' ), 'icon' => 'gear' ),
					array( 'key' => 'verification', 'label' => __( 'Verification', 'smart-login' ), 'icon' => 'check' ),
					array( 'key' => 'security', 'label' => __( 'Security', 'smart-login' ), 'icon' => 'lock' ),
					array( 'key' => 'emails', 'label' => __( 'Emails', 'smart-login' ), 'icon' => 'cloud' ),
				),
			),
		);

		if ( $wc_active ) {
			$nav[1]['items'][] = array( 'key' => 'woocommerce', 'label' => __( 'WooCommerce', 'smart-login' ), 'icon' => 'ext' );
		}

		$nav[1]['items'][] = array( 'key' => 'advanced', 'label' => __( 'Advanced', 'smart-login' ), 'icon' => 'wrench' );

		$panels = array(
			'dashboard'    => $this->panel_dashboard(),
			'users'        => $this->panel_users(),
			'general'      => $this->panel_general(),
			'verification' => $this->panel_verification(),
			'security'     => $this->panel_security(),
			'emails'       => $this->panel_emails(),
		);

		if ( $wc_active ) {
			$panels['woocommerce'] = $this->panel_woocommerce();
		}

		$panels['advanced'] = $this->panel_advanced();

		parent::__construct(
			array(
				'slug'        => 'smart-login',
				'menu_title'  => __( 'Smart Login', 'smart-login' ),
				'page_title'  => __( 'Smart Login Settings', 'smart-login' ),
				'brand'       => 'Smart Login',
				'version'     => SML_VERSION,
				'menu_icon'   => 'dashicons-lock',
				'position'    => 81,
				'assets_url'  => SML_PLUGIN_URL . 'admin/ui-kit/assets/',
				'option_name' => SML_Settings::OPTION_NAME,
				'defaults'    => SML_Settings::defaults(),
				'rest_ns'     => 'smart-login/v1',
				'help_html'   => sprintf(
					/* translators: %s: shortcode */
					esc_html__( 'Place %s on any page to render the login/register form.', 'smart-login' ),
					'<code>[smart_login_form]</code>'
				),
				'nav'         => $nav,
				'panels'      => $panels,
			)
		);
	}

	/* ── panel definitions ───────────────────────────────────────────── */

	/**
	 * The 'html' value here is deliberately left empty and filled in only
	 * inside render() — this whole config array is built unconditionally
	 * in the constructor (SML_Admin_Page is now constructed on every
	 * request, admin or not, so its REST route is always registered; see
	 * SML_Loader), so computing the dashboard's DB queries here would run
	 * them on every single front-end page load site-wide instead of only
	 * when this admin screen is actually being viewed.
	 */
	protected function panel_dashboard() {
		return array(
			'title'    => __( 'Dashboard', 'smart-login' ),
			'desc'     => __( 'Sign-ups, verification, and recent activity at a glance.', 'smart-login' ),
			'sections' => array(
				array(
					'fields' => array(
						array( 'type' => 'html', 'html' => '' ),
					),
				),
			),
		);
	}

	/**
	 * render() only ever runs from the actual admin_menu page callback, so
	 * this is the right (and only) place to compute the dashboard's
	 * queries — see the note on panel_dashboard().
	 */
	public function render() {
		$this->cfg['panels']['dashboard']['sections'][0]['fields'][0]['html'] = $this->dashboard_html();
		$this->cfg['panels']['users']['sections'][0]['fields'][0]['html']     = $this->users_html();
		parent::render();
	}

	protected function panel_general() {
		return array(
			'title'    => __( 'General', 'smart-login' ),
			'desc'     => __( 'Core behaviour of the login and registration form.', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'Behaviour', 'smart-login' ),
					'fields'  => array(
						array(
							'type'  => 'toggle',
							'name'  => 'enable_registration',
							'label' => __( 'Enable registration', 'smart-login' ),
							'desc'  => __( 'When off, the shortcode renders a login-only form and hides the Register tab.', 'smart-login' ),
						),
						array(
							'type'  => 'toggle',
							'name'  => 'enable_login_form',
							'label' => __( 'Enable custom login form', 'smart-login' ),
							'desc'  => __( 'Lets an admin disable the entire front-end form (shortcode renders nothing) without deactivating the plugin.', 'smart-login' ),
						),
						array(
							'type'    => 'select',
							'name'    => 'default_role',
							'label'   => __( 'Default role for new users', 'smart-login' ),
							'options' => $this->role_options(),
						),
						array(
							'type'  => 'page_select',
							'name'  => 'login_redirect_page_id',
							'label' => __( 'Redirect after login', 'smart-login' ),
							'desc'  => __( 'Where users land after logging in, verifying their email, or resetting their password — unless a specific destination was already requested (e.g. Cart/Checkout). Leave unset to use the site home page. Tip: point this at your My Account page.', 'smart-login' ),
						),
					),
				),
				array(
					'heading' => __( 'Button appearance', 'smart-login' ),
					'desc'    => __( 'Colors for the primary button (Log In, Create Account, Verify). Secondary buttons and links are unaffected.', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'color', 'name' => 'button_bg_color', 'label' => __( 'Button background color', 'smart-login' ) ),
						array( 'type' => 'color', 'name' => 'button_text_color', 'label' => __( 'Button text color', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Shortcode & form page', 'smart-login' ),
					'fields'  => array(
						array(
							'type' => 'html',
							'html' => '<div class="apx-row"><label>' . esc_html__( 'Shortcode', 'smart-login' ) . '</label>'
								. '<code id="sml-shortcode-ref" style="padding:6px 10px;border:1px solid var(--apx-border,#ddd);border-radius:6px;">[smart_login_form]</code> '
								. '<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(\'[smart_login_form]\');this.textContent=\'' . esc_js( __( 'Copied!', 'smart-login' ) ) . '\';">' . esc_html__( 'Copy', 'smart-login' ) . '</button></div>',
						),
						array(
							'type'  => 'page_select',
							'name'  => 'login_page_id',
							'label' => __( 'Login page', 'smart-login' ),
							'desc'  => __( 'The page that contains the [smart_login_form] shortcode. Password-reset and email-verification links point here so they open the form. Also used as the redirect target for login-gated Cart/Checkout. If left unset, the plugin tries to find the page automatically.', 'smart-login' ),
						),
					),
				),
				array(
					'heading' => __( 'Allowed countries for registration', 'smart-login' ),
					'desc'    => __( 'Only checked countries appear in the mobile-number country selector on the registration form; registering with any other country is rejected server-side too. Enabled by default: United States, Canada, and Western Europe.', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'countries', 'name' => 'allowed_countries', 'label' => __( 'Allowed countries', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Disposable email protection', 'smart-login' ),
					'desc'    => __( 'Rejects registration when the email address\'s domain matches the list below — reduces fake/fraudulent sign-ups from throwaway inboxes.', 'smart-login' ),
					'fields'  => array(
						array(
							'type'  => 'toggle',
							'name'  => 'block_disposable_emails',
							'label' => __( 'Block disposable/temporary email addresses', 'smart-login' ),
						),
						array(
							'type'        => 'list',
							'name'        => 'disposable_email_domains',
							'label'       => __( 'Blocked domains', 'smart-login' ),
							'help'        => __( 'One domain per line, e.g. mailinator.com — no @ sign.', 'smart-login' ),
							'placeholder' => "mailinator.com\nguerrillamail.com\n10minutemail.com",
						),
					),
				),
			),
		);
	}

	protected function panel_verification() {
		return array(
			'title'    => __( 'Verification', 'smart-login' ),
			'desc'     => __( 'Controls how new accounts confirm their email address.', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'Code & link', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'number', 'name' => 'code_length', 'label' => __( 'Code length', 'smart-login' ), 'min' => 4, 'max' => 8, 'desc' => __( 'Number of digits in the verification code.', 'smart-login' ) ),
						array( 'type' => 'number', 'name' => 'code_expiry_minutes', 'label' => __( 'Code expiry (minutes)', 'smart-login' ), 'min' => 1, 'max' => 1440, 'desc' => __( 'Drives both the backend TTL and the front-end countdown.', 'smart-login' ) ),
						array( 'type' => 'number', 'name' => 'link_expiry_minutes', 'label' => __( 'Link expiry (minutes)', 'smart-login' ), 'min' => 1, 'max' => 10080, 'desc' => __( 'Independent from the code expiry — the link uses its own signed, single-use token.', 'smart-login' ) ),
						array( 'type' => 'number', 'name' => 'resend_cooldown_seconds', 'label' => __( 'Resend cooldown (seconds)', 'smart-login' ), 'min' => 10, 'max' => 3600, 'desc' => __( 'The Resend button is disabled client-side for this long, and the server rejects early resend requests too.', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'verify_intro_text', 'label' => __( 'Verify page intro text', 'smart-login' ), 'desc' => __( 'Shown above the code input on the verify step.', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Access before verification', 'smart-login' ),
					'fields'  => array(
						array(
							'type'    => 'select',
							'name'    => 'block_until_verified',
							'label'   => __( 'Block login until verified', 'smart-login' ),
							'options' => array(
								'block'  => __( 'Block completely', 'smart-login' ),
								'notice' => __( 'Allow with notice', 'smart-login' ),
								'grace'  => __( 'Allow for a grace period', 'smart-login' ),
							),
						),
						array( 'type' => 'number', 'name' => 'grace_period_days', 'label' => __( 'Grace period before block (days)', 'smart-login' ), 'min' => 0, 'max' => 365, 'desc' => __( 'Only used when "Allow for a grace period" is selected above. 0 blocks immediately.', 'smart-login' ) ),
						array(
							'type'    => 'select',
							'name'    => 'legacy_unverified_prompt',
							'label'   => __( 'Pre-existing unverified accounts', 'smart-login' ),
							'options' => array(
								'none'       => __( 'No verification needed — log them straight in', 'smart-login' ),
								'email_only' => __( 'Send a reminder email only', 'smart-login' ),
								'prompt'     => __( 'Show a verification screen they can skip', 'smart-login' ),
							),
							'desc'    => __( 'How to treat accounts that existed before Smart Login was installed and never verified their email. They are never blocked. Only new registrations are ever required to verify. "Show a verification screen" adds the code entry on login with a "Not now" button.', 'smart-login' ),
						),
					),
				),
			),
		);
	}

	protected function panel_security() {
		return array(
			'title'    => __( 'Security', 'smart-login' ),
			'desc'     => __( 'Lockouts, bot protection, and rate limiting.', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'Lockouts', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'number', 'name' => 'max_code_attempts', 'label' => __( 'Max code attempts before lockout', 'smart-login' ), 'min' => 1, 'max' => 20 ),
						array( 'type' => 'number', 'name' => 'login_lockout_threshold', 'label' => __( 'Login attempt lockout threshold', 'smart-login' ), 'min' => 1, 'max' => 20 ),
						array( 'type' => 'number', 'name' => 'lockout_duration_minutes', 'label' => __( 'Lockout duration (minutes)', 'smart-login' ), 'min' => 1, 'max' => 1440 ),
					),
				),
				array(
					'heading' => __( 'Login access', 'smart-login' ),
					'desc'    => __( 'Restrict which user roles are permitted to log in through this form. Leave empty to allow every role (default). A user whose role isn\'t selected is signed back out immediately with a clear message — this only affects the [smart_login_form] form, not wp-login.php.', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'roles', 'name' => 'allowed_login_roles', 'label' => __( 'Roles allowed to log in', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Bot protection', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'toggle', 'name' => 'enable_honeypot', 'label' => __( 'Enable honeypot field', 'smart-login' ), 'desc' => __( 'Adds a hidden field and a time-trap; submissions filled or submitted too fast are rejected as bots. This is a minor speed bump — the time-trap is a client-supplied value a scripted attacker can back-date. For real coverage on a public site, also select a bot-protection provider below.', 'smart-login' ) ),
						array(
							'type'    => 'select',
							'name'    => 'bot_protection_provider',
							'label'   => __( 'Bot protection provider', 'smart-login' ),
							'options' => array(
								'none'       => __( 'None', 'smart-login' ),
								'recaptcha'  => __( 'Google reCAPTCHA v3', 'smart-login' ),
								'turnstile'  => __( 'Cloudflare Turnstile', 'smart-login' ),
							),
						),
						array( 'type' => 'text', 'name' => 'bot_site_key', 'label' => __( 'Site key', 'smart-login' ), 'desc' => __( 'Used by whichever provider is selected above.', 'smart-login' ) ),
						array( 'type' => 'password', 'name' => 'bot_secret_key', 'label' => __( 'Secret key', 'smart-login' ), 'secret' => true, 'desc' => __( 'Stored with autoload disabled and never rendered back in plaintext. Leave blank to keep the current value.', 'smart-login' ) ),
						array( 'type' => 'decimal', 'name' => 'recaptcha_threshold', 'label' => __( 'reCAPTCHA v3 score threshold', 'smart-login' ), 'min' => 0, 'max' => 1, 'desc' => __( 'Only used when the provider is reCAPTCHA v3 (Turnstile is pass/fail).', 'smart-login' ) ),
					),
				),
			),
		);
	}

	protected function panel_emails() {
		return array(
			'title'    => __( 'Emails', 'smart-login' ),
			'desc'     => __( 'Sender identity and message templates. Available placeholders: {code}, {link}, {user}, {site_name}, {expiry_minutes}', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'Sender', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'from_name', 'label' => __( 'From name', 'smart-login' ) ),
						array( 'type' => 'text', 'name' => 'from_email', 'label' => __( 'From email', 'smart-login' ) ),
						array( 'type' => 'html', 'html' => $this->test_email_html() ),
					),
				),
				array(
					'heading' => __( 'Branding & footer', 'smart-login' ),
					'desc'    => __( 'Shown at the top and bottom of every email this plugin sends. {site_name} is available in the footer text.', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'email_footer_name', 'label' => __( 'Site name in email', 'smart-login' ), 'desc' => __( 'Heading shown at the top of the email and in the footer line. Leave blank to use the WordPress site title.', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'email_footer_text', 'label' => __( 'Extra footer text', 'smart-login' ), 'desc' => __( 'Optional lines below the automated-message notice — company name, postal address, support contact, etc. Basic HTML and line breaks are allowed.', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Verification email', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'verify_subject', 'label' => __( 'Subject', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'verify_body', 'label' => __( 'Body', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Resend email', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'resend_subject', 'label' => __( 'Subject', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'resend_body', 'label' => __( 'Body', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Welcome email', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'welcome_subject', 'label' => __( 'Subject', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'welcome_body', 'label' => __( 'Body', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Password reset email', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'text', 'name' => 'reset_subject', 'label' => __( 'Subject', 'smart-login' ) ),
						array( 'type' => 'textarea', 'name' => 'reset_body', 'label' => __( 'Body', 'smart-login' ) ),
					),
				),
			),
		);
	}

	protected function panel_woocommerce() {
		return array(
			'title'    => __( 'WooCommerce', 'smart-login' ),
			'desc'     => __( 'Optional integration with the WooCommerce My Account page.', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'My Account forms', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'toggle', 'name' => 'wc_replace_login', 'label' => __( 'Replace My Account login form', 'smart-login' ), 'desc' => __( 'Swaps WooCommerce\'s default login form for the Smart Login form.', 'smart-login' ) ),
						array( 'type' => 'toggle', 'name' => 'wc_replace_register', 'label' => __( 'Replace My Account register form', 'smart-login' ), 'desc' => __( 'Swaps WooCommerce\'s default register form and applies the same verification flow.', 'smart-login' ) ),
					),
				),
				array(
					'heading' => __( 'Require login for these pages', 'smart-login' ),
					'desc'    => __( 'A visitor who isn\'t logged in is redirected to the Login page (set under General → "Shortcode & form page"), then sent back to the page they wanted once they log in or verify their email. If the Login page isn\'t set, this protection does nothing rather than risk locking visitors out.', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'toggle', 'name' => 'require_login_cart', 'label' => __( 'Require login: Cart', 'smart-login' ) ),
						array( 'type' => 'toggle', 'name' => 'require_login_checkout', 'label' => __( 'Require login: Checkout', 'smart-login' ) ),
					),
				),
			),
		);
	}

	protected function panel_advanced() {
		return array(
			'title'    => __( 'Advanced', 'smart-login' ),
			'desc'     => __( 'Uninstall behaviour and diagnostics.', 'smart-login' ),
			'sections' => array(
				array(
					'heading' => __( 'Danger zone', 'smart-login' ),
					'fields'  => array(
						array(
							'type'  => 'toggle',
							'name'  => 'delete_data_on_uninstall',
							'label' => __( 'Delete plugin data on uninstall', 'smart-login' ),
							'desc'  => __( 'When on, deleting the plugin from Plugins → Installed Plugins permanently drops the verification table and settings. This cannot be undone.', 'smart-login' ),
						),
					),
				),
				array(
					'heading' => __( 'Diagnostics', 'smart-login' ),
					'fields'  => array(
						array(
							'type'  => 'toggle',
							'name'  => 'enable_debug_log',
							'label' => __( 'Enable debug log', 'smart-login' ),
							'desc'  => __( 'Writes to wp-content/uploads/smart-login-logs/. Codes and secrets are never written in plaintext.', 'smart-login' ),
						),
					),
				),
				array(
					'heading' => __( 'Import / export settings', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'html', 'html' => $this->import_export_html() ),
					),
				),
			),
		);
	}

	/**
	 * Roles offered as the "default role for new users". Roles that can
	 * administer the site (manage_options / edit_users) are excluded — a
	 * self-registration default of Administrator is a privilege-escalation
	 * footgun, and SML_Settings::safe_default_role() enforces the same rule
	 * server-side regardless of what is stored.
	 *
	 * @return array<string,string>
	 */
	protected function role_options() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();
			if ( ! empty( $caps['manage_options'] ) || ! empty( $caps['edit_users'] ) ) {
				continue;
			}
			$roles[ $key ] = translate_user_role( $role['name'] );
		}
		if ( ! $roles ) {
			$roles['subscriber'] = translate_user_role( 'Subscriber' );
		}
		return $roles;
	}

	/* ── dashboard ───────────────────────────────────────────────────── */

	/**
	 * Builds the whole dashboard panel body as one HTML block (KPI cards,
	 * sign-up chart, recent-logins table) — rendered as a single 'html'
	 * field since the kit's schema-driven panels don't otherwise support
	 * fully custom layouts.
	 *
	 * @return string
	 */
	protected function dashboard_html() {
		$s = $this->dash_stats();

		ob_start();
		?>
		<div class="apx-kpi-strip" style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:16px;">
			<?php
			echo $this->metric_card( __( 'All users', 'smart-login' ), number_format_i18n( $s['total'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->metric_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Verified', 'smart-login' ),
				number_format_i18n( $s['verified'] ),
				'#12805c',
				$s['total'] > 0 ? sprintf( '%d%%', round( $s['verified'] / $s['total'] * 100 ) ) : ''
			);
			echo $this->metric_card( __( 'Pending verification', 'smart-login' ), number_format_i18n( $s['pending'] ), '#92590a' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->metric_card( __( 'Legacy accounts', 'smart-login' ), number_format_i18n( $s['legacy'] ), '#7a7a85' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<div class="apx-kpi-strip" style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:28px;">
			<div class="apx-metric" style="flex:2;min-width:280px;padding:15px 18px;background:#fcfcfd;border:1px solid #e9e9ee;border-top:3px solid #4d5fd6;border-radius:10px;">
				<div style="font-size:11.5px;font-weight:600;color:#8b8b95;text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'New sign-ups', 'smart-login' ); ?></div>
				<div style="display:flex;gap:30px;margin-top:8px;">
					<div><div style="font-size:23px;font-weight:700;color:#3a3f4b;line-height:1.15;"><?php echo esc_html( number_format_i18n( $s['new_24h'] ) ); ?></div><div style="font-size:11px;color:#8b8b95;"><?php esc_html_e( 'last 24h', 'smart-login' ); ?></div></div>
					<div><div style="font-size:23px;font-weight:700;color:#3a3f4b;line-height:1.15;"><?php echo esc_html( number_format_i18n( $s['new_7d'] ) ); ?></div><div style="font-size:11px;color:#8b8b95;"><?php esc_html_e( 'last 7 days', 'smart-login' ); ?></div></div>
					<div><div style="font-size:23px;font-weight:700;color:#3a3f4b;line-height:1.15;"><?php echo esc_html( number_format_i18n( $s['new_30d'] ) ); ?></div><div style="font-size:11px;color:#8b8b95;"><?php esc_html_e( 'last 30 days', 'smart-login' ); ?></div></div>
				</div>
			</div>
			<?php echo $this->metric_card( __( 'Logins (24h)', 'smart-login' ), number_format_i18n( $s['logins_24h'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<h2 class="apx-section-h">
			<?php esc_html_e( 'Sign-ups — last 6 months', 'smart-login' ); ?>
			<span style="font-weight:400;color:#7a7a85;font-size:13px;">
				<?php
				$chart_total = array_sum( $s['chart'] );
				/* translators: %s: number of sign-ups */
				echo esc_html( '(' . sprintf( _n( '%s total', '%s total', $chart_total, 'smart-login' ), number_format_i18n( $chart_total ) ) . ')' );
				?>
			</span>
		</h2>
		<?php echo $this->render_signup_chart( $s['chart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:26px;margin-top:28px;">
			<div>
				<h2 class="apx-section-h"><?php esc_html_e( 'Recent sign-ups', 'smart-login' ); ?></h2>
				<?php echo $this->activity_table( $s['recent_signups'], __( 'Registered', 'smart-login' ), 'registered' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div>
				<h2 class="apx-section-h"><?php esc_html_e( 'Recent logins', 'smart-login' ); ?></h2>
				<?php echo $this->activity_table( $s['recent_logins'], __( 'Last login', 'smart-login' ), 'when' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>

		<h2 class="apx-section-h" style="margin-top:28px;"><?php esc_html_e( 'Most password-reset requests', 'smart-login' ); ?></h2>
		<div class="apx-table-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'User', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Email', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Requests', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Last request', 'smart-login' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( ! $s['top_reset'] ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No password-reset requests yet.', 'smart-login' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $s['top_reset'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><strong><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></strong></td>
							<td><?php echo esc_html( $row['when'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * One KPI card. $suffix is a small dimmed figure after the value
	 * (e.g. a percentage).
	 *
	 * @param string $label
	 * @param string $value
	 * @param string $color
	 * @param string $suffix
	 * @return string
	 */
	protected function metric_card( $label, $value, $color = '#3a3f4b', $suffix = '' ) {
		return sprintf(
			'<div class="apx-metric" style="flex:1;min-width:150px;padding:15px 18px;background:#fcfcfd;border:1px solid #e9e9ee;border-top:3px solid %2$s;border-radius:10px;">'
				. '<div style="font-size:11.5px;font-weight:600;color:#8b8b95;text-transform:uppercase;letter-spacing:.05em;">%1$s</div>'
				. '<div style="font-size:27px;font-weight:700;color:%2$s;margin-top:5px;line-height:1.15;">%3$s%4$s</div>'
				. '</div>',
			esc_html( $label ),
			esc_attr( $color ),
			esc_html( $value ),
			'' !== $suffix ? ' <span style="font-size:13px;font-weight:500;color:#8b8b95;">' . esc_html( $suffix ) . '</span>' : ''
		);
	}

	/**
	 * A compact activity table: User / Email / Phone / <time column> / Status.
	 *
	 * @param array  $rows       Each: name, email, phone, status, plus the $time_key value.
	 * @param string $time_label Header for the time column.
	 * @param string $time_key   Row key holding the pre-formatted time string.
	 * @return string
	 */
	protected function activity_table( array $rows, $time_label, $time_key ) {
		ob_start();
		?>
		<div class="apx-table-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'User', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Email', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'smart-login' ); ?></th>
					<th><?php echo esc_html( $time_label ); ?></th>
					<th><?php esc_html_e( 'Status', 'smart-login' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'smart-login' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><?php echo esc_html( $row['phone'] ); ?></td>
							<td><?php echo esc_html( isset( $row[ $time_key ] ) ? $row[ $time_key ] : '' ); ?></td>
							<td>
								<?php if ( 'verified' === $row['status'] ) : ?>
									<span style="color:#12805c;">● <?php esc_html_e( 'Verified', 'smart-login' ); ?></span>
								<?php elseif ( 'pending' === $row['status'] ) : ?>
									<span style="color:#92590a;">● <?php esc_html_e( 'Pending', 'smart-login' ); ?></span>
								<?php else : ?>
									<span style="color:#9a9aa2;">— <?php esc_html_e( 'Legacy', 'smart-login' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Everything the dashboard shows, gathered in one place. Only ever
	 * called from render() — see the note on panel_dashboard().
	 *
	 * @return array<string,mixed>
	 */
	protected function dash_stats() {
		global $wpdb;

		$total    = (int) count_users()['total_users'];
		$verified = $this->count_verified_users();
		$pending  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sml_email_verified' AND meta_value = '0'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
		$legacy = max( 0, $total - $verified - $pending );

		$since = function ( $sql_interval ) use ( $wpdb ) {
			return (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$sql_interval})"
			);
		};

		$logins_24h = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sml_last_login' AND (meta_value + 0) >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				time() - DAY_IN_SECONDS
			)
		);

		return array(
			'total'          => $total,
			'verified'       => $verified,
			'pending'        => $pending,
			'legacy'         => $legacy,
			'new_24h'        => $since( '1 DAY' ),
			'new_7d'         => $since( '7 DAY' ),
			'new_30d'        => $since( '30 DAY' ),
			'logins_24h'     => $logins_24h,
			'chart'          => $this->signups_last_6_months(),
			'recent_signups' => $this->recent_signups( 8 ),
			'recent_logins'  => $this->recent_logins( 8 ),
			'top_reset'      => $this->top_reset_requesters( 8 ),
		);
	}

	/**
	 * Accounts that have requested the most password resets.
	 *
	 * @param int $limit
	 * @return array<int,array{name:string,email:string,count:int,when:string}>
	 */
	protected function top_reset_requesters( $limit = 8 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value AS c FROM {$wpdb->usermeta} WHERE meta_key = 'sml_reset_requests' AND ( meta_value + 0 ) > 0 ORDER BY ( meta_value + 0 ) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$limit
			)
		);

		$ids = array_map(
			function ( $r ) {
				return (int) $r->user_id;
			},
			(array) $rows
		);
		if ( $ids ) {
			cache_users( $ids );
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r->user_id );
			if ( ! $user ) {
				continue;
			}
			$last = (int) get_user_meta( $user->ID, 'sml_reset_requested_at', true );
			$out[] = array(
				'name'  => $user->display_name ? $user->display_name : trim( $user->first_name . ' ' . $user->last_name ),
				'email' => $user->user_email,
				'count' => (int) $r->c,
				/* translators: %s: human-readable time difference, e.g. "3 hours" */
				'when'  => $last ? sprintf( __( '%s ago', 'smart-login' ), human_time_diff( $last, time() ) ) : '&mdash;',
			);
		}

		return $out;
	}

	/**
	 * @param int $limit
	 * @return array<int,array{name:string,email:string,phone:string,registered:string,status:string}>
	 */
	protected function recent_signups( $limit = 8 ) {
		$q = new WP_User_Query( array(
			'number'  => $limit,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => 'ID',
		) );

		$ids = array_map( 'intval', (array) $q->get_results() );
		if ( $ids ) {
			cache_users( $ids );
		}

		$out = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			$out[] = array(
				'name'       => $user->display_name ? $user->display_name : trim( $user->first_name . ' ' . $user->last_name ),
				'email'      => $user->user_email,
				'phone'      => $this->user_phone( $id ),
				'registered' => $user->user_registered
					/* translators: %s: human-readable time difference, e.g. "3 hours" */
					? sprintf( __( '%s ago', 'smart-login' ), human_time_diff( strtotime( $user->user_registered . ' UTC' ), time() ) )
					: '',
				'status'     => $this->user_status( $id ),
			);
		}

		return $out;
	}

	/** sml_phone, falling back to the WooCommerce billing phone. */
	protected function user_phone( $user_id ) {
		$phone = (string) get_user_meta( $user_id, 'sml_phone', true );
		return '' !== $phone ? $phone : (string) get_user_meta( $user_id, 'billing_phone', true );
	}

	/** 'verified' | 'pending' | 'legacy'. */
	protected function user_status( $user_id ) {
		if ( SML_Verification::is_verified( $user_id ) ) {
			return 'verified';
		}
		return SML_Verification::has_verification_record( $user_id ) ? 'pending' : 'legacy';
	}

	/* ── Users panel ─────────────────────────────────────────────────── */

	/**
	 * The 'html' is filled in render() (see panel_dashboard note) so the
	 * per-user queries only run when this screen is actually viewed.
	 */
	protected function panel_users() {
		return array(
			'title'    => __( 'Users', 'smart-login' ),
			'desc'     => __( 'Every account, with data pulled together from Smart Login and (when active) WooCommerce. Search by name, email, address or phone.', 'smart-login' ),
			'sections' => array(
				array(
					'fields' => array(
						array( 'type' => 'html', 'html' => '' ),
					),
				),
			),
		);
	}

	/** Per-page row count for the Users table. */
	const USERS_PER_PAGE = 25;

	/**
	 * Server-rendered (no REST/JS) users table: search box, paged results,
	 * and columns aggregated from Smart Login meta + WooCommerce.
	 */
	protected function users_html() {
		$search   = isset( $_GET['sml_uq'] ) ? sanitize_text_field( wp_unslash( $_GET['sml_uq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = isset( $_GET['sml_upg'] ) ? max( 1, (int) $_GET['sml_upg'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = self::USERS_PER_PAGE;
		$wc       = function_exists( 'wc_get_customer_order_count' );

		$found = $this->users_query( $search, $page, $per_page );
		$ids   = $found['ids'];
		$total = $found['total'];
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		if ( $ids ) {
			cache_users( $ids );
		}

		$base = admin_url( 'admin.php?page=smart-login' );
		$fmt  = get_option( 'date_format' );

		ob_start();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="apx-row" style="align-items:center;gap:8px;margin-bottom:12px;">
			<input type="hidden" name="page" value="smart-login">
			<label for="sml-uq"><?php esc_html_e( 'Search', 'smart-login' ); ?></label>
			<input type="search" id="sml-uq" name="sml_uq" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email, address, phone…', 'smart-login' ); ?>" style="min-width:320px">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'smart-login' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a class="button-link" href="<?php echo esc_url( $base . '#users' ); ?>"><?php esc_html_e( 'Clear', 'smart-login' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="apx-table-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'User', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Email', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Address', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Registered', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Last login', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Verified', 'smart-login' ); ?></th>
					<th><?php esc_html_e( 'Resets', 'smart-login' ); ?></th>
					<?php if ( $wc ) : ?>
						<th><?php esc_html_e( 'Orders', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Spent', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Last order', 'smart-login' ); ?></th>
					<?php endif; ?>
				</tr></thead>
				<tbody>
					<?php if ( ! $ids ) : ?>
						<tr><td colspan="<?php echo $wc ? 11 : 8; ?>"><?php esc_html_e( 'No matching users.', 'smart-login' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $ids as $id ) :
						$user = get_userdata( $id );
						if ( ! $user ) {
							continue;
						}
						$status  = $this->user_status( $id );
						$last_ts = (int) get_user_meta( $id, 'sml_last_login', true );
						$resets  = (int) get_user_meta( $id, 'sml_reset_requests', true );
						$summary = $wc ? $this->wc_customer_summary( $id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $user->display_name ? $user->display_name : trim( $user->first_name . ' ' . $user->last_name ) ); ?></td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo esc_html( $this->user_phone( $id ) ?: '—' ); ?></td>
							<td style="max-width:240px;"><?php echo esc_html( $this->billing_address_line( $id ) ?: '—' ); ?></td>
							<td><?php echo esc_html( $user->user_registered ? date_i18n( $fmt, strtotime( $user->user_registered . ' UTC' ) ) : '' ); ?></td>
							<td><?php echo $last_ts ? esc_html( sprintf( __( '%s ago', 'smart-login' ), human_time_diff( $last_ts, time() ) ) ) : '—'; ?></td>
							<td>
								<?php if ( 'verified' === $status ) : ?>
									<span style="color:#12805c;">&#9679; <?php esc_html_e( 'Verified', 'smart-login' ); ?></span>
								<?php elseif ( 'pending' === $status ) : ?>
									<span style="color:#92590a;">&#9679; <?php esc_html_e( 'Pending', 'smart-login' ); ?></span>
								<?php else : ?>
									<span style="color:#9a9aa2;">— <?php esc_html_e( 'Legacy', 'smart-login' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo $resets ? esc_html( number_format_i18n( $resets ) ) : '—'; ?></td>
							<?php if ( $wc ) : ?>
								<td><?php echo esc_html( number_format_i18n( $summary['orders'] ) ); ?></td>
								<td><?php echo wp_kses_post( $summary['spent'] ); ?></td>
								<td><?php echo esc_html( $summary['last_order'] ?: '—' ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div style="display:flex;align-items:center;gap:14px;margin-top:12px;">
			<?php
			$q = array( 'page' => 'smart-login' );
			if ( '' !== $search ) {
				$q['sml_uq'] = $search;
			}
			$prev_url = add_query_arg( array_merge( $q, array( 'sml_upg' => max( 1, $page - 1 ) ) ), admin_url( 'admin.php' ) ) . '#users';
			$next_url = add_query_arg( array_merge( $q, array( 'sml_upg' => min( $pages, $page + 1 ) ) ), admin_url( 'admin.php' ) ) . '#users';
			?>
			<a class="button<?php echo $page <= 1 ? ' disabled" aria-disabled="true' : ''; ?>" href="<?php echo esc_url( $prev_url ); ?>">&larr; <?php esc_html_e( 'Prev', 'smart-login' ); ?></a>
			<a class="button<?php echo $page >= $pages ? ' disabled" aria-disabled="true' : ''; ?>" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'smart-login' ); ?> &rarr;</a>
			<span style="font-size:13px;color:#7a7a85;">
				<?php
				printf(
					/* translators: 1: total users 2: current page 3: total pages */
					esc_html__( 'Total: %1$s · page %2$d/%3$d', 'smart-login' ),
					esc_html( number_format_i18n( $total ) ),
					(int) $page,
					(int) $pages
				);
				?>
			</span>
		</div>

		<script>
		// A search submit reloads the page without the #users fragment; re-open
		// this panel once the kit has booted.
		window.addEventListener('load', function () {
			var p = new URLSearchParams(window.location.search);
			if (p.has('sml_uq') || p.has('sml_upg')) {
				var nav = document.querySelector('[data-apx-tab="users"]');
				if (nav && !nav.classList.contains('active')) { nav.click(); }
			}
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Resolves the user id set + total for the Users table. Empty search =
	 * newest-registered first; a search unions the core columns with a
	 * usermeta LIKE over phone / name / billing address fields.
	 *
	 * @param string $search
	 * @param int    $page
	 * @param int    $per_page
	 * @return array{ids:int[],total:int}
	 */
	protected function users_query( $search, $page, $per_page ) {
		global $wpdb;

		if ( '' === $search ) {
			$q = new WP_User_Query(
				array(
					'number'      => $per_page,
					'paged'       => $page,
					'orderby'     => 'registered',
					'order'       => 'DESC',
					'fields'      => 'ID',
					'count_total' => true,
				)
			);
			return array(
				'ids'   => array_map( 'intval', (array) $q->get_results() ),
				'total' => (int) $q->get_total(),
			);
		}

		$core = new WP_User_Query(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'fields'         => 'ID',
				'number'         => 500,
			)
		);
		$core_ids = array_map( 'intval', (array) $core->get_results() );

		$meta_keys    = array( 'sml_phone', 'first_name', 'last_name', 'billing_phone', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country' );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$meta_ids     = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders) AND meta_value LIKE %s LIMIT 500",
				array_merge( $meta_keys, array( '%' . $wpdb->esc_like( $search ) . '%' ) )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$all = array_values( array_unique( array_merge( $core_ids, array_map( 'intval', (array) $meta_ids ) ) ) );

		$slice = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
		if ( $slice ) {
			$oq    = new WP_User_Query(
				array(
					'include' => $slice,
					'orderby' => 'registered',
					'order'   => 'DESC',
					'fields'  => 'ID',
					'number'  => $per_page,
				)
			);
			$slice = array_map( 'intval', (array) $oq->get_results() );
		}

		return array( 'ids' => $slice, 'total' => count( $all ) );
	}

	/** WooCommerce billing address as one line, or '' when empty. */
	protected function billing_address_line( $user_id ) {
		$parts = array();
		foreach ( array( 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country' ) as $key ) {
			$val = trim( (string) get_user_meta( $user_id, $key, true ) );
			if ( '' !== $val ) {
				$parts[] = $val;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Order count, lifetime spend (as wc_price() HTML) and last-order date
	 * for a customer.
	 *
	 * @param int $user_id
	 * @return array{orders:int,spent:string,last_order:string}
	 */
	protected function wc_customer_summary( $user_id ) {
		$orders = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user_id ) : 0;
		$spent  = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $user_id ) : 0.0;

		$last = '';
		if ( function_exists( 'wc_get_customer_last_order' ) ) {
			$order = wc_get_customer_last_order( $user_id );
			if ( $order && is_a( $order, 'WC_Order' ) && $order->get_date_created() ) {
				$last = date_i18n( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() );
			}
		}

		return array(
			'orders'     => $orders,
			'spent'      => function_exists( 'wc_price' ) ? wc_price( $spent ) : number_format_i18n( $spent, 2 ),
			'last_order' => $last,
		);
	}

	/**
	 * @return int Number of users with sml_email_verified = 1.
	 */
	protected function count_verified_users() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'sml_email_verified' AND meta_value = '1'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
	}

	/**
	 * @return array<string,int> Month label (e.g. "Apr") => sign-up count, oldest first, 6 entries.
	 */
	protected function signups_last_6_months() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT DATE_FORMAT(user_registered, '%Y-%m') AS ym, COUNT(*) AS c
			 FROM {$wpdb->users}
			 WHERE user_registered >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
			 GROUP BY ym", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			OBJECT_K
		);

		$data = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$ts    = strtotime( "-{$i} months" );
			$key   = gmdate( 'Y-m', $ts );
			$label = date_i18n( 'M', $ts );

			$data[ $label ] = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->c : 0;
		}

		return $data;
	}

	/**
	 * @param int $limit
	 * @return array<int,array{name:string,email:string,phone:string,when:string,status:string}>
	 */
	protected function recent_logins( $limit = 8 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sml_last_login' ORDER BY (meta_value + 0) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$limit
			)
		);

		$ids = array_map( function ( $r ) {
			return (int) $r->user_id;
		}, (array) $rows );
		if ( $ids ) {
			cache_users( $ids );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->user_id );
			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'name'   => $user->display_name ? $user->display_name : trim( $user->first_name . ' ' . $user->last_name ),
				'email'  => $user->user_email,
				'phone'  => $this->user_phone( (int) $row->user_id ),
				/* translators: %s: human-readable time difference, e.g. "3 hours" */
				'when'   => sprintf( __( '%s ago', 'smart-login' ), human_time_diff( (int) $row->meta_value, time() ) ),
				'status' => $this->user_status( (int) $row->user_id ),
			);
		}

		return $out;
	}

	/**
	 * A dependency-free inline SVG bar chart — no charting library needed
	 * for six bars, and it keeps the plugin free of external/bundled JS
	 * assets for something this simple.
	 *
	 * @param array<string,int> $data
	 * @return string
	 */
	protected function render_signup_chart( array $data ) {
		$max     = max( 1, max( $data ) );
		$bar_w   = 46;
		$gap     = 30;
		$top_pad = 24; // headroom so the tallest bar's value label never clips
		$chart_h = 132;
		$n       = count( $data );
		$width   = max( 1, $n * ( $bar_w + $gap ) );
		$height  = $top_pad + $chart_h + 26;
		$baseline = $top_pad + $chart_h;

		$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

		$svg = sprintf(
			'<svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="xMinYMid meet" role="img" style="width:100%%;max-width:560px;height:auto;font-family:%3$s;">',
			$width,
			$height,
			$font
		);

		// Soft gradient fill — chic, and clearly not "solid black".
		$svg .= '<defs><linearGradient id="sml-bar" x1="0" y1="0" x2="0" y2="1">'
			. '<stop offset="0" stop-color="#7c8cf8"></stop>'
			. '<stop offset="1" stop-color="#4d5fd6"></stop>'
			. '</linearGradient></defs>';

		// Faint baseline.
		$svg .= sprintf(
			'<line x1="0" y1="%1$d" x2="%2$d" y2="%1$d" stroke="#e6e6ea" stroke-width="1"></line>',
			$baseline,
			$width
		);

		$x = 0;
		foreach ( $data as $label => $count ) {
			$bar_h = $count > 0 ? max( 6, ( $count / $max ) * $chart_h ) : 3;
			$bar_y = $baseline - $bar_h;
			$cx    = $x + ( $bar_w / 2 );

			// Ghost track behind each column so an empty month still reads as a slot.
			$svg .= sprintf(
				'<rect x="%1$.1f" y="%2$d" width="%3$d" height="%4$d" rx="7" fill="#f1f1f4"></rect>',
				$x,
				$top_pad,
				$bar_w,
				$chart_h
			);

			$svg .= sprintf(
				'<rect x="%1$.1f" y="%2$.1f" width="%3$d" height="%4$.1f" rx="7" fill="url(#sml-bar)"></rect>',
				$x,
				$bar_y,
				$bar_w,
				$bar_h
			);
			$svg .= sprintf(
				'<text x="%1$.1f" y="%2$.1f" text-anchor="middle" font-size="12" font-weight="600" fill="#4b4b55">%3$s</text>',
				$cx,
				$bar_y - 8,
				esc_html( $count )
			);
			$svg .= sprintf(
				'<text x="%1$.1f" y="%2$d" text-anchor="middle" font-size="11" fill="#9aa0a8">%3$s</text>',
				$cx,
				$baseline + 18,
				esc_html( $label )
			);

			$x += $bar_w + $gap;
		}

		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * A self-contained "does mail even work on this site" button. It calls
	 * wp_mail() the same way every other email in the plugin does (same
	 * from-address/content-type filters), independent of the
	 * registration/verification flow — so if this also fails, the cause is
	 * the site's mailer (e.g. WP Mail SMTP) or server, not this plugin.
	 */
	protected function test_email_html() {
		return '<div class="apx-row">'
			. '<label>' . esc_html__( 'Test delivery', 'smart-login' ) . '</label>'
			. '<button type="button" class="button button-secondary" id="sml-test-email-btn">' . esc_html__( 'Send test email', 'smart-login' ) . '</button> '
			. '<span id="sml-test-email-status" style="font-size:13px;"></span>'
			. '<p class="description">' . esc_html__( 'Sends a plain test email to your account using the exact same code path as every other Smart Login email. If this fails too, the problem is your SMTP setup (e.g. WP Mail SMTP), not this plugin.', 'smart-login' ) . '</p>'
			. '</div>'
			. '<script>(function(){
				var btn = document.getElementById("sml-test-email-btn");
				var status = document.getElementById("sml-test-email-status");
				if (!btn) { return; }
				btn.addEventListener("click", function(){
					btn.disabled = true;
					status.textContent = "' . esc_js( __( 'Sending…', 'smart-login' ) ) . '";
					fetch(window.APX_UI.restUrl.replace(/\/$/, "") + "/test-email", {
						method: "POST",
						credentials: "same-origin",
						headers: { "X-WP-Nonce": window.APX_UI.restNonce }
					}).then(function(r){ return r.json(); }).then(function(data){
						status.textContent = data.message || "";
						status.style.color = data.sent ? "#12805c" : "#d92d20";
					}).catch(function(){
						status.textContent = "' . esc_js( __( 'Request failed.', 'smart-login' ) ) . '";
						status.style.color = "#d92d20";
					}).then(function(){ btn.disabled = false; });
				});
			})();</script>';
	}

	/**
	 * Sends a bare test email through the identical filters/content-type
	 * every other Smart Login email uses, to the current admin's address.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function rest_test_email( WP_REST_Request $req ) {
		$to             = wp_get_current_user()->user_email;
		$captured_error = null;

		$capture = function ( $error ) use ( &$captured_error ) {
			$captured_error = $error;
		};
		add_action( 'wp_mail_failed', $capture );

		add_filter( 'wp_mail_content_type', array( 'SML_Email', 'content_type' ) );
		add_filter( 'wp_mail_from', array( 'SML_Email', 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( 'SML_Email', 'from_name' ) );

		$sent = wp_mail(
			$to,
			__( 'Smart Login test email', 'smart-login' ),
			__( 'If you are reading this, Smart Login was able to send email through this site\'s configured mailer.', 'smart-login' )
		);

		remove_filter( 'wp_mail_content_type', array( 'SML_Email', 'content_type' ) );
		remove_filter( 'wp_mail_from', array( 'SML_Email', 'from_email' ) );
		remove_filter( 'wp_mail_from_name', array( 'SML_Email', 'from_name' ) );
		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			return rest_ensure_response(
				array(
					'sent'    => false,
					'message' => $captured_error instanceof WP_Error
						? $captured_error->get_error_message()
						: __( 'wp_mail() returned false with no further detail from your mailer.', 'smart-login' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'sent'    => true,
				/* translators: %s: email address */
				'message' => sprintf( __( 'Sent to %s — check your inbox (and spam folder).', 'smart-login' ), $to ),
			)
		);
	}

	/**
	 * "Import / export settings" panel: download the current settings as a
	 * JSON file, or paste/upload one back. Secret-typed fields are never
	 * written into the export (they carry the same placeholder the save path
	 * treats as "unchanged"), so an export is safe to share for review.
	 */
	protected function import_export_html() {
		$rest = 'window.APX_UI.restUrl.replace(/\/$/, "")';

		return '<div class="apx-row">'
			. '<label>' . esc_html__( 'Export', 'smart-login' ) . '</label>'
			. '<button type="button" class="button button-secondary" id="sml-export-btn">' . esc_html__( 'Download settings JSON', 'smart-login' ) . '</button> '
			. '<span id="sml-export-status" style="font-size:13px;"></span>'
			. '<p class="description">' . esc_html__( 'Downloads every setting as a JSON file. Bot-protection secret keys are left out, so the file is safe to send for review.', 'smart-login' ) . '</p>'
			. '</div>'
			. '<div class="apx-field-row">'
			. '<label><strong>' . esc_html__( 'Import', 'smart-login' ) . '</strong></label>'
			. '<p class="description">' . esc_html__( 'Paste an exported JSON below (or choose a file), then Import. Unknown keys are ignored; secret keys already stored are kept.', 'smart-login' ) . '</p>'
			. '<input type="file" accept="application/json,.json" id="sml-import-file" style="margin:6px 0;">'
			. '<textarea id="sml-import-json" rows="7" spellcheck="false" style="width:100%;max-width:640px;font-family:monospace;font-size:12px;" placeholder="{ &quot;settings&quot;: { ... } }"></textarea>'
			. '<div style="margin-top:8px;"><button type="button" class="button button-primary" id="sml-import-btn">' . esc_html__( 'Import', 'smart-login' ) . '</button> '
			. '<span id="sml-import-status" style="font-size:13px;"></span></div>'
			. '</div>'
			. '<script>(function(){
				var R = ' . $rest . ';
				var N = window.APX_UI.restNonce;
				var exBtn = document.getElementById("sml-export-btn");
				var exStatus = document.getElementById("sml-export-status");
				if (exBtn) {
					exBtn.addEventListener("click", function(){
						exBtn.disabled = true; exStatus.textContent = "";
						fetch(R + "/export", { method:"POST", credentials:"same-origin", headers:{ "X-WP-Nonce": N } })
							.then(function(r){ return r.json(); })
							.then(function(d){
								if (!d || !d.json) { throw 0; }
								var blob = new Blob([d.json], { type:"application/json" });
								var a = document.createElement("a");
								a.href = URL.createObjectURL(blob);
								a.download = d.filename || "smart-login-settings.json";
								document.body.appendChild(a); a.click(); a.remove();
								setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1000);
								exStatus.style.color = "#12805c";
								exStatus.textContent = "' . esc_js( __( 'Downloaded.', 'smart-login' ) ) . '";
							})
							.catch(function(){ exStatus.style.color = "#d92d20"; exStatus.textContent = "' . esc_js( __( 'Export failed.', 'smart-login' ) ) . '"; })
							.then(function(){ exBtn.disabled = false; });
					});
				}
				var file = document.getElementById("sml-import-file");
				var ta = document.getElementById("sml-import-json");
				if (file && ta) {
					file.addEventListener("change", function(){
						var f = file.files && file.files[0];
						if (!f) { return; }
						var rd = new FileReader();
						rd.onload = function(){ ta.value = String(rd.result || ""); };
						rd.readAsText(f);
					});
				}
				var imBtn = document.getElementById("sml-import-btn");
				var imStatus = document.getElementById("sml-import-status");
				if (imBtn && ta) {
					imBtn.addEventListener("click", function(){
						imBtn.disabled = true; imStatus.style.color = ""; imStatus.textContent = "' . esc_js( __( 'Importing…', 'smart-login' ) ) . '";
						fetch(R + "/import", {
							method:"POST", credentials:"same-origin",
							headers:{ "X-WP-Nonce": N, "Content-Type":"application/json" },
							body: JSON.stringify({ json: ta.value })
						}).then(function(r){ return r.json(); }).then(function(d){
							imStatus.style.color = d && d.ok ? "#12805c" : "#d92d20";
							imStatus.textContent = (d && d.message) || "' . esc_js( __( 'Import failed.', 'smart-login' ) ) . '";
							if (d && d.ok) { setTimeout(function(){ window.location.reload(); }, 900); }
						}).catch(function(){
							imStatus.style.color = "#d92d20";
							imStatus.textContent = "' . esc_js( __( 'Request failed.', 'smart-login' ) ) . '";
						}).then(function(){ imBtn.disabled = false; });
					});
				}
			})();</script>';
	}

	/**
	 * Builds the shareable export payload: metadata + every setting except
	 * secret-typed fields (those become the "unchanged" placeholder).
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function rest_export_settings( WP_REST_Request $req ) {
		$all    = $this->get_all();
		$schema = $this->field_index();

		foreach ( $schema as $name => $field ) {
			if ( 'password' === $field['type'] && ! empty( $field['secret'] ) && isset( $all[ $name ] ) && '' !== (string) $all[ $name ] ) {
				$all[ $name ] = self::SECRET_PLACEHOLDER;
			}
		}

		$payload = array(
			'_plugin'      => 'smart-login',
			'_version'     => defined( 'SML_VERSION' ) ? SML_VERSION : '',
			'_exported_at' => gmdate( 'c' ),
			'_site'        => home_url( '/' ),
			'settings'     => $all,
		);

		$slug = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );

		return rest_ensure_response(
			array(
				'filename' => 'smart-login-settings-' . ( $slug ? $slug . '-' : '' ) . gmdate( 'Ymd-His' ) . '.json',
				'json'     => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	/**
	 * Applies a pasted/uploaded export. Only keys present in the field
	 * schema are accepted; each is sanitised exactly as a normal save; a
	 * secret field left as the placeholder keeps its stored value.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function rest_import_settings( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$raw  = isset( $body['json'] ) ? (string) $body['json'] : '';

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'That is not valid JSON.', 'smart-login' ) ) );
		}

		// Accept both the wrapped export ({ "_plugin": …, "settings": {…} })
		// and a bare settings object.
		$incoming = ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) ? $decoded['settings'] : $decoded;

		if ( isset( $decoded['_plugin'] ) && 'smart-login' !== $decoded['_plugin'] ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'This file is an export from a different plugin.', 'smart-login' ) ) );
		}

		$schema  = $this->field_index();
		$all     = $this->get_all();
		$applied = 0;

		foreach ( $incoming as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) ) {
				continue;
			}
			$field = $schema[ $name ];

			if ( 'password' === $field['type'] && ! empty( $field['secret'] ) && (string) $value === self::SECRET_PLACEHOLDER ) {
				continue;
			}

			$all[ $name ] = $this->sanitize( $value, $field );
			$applied++;
		}

		if ( 0 === $applied ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No recognised settings found in that file.', 'smart-login' ) ) );
		}

		update_option( $this->cfg['option_name'], $all );
		do_action( 'apx_settings_saved', $all, array_keys( $incoming ), $this->cfg['slug'] );

		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: %d: number of settings applied */
				'message' => sprintf( __( 'Imported %d settings. Reloading…', 'smart-login' ), $applied ),
			)
		);
	}

	public function register_rest() {
		parent::register_rest();

		register_rest_route(
			$this->cfg['rest_ns'],
			'/test-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_test_email' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);

		register_rest_route(
			$this->cfg['rest_ns'],
			'/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_export_settings' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);

		register_rest_route(
			$this->cfg['rest_ns'],
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_settings' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/* ── field-type extensions the base kit doesn't ship ────────────── */

	protected function render_field( array $f, array $s ) {
		$f = wp_parse_args( $f, array( 'type' => 'text', 'name' => '', 'label' => '', 'desc' => '' ) );

		if ( 'html' === $f['type'] ) {
			// Developer-authored markup (never user input) — printed as-is so
			// the inline copy-to-clipboard handler below isn't stripped by
			// wp_kses_post(), which removes onclick attributes.
			echo $f['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		if ( 'textarea' === $f['type'] ) {
			$name = $f['name'];
			$val  = isset( $s[ $name ] ) ? $s[ $name ] : '';
			echo '<div class="apx-field-row">';
			printf( '<label for="%1$s"><strong>%2$s</strong></label>', esc_attr( $name ), esc_html( $f['label'] ) );
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; // phpcs:ignore
			}
			printf(
				'<textarea id="%1$s" name="%1$s" rows="5" style="width:100%%;max-width:640px" data-apx-field>%2$s</textarea>',
				esc_attr( $name ),
				esc_textarea( $val )
			);
			echo '</div>';
			return;
		}

		if ( 'page_select' === $f['type'] ) {
			$name  = $f['name'];
			$val   = isset( $s[ $name ] ) ? (int) $s[ $name ] : 0;
			$pages = get_pages( array( 'sort_column' => 'post_title' ) );
			echo '<div class="apx-row">';
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $f['label'] ) );
			printf( '<select id="%1$s" name="%1$s" data-apx-field>', esc_attr( $name ) );
			printf( '<option value="0">%s</option>', esc_html__( '— Select a page —', 'smart-login' ) );
			foreach ( (array) $pages as $page ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $page->ID,
					selected( $val, $page->ID, false ),
					esc_html( $page->post_title )
				);
			}
			echo '</select>';
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; // phpcs:ignore
			}
			echo '</div>';
			return;
		}

		if ( 'decimal' === $f['type'] ) {
			$name = $f['name'];
			$val  = isset( $s[ $name ] ) ? $s[ $name ] : '';
			echo '<div class="apx-row">';
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $f['label'] ) );
			printf(
				'<input type="number" id="%1$s" name="%1$s" value="%2$s" step="0.1" min="%3$s" max="%4$s" style="width:100px" data-apx-field>',
				esc_attr( $name ),
				esc_attr( $val ),
				esc_attr( isset( $f['min'] ) ? $f['min'] : 0 ),
				esc_attr( isset( $f['max'] ) ? $f['max'] : 1 )
			);
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; // phpcs:ignore
			}
			echo '</div>';
			return;
		}

		if ( 'color' === $f['type'] ) {
			$name = $f['name'];
			$val  = isset( $s[ $name ] ) && sanitize_hex_color( $s[ $name ] ) ? $s[ $name ] : '#000000';
			echo '<div class="apx-row">';
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $f['label'] ) );
			printf(
				'<input type="color" id="%1$s" name="%1$s" value="%2$s" style="width:60px;height:36px;padding:2px;cursor:pointer" data-apx-field oninput="document.getElementById(\'%1$s-hex\').textContent=this.value">',
				esc_attr( $name ),
				esc_attr( $val )
			);
			printf( ' <code id="%1$s-hex">%2$s</code>', esc_attr( $name ), esc_html( $val ) );
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; // phpcs:ignore
			}
			echo '</div>';
			return;
		}

		if ( 'countries' === $f['type'] ) {
			$all = SML_Countries::all();

			// Alphabetical by name so a ~195-country list is actually
			// scannable — the underlying array is grouped default-first for
			// readability of the source, not for display order here.
			uasort( $all, function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			} );

			$options = array();
			foreach ( $all as $id => $country ) {
				$options[ $id ] = $country['name'] . ' (' . $country['dial'] . ')';
			}

			$this->render_searchable_multiselect(
				$f,
				$s,
				$options,
				__( 'Search countries…', 'smart-login' ),
				__( 'Only checked countries appear in the mobile-number country selector.', 'smart-login' )
			);
			return;
		}

		if ( 'roles' === $f['type'] ) {
			$options = array();
			foreach ( wp_roles()->roles as $role_key => $role ) {
				$options[ $role_key ] = translate_user_role( $role['name'] );
			}

			$this->render_searchable_multiselect(
				$f,
				$s,
				$options,
				__( 'Search roles…', 'smart-login' ),
				__( 'None selected = every role allowed.', 'smart-login' )
			);
			return;
		}

		if ( 'password' === $f['type'] && ! empty( $f['secret'] ) ) {
			$name    = $f['name'];
			$hasval  = '' !== (string) ( isset( $s[ $name ] ) ? $s[ $name ] : '' );
			$display = $hasval ? self::SECRET_PLACEHOLDER : '';
			echo '<div class="apx-row">';
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $f['label'] ) );
			printf(
				'<input type="password" id="%1$s" name="%1$s" value="%2$s" autocomplete="new-password" placeholder="%3$s" style="min-width:340px" data-apx-field data-apx-secret="1">',
				esc_attr( $name ),
				esc_attr( $display ),
				$hasval ? esc_attr__( 'Saved — leave unchanged, or type a new value to replace it', 'smart-login' ) : ''
			);
			if ( $f['desc'] ) {
				echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; // phpcs:ignore
			}
			echo '</div>';
			return;
		}

		parent::render_field( $f, $s );
	}

	/**
	 * A searchable, checkbox-driven multi-select dropdown (search box + a
	 * filterable list of checkboxes + removable chips for what's already
	 * selected), backed by the same hidden CSV input the plain multi-select
	 * used — so save/sanitize logic for 'countries'/'roles' fields is
	 * unchanged. Built from scratch rather than a native <select multiple>
	 * because a ctrl/cmd-click listbox doesn't scale to ~195 countries.
	 *
	 * The CSS/JS for it is only printed once per page load (guarded, since
	 * this page can render this widget more than once) and only reaches the
	 * browser on this admin settings screen — never site-wide.
	 *
	 * @param array<string,mixed>  $f       Field config (name, label, desc).
	 * @param array<string,mixed>  $s       Current saved settings.
	 * @param array<string,string> $options id => display label.
	 * @param string               $search_placeholder
	 * @param string               $extra_desc Appended after $f['desc'], if any.
	 */
	protected function render_searchable_multiselect( array $f, array $s, array $options, $search_placeholder, $extra_desc = '' ) {
		$name    = $f['name'];
		$current = isset( $s[ $name ] ) ? (string) $s[ $name ] : '';
		$allowed = array_filter( array_map( 'trim', explode( ',', $current ) ) );

		if ( ! self::$ms_assets_printed ) {
			self::$ms_assets_printed = true;
			echo self::multiselect_assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="apx-field-row">';
		printf( '<label for="%1$s-search"><strong>%2$s</strong></label>', esc_attr( $name ), esc_html( $f['label'] ) );
		printf( '<input type="hidden" id="%1$s" name="%1$s" value="%2$s" data-apx-field>', esc_attr( $name ), esc_attr( $current ) );

		echo '<div class="sml-ms" data-sml-ms="' . esc_attr( $name ) . '">';
		echo '<div class="sml-ms-control" data-sml-ms-control tabindex="-1">';
		echo '<div class="sml-ms-chips" data-sml-ms-chips></div>';
		printf(
			'<input type="text" class="sml-ms-search" id="%1$s-search" placeholder="%2$s" autocomplete="off" data-sml-ms-search>',
			esc_attr( $name ),
			esc_attr( $search_placeholder )
		);
		echo '</div>';
		echo '<div class="sml-ms-dropdown" data-sml-ms-dropdown hidden>';
		foreach ( $options as $id => $label ) {
			printf(
				'<label class="sml-ms-option" data-sml-ms-label="%1$s"><input type="checkbox" value="%1$s"%2$s>%3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $allowed, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<div class="sml-ms-empty" data-sml-ms-empty hidden>' . esc_html__( 'No matches.', 'smart-login' ) . '</div>';
		echo '</div>';
		echo '</div>';

		$desc = trim( ( $f['desc'] ? $f['desc'] . ' ' : '' ) . $extra_desc );
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>'; // phpcs:ignore
		}

		echo '<script>window.smlInitMultiselect && window.smlInitMultiselect(' . wp_json_encode( $name ) . ', ' . wp_json_encode( $options ) . ');</script>';
		echo '</div>';
	}

	/** @var bool Printed once per page load regardless of how many multiselect fields render. */
	protected static $ms_assets_printed = false;

	/**
	 * @return string <style> + <script> for the searchable multi-select, and
	 *                the smlInitMultiselect() factory it calls per field.
	 */
	protected static function multiselect_assets() {
		ob_start();
		?>
		<style>
		.sml-ms { position: relative; max-width: 420px; }
		.sml-ms-control { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; border: 1px solid #8c8f94; border-radius: 4px; padding: 4px 6px; background: #fff; min-height: 32px; }
		.sml-ms-control:focus-within { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.sml-ms-chips { display: flex; flex-wrap: wrap; gap: 4px; }
		.sml-ms-chip { display: inline-flex; align-items: center; gap: 4px; background: #f0f0f1; border-radius: 3px; padding: 2px 4px 2px 8px; font-size: 12px; line-height: 1.6; }
		.sml-ms-chip button { border: 0; background: none; cursor: pointer; padding: 0 4px; font-size: 13px; line-height: 1; color: #646970; }
		.sml-ms-chip button:hover { color: #d63638; }
		.sml-ms-search { flex: 1 1 80px; min-width: 80px; border: 0 !important; box-shadow: none !important; padding: 2px 4px !important; margin: 0 !important; }
		.sml-ms-dropdown { position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: 2px; max-height: 260px; overflow-y: auto; background: #fff; border: 1px solid #8c8f94; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
		.sml-ms-option { display: flex; align-items: center; gap: 8px; padding: 6px 10px; font-weight: 400; cursor: pointer; }
		.sml-ms-option:hover { background: #f0f6fc; }
		.sml-ms-empty { padding: 8px 10px; color: #646970; font-size: 12.5px; }
		</style>
		<script>
		(function () {
			if ( window.smlInitMultiselect ) { return; }

			window.smlInitMultiselect = function ( name, labels ) {
				var root = document.querySelector( '[data-sml-ms="' + name + '"]' );
				var hidden = document.getElementById( name );
				if ( ! root || ! hidden ) { return; }

				var control  = root.querySelector( '[data-sml-ms-control]' );
				var chips    = root.querySelector( '[data-sml-ms-chips]' );
				var search   = root.querySelector( '[data-sml-ms-search]' );
				var dropdown = root.querySelector( '[data-sml-ms-dropdown]' );
				var empty    = root.querySelector( '[data-sml-ms-empty]' );
				var options  = Array.prototype.slice.call( root.querySelectorAll( '.sml-ms-option' ) );

				function selectedIds() {
					return options.filter( function ( o ) { return o.querySelector( 'input' ).checked; } )
						.map( function ( o ) { return o.getAttribute( 'data-sml-ms-label' ); } );
				}

				function renderChips() {
					var ids = selectedIds();
					chips.innerHTML = '';
					ids.forEach( function ( id ) {
						var chip = document.createElement( 'span' );
						chip.className = 'sml-ms-chip';
						var text = document.createElement( 'span' );
						text.textContent = labels[ id ] || id;
						chip.appendChild( text );
						var remove = document.createElement( 'button' );
						remove.type = 'button';
						remove.setAttribute( 'aria-label', 'Remove' );
						remove.textContent = '×';
						remove.addEventListener( 'click', function ( e ) {
							e.stopPropagation();
							var opt = options.filter( function ( o ) { return o.getAttribute( 'data-sml-ms-label' ) === id; } )[0];
							if ( opt ) { opt.querySelector( 'input' ).checked = false; }
							sync();
						} );
						chip.appendChild( remove );
						chips.appendChild( chip );
					} );
				}

				function sync() {
					hidden.value = selectedIds().join( ',' );
					hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					renderChips();
				}

				function filter() {
					var q = search.value.trim().toLowerCase();
					var visible = 0;
					options.forEach( function ( o ) {
						var match = ! q || o.textContent.toLowerCase().indexOf( q ) !== -1;
						o.hidden = ! match;
						if ( match ) { visible++; }
					} );
					empty.hidden = visible !== 0;
				}

				function open() {
					dropdown.hidden = false;
					filter();
				}
				function close() {
					dropdown.hidden = true;
				}

				control.addEventListener( 'click', function () { open(); search.focus(); } );
				search.addEventListener( 'focus', open );
				search.addEventListener( 'input', filter );
				search.addEventListener( 'keydown', function ( e ) {
					if ( 'Escape' === e.key ) { close(); search.blur(); }
				} );

				options.forEach( function ( o ) {
					o.querySelector( 'input' ).addEventListener( 'change', sync );
				} );

				document.addEventListener( 'click', function ( e ) {
					if ( ! root.contains( e.target ) ) { close(); }
				} );

				renderChips();
			};
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	protected function sanitize( $value, array $field ) {
		if ( 'page_select' === $field['type'] ) {
			$page_id = absint( $value );
			return ( $page_id && 'page' === get_post_type( $page_id ) ) ? $page_id : 0;
		}

		if ( 'color' === $field['type'] ) {
			$hex = sanitize_hex_color( (string) $value );
			return $hex ? $hex : SML_Settings::defaults()[ $field['name'] ];
		}

		if ( 'countries' === $field['type'] ) {
			$ids   = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
			$known = array_keys( SML_Countries::all() );
			$valid = array_values( array_intersect( $ids, $known ) );

			return $valid ? implode( ',', $valid ) : SML_Countries::default_allowed_csv();
		}

		if ( 'roles' === $field['type'] ) {
			$ids   = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
			$known = array_keys( wp_roles()->roles );
			return implode( ',', array_values( array_intersect( $ids, $known ) ) );
		}

		if ( 'textarea' === $field['type'] ) {
			// Email bodies may contain admin-authored basic HTML (see
			// SML_Email::send()), so this allows the wp_kses_post() tag set
			// rather than stripping tags outright.
			return wp_kses_post( (string) $value );
		}

		if ( 'decimal' === $field['type'] ) {
			$n = (float) $value;
			if ( isset( $field['min'] ) ) {
				$n = max( (float) $field['min'], $n );
			}
			if ( isset( $field['max'] ) ) {
				$n = min( (float) $field['max'], $n );
			}
			return $n;
		}

		if ( 'password' === $field['type'] && ! empty( $field['secret'] ) ) {
			return sanitize_text_field( (string) $value );
		}

		if ( 'text' === $field['type'] && 'from_email' === $field['name'] ) {
			return sanitize_email( (string) $value );
		}

		return parent::sanitize( $value, $field );
	}

	/**
	 * Overridden so a secret-typed field is never sent back to the browser
	 * in plaintext. The settings screen only needs to know whether a secret
	 * is set, not its value; any saved secret is replaced with the same
	 * placeholder the save path already recognises as "unchanged", so an
	 * untouched round-trip leaves the stored value alone.
	 */
	public function rest_get() {
		$all    = $this->get_all();
		$schema = $this->field_index();

		foreach ( $schema as $name => $field ) {
			if ( 'password' === $field['type'] && ! empty( $field['secret'] )
				&& isset( $all[ $name ] ) && '' !== (string) $all[ $name ] ) {
				$all[ $name ] = self::SECRET_PLACEHOLDER;
			}
		}

		return rest_ensure_response( $all );
	}

	/**
	 * Overridden (rather than calling parent::rest_save()) because a secret
	 * field left unchanged arrives as the literal placeholder string and
	 * must be dropped from the patch, not written to the stored option.
	 */
	public function rest_save( WP_REST_Request $req ) {
		$patch  = (array) $req->get_json_params();
		$schema = $this->field_index();
		$all    = $this->get_all();

		foreach ( $patch as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) ) {
				continue;
			}

			$field = $schema[ $name ];

			if ( 'password' === $field['type'] && ! empty( $field['secret'] ) && (string) $value === self::SECRET_PLACEHOLDER ) {
				continue;
			}

			$all[ $name ] = $this->sanitize( $value, $field );
		}

		update_option( $this->cfg['option_name'], $all );

		do_action( 'apx_settings_saved', $all, array_keys( $patch ), $this->cfg['slug'] );

		return rest_ensure_response( array( 'saved' => true, 'settings' => $all ) );
	}
}
