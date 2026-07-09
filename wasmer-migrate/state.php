<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_state_key()
{
    return 'wasmer_migrate_active_state';
}

function wasmer_migrate_cancelled_runs_key()
{
    return 'wasmer_migrate_cancelled_runs';
}

function wasmer_migrate_default_state()
{
    return [
        'id' => '',
        'run_token' => '',
        'resume_allowed' => false,
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

function wasmer_migrate_new_run_token()
{
    return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('', true));
}

function wasmer_migrate_fresh_state()
{
    $state = wasmer_migrate_default_state();
    $state['run_token'] = wasmer_migrate_new_run_token();
    $state['updated'] = time();
    return $state;
}

function wasmer_migrate_get_state()
{
    $state = get_option(wasmer_migrate_state_key(), []);
    $state = array_merge(wasmer_migrate_default_state(), is_array($state) ? $state : []);
    if (empty($state['run_token'])) {
        $state['run_token'] = wasmer_migrate_new_run_token();
        update_option(wasmer_migrate_state_key(), $state, false);
    }
    return $state;
}

function wasmer_migrate_save_state($state)
{
    $state['updated'] = time();
    update_option(wasmer_migrate_state_key(), $state, false);
}

function wasmer_migrate_replace_state_if_current($state, $current)
{
    global $wpdb;

    $state['updated'] = time();
    $updated = $wpdb->update(
        $wpdb->options,
        ['option_value' => maybe_serialize($state)],
        [
            'option_name' => wasmer_migrate_state_key(),
            'option_value' => maybe_serialize($current),
        ],
        ['%s'],
        ['%s', '%s']
    );
    if ($updated === false) {
        return new WP_Error('wasmer_migrate_state_save_failed', 'Could not save migration state.');
    }
    if ((int) $updated !== 1) {
        wp_cache_delete(wasmer_migrate_state_key(), 'options');
        $latest = get_option(wasmer_migrate_state_key(), []);
        if (maybe_serialize(is_array($latest) ? $latest : []) === maybe_serialize($state)) {
            return $state;
        }
        return false;
    }

    wp_cache_delete(wasmer_migrate_state_key(), 'options');
    return $state;
}

function wasmer_migrate_begin_run()
{
    $state = wasmer_migrate_fresh_state();
    $state['id'] = wasmer_migrate_new_id();
    $state['resume_allowed'] = false;
    wasmer_migrate_save_state($state);
    return $state;
}

function wasmer_migrate_cancel_run($id, $token)
{
    if ((string) $id === '' || (string) $token === '') {
        return;
    }

    $cancelled = get_option(wasmer_migrate_cancelled_runs_key(), []);
    $cancelled = is_array($cancelled) ? $cancelled : [];
    $cancelled[(string) $id] = [
        'token' => (string) $token,
        'time' => time(),
    ];

    if (count($cancelled) > 20) {
        uasort($cancelled, function ($a, $b) {
            return (int) ($a['time'] ?? 0) <=> (int) ($b['time'] ?? 0);
        });
        $cancelled = array_slice($cancelled, -20, null, true);
    }

    update_option(wasmer_migrate_cancelled_runs_key(), $cancelled, false);
    wp_cache_delete(wasmer_migrate_cancelled_runs_key(), 'options');
}

function wasmer_migrate_is_run_cancelled($id, $token)
{
    if ((string) $id === '' || (string) $token === '') {
        return true;
    }

    wp_cache_delete(wasmer_migrate_cancelled_runs_key(), 'options');
    $cancelled = get_option(wasmer_migrate_cancelled_runs_key(), []);
    $cancelled = is_array($cancelled) ? $cancelled : [];
    $entry = $cancelled[(string) $id] ?? null;
    return is_array($entry) && hash_equals((string) ($entry['token'] ?? ''), (string) $token);
}

function wasmer_migrate_is_active_run($id, $token)
{
    if ((string) $id === '' || (string) $token === '') {
        return false;
    }
    if (wasmer_migrate_is_run_cancelled($id, $token)) {
        return false;
    }

    wp_cache_delete(wasmer_migrate_state_key(), 'options');
    $state = wasmer_migrate_get_state();
    return hash_equals((string) ($state['id'] ?? ''), (string) $id)
        && hash_equals((string) ($state['run_token'] ?? ''), (string) $token);
}

function wasmer_migrate_stale_run_error()
{
    return new WP_Error('wasmer_migrate_stale_run', 'This migration run was cancelled or replaced.');
}

function wasmer_migrate_save_state_for_run($state, $id, $token)
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if (wasmer_migrate_is_run_cancelled($id, $token)) {
            return wasmer_migrate_stale_run_error();
        }
        wp_cache_delete(wasmer_migrate_state_key(), 'options');
        $current = wasmer_migrate_get_state();
        if (!hash_equals((string) ($current['id'] ?? ''), (string) $id)
            || !hash_equals((string) ($current['run_token'] ?? ''), (string) $token)) {
            return wasmer_migrate_stale_run_error();
        }

        $state['id'] = $id;
        $state['run_token'] = $token;
        $saved = wasmer_migrate_replace_state_if_current($state, $current);
        if ($saved !== false) {
            return $saved;
        }
    }

    return new WP_Error('wasmer_migrate_state_race', 'Could not save migration state because it changed concurrently.');
}

function wasmer_migrate_reset_state()
{
    wp_cache_delete(wasmer_migrate_state_key(), 'options');
    $state = wasmer_migrate_get_state();
    wasmer_migrate_cancel_run($state['id'] ?? '', $state['run_token'] ?? '');
    wasmer_migrate_save_state(wasmer_migrate_fresh_state());
}
