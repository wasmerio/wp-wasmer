<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_base64url_decode($value)
{
    $value = strtr((string) $value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

function wasmer_migrate_parse_import_code($code)
{
    $code = trim((string) $code);
    $prefix = 'wasmer-import:v1:';
    if (strpos($code, $prefix) !== 0) {
        return new WP_Error('wasmer_migrate_bad_code', 'Import code has an invalid prefix.');
    }

    $json = wasmer_migrate_base64url_decode(substr($code, strlen($prefix)));
    if ($json === false) {
        return new WP_Error('wasmer_migrate_bad_code_encoding', 'Import code is not valid base64url.');
    }

    $payload = json_decode($json, true);
    if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
        return new WP_Error('wasmer_migrate_bad_code_payload', 'Import code payload is invalid.');
    }

    foreach (['rest', 'session', 'token', 'expires'] as $key) {
        if (empty($payload[$key])) {
            return new WP_Error('wasmer_migrate_missing_code_field', 'Import code is missing required fields.');
        }
    }

    if ((int) $payload['expires'] < time()) {
        return new WP_Error('wasmer_migrate_expired_code', 'Import code has expired.');
    }

    $is_local_environment = function_exists('wp_get_environment_type')
        && in_array(wp_get_environment_type(), ['local', 'development'], true);
    $rest_host = wp_parse_url($payload['rest'], PHP_URL_HOST);
    $is_docker_local_host = is_string($rest_host) && strpos($rest_host, '.') === false;
    if (
        strpos($payload['rest'], 'https://') !== 0
        && strpos($payload['rest'], 'http://localhost') !== 0
        && strpos($payload['rest'], 'http://127.0.0.1') !== 0
        && !$is_docker_local_host
        && !$is_local_environment
    ) {
        return new WP_Error('wasmer_migrate_insecure_target', 'Import target must use HTTPS unless it is local development.');
    }

    $payload['rest'] = untrailingslashit(esc_url_raw($payload['rest']));
    $payload['target'] = esc_url_raw($payload['target'] ?? '');
    $payload['session'] = sanitize_key($payload['session']);

    return $payload;
}
