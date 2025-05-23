<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/defines.php';

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
    $themes = wp_get_themes();
    $update_plugins = get_site_transient('update_plugins');
    $update_themes = get_site_transient('update_themes');
    $update_core = get_site_transient('update_core');

    $plugins = array_map(function ($path, $plugin) use ($update_plugins) {
        $slug = dirname($path);
        if ($slug === '.') $slug = basename($path, '.php');
        $transient = $update_plugins->response[$path] ?? $update_plugins->no_update[$path] ?? null;
        return [
            'slug' => $transient->slug ?? $slug,
            'icon' => $transient->icons['1x'] ?? null,
            'url' => $transient->url ?? null,
            'name' => $plugin['Name'],
            'version' => $plugin['Version'] ?? '',
            'description' => $plugin['Description'],
            'is_active' => is_plugin_active($path),
            'latest_version' => $transient->new_version ?? null,
        ];
    }, array_keys($plugins), $plugins);

    $themes = array_map(function ($slug, $theme) use ($update_themes) {
        $transient = $update_themes->response[$slug] ?? $update_themes->no_update[$slug] ?? null;
        return [
            'slug' => $slug,
            'name' => $theme->name,
            'version' => $theme->version,
            'latest_version' => $transient["new_version"] ?? null,
            'is_active' => get_option('template') == $slug,
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
            'latest_version' => $update_core->updates[0]->version ?? null,
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
        do_action('wasmer_autologin_user_logged_in', $args);

        wasmer_callback($args);

        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $redirect_page);
        http_response_code(302);
        exit;
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
