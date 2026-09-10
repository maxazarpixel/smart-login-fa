<?php
/**
 * Plugin Name:       Smart Login
 * Plugin URI:        https://www.azarpixel.com
 * Description:       Modern, secure login & registration plugin with email verification (code + link), for standard WordPress and WooCommerce sites.
 * Version:           1.10.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Maziyar Nikbakhsh
 * Author URI:        https://www.azarpixel.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-login
 * Domain Path:       /languages
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SML_VERSION', '1.10.0' );
define( 'SML_DB_VERSION', '1.0.0' );
define( 'SML_PLUGIN_FILE', __FILE__ );
define( 'SML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SML_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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
register_activation_hook( __FILE__, 'sml_activate' );

function sml_deactivate() {
	wp_clear_scheduled_hook( 'sml_cleanup_expired_verifications' );
}
register_deactivation_hook( __FILE__, 'sml_deactivate' );

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
