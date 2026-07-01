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

function wasmer_migrate_connect($code)
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
    $state['id'] = $state['id'] ?: wasmer_migrate_new_id();
    $state['status'] = 'connected';
    $state['code'] = $code;
    $state['destination'] = $destination;
    wasmer_migrate_save_state($state);
    wasmer_migrate_log($state['id'], 'Connected to destination.', ['target' => $destination['target'] ?? '']);

    return $state;
}

function wasmer_migrate_send_file($destination, $kind, $path, $absolute, $chunk_size, $state)
{
    $size = filesize($absolute);
    $final_hash = hash_file('sha256', $absolute);
    $handle = fopen($absolute, 'rb');
    if (!$handle) {
        return new WP_Error('wasmer_migrate_open_failed', 'Could not open export file.');
    }

    $offset = 0;
    while (!feof($handle)) {
        $data = fread($handle, $chunk_size);
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
            $result = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/chunk', $body);
            $attempt++;
            if (!is_wp_error($result)) {
                break;
            }
            sleep(min($attempt, 5));
        } while ($attempt < 3);

        if (is_wp_error($result)) {
            fclose($handle);
            return $result;
        }

        $offset = $next_offset;
        $state['progress']['bytes_sent'] += strlen($data);
        wasmer_migrate_save_state($state);
    }

    fclose($handle);
    return true;
}

function wasmer_migrate_transfer()
{
    $state = wasmer_migrate_get_state();
    if (empty($state['destination']) || empty($state['manifest'])) {
        return new WP_Error('wasmer_migrate_not_ready', 'Prepare an export before transferring.');
    }

    $destination = $state['destination'];
    $manifest = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/manifest', $state['manifest']);
    if (is_wp_error($manifest)) {
        return $manifest;
    }

    $chunk_size = min((int) (($destination['limits']['max_chunk_size'] ?? 1048576)), 1048576);
    if ($chunk_size <= 0) {
        $chunk_size = 524288;
    }

    $state['status'] = 'transferring';
    wasmer_migrate_save_state($state);

    $db = wasmer_migrate_send_file($destination, 'database', '', $state['database']['path'], $chunk_size, $state);
    if (is_wp_error($db)) {
        return $db;
    }
    $state = wasmer_migrate_get_state();
    $state['progress']['database_sent'] = $state['database']['size'];
    wasmer_migrate_save_state($state);

    foreach ($state['files'] as $file) {
        $sent = wasmer_migrate_send_file($destination, 'file', $file['path'], $file['absolute'], $chunk_size, $state);
        if (is_wp_error($sent)) {
            return $sent;
        }
        $state = wasmer_migrate_get_state();
        $state['progress']['files_sent']++;
        wasmer_migrate_save_state($state);
    }

    $complete = wasmer_migrate_request($destination, '/session/' . rawurlencode($destination['session']) . '/complete', []);
    if (is_wp_error($complete)) {
        return $complete;
    }

    $state = wasmer_migrate_get_state();
    $state['status'] = 'transfer_complete';
    unset($state['destination']['token']);
    $state['code'] = null;
    wasmer_migrate_save_state($state);
    wasmer_migrate_log($state['id'], 'Transfer complete.');

    return $state;
}
