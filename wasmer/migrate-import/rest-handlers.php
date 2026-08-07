<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_finalized_statuses()
{
    return ['verified', 'importing', 'complete', 'failed'];
}

function wasmer_import_is_finalized_status($status)
{
    return in_array((string) $status, wasmer_import_finalized_statuses(), true);
}

function wasmer_import_require_mutable_transfer($session)
{
    if (wasmer_import_is_finalized_status($session['status'] ?? '')) {
        return new WP_Error('wasmer_import_transfer_finalized', 'Transfer has already been finalized for this session.', ['status' => 409]);
    }
    return true;
}

function wasmer_import_rest_create_session($request)
{
    wasmer_import_load_import_dependencies();

    $ttl = absint($request->get_param('ttl'));
    $created = wasmer_import_create_session($ttl ?: 28800);
    return [
        'session' => wasmer_import_public_session($created['session']),
        'code' => $created['code'],
    ];
}

function wasmer_import_rest_get_session($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_get_session($request['id']);
    if (!$session) {
        return new WP_Error('wasmer_import_unknown_session', 'Unknown import session.', ['status' => 404]);
    }
    return wasmer_import_public_session($session);
}

function wasmer_import_rest_connect($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_authenticate_request($request);
    if (is_wp_error($session)) {
        return $session;
    }
    $mutable = wasmer_import_require_mutable_transfer($session);
    if (is_wp_error($mutable)) {
        return $mutable;
    }

    $session['connected'] = true;
    $session['status'] = 'connected';
    $session['source'] = [
        'site_url' => esc_url_raw($request->get_param('site_url')),
        'home_url' => esc_url_raw($request->get_param('home_url')),
    ];
    wasmer_import_save_session($session);
    wasmer_import_log($session['id'], 'Source connected.', $session['source']);

    return [
        'ok' => true,
        'session' => wasmer_import_public_session($session),
        'limits' => $session['limits'],
    ];
}

function wasmer_import_rest_manifest($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_authenticate_request($request);
    if (is_wp_error($session)) {
        return $session;
    }
    $mutable = wasmer_import_require_mutable_transfer($session);
    if (is_wp_error($mutable)) {
        return $mutable;
    }
    return wasmer_import_store_manifest($session, $request->get_json_params());
}

function wasmer_import_validate_chunk_request($session, $kind, $params, $length)
{
    if (!in_array($kind, ['database', 'file'], true)) {
        return new WP_Error('wasmer_import_bad_chunk_kind', 'Chunk kind must be database or file.', ['status' => 400]);
    }

    if (!in_array(($session['status'] ?? ''), ['manifest_received', 'transferring'], true)) {
        return new WP_Error('wasmer_import_not_ready_for_chunks', 'Receive a manifest before uploading chunks.', ['status' => 409]);
    }

    $manifest = wasmer_import_load_manifest($session['id']);
    if (!$manifest) {
        return new WP_Error('wasmer_import_missing_manifest', 'Import manifest is missing.', ['status' => 409]);
    }

    $offset = max(0, (int) ($params['offset'] ?? 0));
    $projected = $offset + $length;
    if ($kind === 'database') {
        $expected = [
            'path' => 'db/database.sql',
            'key' => '',
            'size' => (int) ($manifest['database']['size'] ?? 0),
            'sha256' => strtolower((string) ($manifest['database']['sha256'] ?? '')),
            'received' => (int) ($session['database']['received'] ?? 0),
        ];
    } else {
        $normalized = wasmer_import_normalize_relative_path($params['path'] ?? '');
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        $files = wasmer_import_manifest_file_map($manifest);
        if (is_wp_error($files)) {
            return $files;
        }
        if (!isset($files[$normalized])) {
            return new WP_Error('wasmer_import_file_not_in_manifest', 'Chunk path is not declared in the manifest.', ['status' => 409]);
        }
        $expected = [
            'path' => 'files/' . $normalized,
            'key' => $normalized,
            'size' => $files[$normalized]['size'],
            'sha256' => $files[$normalized]['sha256'],
            'received' => (int) ($session['files'][$normalized]['received'] ?? 0),
        ];
    }

    if ($projected > $expected['size']) {
        return new WP_Error('wasmer_import_chunk_exceeds_manifest', 'Chunk exceeds the manifest size for this item.', ['status' => 413]);
    }

    $is_final_chunk = $projected === $expected['size'];
    $has_final_size = array_key_exists('final_size', $params);
    if ($is_final_chunk && !$has_final_size) {
        return new WP_Error('wasmer_import_missing_final_size', 'Final chunk must include the manifest final size.', ['status' => 400]);
    }
    if (!$is_final_chunk && $has_final_size) {
        return new WP_Error('wasmer_import_early_final_size', 'Final size can only be sent with the final chunk.', ['status' => 400]);
    }
    if ($has_final_size && (int) $params['final_size'] !== $expected['size']) {
        return new WP_Error('wasmer_import_final_size_mismatch', 'Final chunk size does not match the manifest.', ['status' => 400]);
    }
    if ($is_final_chunk && !hash_equals($expected['sha256'], strtolower((string) ($params['final_sha256'] ?? '')))) {
        return new WP_Error('wasmer_import_final_hash_mismatch', 'Final chunk hash does not match the manifest.', ['status' => 400]);
    }

    $total_received = (int) ($session['database']['received'] ?? 0);
    foreach (($session['files'] ?? []) as $file) {
        $total_received += (int) ($file['received'] ?? 0);
    }
    $projected_total = $total_received - $expected['received'] + max($expected['received'], $projected);
    if ($projected_total > (int) ($session['limits']['max_total_size'] ?? 0)) {
        return new WP_Error('wasmer_import_too_large', 'Migration exceeds destination size limit.', ['status' => 413]);
    }

    return $expected;
}

function wasmer_import_rest_chunk($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_authenticate_request($request);
    if (is_wp_error($session)) {
        return $session;
    }
    $mutable = wasmer_import_require_mutable_transfer($session);
    if (is_wp_error($mutable)) {
        return $mutable;
    }

    $params = $request->get_json_params();
    $kind = sanitize_key($params['kind'] ?? 'file');
    $data = base64_decode((string) ($params['data'] ?? ''), true);
    if ($data === false) {
        return new WP_Error('wasmer_import_bad_chunk_data', 'Chunk data must be base64 encoded.', ['status' => 400]);
    }
    $expected = wasmer_import_validate_chunk_request($session, $kind, $params, strlen($data));
    if (is_wp_error($expected)) {
        return $expected;
    }

    $result = wasmer_import_write_chunk(
        $session,
        $expected['path'],
        (int) ($params['offset'] ?? 0),
        $data,
        $params['sha256'] ?? null,
        array_key_exists('final_size', $params) ? (int) $params['final_size'] : null,
        $params['final_sha256'] ?? null
    );
    if (is_wp_error($result)) {
        return $result;
    }

    $session = wasmer_import_get_session($session['id']);
    $session['status'] = 'transferring';
    if ($kind === 'database') {
        $session['database']['received'] = max((int) ($session['database']['received'] ?? 0), (int) $result['received']);
        $session['database']['complete'] = (bool) $result['complete'];
    } else {
        $session['files'][$expected['key']] = [
            'received' => (int) $result['received'],
            'complete' => (bool) $result['complete'],
        ];
    }
    wasmer_import_save_session($session);

    return [
        'ok' => true,
        'result' => $result,
        'session' => wasmer_import_public_session($session),
    ];
}

function wasmer_import_rest_complete($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_authenticate_request($request);
    if (is_wp_error($session)) {
        return $session;
    }
    if (wasmer_import_is_finalized_status($session['status'] ?? '')) {
        return new WP_Error('wasmer_import_transfer_already_complete', 'Transfer has already been finalized for this session.', ['status' => 409]);
    }
    if (!in_array(($session['status'] ?? ''), ['manifest_received', 'transferring'], true)) {
        return new WP_Error('wasmer_import_not_ready_to_complete', 'Transfer is not ready to complete.', ['status' => 409]);
    }

    $verified = wasmer_import_verify_session($session);
    if (is_wp_error($verified)) {
        return $verified;
    }
    wasmer_import_log($session['id'], 'Transfer completed.');

    return [
        'ok' => true,
        'session' => wasmer_import_public_session($verified),
    ];
}

function wasmer_import_rest_start_import($request)
{
    wasmer_import_load_import_dependencies();

    if (function_exists('wasmer_import_dependency_report')) {
        $report = wasmer_import_dependency_report($request['id']);
        if (!empty($report['errors'])) {
            return new WP_Error('wasmer_import_dependency_blocked', implode(' ', $report['errors']), ['status' => 409]);
        }
    }
    return wasmer_import_start($request['id']);
}

function wasmer_import_rest_cancel($request)
{
    wasmer_import_load_import_dependencies();

    $session = wasmer_import_get_session($request['id']);
    if (!$session) {
        return new WP_Error('wasmer_import_unknown_session', 'Unknown import session.', ['status' => 404]);
    }
    $session['status'] = 'cancelled';
    $session['cancelled'] = time();
    wasmer_import_save_session($session);
    wasmer_import_log($session['id'], 'Import session cancelled.');
    $public = wasmer_import_public_session($session);
    wasmer_import_delete_session_artifacts($session['id']);
    return $public;
}
