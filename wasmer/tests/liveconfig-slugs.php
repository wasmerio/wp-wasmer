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
        'network-plugin/network-plugin.php' => [
            'Name' => 'Network Plugin',
            'Version' => '1.0.0',
            'Description' => 'Network active plugin test.',
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

function get_mu_plugins()
{
    return [
        'mu-loader.php' => [
            'Name' => 'MU Loader',
            'Version' => '1.0.0',
            'Description' => 'Must-use plugin test.',
        ],
    ];
}

function get_dropins()
{
    return [
        'object-cache.php' => [
            'Name' => 'Object Cache Drop-in',
            'Version' => '',
            'Description' => 'Drop-in plugin header description.',
        ],
    ];
}

function _get_dropins()
{
    return [
        'object-cache.php' => [
            'External object cache.',
            true,
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
        'inactive-theme' => (object) [
            'name' => 'Inactive Theme',
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
                'inactive-theme' => [
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

function get_site_option($key)
{
    if ($key === 'auto_update_plugins') {
        return [
            'folder-plugin/folder-plugin.php',
            'network-plugin/network-plugin.php',
        ];
    }

    if ($key === 'auto_update_themes') {
        return [
            'child-theme',
        ];
    }

    return null;
}

function is_plugin_active($path)
{
    return $path === 'folder-plugin/folder-plugin.php';
}

function is_plugin_active_for_network($path)
{
    return $path === 'network-plugin/network-plugin.php';
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

function get_template()
{
    return get_option('template');
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

$hello = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Hello Dolly');
assert_same('hello', $hello['slug'], 'Hello Dolly liveconfig slug should match wp plugin list --json name.');
assert_same('hello', $hello['name'], 'Hello Dolly liveconfig name should match wp plugin list --json name.');
assert_same('Hello Dolly', $hello['title'], 'Hello Dolly liveconfig title should keep the plugin display name.');
assert_same('inactive', $hello['status'], 'Inactive plugin liveconfig status should match wp plugin list --json status.');
assert_same('off', $hello['auto_update'], 'Disabled plugin auto-update status should match wp plugin list --json auto_update.');
assert_same('https://wordpress.org/plugins/hello-dolly/', $hello['url'], 'Plugin update metadata should still come from the transient.');

$folder_plugin = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Folder Plugin');
assert_same('folder-plugin', $folder_plugin['slug'], 'Folder plugin liveconfig slug should match wp plugin list --json name.');
assert_same('folder-plugin', $folder_plugin['name'], 'Folder plugin liveconfig name should match wp plugin list --json name.');
assert_same('Folder Plugin', $folder_plugin['title'], 'Folder plugin liveconfig title should keep the plugin display name.');
assert_same('active', $folder_plugin['status'], 'Active plugin liveconfig status should match wp plugin list --json status.');
assert_same('on', $folder_plugin['auto_update'], 'Enabled plugin auto-update status should match wp plugin list --json auto_update.');

$network_plugin = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Network Plugin');
assert_same('network-plugin', $network_plugin['name'], 'Network plugin liveconfig name should match wp plugin list --json name.');
assert_same('Network Plugin', $network_plugin['title'], 'Network plugin liveconfig title should keep the plugin display name.');
assert_same('active-network', $network_plugin['status'], 'Network active plugin liveconfig status should match wp plugin list --json status.');
assert_same('on', $network_plugin['auto_update'], 'Network active plugin auto-update status should match wp plugin list --json auto_update.');
assert_same(true, $network_plugin['is_active'], 'Network active plugin should keep the legacy is_active flag true.');

$mu_plugin = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'MU Loader');
assert_same('mu-loader', $mu_plugin['slug'], 'Must-use plugin liveconfig slug should match wp plugin list --json name.');
assert_same('mu-loader', $mu_plugin['name'], 'Must-use plugin liveconfig name should match wp plugin list --json name.');
assert_same('MU Loader', $mu_plugin['title'], 'Must-use plugin liveconfig title should keep the plugin display name.');
assert_same('must-use', $mu_plugin['status'], 'Must-use plugin liveconfig status should match wp plugin list --json status.');
assert_same('off', $mu_plugin['auto_update'], 'Must-use plugin auto-update status should match wp plugin list --json auto_update.');
assert_same(true, $mu_plugin['is_active'], 'Must-use plugin should keep the legacy is_active flag true.');

$dropin = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Object Cache Drop-in');
assert_same('object-cache.php', $dropin['slug'], 'Drop-in liveconfig slug should match wp plugin list --json name.');
assert_same('object-cache.php', $dropin['name'], 'Drop-in liveconfig name should match wp plugin list --json name.');
assert_same('Object Cache Drop-in', $dropin['title'], 'Drop-in liveconfig title should keep the plugin display name.');
assert_same('dropin', $dropin['status'], 'Drop-in liveconfig status should match wp plugin list --json status.');
assert_same('off', $dropin['auto_update'], 'Drop-in auto-update status should match wp plugin list --json auto_update.');
assert_same('External object cache.', $dropin['description'], 'Drop-in liveconfig description should use WordPress drop-in metadata.');
assert_same(true, $dropin['is_active'], 'Drop-in should keep the legacy is_active flag true.');

$duplicate_primary = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Duplicate Primary');
$duplicate_secondary = find_liveconfig_item($data['wordpress']['plugins'], 'title', 'Duplicate Secondary');
assert_same('duplicate/primary', $duplicate_primary['slug'], 'Duplicate plugin slug should fall back to the path-like WP-CLI name.');
assert_same('duplicate/secondary', $duplicate_secondary['slug'], 'Duplicate plugin slug should fall back to the path-like WP-CLI name.');
assert_same('duplicate/primary', $duplicate_primary['name'], 'Duplicate plugin name should fall back to the path-like WP-CLI name.');
assert_same('duplicate/secondary', $duplicate_secondary['name'], 'Duplicate plugin name should fall back to the path-like WP-CLI name.');

$parent_theme = find_liveconfig_item($data['wordpress']['themes'], 'slug', 'parent-theme');
$child_theme = find_liveconfig_item($data['wordpress']['themes'], 'slug', 'child-theme');
$inactive_theme = find_liveconfig_item($data['wordpress']['themes'], 'slug', 'inactive-theme');
assert_same(false, $parent_theme['is_active'], 'Parent theme should not be marked active when a child theme is active.');
assert_same(true, $child_theme['is_active'], 'Active child theme should match wp theme list --json active status.');
assert_same('parent', $parent_theme['status'], 'Parent theme liveconfig status should match wp theme list --json status.');
assert_same('active', $child_theme['status'], 'Active theme liveconfig status should match wp theme list --json status.');
assert_same('inactive', $inactive_theme['status'], 'Inactive theme liveconfig status should match wp theme list --json status.');
assert_same('parent-theme', $parent_theme['name'], 'Parent theme liveconfig name should match wp theme list --json name.');
assert_same('child-theme', $child_theme['name'], 'Active theme liveconfig name should match wp theme list --json name.');
assert_same('inactive-theme', $inactive_theme['name'], 'Inactive theme liveconfig name should match wp theme list --json name.');
assert_same('Parent Theme', $parent_theme['title'], 'Parent theme liveconfig title should keep the theme display name.');
assert_same('Child Theme', $child_theme['title'], 'Active theme liveconfig title should keep the theme display name.');
assert_same('Inactive Theme', $inactive_theme['title'], 'Inactive theme liveconfig title should keep the theme display name.');
assert_same('off', $parent_theme['auto_update'], 'Disabled parent theme auto-update status should match wp theme list --json auto_update.');
assert_same('on', $child_theme['auto_update'], 'Enabled theme auto-update status should match wp theme list --json auto_update.');
assert_same('off', $inactive_theme['auto_update'], 'Disabled inactive theme auto-update status should match wp theme list --json auto_update.');

echo "ok\n";
