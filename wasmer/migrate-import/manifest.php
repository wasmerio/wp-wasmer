<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_validate_manifest($manifest)
{
    if (!is_array($manifest)) {
        return new WP_Error('wasmer_import_bad_manifest', 'Manifest must be an object.', ['status' => 400]);
    }

    if ((int) ($manifest['version'] ?? 0) !== 1) {
        return new WP_Error('wasmer_import_bad_manifest_version', 'Unsupported manifest version.', ['status' => 400]);
    }

    if (empty($manifest['source']) || !is_array($manifest['source'])) {
        return new WP_Error('wasmer_import_missing_source', 'Manifest is missing source metadata.', ['status' => 400]);
    }

    if (empty($manifest['database']) || !is_array($manifest['database'])) {
        return new WP_Error('wasmer_import_missing_database', 'Manifest is missing database metadata.', ['status' => 400]);
    }

    if (!array_key_exists('size', $manifest['database']) || !wasmer_import_is_non_negative_integer($manifest['database']['size'])) {
        return new WP_Error('wasmer_import_bad_database_size', 'Manifest database size is invalid.', ['status' => 400]);
    }

    if (!wasmer_import_is_sha256($manifest['database']['sha256'] ?? '')) {
        return new WP_Error('wasmer_import_bad_database_hash', 'Manifest database hash is invalid.', ['status' => 400]);
    }

    if (!array_key_exists('files', $manifest) || !is_array($manifest['files'])) {
        return new WP_Error('wasmer_import_missing_files', 'Manifest is missing file metadata.', ['status' => 400]);
    }

    $seen = [];
    foreach ($manifest['files'] as $file) {
        if (!is_array($file)) {
            return new WP_Error('wasmer_import_bad_file_entry', 'Manifest file entries must be objects.', ['status' => 400]);
        }
        $path = wasmer_import_normalize_relative_path($file['path'] ?? '');
        if (is_wp_error($path)) {
            return $path;
        }
        if (!wasmer_import_is_supported_content_path($path)) {
            return new WP_Error('wasmer_import_unsupported_path', 'Imports only support files under wp-content/uploads, wp-content/themes, or wp-content/plugins.', ['status' => 400]);
        }
        if (isset($seen[$path])) {
            return new WP_Error('wasmer_import_duplicate_file', 'Manifest contains a duplicate file path.', ['status' => 400]);
        }
        $seen[$path] = true;
        if (!array_key_exists('size', $file) || !wasmer_import_is_non_negative_integer($file['size'])) {
            return new WP_Error('wasmer_import_bad_file_size', 'Manifest file size is invalid: ' . $path, ['status' => 400]);
        }
        if (!wasmer_import_is_sha256($file['sha256'] ?? '')) {
            return new WP_Error('wasmer_import_bad_file_hash', 'Manifest file hash is invalid: ' . $path, ['status' => 400]);
        }
    }

    $totals = wasmer_import_manifest_totals($manifest);
    if (is_wp_error($totals)) {
        return $totals;
    }
    if (isset($manifest['total_size']) && (int) $manifest['total_size'] !== $totals['total_size']) {
        return new WP_Error('wasmer_import_total_size_mismatch', 'Manifest total size does not match file metadata.', ['status' => 400]);
    }
    if (isset($manifest['file_count']) && (int) $manifest['file_count'] !== $totals['file_count']) {
        return new WP_Error('wasmer_import_file_count_mismatch', 'Manifest file count does not match file metadata.', ['status' => 400]);
    }

    return true;
}

function wasmer_import_is_supported_content_path($path)
{
    foreach (['wp-content/uploads/', 'wp-content/themes/', 'wp-content/plugins/'] as $prefix) {
        if (strpos($path, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

function wasmer_import_is_non_negative_integer($value)
{
    if (is_int($value)) {
        return $value >= 0;
    }
    return is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value);
}

function wasmer_import_is_sha256($value)
{
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value);
}

function wasmer_import_manifest_totals($manifest)
{
    $database_size = (int) ($manifest['database']['size'] ?? 0);
    $files_size = 0;
    $file_count = 0;

    foreach (($manifest['files'] ?? []) as $file) {
        $size = (int) ($file['size'] ?? 0);
        if ($size < 0) {
            return new WP_Error('wasmer_import_bad_file_size', 'Manifest file size is invalid.', ['status' => 400]);
        }
        $files_size += $size;
        $file_count++;
    }

    return [
        'database_size' => $database_size,
        'files_size' => $files_size,
        'total_size' => $database_size + $files_size,
        'file_count' => $file_count,
    ];
}

function wasmer_import_manifest_file_map($manifest)
{
    $map = [];
    foreach (($manifest['files'] ?? []) as $file) {
        $path = wasmer_import_normalize_relative_path($file['path'] ?? '');
        if (is_wp_error($path)) {
            return $path;
        }
        $map[$path] = [
            'size' => (int) ($file['size'] ?? 0),
            'sha256' => strtolower((string) ($file['sha256'] ?? '')),
        ];
    }
    return $map;
}

function wasmer_import_manifest_path($session_id)
{
    return wasmer_import_session_dir($session_id) . '/manifest.json';
}

function wasmer_import_store_manifest($session, $manifest)
{
    $valid = wasmer_import_validate_manifest($manifest);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $manifest_hash = hash('sha256', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES));
    if (!empty($session['manifest']['received'])) {
        $existing_hash = (string) ($session['manifest']['sha256'] ?? '');
        if (!hash_equals($existing_hash, $manifest_hash)) {
            return new WP_Error('wasmer_import_manifest_replay_mismatch', 'Import manifest has already been received for this session.', ['status' => 409]);
        }
        return wasmer_import_public_session($session);
    }

    $dir = wasmer_import_session_dir($session['id']);
    if (!wasmer_import_ensure_session_staging_dirs($session['id'])) {
        return new WP_Error('wasmer_import_unwritable', 'Could not create import session directory.', ['status' => 500]);
    }

    $totals = wasmer_import_manifest_totals($manifest);
    if (is_wp_error($totals)) {
        return $totals;
    }
    if ($totals['total_size'] > (int) ($session['limits']['max_total_size'] ?? 0)) {
        return new WP_Error('wasmer_import_too_large', 'Migration exceeds destination size limit.', ['status' => 413]);
    }

    file_put_contents(wasmer_import_manifest_path($session['id']), wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $session['manifest'] = [
        'received' => true,
        'sha256' => $manifest_hash,
        'file_count' => $totals['file_count'],
        'database_size' => $totals['database_size'],
        'files_size' => $totals['files_size'],
        'total_size' => $totals['total_size'],
    ];
    $session['status'] = 'manifest_received';
    wasmer_import_save_session($session);
    wasmer_import_log($session['id'], 'Manifest received.', $session['manifest']);

    return wasmer_import_public_session($session);
}

function wasmer_import_load_manifest($session_id)
{
    $file = wasmer_import_manifest_path($session_id);
    if (!is_readable($file)) {
        return null;
    }
    $manifest = json_decode(file_get_contents($file), true);
    return is_array($manifest) ? $manifest : null;
}

function wasmer_import_is_guard_file($name)
{
    return in_array($name, ['index.php', '.htaccess', 'web.config', '.user.ini'], true);
}

function wasmer_import_verify_no_unexpected_files($base, $expected)
{
    if (!is_dir($base)) {
        return true;
    }

    $base_real = realpath($base);
    if (!$base_real) {
        return new WP_Error('wasmer_import_bad_staging_dir', 'Could not inspect staged files.', ['status' => 409]);
    }
    $base_real = rtrim(str_replace('\\', '/', $base_real), '/');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        if (wasmer_import_is_guard_file($item->getFilename())) {
            continue;
        }

        $path = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($path, strlen($base_real)), '/');
        if (!isset($expected[$relative])) {
            return new WP_Error('wasmer_import_unexpected_file', 'Unexpected staged file: ' . $relative, ['status' => 409]);
        }
    }

    return true;
}

function wasmer_import_verify_session($session)
{
    $manifest = wasmer_import_load_manifest($session['id']);
    if (!$manifest) {
        return new WP_Error('wasmer_import_missing_manifest', 'Import manifest is missing.', ['status' => 409]);
    }
    $valid = wasmer_import_validate_manifest($manifest);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $totals = wasmer_import_manifest_totals($manifest);
    if (is_wp_error($totals)) {
        return $totals;
    }
    if ($totals['total_size'] > (int) ($session['limits']['max_total_size'] ?? 0)) {
        return new WP_Error('wasmer_import_too_large', 'Migration exceeds destination size limit.', ['status' => 413]);
    }

    $db_file = wasmer_import_session_dir($session['id']) . '/db/database.sql';
    $db_size = (int) ($manifest['database']['size'] ?? 0);
    $db_hash = strtolower((string) ($manifest['database']['sha256'] ?? ''));
    if (!is_readable($db_file) || filesize($db_file) !== $db_size) {
        return new WP_Error('wasmer_import_database_incomplete', 'Database transfer is incomplete.', ['status' => 409]);
    }
    if (!hash_equals($db_hash, hash_file('sha256', $db_file))) {
        return new WP_Error('wasmer_import_database_hash_mismatch', 'Database hash verification failed.', ['status' => 409]);
    }

    $expected_db = ['database.sql' => true];
    $unexpected_db = wasmer_import_verify_no_unexpected_files(wasmer_import_session_dir($session['id']) . '/db', $expected_db);
    if (is_wp_error($unexpected_db)) {
        return $unexpected_db;
    }

    $expected_files = [];
    $verified_files = 0;
    foreach (($manifest['files'] ?? []) as $file) {
        $relative = wasmer_import_normalize_relative_path($file['path'] ?? '');
        if (is_wp_error($relative)) {
            return $relative;
        }
        $expected_files[$relative] = true;
        $path = wasmer_import_session_dir($session['id']) . '/files/' . $relative;
        if (!is_readable($path) || filesize($path) !== (int) ($file['size'] ?? 0)) {
            return new WP_Error('wasmer_import_file_incomplete', 'File transfer is incomplete: ' . $relative, ['status' => 409]);
        }
        $hash = strtolower((string) ($file['sha256'] ?? ''));
        if (!hash_equals($hash, hash_file('sha256', $path))) {
            return new WP_Error('wasmer_import_file_hash_mismatch', 'File hash verification failed: ' . $relative, ['status' => 409]);
        }
        $verified_files++;
    }

    if ($verified_files !== $totals['file_count']) {
        return new WP_Error('wasmer_import_file_count_mismatch', 'Verified file count does not match manifest.', ['status' => 409]);
    }

    $unexpected_files = wasmer_import_verify_no_unexpected_files(wasmer_import_session_dir($session['id']) . '/files', $expected_files);
    if (is_wp_error($unexpected_files)) {
        return $unexpected_files;
    }

    $session['status'] = 'verified';
    $session['verified'] = time();
    $session['transfer_completed'] = $session['transfer_completed'] ?? time();
    wasmer_import_save_session($session);
    wasmer_import_log($session['id'], 'Transfer verified.');

    return $session;
}
