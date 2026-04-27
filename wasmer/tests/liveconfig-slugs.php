<?php

define('ABSPATH', __DIR__ . '/');
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);

if (!defined('WP_WASMER_PLUGIN_VERSION')) {
    define('WP_WASMER_PLUGIN_VERSION', '0.3.2');
}

if (!defined('WP_WASMER_PLUGIN_DIR_PATH')) {
    define('WP_WASMER_PLUGIN_DIR_PATH', '/tmp/wp-wasmer/');
}

if (!defined('WP_WASMER_PLUGIN_DIR_URL')) {
    define('WP_WASMER_PLUGIN_DIR_URL', 'http://example.test/wp-content/plugins/wp-wasmer/');
}

$wpdb = new class {
    public function db_version()
    {
        return '8.0';
    }

    public function db_server_info()
    {
        return '8.0';
    }
};

function get_plugins()
{
    return [
        'hello.php' => [
            'Name' => 'Hello Dolly',
            'Version' => '1.7.2',
            'Description' => 'Hello Dolly test plugin.',
        ],
        'folder-plugin/folder-plugin.php' => [
            'Name' => 'Folder Plugin',
            'Version' => '1.0.0',
            'Description' => 'Folder plugin test.',
        ],
        'duplicate/primary.php' => [
            'Name' => 'Duplicate Primary',
            'Version' => '1.0.0',
            'Description' => 'Duplicate slug primary plugin test.',
        ],
        'duplicate/secondary.php' => [
            'Name' => 'Duplicate Secondary',
            'Version' => '1.0.0',
            'Description' => 'Duplicate slug secondary plugin test.',
        ],
    ];
}

function wp_get_themes()
{
    return [
        'parent-theme' => (object) [
            'name' => 'Parent Theme',
            'version' => '1.0.0',
        ],
        'child-theme' => (object) [
            'name' => 'Child Theme',
            'version' => '1.0.0',
        ],
    ];
}

function get_site_transient($key)
{
    if ($key === 'update_plugins') {
        return (object) [
            'response' => [],
            'no_update' => [
                'hello.php' => (object) [
                    'slug' => 'hello-dolly',
                    'icons' => [
                        '1x' => 'https://ps.w.org/hello-dolly/assets/icon-128x128.jpg',
                    ],
                    'url' => 'https://wordpress.org/plugins/hello-dolly/',
                    'new_version' => '1.7.2',
                ],
                'folder-plugin/folder-plugin.php' => (object) [
                    'slug' => 'folder-plugin-api-slug',
                    'icons' => [],
                    'url' => 'https://wordpress.org/plugins/folder-plugin-api-slug/',
                    'new_version' => '1.0.0',
                ],
            ],
        ];
    }

    if ($key === 'update_themes') {
        return (object) [
            'response' => [],
            'no_update' => [
                'parent-theme' => [
                    'new_version' => '1.0.0',
                ],
                'child-theme' => [
                    'new_version' => '1.0.0',
                ],
            ],
        ];
    }

    if ($key === 'update_core') {
        return (object) [
            'updates' => [],
        ];
    }

    return null;
}

function is_plugin_active($path)
{
    return $path === 'folder-plugin/folder-plugin.php';
}

function get_bloginfo($show)
{
    return '6.9';
}

function home_url()
{
    return 'http://example.test';
}

function get_locale()
{
    return 'en_US';
}

function is_main_site()
{
    return true;
}

function count_users()
{
    return [
        'total_users' => 1,
        'avail_roles' => [
            'administrator' => 1,
        ],
    ];
}

function get_users()
{
    return [];
}

function wp_count_posts($type)
{
    return (object) [
        'publish' => '0',
    ];
}

function get_option($name)
{
    $options = [
        'template' => 'parent-theme',
        'stylesheet' => 'child-theme',
    ];

    return $options[$name] ?? null;
}

function get_stylesheet()
{
    return get_option('stylesheet');
}

function assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function find_liveconfig_item($items, $field, $value)
{
    foreach ($items as $item) {
        if (($item[$field] ?? null) === $value) {
            return $item;
        }
    }

    fwrite(STDERR, "Could not find liveconfig item {$field}={$value}." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../rest-api.php';

$data = wasmer_get_liveconfig_data();

$hello = find_liveconfig_item($data['wordpress']['plugins'], 'name', 'Hello Dolly');
assert_same('hello', $hello['slug'], 'Hello Dolly liveconfig slug should match wp plugin list --json name.');
assert_same('https://wordpress.org/plugins/hello-dolly/', $hello['url'], 'Plugin update metadata should still come from the transient.');

$folder_plugin = find_liveconfig_item($data['wordpress']['plugins'], 'name', 'Folder Plugin');
assert_same('folder-plugin', $folder_plugin['slug'], 'Folder plugin liveconfig slug should match wp plugin list --json name.');

$duplicate_primary = find_liveconfig_item($data['wordpress']['plugins'], 'name', 'Duplicate Primary');
$duplicate_secondary = find_liveconfig_item($data['wordpress']['plugins'], 'name', 'Duplicate Secondary');
assert_same('duplicate/primary', $duplicate_primary['slug'], 'Duplicate plugin slug should fall back to the path-like WP-CLI name.');
assert_same('duplicate/secondary', $duplicate_secondary['slug'], 'Duplicate plugin slug should fall back to the path-like WP-CLI name.');

$parent_theme = find_liveconfig_item($data['wordpress']['themes'], 'slug', 'parent-theme');
$child_theme = find_liveconfig_item($data['wordpress']['themes'], 'slug', 'child-theme');
assert_same(false, $parent_theme['is_active'], 'Parent theme should not be marked active when a child theme is active.');
assert_same(true, $child_theme['is_active'], 'Active child theme should match wp theme list --json active status.');

echo "ok\n";
