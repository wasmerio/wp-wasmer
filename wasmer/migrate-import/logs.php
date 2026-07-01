<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_log($session_id, $message, $context = [])
{
    $session_id = sanitize_key($session_id);
    if (!$session_id) {
        return;
    }

    $dir = wasmer_import_session_dir($session_id) . '/logs';
    if (!wp_mkdir_p($dir)) {
        return;
    }

    $context = is_array($context) ? $context : [];
    unset($context['token'], $context['authorization'], $context['code']);

    $line = wp_json_encode([
        'time' => gmdate('c'),
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES);

    if ($line) {
        file_put_contents($dir . '/import.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
