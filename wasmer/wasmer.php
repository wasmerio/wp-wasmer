<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/defines.php';
require_once __DIR__ . '/rest-api.php';
require_once __DIR__ . '/admin.php';

if (WASMER_CLI) {
    include_once __DIR__ . '/wp-cli.php';
}

// Disable automatic core updates
add_filter('automatic_updater_disabled', '__return_true');

// Disable automatic theme updates
add_filter('auto_update_theme', '__return_false');

// Disable automatic plugin updates
add_filter('auto_update_plugin', '__return_false');

add_action( 'rest_api_init', function () {
    register_rest_route( 'wasmer/v1', '/liveconfig', array(
      'methods' => 'GET',
      'callback' => 'wasmer_liveconfig_callback',
    ) );
    register_rest_route( 'wasmer/v1', '/check', array(
        'methods' => 'GET',
        'callback' => 'wasmer_check_callback',
      ) );
    register_rest_route( 'wasmer/v1', '/magiclogin', array(
        'methods' => 'GET',
        'callback' => 'wasmer_magiclogin_callback',
      ) );
  } );

// Hook to add admin menu
// add_action('admin_menu', 'wasmer_add_admin_menu');
// Hook to add a menu to the admin top bar
add_action('admin_bar_menu', 'wasmer_add_top_bar_menu', 100);

// Activation hook
register_activation_hook(__FILE__, 'wasmer_plugin_activate');
function wasmer_plugin_activate() {
    // Code to run on activation, e.g., setting default options.
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wasmer_plugin_deactivate');
function wasmer_plugin_deactivate() {
    // Code to run on deactivation, e.g., cleaning up options.
}
