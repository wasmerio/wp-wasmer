<?php
/**
 * Plugin Name: Wasmer Migrate
 * Plugin URI: https://github.com/wasmerio/wp-wasmer
 * Description: Migrate a WordPress site into a Wasmer WordPress app.
 * Author: Wasmer
 * Author URI: https://wasmer.io
 * Version: 0.1.0
 * Text Domain: wasmer-migrate
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WASMER_MIGRATE_VERSION', '0.1.0');
define('WASMER_MIGRATE_PLUGIN_FILE', __FILE__);
define('WASMER_MIGRATE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WASMER_MIGRATE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/code-parser.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/db-export.php';
require_once __DIR__ . '/exporter.php';
require_once __DIR__ . '/transfer-client.php';
require_once __DIR__ . '/auto-app.php';
require_once __DIR__ . '/admin-page.php';

function wasmer_migrate_uninstall()
{
    wasmer_migrate_delete_all_data();
    delete_option(wasmer_migrate_state_key());
    delete_option(wasmer_migrate_cancelled_runs_key());
    delete_option(wasmer_migrate_auto_lock_key());
}
register_uninstall_hook(__FILE__, 'wasmer_migrate_uninstall');

if (defined('WP_CLI') && WP_CLI) {
    class Wasmer_Migrate_Command
    {
        public function connect($args, $assoc_args)
        {
            $result = wasmer_migrate_connect($args[0] ?? '');
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('Connected to destination.');
        }

        public function plan($args, $assoc_args)
        {
            $result = wasmer_migrate_prepare_export();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::line(wp_json_encode([
                'database_size' => $result['database']['size'] ?? 0,
                'files' => count($result['files'] ?? []),
                'status' => $result['status'],
                'preflight' => $result['manifest']['preflight'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        public function start($args, $assoc_args)
        {
            $state = wasmer_migrate_get_state();
            if (empty($state['manifest'])) {
                $prepared = wasmer_migrate_prepare_export();
                if (is_wp_error($prepared)) {
                    WP_CLI::error($prepared->get_error_message());
                }
            }
            $result = wasmer_migrate_transfer();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('Transfer complete.');
        }

        public function status($args, $assoc_args)
        {
            $state = wasmer_migrate_get_state();
            unset($state['code'], $state['destination']['token'], $state['auto_app']['token'], $state['auto_app']['app_token']);
            WP_CLI::line(wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        public function resume($args, $assoc_args)
        {
            $result = wasmer_migrate_transfer();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('Transfer complete.');
        }

        public function cancel($args, $assoc_args)
        {
            wasmer_migrate_reset_state();
            WP_CLI::success('Migration state cleared.');
        }

        public function auto($args, $assoc_args)
        {
            $result = wasmer_migrate_auto_app_import([
                'graphql_url' => $assoc_args['graphql-url'] ?? '',
                'token' => $assoc_args['token'] ?? '',
                'owner' => $assoc_args['owner'] ?? '',
                'region' => $assoc_args['region'] ?? '',
                'perish_at' => $assoc_args['perish-at'] ?? '',
                'app_name' => $assoc_args['app-name'] ?? '',
            ]);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::line(wp_json_encode(wasmer_migrate_public_state($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            WP_CLI::success('Automatic Wasmer import completed.');
        }
    }

    WP_CLI::add_command('wasmer-migrate', 'Wasmer_Migrate_Command');
}
