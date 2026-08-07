<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_sql_string($value)
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function wasmer_migrate_sql_identifier($value)
{
    return '`' . str_replace('`', '``', (string) $value) . '`';
}

function wasmer_migrate_export_database($migration_id)
{
    global $wpdb;

    wasmer_migrate_ensure_run_dir($migration_id);
    $file = wasmer_migrate_run_dir($migration_id) . '/database.sql';
    $handle = wasmer_migrate_stream_open($file, 'wb');
    if (!$handle) {
        return new WP_Error('wasmer_migrate_db_export_failed', 'Could not create database export file.');
    }

    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
    foreach ($tables as $table) {
        $table_identifier = wasmer_migrate_sql_identifier($table);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The identifier is backtick-escaped by wasmer_migrate_sql_identifier().
        $create = $wpdb->get_row('SHOW CREATE TABLE ' . $table_identifier, ARRAY_N);
        if ($create && isset($create[1])) {
            wasmer_migrate_stream_write($handle, 'DROP TABLE IF EXISTS ' . $table_identifier . ";\n" . $create[1] . ";\n\n");
        }

        $offset = 0;
        $limit = 500;
        do {
            $rows = $wpdb->get_results("SELECT * FROM {$table_identifier} LIMIT $limit OFFSET $offset", ARRAY_A);
            foreach ($rows as $row) {
                $columns = array_map('wasmer_migrate_sql_identifier', array_keys($row));
                $values = array_map('wasmer_migrate_sql_string', array_values($row));
                wasmer_migrate_stream_write($handle, 'INSERT INTO ' . $table_identifier . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }
            $offset += $limit;
        } while (count($rows) === $limit);

        wasmer_migrate_stream_write($handle, "\n");
    }

    wasmer_migrate_stream_close($handle);

    return [
        'path' => $file,
        'size' => filesize($file),
        'sha256' => hash_file('sha256', $file),
        'tables' => $tables,
    ];
}
