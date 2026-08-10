<?php
/**
 * Plugin Name: Wasmer Hosting Integration
 * Plugin URI: https://github.com/wasmerio/wp-wasmer
 * GitHub Plugin URI: https://github.com/wasmerio/wp-wasmer
 * Description: Integrates WordPress sites hosted on Wasmer with CDN cache controls, managed updates, dashboard access, and site migrations.
 * Author: Wasmer
 * Author URI: https://wasmer.io
 * x-release-please-start-version
 * Version: 0.5.0
 * x-release-please-end
 * Text Domain: wasmer
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package  wp-wasmer
 * @category Core
 * @author   Wasmer
 * x-release-please-start-version
 * @version  0.5.0
 * x-release-please-end
 */

// x-release-please-start-version
define( 'WP_WASMER_PLUGIN_VERSION', '0.5.0' );
// x-release-please-end

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define( 'WP_WASMER_PLUGIN_MINIMUM_PHP', '7.4' );
define( 'WP_WASMER_PLUGIN_MAIN_FILE', __FILE__ );
define( 'WP_WASMER_PLUGIN_DIR_PATH', plugin_dir_path( WP_WASMER_PLUGIN_MAIN_FILE ) );
define( 'WP_WASMER_PLUGIN_DIR_URL', plugin_dir_url( WP_WASMER_PLUGIN_MAIN_FILE ) );

function wasmer_uninstall()
{
    require_once __DIR__ . '/wasmer/migrate-import/sessions.php';
    wasmer_import_delete_all_data();
    wp_clear_scheduled_hook('wasmer_import_cleanup_event');
    delete_option('wasmer_cdn_auto_purge_enabled');
    delete_option('wasmer_cdn_cache_last_purged');
}
register_uninstall_hook(__FILE__, 'wasmer_uninstall');

function wasmer_deactivate()
{
    wp_clear_scheduled_hook('wasmer_import_cleanup_event');
}
register_deactivation_hook(__FILE__, 'wasmer_deactivate');


function wasmer_load() {
	// Check for supported PHP version.
	if ( version_compare( phpversion(), WP_WASMER_PLUGIN_MINIMUM_PHP, '<' ) ) {
		add_action( 'admin_notices', 'wasmer_display_php_version_notice' );
		return;
	}

	require_once __DIR__ . '/wasmer/wasmer.php';
}

function wasmer_display_php_version_notice() {
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: 1: required version, 2: currently used version */
		esc_html__( 'WP Wasmer requires at least PHP version %1$s. Your site is currently running on PHP %2$s.', 'wasmer' ),
		esc_html( WP_WASMER_PLUGIN_MINIMUM_PHP ),
		esc_html( phpversion() )
	);
	echo '</p></div>';
}

wasmer_load();
