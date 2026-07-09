<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_build_manifest($database, $files)
{
    global $wpdb;

    $options = [
        'include_uploads' => true,
        'include_themes' => true,
        'include_plugins' => true,
        'include_mu_plugins' => false,
    ];
    $files_size = array_sum(array_map(function ($file) {
        return (int) ($file['size'] ?? 0);
    }, $files));

    $source = [
        'site_url' => site_url(),
        'home_url' => home_url(),
        'wp_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'table_prefix' => $wpdb->prefix,
    ];
    $additional_config = getenv('WP_ADDITIONAL_CONFIG');
    if (is_string($additional_config) && $additional_config !== '') {
        $content_dir = trailingslashit(wp_normalize_path(WP_CONTENT_DIR));
        $config_path = wp_normalize_path($additional_config);
        if (strpos($config_path, $content_dir) === 0) {
            $source['additional_config'] = ltrim(substr($config_path, strlen($content_dir)), '/');
        }
    }

    return [
        'version' => 1,
        'source' => $source,
        'database' => [
            'tables' => $database['tables'],
            'size' => $database['size'],
            'sha256' => $database['sha256'],
        ],
        'files' => array_map(function ($file) {
            return [
                'path' => $file['path'],
                'size' => $file['size'],
                'mtime' => $file['mtime'],
                'sha256' => $file['sha256'],
            ];
        }, $files),
        'file_count' => count($files),
        'total_size' => (int) $database['size'] + $files_size,
        'options' => $options,
        'preflight' => wasmer_migrate_build_source_preflight($options),
    ];
}

function wasmer_migrate_build_source_preflight($options)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $active_theme = wp_get_theme();
    $active_plugins = get_option('active_plugins', []);
    if (!is_array($active_plugins)) {
        $active_plugins = [];
    }

    $plugins = function_exists('get_plugins') ? get_plugins() : [];
    $warnings = [];

    if (empty($options['include_themes'])) {
        $warnings[] = 'Active source theme files are not included in this transfer.';
    }
    if (empty($options['include_plugins']) && !empty($active_plugins)) {
        $warnings[] = 'Active source plugin files are not included in this transfer.';
    }

    return [
        'active_theme' => [
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
            'name' => $active_theme ? $active_theme->get('Name') : '',
            'version' => $active_theme ? $active_theme->get('Version') : '',
        ],
        'active_plugins' => array_values(array_map(function ($plugin_file) use ($plugins) {
            $plugin = $plugins[$plugin_file] ?? [];
            return [
                'file' => $plugin_file,
                'slug' => dirname($plugin_file) === '.' ? basename($plugin_file, '.php') : dirname($plugin_file),
                'name' => $plugin['Name'] ?? $plugin_file,
                'version' => $plugin['Version'] ?? '',
            ];
        }, $active_plugins)),
        'warnings' => $warnings,
    ];
}

function wasmer_migrate_prepare_export($run_id = '', $run_token = '')
{
    $state = wasmer_migrate_get_state();
    if (empty($state['id'])) {
        $state['id'] = wasmer_migrate_new_id();
    }
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    wasmer_migrate_ensure_run_dir($state['id']);

    $database = wasmer_migrate_export_database($state['id']);
    if (is_wp_error($database)) {
        return $database;
    }
    $options = [
        'include_uploads' => true,
        'include_themes' => true,
        'include_plugins' => true,
        'include_mu_plugins' => false,
    ];
    $files = wasmer_migrate_scan_content_assets($options);
    $manifest = wasmer_migrate_build_manifest($database, $files);
    file_put_contents(wasmer_migrate_run_dir($state['id']) . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $state['status'] = 'exported';
    $state['database'] = $database;
    $state['files'] = $files;
    $state['manifest'] = $manifest;
    $saved = ($run_id !== '' && $run_token !== '')
        ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
        : wasmer_migrate_save_state($state);
    if (is_wp_error($saved)) {
        return $saved;
    }
    if ($run_id !== '' && $run_token !== '') {
        wasmer_migrate_log_for_run($state['id'], $run_token, 'Export prepared.', [
            'files' => count($files),
            'database_size' => $database['size'],
        ]);
    } else {
        wasmer_migrate_log($state['id'], 'Export prepared.', [
            'files' => count($files),
            'database_size' => $database['size'],
        ]);
    }

    return $state;
}
