<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_normalize_relative_path($path)
{
    $path = str_replace('\\', '/', (string) $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');

    if ($path === '' || strpos($path, "\0") !== false || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
        return new WP_Error('wasmer_import_bad_path', 'Invalid file path.', ['status' => 400]);
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return new WP_Error('wasmer_import_path_traversal', 'Path traversal is not allowed.', ['status' => 400]);
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function wasmer_import_storage_path($session_id, $relative_path)
{
    $relative_path = wasmer_import_normalize_relative_path($relative_path);
    if (is_wp_error($relative_path)) {
        return $relative_path;
    }

    $base = wasmer_import_session_dir($session_id);
    $path = $base . '/' . $relative_path;
    $base_real = realpath($base);
    if (!$base_real) {
        wp_mkdir_p($base);
        $base_real = realpath($base);
    }

    $parent = dirname($path);
    if (!wp_mkdir_p($parent)) {
        return new WP_Error('wasmer_import_unwritable', 'Could not create destination directory.', ['status' => 500]);
    }
    $guard_parent = $parent;
    $files_root = rtrim($base, '/') . '/files';
    $normalized_parent = rtrim(str_replace('\\', '/', $parent), '/');
    if ($normalized_parent !== $files_root && strpos($normalized_parent . '/', $files_root . '/') === 0) {
        $guard_parent = $files_root;
    }
    wasmer_import_write_access_guards_recursive($base, $guard_parent);

    $parent_real = realpath($parent);
    $base_real = $base_real ? rtrim(str_replace('\\', '/', $base_real), '/') : '';
    $parent_real = $parent_real ? rtrim(str_replace('\\', '/', $parent_real), '/') : '';
    if (!$base_real || !$parent_real || ($parent_real !== $base_real && strpos($parent_real . '/', $base_real . '/') !== 0)) {
        return new WP_Error('wasmer_import_outside_root', 'Resolved path is outside the import session.', ['status' => 400]);
    }

    return $path;
}

function wasmer_import_write_chunk($session, $relative_path, $offset, $bytes, $chunk_hash = null, $final_size = null, $final_hash = null)
{
    $max_chunk = (int) ($session['limits']['max_chunk_size'] ?? 1048576);
    if (strlen($bytes) > $max_chunk) {
        return new WP_Error('wasmer_import_chunk_too_large', 'Chunk exceeds destination limit.', ['status' => 413]);
    }

    if ($chunk_hash && !hash_equals(strtolower($chunk_hash), hash('sha256', $bytes))) {
        return new WP_Error('wasmer_import_chunk_hash_mismatch', 'Chunk hash mismatch.', ['status' => 400]);
    }

    $final = wasmer_import_storage_path($session['id'], $relative_path);
    if (is_wp_error($final)) {
        return $final;
    }

    $target = wasmer_import_storage_path($session['id'], $relative_path . '.part');
    if (is_wp_error($target)) {
        return $target;
    }

    $offset = max(0, (int) $offset);
    $current_size = file_exists($target) ? filesize($target) : 0;
    $length = strlen($bytes);

    if (file_exists($final)) {
        $final_file_size = filesize($final);
        if ($final_size !== null && (int) $final_size === $final_file_size && $offset + $length <= $final_file_size) {
            $existing = file_get_contents($final, false, null, $offset, $length);
            $hash_matches = !$final_hash || hash_equals(strtolower($final_hash), hash_file('sha256', $final));
            if ($existing !== false && strlen($existing) === $length && hash_equals(hash('sha256', $existing), hash('sha256', $bytes)) && $hash_matches) {
                return [
                    'accepted' => true,
                    'duplicate' => true,
                    'offset' => $offset,
                    'received' => $final_file_size,
                    'complete' => true,
                ];
            }
        }
        return new WP_Error('wasmer_import_file_already_complete', 'Staged file is already complete.', ['status' => 409]);
    }

    if ($current_size > $offset) {
        $existing = file_get_contents($target, false, null, $offset, $length);
        if ($existing !== false && strlen($existing) === $length && hash_equals(hash('sha256', $existing), hash('sha256', $bytes))) {
            return [
                'accepted' => true,
                'duplicate' => true,
                'offset' => $offset,
                'received' => $current_size,
                'complete' => false,
            ];
        }
        return new WP_Error('wasmer_import_offset_mismatch', 'Chunk offset does not match current file size.', [
            'status' => 409,
            'expected_offset' => $current_size,
        ]);
    }

    if ($current_size !== $offset) {
        return new WP_Error('wasmer_import_offset_mismatch', 'Chunk offset does not match current file size.', [
            'status' => 409,
            'expected_offset' => $current_size,
        ]);
    }

    $handle = fopen($target, 'c+b');
    if (!$handle) {
        return new WP_Error('wasmer_import_unwritable', 'Could not open destination file.', ['status' => 500]);
    }

    if (fseek($handle, $offset) !== 0) {
        fclose($handle);
        return new WP_Error('wasmer_import_seek_failed', 'Could not seek destination file.', ['status' => 500]);
    }

    $written = fwrite($handle, $bytes);
    fflush($handle);
    fclose($handle);

    if ($written !== $length) {
        return new WP_Error('wasmer_import_write_failed', 'Could not write complete chunk.', ['status' => 500]);
    }

    $received = $offset + $length;
    $complete = false;
    if ($final_size !== null && (int) $final_size === $received) {
        if ($final_hash && !hash_equals(strtolower($final_hash), hash_file('sha256', $target))) {
            return new WP_Error('wasmer_import_file_hash_mismatch', 'Final file hash mismatch.', ['status' => 400]);
        }
        rename($target, $final);
        $complete = true;
    }

    return [
        'accepted' => true,
        'duplicate' => false,
        'offset' => $offset,
        'received' => $received,
        'complete' => $complete,
    ];
}
