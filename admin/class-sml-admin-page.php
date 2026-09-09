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
			$nav[0]['items'][] = array( 'key' => 'woocommerce', 'label' => __( 'WooCommerce', 'smart-login' ), 'icon' => 'ext' );
		}

		$nav[0]['items'][] = array( 'key' => 'advanced', 'label' => __( 'Advanced', 'smart-login' ), 'icon' => 'wrench' );

		$panels = array(
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
					),
				),
				array(
					'heading' => __( 'Shortcode', 'smart-login' ),
					'fields'  => array(
						array(
							'type' => 'html',
							'html' => '<div class="apx-row"><label>' . esc_html__( 'Shortcode', 'smart-login' ) . '</label>'
								. '<code id="sml-shortcode-ref" style="padding:6px 10px;border:1px solid var(--apx-border,#ddd);border-radius:6px;">[smart_login_form]</code> '
								. '<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(\'[smart_login_form]\');this.textContent=\'' . esc_js( __( 'Copied!', 'smart-login' ) ) . '\';">' . esc_html__( 'Copy', 'smart-login' ) . '</button></div>',
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
					'heading' => __( 'Bot protection', 'smart-login' ),
					'fields'  => array(
						array( 'type' => 'toggle', 'name' => 'enable_honeypot', 'label' => __( 'Enable honeypot field', 'smart-login' ), 'desc' => __( 'Adds a hidden field and a time-trap; submissions filled or submitted too fast are rejected as bots.', 'smart-login' ) ),
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
			),
		);
	}

	protected function role_options() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[ $key ] = translate_user_role( $role['name'] );
		}
		return $roles;
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

	protected function sanitize( $value, array $field ) {
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
