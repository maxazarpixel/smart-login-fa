<?php
/**
 * Plugin bootstrap: loads the classes and wires activation, deactivation and
 * upgrade handling. Kept out of the main plugin file so it is only compiled
 * once smart-login.php has checked that the original Smart Login plugin is
 * not already running (PHP defines top-level functions at compile time).
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SML_PLUGIN_DIR . 'includes/class-sml-loader.php';
require_once SML_PLUGIN_DIR . 'includes/class-sml-disposable-email.php';
require_once SML_PLUGIN_DIR . 'includes/class-sml-settings.php';
require_once SML_PLUGIN_DIR . 'includes/class-sml-countries.php';

/**
 * Creates/upgrades the sml_verifications table via dbDelta.
 * Safe to call on every activation and on version-mismatch auto-upgrade.
 */
function sml_install_db() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'sml_verifications';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		code_hash VARCHAR(255) NOT NULL,
		link_token_hash VARCHAR(255) NOT NULL,
		code_expires_at DATETIME NOT NULL,
		link_expires_at DATETIME NOT NULL,
		attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		last_sent_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'sml_db_version', SML_DB_VERSION );
}

function sml_activate() {
	sml_install_db();

	if ( false === get_option( 'sml_login_settings', false ) ) {
		add_option( 'sml_login_settings', SML_Settings::defaults() );
	}

	if ( ! wp_next_scheduled( 'sml_cleanup_expired_verifications' ) ) {
		wp_schedule_event( time(), 'hourly', 'sml_cleanup_expired_verifications' );
	}
}
register_activation_hook( SML_PLUGIN_FILE, 'sml_activate' );

function sml_deactivate() {
	wp_clear_scheduled_hook( 'sml_cleanup_expired_verifications' );
}
register_deactivation_hook( SML_PLUGIN_FILE, 'sml_deactivate' );

/**
 * Re-creates the hourly cleanup task if it is missing — e.g. after switching
 * from the original Smart Login plugin, which clears it when deactivated.
 */
function sml_ensure_cron() {
	if ( ! wp_next_scheduled( 'sml_cleanup_expired_verifications' ) ) {
		wp_schedule_event( time(), 'hourly', 'sml_cleanup_expired_verifications' );
	}
}
add_action( 'init', 'sml_ensure_cron' );

/**
 * Keeps the DB schema current if the plugin was updated without a reactivation.
 */
function sml_maybe_upgrade_db() {
	if ( get_option( 'sml_db_version' ) !== SML_DB_VERSION ) {
		sml_install_db();
	}
}
add_action( 'plugins_loaded', 'sml_maybe_upgrade_db' );

add_action( 'plugins_loaded', array( 'SML_Loader', 'init' ) );
