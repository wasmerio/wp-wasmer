<?php

$kind = $args[0] ?? '';
$id = $args[1] ?? '';

function wasmer_e2e_error($message)
{
    WP_CLI::error($message);
}

function wasmer_e2e_assert_contains($haystack, $needle, $file)
{
    if (strpos($haystack, $needle) === false) {
        wasmer_e2e_error('Guard file missing "' . $needle . '": ' . $file);
    }
}

function wasmer_e2e_assert_guarded_dir($dir)
{
    if (!is_dir($dir)) {
        wasmer_e2e_error('Expected staging directory missing: ' . $dir);
    }

    $guards = [
        'index.php',
        '.htaccess',
        'web.config',
        '.user.ini',
    ];
    foreach ($guards as $guard) {
        $path = trailingslashit($dir) . $guard;
        if (!is_readable($path)) {
            wasmer_e2e_error('Guard file missing: ' . $path);
        }
    }

    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . '.htaccess'), 'Require all denied', $dir . '/.htaccess');
    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . '.htaccess'), 'Deny from all', $dir . '/.htaccess');
    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . '.htaccess'), 'FilesMatch', $dir . '/.htaccess');
    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . 'web.config'), '<deny users="*" />', $dir . '/web.config');
    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . 'web.config'), 'fileExtension=".php"', $dir . '/web.config');
    wasmer_e2e_assert_contains(file_get_contents(trailingslashit($dir) . '.user.ini'), 'engine = Off', $dir . '/.user.ini');
}

function wasmer_e2e_assert_guarded_tree($root)
{
    wasmer_e2e_assert_guarded_dir($root);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            wasmer_e2e_assert_guarded_dir($item->getPathname());
        }
    }
}

if ($kind === 'import') {
    if (!$id) {
        wasmer_e2e_error('Missing import session id.');
    }
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        wasmer_e2e_error($uploads['error']);
    }
    $root = trailingslashit($uploads['basedir']) . 'wasmer-hosting-integration/import';
    $session_root = $root . '/' . sanitize_key($id);
    wasmer_e2e_assert_guarded_dir($root);
    wasmer_e2e_assert_guarded_dir($root . '/sessions');
    if (file_exists($session_root)) {
        wasmer_e2e_error('Completed import artifacts were not removed: ' . $session_root);
    }
    WP_CLI::success('Validated import staging cleanup.');
    return;
}

if ($kind === 'migrate') {
    $state = get_option('wasmer_migrate_active_state', []);
    $id = $id ?: (is_array($state) ? ($state['id'] ?? '') : '');
    if (!$id) {
        wasmer_e2e_error('Missing migrate run id.');
    }
    $root = trailingslashit(WP_CONTENT_DIR) . 'wasmer-migrate';
    wasmer_e2e_assert_guarded_dir($root);
    wasmer_e2e_assert_guarded_tree($root . '/' . sanitize_key($id));
    if (file_exists($root . '/' . sanitize_key($id) . '/database.sql')) {
        wasmer_e2e_error('Transferred database export was not removed.');
    }
    WP_CLI::success('Validated migrate staging cleanup and guards.');
    return;
}

wasmer_e2e_error('Unknown staging guard kind: ' . $kind);
