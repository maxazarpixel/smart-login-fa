<?php
/**
 * Plugin Name:       Smart Login FA
 * Plugin URI:        https://www.azarpixel.com
 * Description:       Modern, secure login & registration plugin with email verification (code + link), for standard WordPress and WooCommerce sites.
 * Version:           1.34.1
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

// Smart Login FA shares its classes, constants and settings with the original
// Smart Login plugin, so only one of the two can run. If the original is
// already loaded, step aside — and once this plugin has been activated,
// switch the original off so the site carries on with this one.
if ( defined( 'SML_VERSION' ) ) {
	if ( ! function_exists( 'smlfa_take_over' ) ) {
		/**
		 * Runs after WordPress has saved the activation, so the change is not
		 * overwritten with the list it read before.
		 *
		 * @param string $plugin Basename of the plugin that was just activated.
		 */
		function smlfa_take_over( $plugin ) {
			$other = defined( 'SML_PLUGIN_BASENAME' ) ? SML_PLUGIN_BASENAME : '';
			if ( plugin_basename( __FILE__ ) !== $plugin || '' === $other || $other === $plugin ) {
				return;
			}
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			// Silent: keep the other copy's scheduled task and data in place,
			// this plugin uses the same ones.
			deactivate_plugins( $other, true );
		}

		function smlfa_conflict_notice() {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Smart Login FA could not start because another copy of Smart Login is active. Activate Smart Login FA from the Plugins screen and the other copy is switched off automatically; your settings are shared.', 'smart-login' )
				. '</p></div>';
		}
	}

	add_action( 'activated_plugin', 'smlfa_take_over' );
	add_action( 'admin_notices', 'smlfa_conflict_notice' );
	return;
}

define( 'SML_VERSION', '1.34.1' );
define( 'SML_DB_VERSION', '1.0.0' );
define( 'SML_PLUGIN_FILE', __FILE__ );
define( 'SML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SML_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SML_PLUGIN_DIR . 'includes/bootstrap.php';
