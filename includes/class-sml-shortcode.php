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

		$redirect_url = remove_query_arg( 'sml_verify' );

		if ( is_wp_error( $result ) ) {
			$redirect_url = add_query_arg( 'sml_verified', 'error', $redirect_url );
		} else {
			$user = get_user_by( 'id', $result );
			if ( $user ) {
				SML_Email::send_welcome( $user );
				wp_set_current_user( $result );
				wp_set_auth_cookie( $result );
			}
			$redirect_url = add_query_arg( 'sml_verified', 'success', $redirect_url );
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
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'registrationNonce' => wp_create_nonce( SML_Registration_Handler::NONCE_ACTION ),
				'loginNonce'        => wp_create_nonce( SML_Login_Handler::NONCE_ACTION ),
				'botProvider'       => $provider_key,
				'botSiteKey'        => 'none' === $provider_key ? '' : SML_Settings::get( 'bot_site_key' ),
				'i18n'              => array(
					'verifying'      => __( 'Verifying…', 'smart-login' ),
					'sending'        => __( 'Sending…', 'smart-login' ),
					'loggingIn'      => __( 'Logging in…', 'smart-login' ),
					'resend'         => __( 'Resend code', 'smart-login' ),
					'resendIn'       => __( 'Resend in %ds', 'smart-login' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'smart-login' ),
					'show'           => __( 'Show', 'smart-login' ),
					'hide'           => __( 'Hide', 'smart-login' ),
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

		$action         = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_panel  = ( 'register' === $action && $show_register ) ? 'register' : 'login';
		$register_url   = esc_url( add_query_arg( 'action', 'register' ) );
		$login_url      = esc_url( remove_query_arg( 'action' ) );

		ob_start();
		?>
		<div class="sml-card" data-sml-root>
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
					<div class="sml-field-boxed">
						<input type="email" id="sml-login-user" name="username" autocomplete="username" placeholder=" " required>
						<label for="sml-login-user"><?php esc_html_e( 'Email', 'smart-login' ); ?></label>
					</div>
					<div class="sml-field-boxed">
						<div class="sml-password-group">
							<input type="password" id="sml-login-pass" name="password" autocomplete="current-password" placeholder=" " required>
							<button type="button" class="sml-password-toggle" data-sml-password-toggle aria-label="<?php esc_attr_e( 'Show password', 'smart-login' ); ?>">
								<?php esc_html_e( 'Show', 'smart-login' ); ?>
							</button>
						</div>
						<label for="sml-login-pass"><?php esc_html_e( 'Password', 'smart-login' ); ?></label>
					</div>
					<a class="sml-forgot-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'smart-login' ); ?></a>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Log In', 'smart-login' ); ?></button>
					<p class="sml-resend-link" data-sml-resend-prompt hidden>
						<button type="button" class="sml-link-btn" data-sml-resend-from-login><?php esc_html_e( 'Resend verification email', 'smart-login' ); ?></button>
					</p>
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
								<input type="text" id="sml-reg-first" name="first_name" autocomplete="given-name" placeholder=" " required>
								<label for="sml-reg-first"><?php esc_html_e( 'First name', 'smart-login' ); ?> <span class="sml-required">*</span></label>
							</div>
							<div class="sml-field-boxed">
								<input type="text" id="sml-reg-last" name="last_name" autocomplete="family-name" placeholder=" " required>
								<label for="sml-reg-last"><?php esc_html_e( 'Last name', 'smart-login' ); ?> <span class="sml-required">*</span></label>
							</div>
						</div>
						<div class="sml-field-boxed">
							<input type="email" id="sml-reg-email" name="email" autocomplete="email" placeholder=" " required>
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
									<svg class="sml-phone-flag" viewBox="0 0 20 14" aria-hidden="true"><use href="#sml-flag-<?php echo esc_attr( $sml_default_country ); ?>" data-sml-flag-use></use></svg>
									<select name="phone_country" id="sml-reg-phone-country" aria-label="<?php esc_attr_e( 'Country code', 'smart-login' ); ?>" data-sml-flag-select>
										<?php foreach ( $sml_allowed_countries as $id => $country ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $sml_default_country, $id ); ?>><?php echo esc_html( $country['dial'] . ' ' . $country['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<input type="tel" id="sml-reg-phone" name="phone_number" autocomplete="tel-national" inputmode="numeric" placeholder="<?php esc_attr_e( 'Phone number', 'smart-login' ); ?>" required>
							</div>
							<label for="sml-reg-phone"><?php esc_html_e( 'Mobile number', 'smart-login' ); ?> <span class="sml-required">*</span></label>
						</div>
						<div class="sml-field-boxed">
							<div class="sml-password-group">
								<input type="password" id="sml-reg-pass" name="password" autocomplete="new-password" placeholder=" " required>
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

			<div class="sml-panel" data-sml-panel="verify" hidden>
				<p class="sml-verify-intro"><?php echo esc_html( SML_Settings::get( 'verify_intro_text' ) ); ?></p>
				<form data-sml-form="verify" novalidate>
					<input type="hidden" name="user_id" data-sml-user-id value="">
					<input type="hidden" name="code" data-sml-otp-value value="">
					<div class="sml-otp" data-sml-otp role="group" aria-label="<?php esc_attr_e( 'Verification code', 'smart-login' ); ?>">
						<?php $code_length = (int) SML_Settings::get( 'code_length', 6 ); ?>
						<?php for ( $i = 0; $i < $code_length; $i++ ) : ?>
							<input
								type="text"
								class="sml-otp-box"
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
