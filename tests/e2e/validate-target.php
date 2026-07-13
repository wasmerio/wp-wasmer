<?php

$marker = $args[0] ?? '';
if (!$marker) {
    WP_CLI::error('Missing migration marker argument.');
}
$source_prefix = $args[1] ?? '';
$target_prefix = $args[2] ?? '';

global $wpdb;
if ($source_prefix === '' || $target_prefix === '') {
    WP_CLI::error('Missing source or target table prefix argument.');
}
if ($source_prefix === $target_prefix) {
    WP_CLI::error('E2E must use different source and target table prefixes.');
}
if ($wpdb->prefix !== $target_prefix) {
    WP_CLI::error('Target table prefix mismatch. Expected ' . $target_prefix . ', got ' . $wpdb->prefix);
}

$actual_marker = get_option('wasmer_migration_e2e_marker');
if ($actual_marker !== $marker) {
    WP_CLI::error('Marker option mismatch. Expected ' . $marker . ', got ' . var_export($actual_marker, true));
}

$post = get_page_by_path('wasmer-migration-e2e-post', OBJECT, 'post');
if (!$post || strpos($post->post_content, $marker) === false) {
    WP_CLI::error('Migrated post missing or does not contain marker.');
}
if (strpos($post->post_content, 'http://source') !== false) {
    WP_CLI::error('Migrated post still contains source URL.');
}
if (strpos($post->post_content, content_url('uploads/')) === false) {
    WP_CLI::error('Migrated post does not contain target content URL.');
}

$page = get_page_by_path('wasmer-migration-e2e-page', OBJECT, 'page');
if (!$page || strpos($page->post_content, $marker) === false) {
    WP_CLI::error('Migrated page missing or does not contain marker.');
}

$uploads = get_option('wasmer_migration_e2e_uploads');
if (!is_array($uploads) || count($uploads) < 2) {
    WP_CLI::error('Migrated uploads manifest option missing.');
}

foreach ($uploads as $upload) {
    $relative = $upload['relative'] ?? '';
    $expected_hash = $upload['sha256'] ?? '';
    $path = trailingslashit(WP_CONTENT_DIR) . ltrim($relative, '/');
    if (!is_readable($path)) {
        WP_CLI::error('Migrated upload missing: ' . $relative);
    }
    $actual_hash = hash_file('sha256', $path);
    if (!hash_equals($expected_hash, $actual_hash)) {
        WP_CLI::error('Migrated upload hash mismatch for ' . $relative);
    }
}

$urls = get_option('wasmer_migration_e2e_urls');
if (!is_array($urls)) {
    WP_CLI::error('Serialized URL option missing.');
}
$url_values = [
    $urls['site'] ?? '',
    $urls['home'] ?? '',
    $urls['content'] ?? '',
    $urls['nested']['home'] ?? '',
    get_post_meta($post->ID, 'wasmer_migration_e2e_source_url', true),
];
foreach ($url_values as $url_value) {
    if (strpos($url_value, 'http://source') !== false) {
        WP_CLI::error('Migrated data still contains source URL: ' . $url_value);
    }
    if (strpos($url_value, 'http://target') !== 0) {
        WP_CLI::error('Migrated data did not receive target URL: ' . $url_value);
    }
}

$target_prefixed_option = get_option($target_prefix . 'wasmer_migration_e2e_prefixed_option');
if ($target_prefixed_option !== $marker) {
    WP_CLI::error('Prefix-scoped option was not remapped to target prefix.');
}
if (get_option($source_prefix . 'wasmer_migration_e2e_prefixed_option') !== false) {
    WP_CLI::error('Source-prefixed option key remained after import.');
}
$source_user = get_user_by('login', 'source-author');
if (!$source_user) {
    WP_CLI::error('Imported source user missing.');
}
$target_prefixed_user_meta = get_user_meta($source_user->ID, $target_prefix . 'wasmer_migration_e2e_prefixed_user_meta', true);
if ($target_prefixed_user_meta !== 'prefixed-' . $marker) {
    WP_CLI::error('Prefix-scoped usermeta key was not remapped to target prefix.');
}
if (get_user_meta($source_user->ID, $source_prefix . 'wasmer_migration_e2e_prefixed_user_meta', true) !== '') {
    WP_CLI::error('Source-prefixed usermeta key remained after import.');
}

$source_admin = get_user_by('login', 'admin');
if (!$source_admin || !wp_check_password('password', $source_admin->user_pass, $source_admin->ID)) {
    WP_CLI::error('Source admin password was not preserved over the colliding destination admin.');
}

$active_plugins = get_option('active_plugins', []);
if (!is_array($active_plugins) || !in_array('wp-wasmer/wp-wasmer.php', $active_plugins, true)) {
    WP_CLI::error('Target wp-wasmer plugin was not preserved as active.');
}
if (!in_array('wasmer-e2e-active-plugin/wasmer-e2e-active-plugin.php', $active_plugins, true)) {
    WP_CLI::error('Source active plugin was not preserved as active.');
}
if (!is_readable(trailingslashit(WP_CONTENT_DIR) . 'plugins/wasmer-e2e-active-plugin/wasmer-e2e-active-plugin.php')) {
    WP_CLI::error('Source active plugin files were not copied.');
}
if (get_stylesheet() !== 'wasmer-e2e-active-theme' || get_template() !== 'wasmer-e2e-active-theme') {
    WP_CLI::error('Source active theme was not preserved.');
}
if (!is_readable(trailingslashit(WP_CONTENT_DIR) . 'themes/wasmer-e2e-active-theme/style.css')) {
    WP_CLI::error('Source active theme files were not copied.');
}

$target_user = get_user_by('login', 'target-editor');
if (!$target_user) {
    WP_CLI::error('Existing target user was not preserved.');
}
if (!wp_check_password('targetpass', $target_user->user_pass, $target_user->ID)) {
    WP_CLI::error('Existing target user password was not preserved.');
}
if (!in_array('editor', (array) $target_user->roles, true)) {
    WP_CLI::error('Existing target user role was not preserved.');
}
if (get_user_meta($target_user->ID, 'wasmer_migration_preserve_user_meta', true) !== 'target-user-meta') {
    WP_CLI::error('Existing target user metadata was not preserved.');
}

$home = home_url();
if (strpos($home, 'target') === false) {
    WP_CLI::error('Target home URL was not preserved after import: ' . $home);
}

WP_CLI::success('Validated migrated target content.');
