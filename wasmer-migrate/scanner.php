<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_should_exclude_path($path)
{
    $path = str_replace('\\', '/', $path);
    $exclusions = [
        '/cache/',
        '/.cache/',
        '/wasmer-import/',
        '/wasmer-migrate/',
        '/wp-wasmer/',
        '/backup/',
        '/backups/',
        '/backup-',
        '/backups-',
        '/-backup/',
        '/-backups/',
    ];
    foreach ($exclusions as $exclude) {
        if (strpos($path, $exclude) !== false) {
            return true;
        }
    }
    return (bool) preg_match('/\.(log|tmp|part)$/i', $path);
}

function wasmer_migrate_file_entry($absolute, $relative)
{
    return [
        'path' => $relative,
        'absolute' => $absolute,
        'size' => filesize($absolute),
        'mtime' => filemtime($absolute),
        'sha256' => hash_file('sha256', $absolute),
    ];
}

function wasmer_migrate_scan_directory($base, $relative_base)
{
    $files = [];

    if (!is_dir($base)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $absolute = $item->getPathname();
        if (wasmer_migrate_should_exclude_path($absolute)) {
            continue;
        }
        $relative_to_base = ltrim(str_replace('\\', '/', substr($absolute, strlen($base))), '/');
        $files[] = wasmer_migrate_file_entry($absolute, trailingslashit($relative_base) . $relative_to_base);
    }

    return $files;
}

function wasmer_migrate_scan_uploads()
{
    $upload = wp_get_upload_dir();
    return wasmer_migrate_scan_directory($upload['basedir'], 'wp-content/uploads');
}

function wasmer_migrate_scan_content_assets($options = [])
{
    $files = [];

    if (!empty($options['include_uploads'])) {
        $files = array_merge($files, wasmer_migrate_scan_uploads());
    }
    if (!empty($options['include_themes'])) {
        $files = array_merge($files, wasmer_migrate_scan_directory(WP_CONTENT_DIR . '/themes', 'wp-content/themes'));
    }
    if (!empty($options['include_plugins'])) {
        $files = array_merge($files, wasmer_migrate_scan_directory(WP_CONTENT_DIR . '/plugins', 'wp-content/plugins'));
    }

    return $files;
}
