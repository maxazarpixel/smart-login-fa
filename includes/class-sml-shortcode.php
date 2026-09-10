<?php
/**
 * [smart_login_form] shortcode, front-end asset loading, and the
 * ?sml_verify=TOKEN link endpoint.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Shortcode {

	public static function init() {
		add_shortcode( 'smart_login_form', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_verify_link' ) );
	}

	/**
	 * Handles a visit to any URL carrying ?sml_verify=TOKEN — consumes the
	 * token and redirects with a status flag the shortcode reads back.
	 */
	public static function maybe_handle_verify_link() {
		if ( ! isset( $_GET['sml_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['sml_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = SML_Verification::verify_link( $token );

		// Carried on the verify link itself (embedded when the email was
		// sent — see SML_Email::verify_link()), not the current request's
		// own query string, since this URL is only ever "?sml_verify=...".
		$redirect_to = SML_Page_Guard::validate_redirect(
			isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		if ( is_wp_error( $result ) ) {
			$redirect_url = add_query_arg( 'sml_verified', 'error', remove_query_arg( array( 'sml_verify', 'redirect_to' ) ) );
		} else {
			$user = get_user_by( 'id', $result );
			if ( $user ) {
				SML_Email::send_welcome( $user );
				wp_set_current_user( $result );
				wp_set_auth_cookie( $result );
			}

			// A verified visitor who came from Cart/Checkout goes straight
			// back there instead of wherever the login form happens to live.
			$redirect_url = $redirect_to
				? $redirect_to
				: add_query_arg( 'sml_verified', 'success', remove_query_arg( array( 'sml_verify', 'redirect_to' ) ) );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function enqueue_assets() {
		wp_enqueue_style( 'smart-login', SML_PLUGIN_URL . 'public/css/smart-login.css', array(), SML_VERSION );
		wp_enqueue_script( 'smart-login', SML_PLUGIN_URL . 'public/js/smart-login.js', array(), SML_VERSION, true );

		$provider_key = SML_Settings::get( 'bot_protection_provider', 'none' );

		wp_localize_script(
			'smart-login',
			'SmartLogin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'registrationNonce'  => wp_create_nonce( SML_Registration_Handler::NONCE_ACTION ),
				'loginNonce'         => wp_create_nonce( SML_Login_Handler::NONCE_ACTION ),
				'passwordResetNonce' => wp_create_nonce( SML_Password_Reset_Handler::NONCE_ACTION ),
				'botProvider'        => $provider_key,
				'botSiteKey'         => 'none' === $provider_key ? '' : SML_Settings::get( 'bot_site_key' ),
				'i18n'               => array(
					'verifying'      => __( 'Verifying…', 'smart-login' ),
					'sending'        => __( 'Sending…', 'smart-login' ),
					'loggingIn'      => __( 'Logging in…', 'smart-login' ),
					'resend'         => __( 'Resend code', 'smart-login' ),
					'resendIn'       => __( 'Resend in %ds', 'smart-login' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'smart-login' ),
					'show'           => __( 'Show', 'smart-login' ),
					'hide'           => __( 'Hide', 'smart-login' ),
					'resetting'      => __( 'Resetting…', 'smart-login' ),
				),
			)
		);

		if ( 'none' !== $provider_key ) {
			$provider = SML_Loader::bot_provider( $provider_key );
			if ( $provider ) {
				$provider->enqueue();
			}
		}
	}

	public static function render( $atts ) {
		self::enqueue_assets();

		$show_login    = (bool) SML_Settings::get( 'enable_login_form', 1 );
		$show_register = (bool) SML_Settings::get( 'enable_registration', 1 );

		if ( ! $show_login ) {
			return '';
		}

		$verified_status = isset( $_GET['sml_verified'] ) ? sanitize_text_field( wp_unslash( $_GET['sml_verified'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Where to send the visitor once they're logged in/verified — set
		// by SML_Page_Guard when a protected page (e.g. Cart, Checkout)
		// redirected them here. Carried through every form on this page as
		// a hidden field so it survives the AJAX round-trip.
		$redirect_to = SML_Page_Guard::validate_redirect(
			isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		$action        = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$register_url  = esc_url( add_query_arg( 'action', 'register' ) );
		$forgot_url    = esc_url( add_query_arg( 'action', 'forgot' ) );
		$login_url     = esc_url( remove_query_arg( 'action' ) );

		// A visit carrying ?sml_reset=1&key=...&login=... is the link from
		// the password-reset email — checked (not consumed) here purely to
		// decide which panel to land on and whether to show an error;
		// SML_Password_Reset_Handler::ajax_reset_password() re-validates
		// (and actually consumes) the key when the form is submitted.
		$reset_key       = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset_login     = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_reset_link   = isset( $_GET['sml_reset'] ) && $reset_key && $reset_login; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset_key_valid = $is_reset_link && ! is_wp_error( check_password_reset_key( $reset_key, $reset_login ) );

		if ( $is_reset_link ) {
			$initial_panel = 'reset';
		} elseif ( 'register' === $action && $show_register ) {
			$initial_panel = 'register';
		} elseif ( 'forgot' === $action ) {
			$initial_panel = 'forgot';
		} else {
			$initial_panel = 'login';
		}

		$button_bg   = sanitize_hex_color( SML_Settings::get( 'button_bg_color' ) );
		$button_text = sanitize_hex_color( SML_Settings::get( 'button_text_color' ) );
		$button_vars = '';
		if ( $button_bg ) {
			$button_vars .= '--sml-accent:' . $button_bg . ';';
		}
		if ( $button_text ) {
			$button_vars .= '--sml-accent-fg:' . $button_text . ';';
		}

		ob_start();
		?>
		<div class="sml-card" data-sml-root<?php echo $button_vars ? ' style="' . esc_attr( $button_vars ) . '"' : ''; ?>>
			<?php if ( $verified_status ) : ?>
				<?php if ( 'success' === $verified_status ) : ?>
					<div class="sml-notice sml-notice--ok"><?php esc_html_e( 'Your email has been verified. You are now logged in.', 'smart-login' ); ?></div>
				<?php else : ?>
					<div class="sml-notice sml-notice--error"><?php esc_html_e( 'That verification link is invalid or has expired.', 'smart-login' ); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="sml-message" data-sml-message hidden></div>

			<div class="sml-panel" data-sml-panel="login"<?php echo 'login' === $initial_panel ? '' : ' hidden'; ?>>
				<div class="sml-heading">
					<div class="sml-heading-line1"><?php esc_html_e( 'Log In', 'smart-login' ); ?></div>
					<div class="sml-heading-line2"><?php esc_html_e( 'to your account', 'smart-login' ); ?></div>
				</div>
				<form data-sml-form="login" novalidate>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
					<div class="sml-field-boxed">
						<input type="email" id="sml-login-user" class="sml-input" name="username" autocomplete="username" placeholder=" " required>
						<label for="sml-login-user"><?php esc_html_e( 'Email', 'smart-login' ); ?></label>
					</div>
					<div class="sml-field-boxed">
						<div class="sml-password-group">
							<input type="password" id="sml-login-pass" class="sml-input" name="password" autocomplete="current-password" placeholder=" " required>
							<button type="button" class="sml-password-toggle" data-sml-password-toggle aria-label="<?php esc_attr_e( 'Show password', 'smart-login' ); ?>">
								<?php esc_html_e( 'Show', 'smart-login' ); ?>
							</button>
						</div>
						<label for="sml-login-pass"><?php esc_html_e( 'Password', 'smart-login' ); ?></label>
					</div>
					<a class="sml-forgot-link" data-sml-tab="forgot" href="<?php echo $forgot_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Forgot password?', 'smart-login' ); ?></a>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Log In', 'smart-login' ); ?></button>
					<?php if ( $show_register ) : ?>
						<a class="sml-btn sml-btn--secondary" data-sml-tab="register" href="<?php echo $register_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Create Account', 'smart-login' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( $show_register ) : ?>
				<div class="sml-panel" data-sml-panel="register"<?php echo 'register' === $initial_panel ? '' : ' hidden'; ?>>
					<div class="sml-heading">
						<div class="sml-heading-line1"><?php esc_html_e( 'Create Your', 'smart-login' ); ?></div>
						<div class="sml-heading-line2"><?php esc_html_e( 'Account', 'smart-login' ); ?></div>
					</div>
					<form data-sml-form="register" novalidate>
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
						<div class="sml-field sml-hp-field" aria-hidden="true">
							<label for="sml-hp"><?php esc_html_e( 'Leave this field empty', 'smart-login' ); ?></label>
							<input type="text" id="sml-hp" name="sml_hp" tabindex="-1" autocomplete="off">
						</div>
						<input type="hidden" name="sml_ts" value="<?php echo esc_attr( (int) round( microtime( true ) * 1000 ) ); ?>">
						<input type="hidden" name="sml_bot_token" data-sml-bot-token value="">
						<?php if ( 'turnstile' === SML_Settings::get( 'bot_protection_provider', 'none' ) && SML_Settings::get( 'bot_site_key' ) ) : ?>
							<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( SML_Settings::get( 'bot_site_key' ) ); ?>" data-callback="smlTurnstileCallback"></div>
						<?php endif; ?>
						<div class="sml-field-row">
							<div class="sml-field-boxed">
								<input type="text" id="sml-reg-first" class="sml-input" name="first_name" autocomplete="given-name" placeholder=" " required>
								<label for="sml-reg-first"><?php esc_html_e( 'First name', 'smart-login' ); ?> <span class="sml-required">*</span></label>
							</div>
							<div class="sml-field-boxed">
								<input type="text" id="sml-reg-last" class="sml-input" name="last_name" autocomplete="family-name" placeholder=" " required>
								<label for="sml-reg-last"><?php esc_html_e( 'Last name', 'smart-login' ); ?> <span class="sml-required">*</span></label>
							</div>
						</div>
						<div class="sml-field-boxed">
							<input type="email" id="sml-reg-email" class="sml-input" name="email" autocomplete="email" placeholder=" " required>
							<label for="sml-reg-email"><?php esc_html_e( 'Email', 'smart-login' ); ?> <span class="sml-required">*</span></label>
						</div>
						<?php
						$sml_allowed_countries = SML_Countries::allowed();
						$sml_default_country   = isset( $sml_allowed_countries['us'] ) ? 'us' : (string) array_key_first( $sml_allowed_countries );
						?>
						<?php SML_Flags::sprite(); ?>
						<div class="sml-field-boxed">
							<div class="sml-phone-group">
								<div class="sml-phone-code">
									<span class="sml-phone-flag" data-sml-flag-wrap><?php echo SML_Flags::icon( $sml_default_country ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<select name="phone_country" id="sml-reg-phone-country" class="sml-input" aria-label="<?php esc_attr_e( 'Country code', 'smart-login' ); ?>" data-sml-flag-select>
										<?php foreach ( $sml_allowed_countries as $id => $country ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" data-max="<?php echo esc_attr( SML_Phone::max_length( $id ) ); ?>" data-flag="<?php echo esc_attr( base64_encode( SML_Flags::icon( $id ) ) ); ?>"<?php selected( $sml_default_country, $id ); ?>><?php echo esc_html( $country['dial'] . ' ' . $country['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<input type="tel" id="sml-reg-phone" class="sml-input" name="phone_number" data-sml-phone-input autocomplete="tel-national" inputmode="numeric" placeholder="<?php esc_attr_e( 'Phone number', 'smart-login' ); ?>" maxlength="<?php echo esc_attr( SML_Phone::max_length( $sml_default_country ) + 4 ); ?>" required>
							</div>
							<label for="sml-reg-phone"><?php esc_html_e( 'Mobile number', 'smart-login' ); ?> <span class="sml-required">*</span></label>
						</div>
						<div class="sml-field-boxed">
							<div class="sml-password-group">
								<input type="password" id="sml-reg-pass" class="sml-input" name="password" autocomplete="new-password" placeholder=" " required>
								<button type="button" class="sml-password-toggle" data-sml-password-toggle aria-label="<?php esc_attr_e( 'Show password', 'smart-login' ); ?>">
									<?php esc_html_e( 'Show', 'smart-login' ); ?>
								</button>
							</div>
							<label for="sml-reg-pass"><?php esc_html_e( 'Password', 'smart-login' ); ?> <span class="sml-required">*</span></label>
						</div>
						<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Create Account', 'smart-login' ); ?></button>
						<a class="sml-btn sml-btn--secondary" data-sml-tab="login" href="<?php echo $login_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Log In', 'smart-login' ); ?></a>
					</form>
				</div>
			<?php endif; ?>

			<div class="sml-panel" data-sml-panel="forgot"<?php echo 'forgot' === $initial_panel ? '' : ' hidden'; ?>>
				<div class="sml-heading">
					<div class="sml-heading-line1"><?php esc_html_e( 'Reset Your', 'smart-login' ); ?></div>
					<div class="sml-heading-line2"><?php esc_html_e( 'Password', 'smart-login' ); ?></div>
				</div>
				<p class="sml-verify-intro"><?php esc_html_e( 'Enter your email address and we\'ll send you a link to reset your password.', 'smart-login' ); ?></p>
				<form data-sml-form="forgot" novalidate>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
					<div class="sml-field-boxed">
						<input type="email" id="sml-forgot-email" class="sml-input" name="login" autocomplete="email" placeholder=" " required>
						<label for="sml-forgot-email"><?php esc_html_e( 'Email', 'smart-login' ); ?></label>
					</div>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Send Reset Link', 'smart-login' ); ?></button>
					<a class="sml-btn sml-btn--secondary" data-sml-tab="login" href="<?php echo $login_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Back to Log In', 'smart-login' ); ?></a>
				</form>
			</div>

			<div class="sml-panel" data-sml-panel="reset"<?php echo 'reset' === $initial_panel ? '' : ' hidden'; ?>>
				<div class="sml-heading">
					<div class="sml-heading-line1"><?php esc_html_e( 'Set a New', 'smart-login' ); ?></div>
					<div class="sml-heading-line2"><?php esc_html_e( 'Password', 'smart-login' ); ?></div>
				</div>
				<?php if ( $is_reset_link && ! $reset_key_valid ) : ?>
					<div class="sml-notice sml-notice--error"><?php esc_html_e( 'This password reset link is invalid or has expired.', 'smart-login' ); ?></div>
					<a class="sml-btn sml-btn--secondary" data-sml-tab="forgot" href="<?php echo $forgot_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Request a new link', 'smart-login' ); ?></a>
				<?php else : ?>
					<form data-sml-form="reset" novalidate>
						<input type="hidden" name="login" value="<?php echo esc_attr( $reset_login ); ?>">
						<input type="hidden" name="key" value="<?php echo esc_attr( $reset_key ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
						<div class="sml-field-boxed">
							<div class="sml-password-group">
								<input type="password" id="sml-reset-pass" class="sml-input" name="password" autocomplete="new-password" placeholder=" " required>
								<button type="button" class="sml-password-toggle" data-sml-password-toggle aria-label="<?php esc_attr_e( 'Show password', 'smart-login' ); ?>">
									<?php esc_html_e( 'Show', 'smart-login' ); ?>
								</button>
							</div>
							<label for="sml-reset-pass"><?php esc_html_e( 'New password', 'smart-login' ); ?></label>
						</div>
						<div class="sml-field-boxed">
							<div class="sml-password-group">
								<input type="password" id="sml-reset-pass-confirm" class="sml-input" name="password_confirm" autocomplete="new-password" placeholder=" " required>
								<button type="button" class="sml-password-toggle" data-sml-password-toggle aria-label="<?php esc_attr_e( 'Show password', 'smart-login' ); ?>">
									<?php esc_html_e( 'Show', 'smart-login' ); ?>
								</button>
							</div>
							<label for="sml-reset-pass-confirm"><?php esc_html_e( 'Confirm password', 'smart-login' ); ?></label>
						</div>
						<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Reset Password', 'smart-login' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="sml-panel" data-sml-panel="verify" hidden>
				<p class="sml-verify-intro"><?php echo esc_html( SML_Settings::get( 'verify_intro_text' ) ); ?></p>
				<form data-sml-form="verify" novalidate>
					<input type="hidden" name="user_id" data-sml-user-id value="">
					<input type="hidden" name="code" data-sml-otp-value value="">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
					<div class="sml-otp" data-sml-otp role="group" aria-label="<?php esc_attr_e( 'Verification code', 'smart-login' ); ?>">
						<?php $code_length = (int) SML_Settings::get( 'code_length', 6 ); ?>
						<?php for ( $i = 0; $i < $code_length; $i++ ) : ?>
							<input
								type="text"
								class="sml-otp-box sml-input"
								inputmode="numeric"
								pattern="[0-9]*"
								maxlength="1"
								autocomplete="<?php echo 0 === $i ? 'one-time-code' : 'off'; ?>"
								data-sml-otp-box
								aria-label="<?php echo esc_attr( sprintf( /* translators: %d: digit position */ __( 'Digit %d', 'smart-login' ), $i + 1 ) ); ?>">
						<?php endfor; ?>
					</div>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Verify', 'smart-login' ); ?></button>
					<p class="sml-resend-link">
						<button type="button" class="sml-link-btn" data-sml-resend><?php esc_html_e( 'Resend code', 'smart-login' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
