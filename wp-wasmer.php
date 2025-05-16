<?php
/**
 * Plugin Name: WP Wasmer
 * Plugin URI: https://github.com/wasmerio/wp-wasmer
 * GitHub Plugin URI: https://github.com/wasmerio/wp-wasmer
 * Description: Wasmer Plugin for WordPress
 * Author: Wasmer
 * Author URI: https://wasmer.io
 * Version: 0.1.6
 * Text Domain: wasmer
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package  wp-wasmer
 * @category Core
 * @author   Wasmer
 * @version  0.1.6
 */

define( 'WP_WASMER_PLUGIN_VERSION', '0.1.6' );

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define( 'WP_WASMER_PLUGIN_MINIMUM_PHP', '7.4' );
define( 'WP_WASMER_PLUGIN_MAIN_FILE', __FILE__ );
define( 'WP_WASMER_PLUGIN_DIR_PATH', plugin_dir_path( WP_WASMER_PLUGIN_MAIN_FILE ) );
define( 'WP_WASMER_PLUGIN_DIR_URL', plugin_dir_url( WP_WASMER_PLUGIN_MAIN_FILE ) );


function wp_wasmer_load() {
	// Check for supported PHP version.
	if ( version_compare( phpversion(), WP_WASMER_PLUGIN_MINIMUM_PHP, '<' ) ) {
		add_action( 'admin_notices', 'wp_wasmer_display_php_version_notice' );
		return;
	}

	require_once __DIR__ . '/wasmer/wasmer.php';
}

function wp_wasmer_display_php_version_notice() {
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: 1: required version, 2: currently used version */
		esc_html__( 'WP Wasmer requires at least PHP version %1$s. Your site is currently running on PHP %2$s.', 'wasmer' ),
		esc_html( WP_WASMER_PLUGIN_MINIMUM_PHP ),
		esc_html( phpversion() )
	);
	echo '</p></div>';
}

wp_wasmer_load();
