<?php
/**
 * "Smart Login" section on the user edit / profile screen: mobile number,
 * national ID and account verification state.
 *
 * Only users who can edit other users may change the values. A person
 * editing their own profile sees them read-only — letting someone swap a
 * verified mobile number for an unverified one would defeat verification.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_User_Profile {

	const NONCE_ACTION = 'sml_user_profile';
	const NONCE_FIELD  = 'sml_profile_nonce';

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'save' ) );
	}

	protected static function can_edit() {
		return current_user_can( 'edit_users' );
	}

	/**
	 * True when the submitted form carries a valid nonce from an allowed editor.
	 */
	protected static function submitted() {
		return self::can_edit()
			&& isset( $_POST[ self::NONCE_FIELD ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
	}

	/**
	 * @param WP_User $user
	 */
	public static function render( $user ) {
		$phone       = (string) get_user_meta( $user->ID, 'sml_phone', true );
		$national_id = (string) get_user_meta( $user->ID, SML_Iran::NATIONAL_META, true );
		$can_edit    = self::can_edit();
		$show_id     = SML_Iran::enabled() || '' !== $national_id;
		$verified    = SML_Verification::is_verified( $user->ID );
		$tracked     = SML_Verification::has_verification_record( $user->ID );

		// Nothing useful to show for an account this plugin knows nothing about
		// and an editor who has nothing to add.
		if ( ! $can_edit && '' === $phone && '' === $national_id && ! $tracked ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Smart Login', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="sml_profile_phone"><?php esc_html_e( 'Mobile number', 'smart-login' ); ?></label></th>
				<td>
					<?php if ( $can_edit ) : ?>
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="text" name="sml_profile_phone" id="sml_profile_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" dir="ltr" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Use 09123456789 for an Iranian number, or +<country code><number>. Leave empty to remove it.', 'smart-login' ); ?></p>
					<?php else : ?>
						<span dir="ltr"><?php echo '' !== $phone ? esc_html( $phone ) : '&mdash;'; ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $show_id ) : ?>
				<tr>
					<th><label for="sml_profile_national_id"><?php esc_html_e( 'National ID', 'smart-login' ); ?></label></th>
					<td>
						<?php if ( $can_edit ) : ?>
							<input type="text" name="sml_profile_national_id" id="sml_profile_national_id" value="<?php echo esc_attr( $national_id ); ?>" class="regular-text" dir="ltr" inputmode="numeric" maxlength="10" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Ten digits. Leave empty to remove it.', 'smart-login' ); ?></p>
						<?php else : ?>
							<span dir="ltr"><?php echo '' !== $national_id ? esc_html( $national_id ) : '&mdash;'; ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( $tracked ) : ?>
				<tr>
					<th><?php esc_html_e( 'Account verification', 'smart-login' ); ?></th>
					<td>
						<?php if ( $verified ) : ?>
							<span style="color:#12805c;"><?php esc_html_e( 'Verified', 'smart-login' ); ?></span>
							<?php if ( 'none' === get_user_meta( $user->ID, 'sml_verified_via', true ) ) : ?>
								<p class="description"><?php esc_html_e( 'Signed up while verification was switched off, so this address and number were never confirmed.', 'smart-login' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<span style="color:#92590a;"><?php esc_html_e( 'Pending', 'smart-login' ); ?></span>
						<?php endif; ?>
						<?php if ( SML_Verification::has_placeholder_email( $user->ID ) ) : ?>
							<p class="description"><?php esc_html_e( 'Registered with a mobile number only, so there is no real email address on this account.', 'smart-login' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Reads and checks the submitted values.
	 *
	 * @param WP_Error $errors
	 * @param bool     $update
	 * @param object   $user
	 */
	public static function validate( $errors, $update, $user ) {
		if ( ! $update || ! self::submitted() ) {
			return;
		}

		$values = self::read_values();
		$uid    = isset( $user->ID ) ? (int) $user->ID : 0;

		if ( '' !== $values['phone'] ) {
			if ( '' === $values['e164'] ) {
				$errors->add( 'sml_phone', __( 'Please enter a valid mobile number.', 'smart-login' ) );
			} else {
				foreach ( SML_Phone::users_for( $values['e164'] ) as $other ) {
					if ( (int) $other->ID !== $uid && SML_Verification::is_verified( $other->ID ) ) {
						$errors->add( 'sml_phone', __( 'An account with that mobile number already exists.', 'smart-login' ) );
						break;
					}
				}
			}
		}

		if ( '' !== $values['national_id'] ) {
			if ( ! SML_Iran::is_valid_national_id( $values['national_id'] ) ) {
				$errors->add( 'sml_national_id', __( 'Please enter a valid national ID (10 digits).', 'smart-login' ) );
			} elseif ( SML_Iran::national_id_exists( $values['national_id'], $uid ) ) {
				$errors->add( 'sml_national_id', __( 'An account with that national ID already exists.', 'smart-login' ) );
			}
		}
	}

	/**
	 * Runs only after core accepted the whole profile, so nothing is saved
	 * when validate() reported an error.
	 *
	 * @param int $user_id
	 */
	public static function save( $user_id ) {
		if ( ! self::submitted() ) {
			return;
		}

		$values = self::read_values();

		if ( '' === $values['phone'] ) {
			delete_user_meta( $user_id, 'sml_phone' );
			delete_user_meta( $user_id, 'sml_phone_e164' );
		} elseif ( '' !== $values['e164'] ) {
			update_user_meta( $user_id, 'sml_phone_e164', $values['e164'] );
			update_user_meta( $user_id, 'sml_phone', SML_Phone::display_from_e164( $values['e164'] ) );
		}

		if ( isset( $_POST['sml_profile_national_id'] ) ) {
			if ( '' === $values['national_id'] ) {
				delete_user_meta( $user_id, SML_Iran::NATIONAL_META );
			} else {
				update_user_meta( $user_id, SML_Iran::NATIONAL_META, $values['national_id'] );
			}
		}
	}

	/**
	 * @return array{phone:string,e164:string,national_id:string}
	 */
	protected static function read_values() {
		$phone = isset( $_POST['sml_profile_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sml_profile_phone'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nid   = isset( $_POST['sml_profile_national_id'] ) ? preg_replace( '/\D/', '', SML_Iran::to_latin_digits( sanitize_text_field( wp_unslash( $_POST['sml_profile_national_id'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return array(
			'phone'       => $phone,
			'e164'        => '' !== $phone ? SML_Phone::parse_e164( $phone ) : '',
			'national_id' => $nid,
		);
	}
}
