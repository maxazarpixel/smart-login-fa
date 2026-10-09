<?php
/**
 * Fires only on plugin deletion (not on deactivation).
 *
 * @package Smart_Login
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'sml_login_settings', array() );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sml_verifications" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'sml_login_settings' );
delete_option( 'sml_db_version' );
delete_option( 'sml_log_key' );

$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('sml_email_verified', 'sml_phone', 'sml_last_login', 'sml_reset_requests', 'sml_reset_requested_at', 'sml_google_sub', 'sml_national_id', 'sml_phone_e164', 'sml_placeholder_email', 'sml_reset_sms')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// The log directory carries a random filename, so remove the whole folder.
$sml_uploads = wp_upload_dir();
if ( empty( $sml_uploads['error'] ) ) {
	$sml_log_dir = trailingslashit( $sml_uploads['basedir'] ) . 'smart-login-logs';
	if ( is_dir( $sml_log_dir ) ) {
		foreach ( (array) glob( $sml_log_dir . '/*' ) as $sml_log_file ) {
			if ( is_file( $sml_log_file ) ) {
				wp_delete_file( $sml_log_file );
			}
		}
		@rmdir( $sml_log_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.directory_rmdir
	}
}

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sml\_legacy\_reminder\_%' OR option_name LIKE '\_transient\_timeout\_sml\_legacy\_reminder\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

wp_clear_scheduled_hook( 'sml_cleanup_expired_verifications' );
