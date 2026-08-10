<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_cli_auto($assoc_args)
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

    $app = $result['auto_app']['app'] ?? null;
    if (!is_array($app) || trim((string) ($app['id'] ?? '')) === '') {
        WP_CLI::error('Automatic Wasmer import completed without target app information.');
    }

    $json = wp_json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        WP_CLI::error('Could not encode target app information as JSON.');
    }

    WP_CLI::line($json);
}
