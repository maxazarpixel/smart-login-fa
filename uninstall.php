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

$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('sml_email_verified', 'sml_phone', 'sml_last_login', 'sml_reset_requests', 'sml_reset_requested_at')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sml\_legacy\_reminder\_%' OR option_name LIKE '\_transient\_timeout\_sml\_legacy\_reminder\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

wp_clear_scheduled_hook( 'sml_cleanup_expired_verifications' );
