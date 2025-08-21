<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

function wasmer_base_url()
{
  if (!WASMER_WEBSITE_URL) {
    // Fallback to calculating the right Website
    // URL from the GraphQL URL
    if (!WASMER_GRAPHQL_URL) {
      return 'https://wasmer.io';
    }
    $host = parse_url(WASMER_GRAPHQL_URL, PHP_URL_HOST);
    $host = str_replace('registry.', '', $host);

    return "https://$host";
  }

  return WASMER_WEBSITE_URL;
}

require_once __DIR__ . '/defines.php';
require_once __DIR__ . '/rest-api.php';
require_once __DIR__ . '/admin.php';

if (WASMER_CLI) {
  include_once __DIR__ . '/wp-cli.php';
}

// Disable automatic theme updates
add_filter('auto_update_theme', '__return_false');

// Disable automatic plugin updates
add_filter('auto_update_plugin', '__return_false');

// function dbg_var_dump($a, $var)
//     {
//         ob_start();
//         var_dump($a, $var);
//         $result = ob_get_clean();
//         $out = fopen('php://stdout', 'w'); //output handler
//         fputs($out, $result); //writing output operation
//         fclose($out); //closing handler
//     }

// add_filter('request_filesystem_credentials', function ($form_post, $type = '', $error = false, $context = '', $extra_fields = null, $allow_relaxed_file_ownership = false) {
//   dbg_var_dump("FORM POST: ", $form_post);
//   dbg_var_dump("TYPE: ", $type);
//   dbg_var_dump("ERROR: ", $error);
//   dbg_var_dump("CONTEXT: ", $context);
//   dbg_var_dump("EXTRA FIELDS: ", $extra_fields);
//   dbg_var_dump("ALLOW RELAXED FILE OWNERSHIP: ", $allow_relaxed_file_ownership);
//   return false;
// });

add_action('rest_api_init', function () {
  register_rest_route('wasmer/v1', '/liveconfig', array(
    'methods' => 'GET',
    'callback' => 'wasmer_liveconfig_callback',
  ));
  register_rest_route('wasmer/v1', '/check', array(
    'methods' => 'GET',
    'callback' => 'wasmer_check_callback',
  ));
  register_rest_route('wasmer/v1', '/magiclogin', array(
    'methods' => 'GET',
    'callback' => 'wasmer_magiclogin_callback',
  ));
});

// Hook to add admin menu
// add_action('admin_menu', 'wasmer_add_admin_menu');
// Hook to add a menu to the admin top bar
add_action('admin_bar_menu', 'wasmer_add_top_bar_menu', 100);

// Activation hook
register_activation_hook(__FILE__, 'wasmer_plugin_activate');
function wasmer_plugin_activate()
{
  // Code to run on activation, e.g., setting default options.
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wasmer_plugin_deactivate');
function wasmer_plugin_deactivate()
{
  // Code to run on deactivation, e.g., cleaning up options.
}

/**
 * Bypass Password-Protected Plugins to allow for REST API exceptions.
 *
 * @param mixed $result WP_Error if authentication error, null if authentication
 *                      method wasn't used, true if authentication succeeded.
 */
function wasmer_bypass_rest_api_auth_errors( $result ) {

  // Skip if request is authenticated
  if (!empty($result)) {
      return $result;
  }

  if (str_starts_with($_GET['rest_route'], '/wasmer/v1/')) {
      return true;
  }

  return $result;
}
add_filter( 'rest_authentication_errors', 'wasmer_bypass_rest_api_auth_errors', 20 );

/* -------------------------------------------------------------------------
 *  Stop WordPress from showing or fetching core updates
 * ---------------------------------------------------------------------- */

// Disable background update scheduler (automatic updates).
add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MAX);

/* -------------------------------------------------------------------------
 *  Block manual attempts to update the core and return a hard error
 * ---------------------------------------------------------------------- */

/**
 * Throws a WP_Error right before WP downloads the core package.
 *
 * @param mixed       $reply    Should be a file path or false; we return WP_Error.
 * @param string      $package  URL of the package WP is trying to download.
 * @param WP_Upgrader $upgrader The upgrader instance handling the request.
 *
 * @return WP_Error|mixed
 */
function wasmer_dcu_block_core_download($reply, $package, $upgrader)
{
  if ($upgrader instanceof Core_Upgrader) {
    return new WP_Error(
      'core_upgrade_disabled',
      __('Manual WordPress core upgrades are disabled. Please use the Wasmer settings to upgrade your WordPress core.')
    );
  }

  // For theme/plugin uploads we allow normal behaviour.
  return $reply;
}
add_filter('upgrader_pre_download', 'wasmer_dcu_block_core_download', 10, 3);

/* -------------------------------------------------------------------------
 *  UX: Warn administrators on the Updates screen
 * ---------------------------------------------------------------------- */
function wasmer_dcu_admin_notice()
{
  global $pagenow;

  // Check if we're on the core upgrade action page
  if ($pagenow === 'update-core.php') {
    $upgrade_link = sprintf("%s/id/%s/settings/wordpress", wasmer_base_url(), WASMER_APP_ID);
    $current = get_site_transient('update_core');
    $latest_version = isset($current->updates[0]->version) ? $current->updates[0]->version : '';
    if (isset($_GET['action']) && $_GET['action'] === 'do-core-upgrade') {
      wp_die(
        '<div class="notice notice-warning"><p>' .
          sprintf(
            __('Manual WordPress core upgrades are disabled.'),
          ) .
          '</p></div><div>' .
          sprintf(
            __('<p>You can upgrade from WordPress %s to <strong>WordPress %s</strong> using the Wasmer WordPress Settings.</p><p>%s</p>'),
            esc_html(get_bloginfo('version')),
            esc_html($latest_version),
            sprintf(
              '<a href="%s" class="button button-primary">%s</a>',
              esc_attr($upgrade_link),
              sprintf(
                esc_html__('Go to Wasmer WordPress Settings to upgrade WP'),
                esc_html($latest_version)
              )
            )
          ) .
          '</div>',
        __('WordPress Core Update Disabled'),
        array(
          'response' => 403,
          'back_link' => true,
        )
      );
    }

    if (current_user_can('update_core')) {
      echo '<div class="notice notice-info"><p>' .
        sprintf(
          __('<strong>WordPress %s</strong> is available for upgrade. %s'),
          esc_html($latest_version),
          sprintf(
            '<a href="%s">%s</a>',
            esc_attr($upgrade_link),
            sprintf(
              esc_html__('Update to version %s from Wasmer WordPress Settings'),
              esc_html($latest_version)
            )
          )
        ) .
        '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
  }
}
add_action('admin_notices', 'wasmer_dcu_admin_notice', 1);
