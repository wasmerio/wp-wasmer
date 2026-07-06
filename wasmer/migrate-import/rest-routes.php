<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_load_import_dependencies()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/sessions.php';
    require_once __DIR__ . '/storage.php';
    require_once __DIR__ . '/logs.php';
    require_once __DIR__ . '/manifest.php';
    require_once __DIR__ . '/importer.php';

    $loaded = true;
}

function wasmer_import_load_rest_handlers()
{
    require_once __DIR__ . '/rest-handlers.php';
}

function wasmer_import_admin_permission()
{
    return current_user_can('manage_options');
}

function wasmer_import_dispatch_rest_request($handler, $request)
{
    wasmer_import_load_rest_handlers();

    $function = 'wasmer_import_rest_' . $handler;
    if (!function_exists($function)) {
        return new WP_Error('wasmer_import_unknown_handler', 'Unknown import route handler.', ['status' => 500]);
    }

    return $function($request);
}

function wasmer_import_rest_callback($handler)
{
    return static function ($request) use ($handler) {
        return wasmer_import_dispatch_rest_request($handler, $request);
    };
}

function wasmer_import_register_rest_routes()
{
    register_rest_route('wasmer/v1/import', '/session', [
        'methods' => 'POST',
        'callback' => wasmer_import_rest_callback('create_session'),
        'permission_callback' => 'wasmer_import_admin_permission',
    ]);

    register_rest_route('wasmer/v1/import', '/session/(?P<id>wmi_[A-Za-z0-9_\\-]+)', [
        'methods' => 'GET',
        'callback' => wasmer_import_rest_callback('get_session'),
        'permission_callback' => 'wasmer_import_admin_permission',
    ]);

    foreach (['connect', 'manifest', 'chunk', 'complete'] as $route) {
        register_rest_route('wasmer/v1/import', '/session/(?P<id>wmi_[A-Za-z0-9_\\-]+)/' . $route, [
            'methods' => 'POST',
            'callback' => wasmer_import_rest_callback(str_replace('-', '_', $route)),
            'permission_callback' => '__return_true',
        ]);
    }

    register_rest_route('wasmer/v1/import', '/session/(?P<id>wmi_[A-Za-z0-9_\\-]+)/start-import', [
        'methods' => 'POST',
        'callback' => wasmer_import_rest_callback('start_import'),
        'permission_callback' => 'wasmer_import_admin_permission',
    ]);

    register_rest_route('wasmer/v1/import', '/session/(?P<id>wmi_[A-Za-z0-9_\\-]+)/cancel', [
        'methods' => 'POST',
        'callback' => wasmer_import_rest_callback('cancel'),
        'permission_callback' => 'wasmer_import_admin_permission',
    ]);
}
add_action('rest_api_init', 'wasmer_import_register_rest_routes');
