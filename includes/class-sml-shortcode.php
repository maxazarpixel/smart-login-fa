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

			<?php if ( $show_register ) : ?>
				<div class="sml-tabs" role="tablist">
					<button type="button" class="sml-tab active" data-sml-tab="login" role="tab" aria-selected="true"><?php esc_html_e( 'Log In', 'smart-login' ); ?></button>
					<button type="button" class="sml-tab" data-sml-tab="register" role="tab" aria-selected="false"><?php esc_html_e( 'Register', 'smart-login' ); ?></button>
				</div>
			<?php endif; ?>

			<div class="sml-message" data-sml-message hidden></div>

			<div class="sml-panel" data-sml-panel="login">
				<form data-sml-form="login" novalidate>
					<div class="sml-field">
						<label for="sml-login-user"><?php esc_html_e( 'Username or Email', 'smart-login' ); ?></label>
						<input type="text" id="sml-login-user" name="username" autocomplete="username" required>
					</div>
					<div class="sml-field">
						<label for="sml-login-pass"><?php esc_html_e( 'Password', 'smart-login' ); ?></label>
						<input type="password" id="sml-login-pass" name="password" autocomplete="current-password" required>
					</div>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Log In', 'smart-login' ); ?></button>
					<p class="sml-resend-link" data-sml-resend-prompt hidden>
						<button type="button" class="sml-link-btn" data-sml-resend-from-login><?php esc_html_e( 'Resend verification email', 'smart-login' ); ?></button>
					</p>
				</form>
			</div>

			<?php if ( $show_register ) : ?>
				<div class="sml-panel" data-sml-panel="register" hidden>
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
						<div class="sml-field">
							<label for="sml-reg-user"><?php esc_html_e( 'Username', 'smart-login' ); ?></label>
							<input type="text" id="sml-reg-user" name="username" autocomplete="username" required>
						</div>
						<div class="sml-field">
							<label for="sml-reg-email"><?php esc_html_e( 'Email', 'smart-login' ); ?></label>
							<input type="email" id="sml-reg-email" name="email" autocomplete="email" required>
						</div>
						<div class="sml-field">
							<label for="sml-reg-pass"><?php esc_html_e( 'Password', 'smart-login' ); ?></label>
							<input type="password" id="sml-reg-pass" name="password" autocomplete="new-password" required>
						</div>
						<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Create Account', 'smart-login' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<div class="sml-panel" data-sml-panel="verify" hidden>
				<p class="sml-verify-intro"><?php esc_html_e( 'We sent a verification code to your email. Enter it below, or click the link in the email.', 'smart-login' ); ?></p>
				<p class="sml-countdown"><?php esc_html_e( 'Code expires in', 'smart-login' ); ?> <span data-sml-countdown>--:--</span></p>
				<form data-sml-form="verify" novalidate>
					<input type="hidden" name="user_id" data-sml-user-id value="">
					<div class="sml-field">
						<label for="sml-code"><?php esc_html_e( 'Verification code', 'smart-login' ); ?></label>
						<input type="text" id="sml-code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
					</div>
					<button type="submit" class="sml-btn sml-btn--primary"><?php esc_html_e( 'Verify', 'smart-login' ); ?></button>
					<button type="button" class="sml-btn sml-btn--secondary" data-sml-resend><?php esc_html_e( 'Resend code', 'smart-login' ); ?></button>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
