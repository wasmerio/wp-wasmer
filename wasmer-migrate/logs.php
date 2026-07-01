<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_log($migration_id, $message, $context = [])
{
    if (!$migration_id) {
        return;
    }
    $dir = wasmer_migrate_run_dir($migration_id) . '/logs';
    if (!wp_mkdir_p($dir)) {
        return;
    }
    $context = is_array($context) ? $context : [];
    unset($context['token'], $context['code'], $context['authorization']);
    $line = wp_json_encode([
        'time' => gmdate('c'),
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES);
    if ($line) {
        file_put_contents($dir . '/migrate.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function wasmer_migrate_read_logs($migration_id, $limit = 100)
{
    $file = wasmer_migrate_run_dir($migration_id) . '/logs/migrate.log';
    if (!is_readable($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice($lines ?: [], -1 * absint($limit));
}
