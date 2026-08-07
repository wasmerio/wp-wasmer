<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_root_dir()
{
    return trailingslashit(WP_CONTENT_DIR) . 'wasmer-migrate';
}

function wasmer_migrate_run_dir($migration_id)
{
    return wasmer_migrate_root_dir() . '/' . sanitize_key($migration_id);
}

function wasmer_migrate_delete_path($path)
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    return $wp_filesystem && $wp_filesystem->delete($path, true);
}

function wasmer_migrate_delete_run_dir($migration_id)
{
    $migration_id = sanitize_key((string) $migration_id);
    if ($migration_id === '') {
        return false;
    }

    return wasmer_migrate_delete_path(wasmer_migrate_run_dir($migration_id));
}

function wasmer_migrate_delete_all_data()
{
    return wasmer_migrate_delete_path(wasmer_migrate_root_dir());
}

function wasmer_migrate_ensure_run_dir($migration_id)
{
    $root = wasmer_migrate_root_dir();
    $run = wasmer_migrate_run_dir($migration_id);
    if (!wp_mkdir_p($run) || !wp_mkdir_p($run . '/logs')) {
        return false;
    }
    wasmer_migrate_write_access_guards($root);
    wasmer_migrate_write_access_guards_recursive($root, $run . '/logs');
    return true;
}

function wasmer_migrate_write_access_guards($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $index = trailingslashit($dir) . 'index.php';
    file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);

    $htaccess = trailingslashit($dir) . '.htaccess';
    file_put_contents($htaccess, implode("\n", [
        'Options -Indexes',
        '<IfModule mod_authz_core.c>',
        'Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        'Deny from all',
        '</IfModule>',
        '<FilesMatch "\\.(php|php[0-9]?|phtml|phar)$">',
        '<IfModule mod_authz_core.c>',
        'Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        'Deny from all',
        '</IfModule>',
        '</FilesMatch>',
        '<IfModule mod_php.c>',
        'php_flag engine off',
        '</IfModule>',
        '<IfModule mod_php7.c>',
        'php_flag engine off',
        '</IfModule>',
        '<IfModule mod_php8.c>',
        'php_flag engine off',
        '</IfModule>',
        '',
    ]), LOCK_EX);

    $web_config = trailingslashit($dir) . 'web.config';
    file_put_contents($web_config, implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<configuration>',
        '  <system.webServer>',
        '    <authorization>',
        '      <deny users="*" />',
        '    </authorization>',
        '    <security>',
        '      <requestFiltering>',
        '        <fileExtensions>',
        '          <add fileExtension=".php" allowed="false" />',
        '          <add fileExtension=".phtml" allowed="false" />',
        '          <add fileExtension=".phar" allowed="false" />',
        '        </fileExtensions>',
        '      </requestFiltering>',
        '    </security>',
        '  </system.webServer>',
        '</configuration>',
        '',
    ]), LOCK_EX);

    $user_ini = trailingslashit($dir) . '.user.ini';
    file_put_contents($user_ini, "engine = Off\n", LOCK_EX);
}

function wasmer_migrate_write_access_guards_recursive($base, $dir)
{
    if (!is_dir($base) || !is_dir($dir)) {
        return false;
    }

    $base_real = realpath($base);
    $dir_real = realpath($dir);
    if (!$base_real || !$dir_real) {
        return false;
    }

    $base_real = rtrim(str_replace('\\', '/', $base_real), '/');
    $dir_real = rtrim(str_replace('\\', '/', $dir_real), '/');
    if ($dir_real !== $base_real && strpos($dir_real . '/', $base_real . '/') !== 0) {
        return false;
    }

    $paths = [];
    $current = $dir_real;
    while ($current !== '' && $current !== dirname($current)) {
        $paths[] = $current;
        if ($current === $base_real) {
            break;
        }
        $current = dirname($current);
    }

    foreach (array_reverse($paths) as $path) {
        wasmer_migrate_write_access_guards($path);
    }

    return true;
}

function wasmer_migrate_new_id()
{
    return 'wms_' . strtolower(wp_generate_password(20, false, false));
}
