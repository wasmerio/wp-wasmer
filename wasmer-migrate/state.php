<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_state_key()
{
    return 'wasmer_migrate_active_state';
}

function wasmer_migrate_default_state()
{
    return [
        'id' => '',
        'status' => 'idle',
        'code' => null,
        'destination' => null,
        'auto_app' => null,
        'manifest' => null,
        'database' => null,
        'files' => [],
        'progress' => [
            'database_sent' => 0,
            'files_sent' => 0,
            'bytes_sent' => 0,
        ],
        'error' => '',
        'updated' => time(),
    ];
}

function wasmer_migrate_get_state()
{
    $state = get_option(wasmer_migrate_state_key(), []);
    return array_merge(wasmer_migrate_default_state(), is_array($state) ? $state : []);
}

function wasmer_migrate_save_state($state)
{
    $state['updated'] = time();
    update_option(wasmer_migrate_state_key(), $state, false);
}

function wasmer_migrate_reset_state()
{
    delete_option(wasmer_migrate_state_key());
}
