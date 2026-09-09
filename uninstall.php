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

$users = get_users(
	array(
		'meta_key' => 'sml_email_verified', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'fields'   => 'ID',
	)
);
foreach ( $users as $user_id ) {
	delete_user_meta( $user_id, 'sml_email_verified' );
}

wp_clear_scheduled_hook( 'sml_cleanup_expired_verifications' );
