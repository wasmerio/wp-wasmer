<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_auth_header($destination)
{
    return 'WasmerImport ' . $destination['session'] . ':' . $destination['token'];
}

function wasmer_migrate_request($destination, $path, $body)
{
    $response = wp_remote_post($destination['rest'] . $path, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => wasmer_migrate_auth_header($destination),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('wasmer_migrate_remote_error', $decoded['message'] ?? 'Destination request failed.', [
            'status' => $status,
            'body' => $decoded,
        ]);
    }

    return is_array($decoded) ? $decoded : [];
}

function wasmer_migrate_connect($code, $run_id = '', $run_token = '')
{
    $destination = wasmer_migrate_parse_import_code($code);
    if (is_wp_error($destination)) {
        return $destination;
    }

    $result = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/connect', [
        'site_url' => site_url(),
        'home_url' => home_url(),
    ]);
    if (is_wp_error($result)) {
        return $result;
    }
    if (isset($result['limits']) && is_array($result['limits'])) {
        $destination['limits'] = $result['limits'];
    }

    $state = wasmer_migrate_get_state();
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $state['id'] = $state['id'] ?: wasmer_migrate_new_id();
    $state['status'] = 'connected';
    $state['code'] = $code;
    $state['destination'] = $destination;
    $saved = ($run_id !== '' && $run_token !== '')
        ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
        : wasmer_migrate_save_state($state);
    if (is_wp_error($saved)) {
        return $saved;
    }
    if ($run_id !== '' && $run_token !== '') {
        wasmer_migrate_log_for_run($state['id'], $run_token, 'Connected to destination.', ['target' => $destination['target'] ?? '']);
    } else {
        wasmer_migrate_log($state['id'], 'Connected to destination.', ['target' => $destination['target'] ?? '']);
    }

    return $state;
}

function wasmer_migrate_send_file($destination, $kind, $path, $absolute, $chunk_size, $state, $run_id = '', $run_token = '')
{
    $size = filesize($absolute);
    $final_hash = hash_file('sha256', $absolute);
    $handle = wasmer_migrate_stream_open($absolute, 'rb');
    if (!$handle) {
        return new WP_Error('wasmer_migrate_open_failed', 'Could not open export file.');
    }

    $offset = 0;
    while (!feof($handle)) {
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            wasmer_migrate_stream_close($handle);
            return wasmer_migrate_stale_run_error();
        }
        $data = wasmer_migrate_stream_read($handle, $chunk_size);
        if ($data === '' || $data === false) {
            break;
        }
        $body = [
            'kind' => $kind,
            'path' => $path,
            'offset' => $offset,
            'data' => base64_encode($data),
            'sha256' => hash('sha256', $data),
        ];
        $next_offset = $offset + strlen($data);
        if ($next_offset === $size) {
            $body['final_size'] = $size;
            $body['final_sha256'] = $final_hash;
        }

        $attempt = 0;
        do {
            if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
                wasmer_migrate_stream_close($handle);
                return wasmer_migrate_stale_run_error();
            }
            $result = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/chunk', $body);
            if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
                wasmer_migrate_stream_close($handle);
                return wasmer_migrate_stale_run_error();
            }
            $attempt++;
            if (!is_wp_error($result)) {
                break;
            }
            if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
                wasmer_migrate_stream_close($handle);
                return wasmer_migrate_stale_run_error();
            }
            sleep(min($attempt, 5));
        } while ($attempt < 3);

        if (is_wp_error($result)) {
            wasmer_migrate_stream_close($handle);
            return $result;
        }

        $offset = $next_offset;
        $state['progress']['bytes_sent'] += strlen($data);
        $saved = ($run_id !== '' && $run_token !== '')
            ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
            : wasmer_migrate_save_state($state);
        if (is_wp_error($saved)) {
            wasmer_migrate_stream_close($handle);
            return $saved;
        }
    }

    wasmer_migrate_stream_close($handle);
    return true;
}

function wasmer_migrate_transfer($run_id = '', $run_token = '')
{
    $state = wasmer_migrate_get_state();
    if (empty($state['destination']) || empty($state['manifest'])) {
        return new WP_Error('wasmer_migrate_not_ready', 'Prepare an export before transferring.');
    }
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }

    $destination = $state['destination'];
    $manifest = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/manifest', $state['manifest']);
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    if (is_wp_error($manifest)) {
        return $manifest;
    }

    $chunk_size = min((int) (($destination['limits']['max_chunk_size'] ?? 1048576)), 1048576);
    if ($chunk_size <= 0) {
        $chunk_size = 524288;
    }

    $progress = is_array($state['progress'] ?? null) ? $state['progress'] : [];
    $database_already_sent = !empty($state['database']['size'])
        && (int) ($progress['database_sent'] ?? 0) >= (int) $state['database']['size'];
    $files_already_sent = max(0, (int) ($progress['files_sent'] ?? 0));

    $state['status'] = 'transferring';
    $saved = ($run_id !== '' && $run_token !== '')
        ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
        : wasmer_migrate_save_state($state);
    if (is_wp_error($saved)) {
        return $saved;
    }

    if (!$database_already_sent) {
        $db = wasmer_migrate_send_file($destination, 'database', '', $state['database']['path'], $chunk_size, $state, $run_id, $run_token);
        if (is_wp_error($db)) {
            return $db;
        }
        $state = wasmer_migrate_get_state();
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        $state['progress']['database_sent'] = $state['database']['size'];
        $saved = ($run_id !== '' && $run_token !== '')
            ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
            : wasmer_migrate_save_state($state);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }
    $state = wasmer_migrate_get_state();

    foreach ($state['files'] as $index => $file) {
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if ($index < $files_already_sent) {
            continue;
        }
        $sent = wasmer_migrate_send_file($destination, 'file', $file['path'], $file['absolute'], $chunk_size, $state, $run_id, $run_token);
        if (is_wp_error($sent)) {
            return $sent;
        }
        $state = wasmer_migrate_get_state();
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        $state['progress']['files_sent']++;
        $saved = ($run_id !== '' && $run_token !== '')
            ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
            : wasmer_migrate_save_state($state);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }

    $complete = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/complete', []);
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    if (is_wp_error($complete)) {
        return $complete;
    }

    $state = wasmer_migrate_get_state();
    $state['status'] = 'transfer_complete';
    unset($state['destination']['token']);
    $state['code'] = null;
    $state['database'] = null;
    $state['files'] = [];
    $saved = ($run_id !== '' && $run_token !== '')
        ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
        : wasmer_migrate_save_state($state);
    if (is_wp_error($saved)) {
        return $saved;
    }
    if (!wasmer_migrate_delete_run_dir($state['id'])) {
        $state['cleanup_warning'] = 'The transfer completed, but temporary export files could not be removed automatically.';
        if ($run_id !== '' && $run_token !== '') {
            wasmer_migrate_save_state_for_run($state, $run_id, $run_token);
            wasmer_migrate_log_for_run($state['id'], $run_token, $state['cleanup_warning']);
        } else {
            wasmer_migrate_save_state($state);
            wasmer_migrate_log($state['id'], $state['cleanup_warning']);
        }
    }
    if ($run_id !== '' && $run_token !== '') {
        wasmer_migrate_log_for_run($state['id'], $run_token, 'Transfer complete.');
    } else {
        wasmer_migrate_log($state['id'], 'Transfer complete.');
    }

    return $state;
}
