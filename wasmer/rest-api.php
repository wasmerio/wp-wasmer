<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/defines.php';

function wasmer_get_plugin_slug_from_path($path)
{
    $slug = dirname($path);
    if ($slug === '.') {
        $slug = basename($path, '.php');
    }

    return $slug;
}

function wasmer_get_plugin_slugs_from_paths($paths)
{
    // WP-CLI's plugin "name" field is derived from the installed plugin path,
    // not from the wordpress.org update API slug. When multiple plugin files
    // collapse to the same slug, WP-CLI falls back to the path without .php.
    $slugs = [];
    $duplicates = [];

    foreach ($paths as $path) {
        $slug = wasmer_get_plugin_slug_from_path($path);
        $slugs[$path] = $slug;
        if (!isset($duplicates[$slug])) {
            $duplicates[$slug] = [];
        }
        $duplicates[$slug][] = $path;
    }

    foreach ($duplicates as $paths_for_slug) {
        if (count($paths_for_slug) <= 1) {
            continue;
        }

        foreach ($paths_for_slug as $path) {
            $slugs[$path] = preg_replace('/\.php$/', '', $path);
        }
    }

    return $slugs;
}

function wasmer_get_plugin_status($path)
{
    if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($path)) {
        return 'active-network';
    }

    if (is_plugin_active($path)) {
        return 'active';
    }

    return 'inactive';
}

function wasmer_get_theme_status($slug, $active_theme_slug, $active_theme_template_slug)
{
    if ($active_theme_slug == $slug) {
        return 'active';
    }

    if ($active_theme_template_slug == $slug) {
        return 'parent';
    }

    return 'inactive';
}

function wasmer_get_mu_plugin_liveconfig($path, $plugin)
{
    $slug = wasmer_get_plugin_slug_from_path($path);

    return [
        'slug' => $slug,
        'status' => 'must-use',
        'auto_update' => 'off',
        'icon' => null,
        'url' => null,
        'name' => $slug,
        'title' => $plugin['Name'] ?? $plugin['Title'] ?? $path,
        'version' => $plugin['Version'] ?? '',
        'description' => $plugin['Description'] ?? '',
        'is_active' => true,
        'latest_version' => null,
    ];
}

function wasmer_get_dropin_liveconfig($path, $plugin, $dropin_descriptions)
{
    return [
        'slug' => $path,
        'status' => 'dropin',
        'auto_update' => 'off',
        'icon' => null,
        'url' => null,
        'name' => $path,
        'title' => $plugin['Name'] ?? $plugin['Title'] ?? $path,
        'version' => $plugin['Version'] ?? '',
        'description' => $dropin_descriptions[$path][0] ?? $plugin['Description'] ?? '',
        'is_active' => true,
        'latest_version' => null,
    ];
}

function wasmer_get_liveconfig_data()
{
    global $wpdb;
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('wp_get_themes')) {
        require_once ABSPATH . 'wp-includes/theme.php';
    }

    if (!function_exists('wp_count_posts')) {
        require_once ABSPATH . 'wp-includes/post.php';
    }

    if (!function_exists('get_users') || !function_exists('count_users')) {
        require_once ABSPATH . 'wp-includes/user.php';
    }

    if (!function_exists('get_site_transient')) {
        require_once ABSPATH . 'wp-includes/option.php';
    }

    $plugins = get_plugins();
    $mu_plugins = function_exists('get_mu_plugins') ? get_mu_plugins() : [];
    $dropins = function_exists('get_dropins') ? get_dropins() : [];
    $dropin_descriptions = function_exists('_get_dropins') ? _get_dropins() : [];
    $themes = wp_get_themes();
    $auto_update_plugins = get_site_option('auto_update_plugins');
    $auto_update_themes = get_site_option('auto_update_themes');
    $update_plugins = get_site_transient('update_plugins');
    $update_themes = get_site_transient('update_themes');
    $update_core = get_site_transient('update_core');

    if (!is_array($auto_update_plugins)) {
        $auto_update_plugins = [];
    }

    if (!is_array($auto_update_themes)) {
        $auto_update_themes = [];
    }

    if (!is_object($update_plugins)) {
        $update_plugins = (object) [
            'response' => [],
            'no_update' => [],
        ];
    } else {
        $update_plugins->response = is_array($update_plugins->response ?? null) ? $update_plugins->response : [];
        $update_plugins->no_update = is_array($update_plugins->no_update ?? null) ? $update_plugins->no_update : [];
    }

    if (!is_object($update_themes)) {
        $update_themes = (object) [
            'response' => [],
            'no_update' => [],
        ];
    } else {
        $update_themes->response = is_array($update_themes->response ?? null) ? $update_themes->response : [];
        $update_themes->no_update = is_array($update_themes->no_update ?? null) ? $update_themes->no_update : [];
    }

    $update_core_updates = is_object($update_core) && is_array($update_core->updates ?? null)
        ? $update_core->updates
        : [];

    $plugin_slugs = wasmer_get_plugin_slugs_from_paths(array_keys($plugins));

    $plugins = array_map(function ($path, $plugin) use ($update_plugins, $plugin_slugs, $auto_update_plugins) {
        $slug = $plugin_slugs[$path];
        $status = wasmer_get_plugin_status($path);
        $transient = $update_plugins->response[$path] ?? $update_plugins->no_update[$path] ?? null;
        return [
            'slug' => $slug,
            'status' => $status,
            'auto_update' => in_array($path, $auto_update_plugins, true) ? 'on' : 'off',
            'icon' => $transient->icons['1x'] ?? null,
            'url' => $transient->url ?? null,
            'name' => $slug,
            'title' => $plugin['Name'],
            'version' => $plugin['Version'] ?? '',
            'description' => $plugin['Description'],
            'is_active' => $status === 'active' || $status === 'active-network',
            'latest_version' => $transient->new_version ?? null,
        ];
    }, array_keys($plugins), $plugins);

    $mu_plugins = array_map(function ($path, $plugin) {
        return wasmer_get_mu_plugin_liveconfig($path, $plugin);
    }, array_keys($mu_plugins), $mu_plugins);

    $dropins = array_map(function ($path, $plugin) use ($dropin_descriptions) {
        return wasmer_get_dropin_liveconfig($path, $plugin, $dropin_descriptions);
    }, array_keys($dropins), $dropins);

    $plugins = array_merge($plugins, $mu_plugins, $dropins);

    $active_theme_slug = function_exists('get_stylesheet') ? get_stylesheet() : get_option('stylesheet');
    $active_theme_template_slug = function_exists('get_template') ? get_template() : get_option('template');

    $themes = array_map(function ($slug, $theme) use ($update_themes, $auto_update_themes, $active_theme_slug, $active_theme_template_slug) {
        $status = wasmer_get_theme_status($slug, $active_theme_slug, $active_theme_template_slug);
        $transient = $update_themes->response[$slug] ?? $update_themes->no_update[$slug] ?? null;
        return [
            'slug' => $slug,
            'status' => $status,
            'auto_update' => in_array($slug, $auto_update_themes, true) ? 'on' : 'off',
            'name' => $slug,
            'title' => $theme->name,
            'version' => $theme->version,
            'latest_version' => $transient["new_version"] ?? null,
            'is_active' => $status === 'active',
        ];
    }, array_keys($themes), $themes);

    $user_count = count_users();

    return [
        'liveconfig_version' => '1',
        'wasmer_plugin' => [
            'version' => WP_WASMER_PLUGIN_VERSION,
            'dir' => WP_WASMER_PLUGIN_DIR_PATH,
            'url' => WP_WASMER_PLUGIN_DIR_URL,
        ],
        'wordpress' => [
            'version' => get_bloginfo('version'),
            'latest_version' => $update_core_updates[0]->version ?? null,
            'url' => home_url(),
            'language' => get_locale(),
            'timezone' => date_default_timezone_get(),
            'debug' => WP_DEBUG,
            'debug_log' => WP_DEBUG_LOG,
            'is_main_site' => is_main_site(),
            'plugins' => $plugins,
            'themes' => $themes,
            'users' => [
                'total' => $user_count['total_users'],
                'admins' => $user_count['avail_roles']['administrator'] ?? 0,
                'main_admin_id' => wasmer_get_user_id(""),
            ],
            'posts' => ['count' => wp_count_posts('post')->publish],
            'pages' => ['count' => wp_count_posts('page')->publish],
        ],
        'php' => [
            'version' => phpversion(),
            'architecture' => PHP_INT_SIZE === 8 ? '64' : '32',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'max_input_vars' => ini_get('max_input_vars'),
        ],
        'mysql' => [
            'version' => $wpdb->db_version(),
            'server' => $wpdb->db_server_info(),
        ],
    ];
}


function wasmer_auto_login($args)
{
    // Note: important to not use new WP_REST_Response($data);
    // and bypass the default response of the rest api,
    // as it will set the `Expires` header to 0 making cookies
    // expire immediately if being proxied by Cloudflare or other
    // CDN proxies.
    $redirect_page = $args['redirect_page'];

    if (is_user_logged_in()) {
        // If the user is logged in, but the user_id is not set,
        // we need to log the user in. This can happen if the user
        // is logged in via a different method, such as a cookie.
        $user_id = get_current_user_id();
        if (!$user_id) {
            do_action('wasmer_autologin_user_logged_in', $args);

            wasmer_callback($args);

            header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Location: ' . $redirect_page);
            http_response_code(302);
            exit;
        }
    }

    // User not logged in
    $user_id       = wasmer_get_user_id($args['email']);
    $user          = get_user_by('ID', $user_id);
    if (!$user) {
        wasmer_callback($args);

        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $redirect_page);
        http_response_code(302);
        exit;
    }

    $login_username = $user->user_login;
    wp_set_current_user($user_id, $login_username);
    wp_set_auth_cookie($user_id);
    do_action('wp_login', $login_username, $user);
    do_action('wasmer_autologin', $args);

    wasmer_callback($args);

    header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $redirect_page);
    http_response_code(302);
    exit;
}

function wasmer_get_user_id($email)
{
    $admins = get_users([
        'role' => 'administrator',
        'search' => '*' . $email . '*',
        'search_columns' => ['user_email'],
    ]);
    if (isset($admins[0]->ID)) {
        return $admins[0]->ID;
    }

    $admins = get_users(['role' => 'administrator']);
    if (isset($admins[0]->ID)) {
        return $admins[0]->ID;
    }

    return null;
}

function wasmer_get_login_link($args)
{
    $query_args = [
        'platform' => $args['redirect_location'],
    ];
    if (!empty($args['client_id'])) {
        $query_args['client_id'] = $args['client_id'];
    }
    if (!empty($args['acting_client_id'])) {
        $query_args['acting_client_id'] = $args['acting_client_id'];
    }
    return add_query_arg($query_args, admin_url());
}

function wasmer_graphql_query($url, $query, $variables, $authToken = null)
{
    $body = json_encode(['query' => $query, 'variables' => $variables]);
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    if ($authToken) {
        $headers['Authorization'] = 'Bearer ' . $authToken;
    }

    $response = wp_remote_post($url, [
        'headers' => $headers,
        'body' => $body,
    ]);

    if (is_wp_error($response)) return null;
    return json_decode(wp_remote_retrieve_body($response), true);
}




function wasmer_liveconfig_callback($request)
{
    $data = wasmer_get_liveconfig_data();
    $response = new WP_REST_Response($data);
    $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');
    return $response;
}

function wasmer_check_callback($request)
{
    $data = [
        'status' => 'success'
    ];
    $response = new WP_REST_Response($data);
    $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');

    return $response;
}

function wasmer_magiclogin_callback($request)
{
    $token = $_GET['magiclogin'] ?? null;

    if (!$token) {
        return new WP_Error('missing_token', 'Missing token', array('status' => 500));
    }
    if (!WASMER_GRAPHQL_URL) {
        return new WP_Error('missing_env_url', 'Missing environment variables: WASMER_GRAPHQL_URL', array('status' => 500));
    }
    if (!WASMER_APP_ID) {
        return new WP_Error('missing_env_appid', 'Missing environment variables: WASMER_APP_ID', array('status' => 500));
    }

    $query = <<<'GRAPHQL'
    query ($appid: ID!) {
        viewer {
            email
        }
        node(id: $appid) {
            ... on DeployApp {
                id
            }
        }
    }
    GRAPHQL;

    $response = wasmer_graphql_query(WASMER_GRAPHQL_URL, $query, ['appid' => WASMER_APP_ID], $token);
    if (!$response) {
        return new WP_Error('graphql_error', 'GraphQL query failed', array('status' => 400));
    }

    $viewer = $response['data']['viewer'] ?? null;
    $node = $response['data']['node'] ?? null;

    if (!$viewer || !$node || !isset($node['id'])) {
        return new WP_Error('invalid_token', 'Invalid or expired token', array('status' => 403));
    }

    $wasmerLoginData = [
        'email' => $viewer['email'],
        'redirect_location' => 'wasmer',
        'client_id' => '',
        'acting_client_id' => '',
        'callback_url' => '',
    ];
    $redirect_page = wasmer_get_login_link($wasmerLoginData);
    $wasmerLoginData['redirect_page'] = $redirect_page;

    return wasmer_auto_login($wasmerLoginData);
}

function wasmer_callback($args)
{
    if (empty($args['callback_url'])) {
        return;
    }

    wp_remote_post($args['callback_url'], ['body' => $args]);
}
