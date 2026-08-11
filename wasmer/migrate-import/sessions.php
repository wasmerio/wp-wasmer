<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_root_dir()
{
    $uploads = wp_upload_dir();
    return trailingslashit($uploads['basedir']) . 'wasmer-hosting-integration/import';
}

function wasmer_import_sessions_dir()
{
    return wasmer_import_root_dir() . '/sessions';
}

function wasmer_import_session_dir($session_id)
{
    return wasmer_import_root_dir() . '/' . sanitize_key($session_id);
}

function wasmer_import_session_file($session_id)
{
    return wasmer_import_sessions_dir() . '/' . sanitize_key($session_id) . '.json';
}

function wasmer_import_delete_path($path)
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    return $wp_filesystem && $wp_filesystem->delete($path, true);
}

function wasmer_import_delete_session_artifacts($session_id, $delete_session = false)
{
    $session_id = sanitize_key((string) $session_id);
    if ($session_id === '') {
        return false;
    }

    $deleted = wasmer_import_delete_path(wasmer_import_session_dir($session_id));
    if ($delete_session) {
        $deleted = wasmer_import_delete_path(wasmer_import_session_file($session_id)) && $deleted;
    }
    return $deleted;
}

function wasmer_import_cleanup_stale_sessions($max_age = DAY_IN_SECONDS)
{
    $cutoff = time() - max(HOUR_IN_SECONDS, (int) $max_age);
    foreach (wasmer_import_list_sessions() as $session) {
        if ((int) ($session['updated'] ?? 0) >= $cutoff && (int) ($session['expires'] ?? 0) >= time()) {
            continue;
        }
        wasmer_import_delete_session_artifacts($session['id'] ?? '', true);
    }
}

function wasmer_import_schedule_cleanup()
{
    if (!wp_next_scheduled('wasmer_import_cleanup_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wasmer_import_cleanup_event');
    }
}
add_action('init', 'wasmer_import_schedule_cleanup');
add_action('wasmer_import_cleanup_event', 'wasmer_import_cleanup_stale_sessions');

function wasmer_import_delete_all_data()
{
    return wasmer_import_delete_path(wasmer_import_root_dir());
}

function wasmer_import_ensure_root()
{
    $root = wasmer_import_root_dir();
    if (!wp_mkdir_p(wasmer_import_sessions_dir())) {
        return false;
    }
    wasmer_import_write_access_guards($root);
    wasmer_import_write_access_guards(wasmer_import_sessions_dir());
    return true;
}

function wasmer_import_write_access_guards($dir)
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

function wasmer_import_write_access_guards_recursive($base, $dir)
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
        wasmer_import_write_access_guards($path);
    }

    return true;
}

function wasmer_import_ensure_session_staging_dirs($session_id)
{
    $dir = wasmer_import_session_dir($session_id);
    foreach ([$dir, $dir . '/db', $dir . '/files', $dir . '/logs'] as $path) {
        if (!wp_mkdir_p($path)) {
            return false;
        }
        wasmer_import_write_access_guards_recursive($dir, $path);
    }

    return true;
}

function wasmer_import_new_session_id()
{
    return 'wmi_' . strtolower(wp_generate_password(20, false, false));
}

function wasmer_import_create_session($ttl = 28800)
{
    wasmer_import_cleanup_stale_sessions();
    wasmer_import_ensure_root();
    $id = wasmer_import_new_session_id();
    $token = wasmer_import_random_token();
    $session = [
        'id' => $id,
        'status' => 'created',
        'created' => time(),
        'updated' => time(),
        'expires' => time() + max(900, (int) $ttl),
        'token_hash' => wasmer_import_token_hash($token),
        'connected' => false,
        'manifest' => null,
        'database' => [
            'received' => 0,
            'complete' => false,
        ],
        'files' => [],
        'limits' => [
            'max_chunk_size' => (int) apply_filters('wasmer_import_max_chunk_size', 1024 * 1024),
            'max_total_size' => (int) apply_filters(
                'wasmer_import_max_total_size',
                PHP_INT_SIZE >= 8 ? 20 * 1024 * 1024 * 1024 : PHP_INT_MAX
            ),
        ],
    ];

    wasmer_import_save_session($session);
    wasmer_import_ensure_session_staging_dirs($id);

    return [
        'session' => $session,
        'token' => $token,
        'code' => wasmer_import_code_from_session($session, $token),
    ];
}

function wasmer_import_get_session($session_id)
{
    $file = wasmer_import_session_file($session_id);
    if (!is_readable($file)) {
        return null;
    }

    $json = file_get_contents($file);
    $session = json_decode($json, true);
    return is_array($session) ? $session : null;
}

function wasmer_import_save_session($session)
{
    if (empty($session['id'])) {
        return false;
    }

    if (!wasmer_import_ensure_root()) {
        return false;
    }

    $session['updated'] = time();
    $file = wasmer_import_session_file($session['id']);
    return (bool) file_put_contents($file, wp_json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function wasmer_import_public_session($session)
{
    if (!$session) {
        return null;
    }

    $public = $session;
    unset($public['token_hash']);
    $public['logs'] = wasmer_import_read_logs($session['id'], 100);
    return $public;
}

function wasmer_import_read_logs($session_id, $limit = 100)
{
    $file = wasmer_import_session_dir($session_id) . '/logs/import.log';
    if (!is_readable($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }

    return array_slice($lines, -1 * absint($limit));
}

function wasmer_import_list_sessions()
{
    $sessions = [];
    foreach (glob(wasmer_import_sessions_dir() . '/*.json') ?: [] as $file) {
        $session = json_decode(file_get_contents($file), true);
        if (is_array($session)) {
            $sessions[] = wasmer_import_public_session($session);
        }
    }
    usort($sessions, function ($a, $b) {
        return ($b['created'] ?? 0) <=> ($a['created'] ?? 0);
    });
    return $sessions;
}
