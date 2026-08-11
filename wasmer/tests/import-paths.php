<?php

define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', '/srv/custom/plugins');
define('WP_WASMER_PLUGIN_MAIN_FILE', WP_PLUGIN_DIR . '/wasmer-hosting-integration/wp-wasmer.php');

function trailingslashit($value)
{
    return rtrim($value, '/\\') . '/';
}

function wp_get_upload_dir()
{
    return [
        'basedir' => '/srv/custom/uploads',
        'baseurl' => 'https://example.test/media',
        'error' => false,
    ];
}

function wp_upload_dir()
{
    return wp_get_upload_dir();
}

function get_theme_root($theme = '')
{
    return '/srv/custom/themes';
}

function plugin_basename($file)
{
    return ltrim(substr($file, strlen(WP_PLUGIN_DIR)), '/');
}

function add_action()
{
}

function wasmer_test_assert_same($expected, $actual, $message)
{
    if ($expected === $actual) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../migrate-import/importer.php';
require_once __DIR__ . '/../migrate-import/sessions.php';

wasmer_test_assert_same(
    '/srv/custom/uploads/wasmer-hosting-integration/import',
    wasmer_import_root_dir(),
    'Temporary import data should use a plugin-specific directory under uploads.'
);

wasmer_test_assert_same(
    '/srv/custom/uploads/2026/08/image.jpg',
    wasmer_import_content_destination('wp-content/uploads/2026/08/image.jpg'),
    'Uploads should resolve through the configured uploads directory.'
);
wasmer_test_assert_same(
    '/srv/custom/themes/example/style.css',
    wasmer_import_content_destination('wp-content/themes/example/style.css'),
    'Themes should resolve through the configured theme root.'
);
wasmer_test_assert_same(
    '/srv/custom/plugins/example/example.php',
    wasmer_import_content_destination('wp-content/plugins/example/example.php'),
    'Plugins should resolve through the configured plugin directory.'
);
wasmer_test_assert_same(
    null,
    wasmer_import_content_destination('wp-content/languages/example.mo'),
    'Unsupported content paths should not resolve to a destination.'
);

$current_plugin = 'wasmer-hosting-integration/wp-wasmer.php';
$active_plugins = wasmer_import_final_active_plugins(
    ['example/example.php', 'wasmer-migrate/wasmer-migrate.php'],
    [$current_plugin]
);
wasmer_test_assert_same(
    ['example/example.php', $current_plugin],
    $active_plugins,
    'The current plugin basename should be preserved without hardcoding its directory.'
);

echo "ok\n";
