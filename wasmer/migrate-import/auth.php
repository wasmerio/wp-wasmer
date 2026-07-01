<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_token_hash($token)
{
    $salt = defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : wp_salt('auth');
    return hash_hmac('sha256', (string) $token, $salt);
}

function wasmer_import_random_token()
{
    return bin2hex(random_bytes(32));
}

function wasmer_import_base64url_encode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function wasmer_import_base64url_decode($value)
{
    $value = strtr((string) $value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

function wasmer_import_code_from_session($session, $token)
{
    $payload = [
        'v' => 1,
        'target' => home_url(),
        'rest' => rest_url('wasmer/v1/import'),
        'session' => $session['id'],
        'token' => $token,
        'expires' => (int) $session['expires'],
        'app_id' => defined('WASMER_APP_ID') ? WASMER_APP_ID : '',
    ];

    return 'wasmer-import:v1:' . wasmer_import_base64url_encode(wp_json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function wasmer_import_parse_authorization($request)
{
    $header = $request->get_header('authorization');
    if (!$header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$header) {
        return new WP_Error('wasmer_import_missing_auth', 'Missing import authorization.', ['status' => 401]);
    }

    if (preg_match('/^WasmerImport\s+([^:]+):(.+)$/', $header, $matches)) {
        return [
            'session' => sanitize_key($matches[1]),
            'token' => trim($matches[2]),
        ];
    }

    if (preg_match('/^Bearer\s+(.+)$/', $header, $matches)) {
        $parts = explode(':', trim($matches[1]), 2);
        if (count($parts) === 2) {
            return [
                'session' => sanitize_key($parts[0]),
                'token' => $parts[1],
            ];
        }
    }

    return new WP_Error('wasmer_import_bad_auth', 'Invalid import authorization.', ['status' => 401]);
}

function wasmer_import_authenticate_request($request)
{
    $auth = wasmer_import_parse_authorization($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $session = wasmer_import_get_session($auth['session']);
    if (!$session) {
        return new WP_Error('wasmer_import_unknown_session', 'Unknown import session.', ['status' => 404]);
    }

    if (!empty($session['cancelled']) || ($session['status'] ?? '') === 'cancelled') {
        return new WP_Error('wasmer_import_cancelled', 'Import session was cancelled.', ['status' => 409]);
    }

    if ((int) ($session['expires'] ?? 0) < time()) {
        $session['status'] = 'expired';
        wasmer_import_save_session($session);
        return new WP_Error('wasmer_import_expired', 'Import code expired.', ['status' => 403]);
    }

    if (empty($session['token_hash']) || !hash_equals($session['token_hash'], wasmer_import_token_hash($auth['token']))) {
        return new WP_Error('wasmer_import_forbidden', 'Invalid import token.', ['status' => 403]);
    }

    return $session;
}
