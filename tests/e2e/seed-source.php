<?php

$marker = $args[0] ?? '';
if (!$marker) {
    WP_CLI::error('Missing migration marker argument.');
}
if (!function_exists('activate_plugin')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$upload = wp_upload_dir();
if (!empty($upload['error'])) {
    WP_CLI::error($upload['error']);
}

if (!wp_mkdir_p($upload['path'])) {
    WP_CLI::error('Could not create uploads directory.');
}

$plugin_dir = trailingslashit(WP_CONTENT_DIR) . 'plugins/wasmer-e2e-active-plugin';
$theme_dir = trailingslashit(WP_CONTENT_DIR) . 'themes/wasmer-e2e-active-theme';
if (!wp_mkdir_p($plugin_dir) || !wp_mkdir_p($theme_dir)) {
    WP_CLI::error('Could not create plugin/theme fixture directories.');
}
file_put_contents(trailingslashit($plugin_dir) . 'wasmer-e2e-active-plugin.php', "<?php\n/**\n * Plugin Name: Wasmer E2E Active Plugin\n */\nadd_action('init', function () {\n    update_option('wasmer_e2e_active_plugin_loaded', 'yes', false);\n});\n");
file_put_contents(trailingslashit($theme_dir) . 'style.css', "/*\nTheme Name: Wasmer E2E Active Theme\nVersion: 1.0.0\n*/\n");
file_put_contents(trailingslashit($theme_dir) . 'index.php', "<?php\n?><main>Wasmer E2E Active Theme</main>\n");

activate_plugin('wasmer-e2e-active-plugin/wasmer-e2e-active-plugin.php');
switch_theme('wasmer-e2e-active-theme');

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
    true
);
if ($png === false) {
    WP_CLI::error('Could not decode fixture image.');
}

$image_path = trailingslashit($upload['path']) . 'wasmer-migration-e2e.png';
$text_path = trailingslashit($upload['path']) . 'wasmer-migration-e2e.txt';
file_put_contents($image_path, $png);
file_put_contents($text_path, "Wasmer migration E2E marker: {$marker}\n");

$image_rel = ltrim(str_replace(trailingslashit(WP_CONTENT_DIR), '', $image_path), '/');
$text_rel = ltrim(str_replace(trailingslashit(WP_CONTENT_DIR), '', $text_path), '/');

$post_id = wp_insert_post([
    'post_title' => 'Wasmer Migration E2E Post',
    'post_name' => 'wasmer-migration-e2e-post',
    'post_status' => 'publish',
    'post_type' => 'post',
    'post_content' => '<p>Wasmer migration marker ' . esc_html($marker) . '</p><img src="' . esc_url(content_url($image_rel)) . '" alt="Wasmer migration fixture" />',
], true);
if (is_wp_error($post_id)) {
    WP_CLI::error($post_id->get_error_message());
}

$page_id = wp_insert_post([
    'post_title' => 'Wasmer Migration E2E Page',
    'post_name' => 'wasmer-migration-e2e-page',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => 'Wasmer migration page marker ' . $marker,
], true);
if (is_wp_error($page_id)) {
    WP_CLI::error($page_id->get_error_message());
}

global $wpdb;

update_post_meta($post_id, 'wasmer_migration_e2e_source_url', site_url('/post-meta-source-url'));
update_option('wasmer_migration_e2e_marker', $marker, false);
update_option('wasmer_migration_e2e_uploads', [
    [
        'relative' => $image_rel,
        'sha256' => hash_file('sha256', $image_path),
    ],
    [
        'relative' => $text_rel,
        'sha256' => hash_file('sha256', $text_path),
    ],
], false);
update_option('wasmer_migration_e2e_urls', [
    'site' => site_url('/source-site-path'),
    'home' => home_url('/source-home-path'),
    'content' => content_url($image_rel),
    'nested' => [
        'home' => home_url('/nested-home-path'),
    ],
], false);
update_option($wpdb->prefix . 'wasmer_migration_e2e_prefixed_option', $marker, false);
$source_user_id = wp_create_user('source-author', 'sourcepass', 'source-author@example.com');
if (is_wp_error($source_user_id)) {
    WP_CLI::error($source_user_id->get_error_message());
}
$source_user = new WP_User($source_user_id);
$source_user->set_role('author');
update_user_meta($source_user_id, $wpdb->prefix . 'wasmer_migration_e2e_prefixed_user_meta', 'prefixed-' . $marker);

WP_CLI::success('Seeded source migration content.');
