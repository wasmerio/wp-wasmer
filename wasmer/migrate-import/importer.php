<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_split_sql($sql)
{
    $statements = [];
    $statement = '';
    $in_string = false;
    $string_char = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $statement .= $char;

        if ($in_string) {
            if ($char === $string_char && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $in_string = false;
            }
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $in_string = true;
            $string_char = $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $statement = '';
        }
    }

    $trimmed = trim($statement);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function wasmer_import_copy_dir($source, $destination, $excluded_top_dirs = [])
{
    if (!is_dir($source)) {
        return true;
    }
    $excluded_top_dirs = array_flip(array_map('sanitize_key', $excluded_top_dirs));

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        $sub_path = str_replace('\\', '/', $iterator->getSubPathName());
        $top_dir = sanitize_key(strtok($sub_path, '/'));
        if ($top_dir && isset($excluded_top_dirs[$top_dir])) {
            continue;
        }
        $target = $destination . '/' . $sub_path;
        if ($item->isDir()) {
            if (!wp_mkdir_p($target)) {
                return new WP_Error('wasmer_import_copy_failed', 'Could not create directory: ' . $target, ['status' => 500]);
            }
        } else {
            if (!wp_mkdir_p(dirname($target))) {
                return new WP_Error('wasmer_import_copy_failed', 'Could not create directory: ' . dirname($target), ['status' => 500]);
            }
            if (!copy($item->getPathname(), $target)) {
                return new WP_Error('wasmer_import_copy_failed', 'Could not copy file: ' . $sub_path, ['status' => 500]);
            }
        }
    }

    return true;
}

function wasmer_import_content_destination($relative_path)
{
    if (strpos($relative_path, 'wp-content/') !== 0) {
        return null;
    }
    return trailingslashit(WP_CONTENT_DIR) . substr($relative_path, strlen('wp-content/'));
}

function wasmer_import_validate_copied_content($manifest)
{
    foreach (($manifest['files'] ?? []) as $file) {
        $relative_path = (string) ($file['path'] ?? '');
        $destination = wasmer_import_content_destination($relative_path);
        if (!$destination) {
            continue;
        }
        if (!is_readable($destination)) {
            return new WP_Error('wasmer_import_copied_file_missing', 'Imported file is missing after copy: ' . $relative_path, ['status' => 500]);
        }
        $expected_size = (int) ($file['size'] ?? -1);
        if (filesize($destination) !== $expected_size) {
            return new WP_Error('wasmer_import_copied_file_size_mismatch', 'Imported file size mismatch: ' . $relative_path, ['status' => 500]);
        }
        $expected_hash = strtolower((string) ($file['sha256'] ?? ''));
        if (!hash_equals($expected_hash, strtolower(hash_file('sha256', $destination)))) {
            return new WP_Error('wasmer_import_copied_file_hash_mismatch', 'Imported file hash mismatch: ' . $relative_path, ['status' => 500]);
        }
    }

    return true;
}

function wasmer_import_update_imported_option($option, $value)
{
    global $wpdb;

    $serialized = maybe_serialize($value);
    $updated = $wpdb->update(
        $wpdb->options,
        ['option_value' => $serialized],
        ['option_name' => $option],
        ['%s'],
        ['%s']
    );
    if ($updated === false || $updated === 0) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $option));
        if (!$exists) {
            $wpdb->insert(
                $wpdb->options,
                [
                    'option_name' => $option,
                    'option_value' => $serialized,
                    'autoload' => 'yes',
                ],
                ['%s', '%s', '%s']
            );
        }
    }
    wp_cache_delete($option, 'options');
    wp_cache_delete('alloptions', 'options');
}

function wasmer_import_database_active_plugins()
{
    global $wpdb;

    $serialized = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins'));
    $active_plugins = maybe_unserialize($serialized);
    return is_array($active_plugins) ? $active_plugins : [];
}

function wasmer_import_final_active_plugins($imported_active_plugins, $destination_active_plugins)
{
    $imported_active_plugins = is_array($imported_active_plugins) ? $imported_active_plugins : [];
    $destination_active_plugins = is_array($destination_active_plugins) ? $destination_active_plugins : [];

    $active_plugins = array_filter($imported_active_plugins, function ($plugin) {
        return is_string($plugin) && $plugin !== 'wasmer-migrate/wasmer-migrate.php';
    });

    if (in_array('wp-wasmer/wp-wasmer.php', $destination_active_plugins, true)) {
        $active_plugins[] = 'wp-wasmer/wp-wasmer.php';
    }

    return array_values(array_unique($active_plugins));
}

function wasmer_import_validate_plugin_file($plugin_file)
{
    $plugin_file = str_replace('\\', '/', (string) $plugin_file);
    if ($plugin_file === '' || strpos($plugin_file, '../') !== false || strpos($plugin_file, '/') === 0) {
        return false;
    }
    return is_readable(trailingslashit(WP_CONTENT_DIR) . 'plugins/' . $plugin_file);
}

function wasmer_import_validate_active_dependencies()
{
    $active_plugins = get_option('active_plugins', []);
    if (is_array($active_plugins)) {
        foreach ($active_plugins as $plugin_file) {
            if (!is_string($plugin_file)) {
                continue;
            }
            if (!wasmer_import_validate_plugin_file($plugin_file)) {
                return new WP_Error('wasmer_import_active_plugin_missing', 'Active plugin file is missing after import: ' . $plugin_file, ['status' => 500]);
            }
        }
    }

    $stylesheet = sanitize_file_name((string) get_option('stylesheet'));
    $template = sanitize_file_name((string) get_option('template'));
    foreach (array_unique(array_filter([$stylesheet, $template])) as $theme_slug) {
        if (!is_readable(trailingslashit(WP_CONTENT_DIR) . 'themes/' . $theme_slug . '/style.css')) {
            return new WP_Error('wasmer_import_active_theme_missing', 'Active theme files are missing after import: ' . $theme_slug, ['status' => 500]);
        }
    }

    return true;
}

function wasmer_import_validate_core_version($manifest)
{
    $source_version = (string) ($manifest['source']['wp_version'] ?? '');
    $destination_version = get_bloginfo('version');
    if ($source_version !== '' && version_compare($source_version, $destination_version, '>')) {
        return 'The source site uses WordPress ' . $source_version . ', while this Wasmer app package runs WordPress ' . $destination_version . '. Import can continue, but review the site after import and upgrade the Wasmer WordPress package when available.';
    }

    return true;
}

function wasmer_import_run_core_db_upgrade()
{
    if (!function_exists('wp_upgrade')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    if (function_exists('wp_upgrade')) {
        wp_upgrade();
    }
}

function wasmer_import_fail_session($session, $error, $message = '')
{
    if (!is_array($session)) {
        return;
    }
    $session['status'] = 'failed';
    $session['error'] = $message ?: (is_wp_error($error) ? $error->get_error_message() : (string) $error);
    wasmer_import_save_session($session);
    wasmer_import_log($session['id'], 'Import failed.', ['error' => $session['error']]);
}

function wasmer_import_manifest_database_tables($manifest)
{
    $database = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
    $tables = is_array($database['tables'] ?? null) ? $database['tables'] : [];
    return array_values(array_filter($tables, 'is_string'));
}

function wasmer_import_staging_prefix($destination_prefix, $session_id)
{
    return $destination_prefix . 'wmi_' . substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $session_id)), 4, 8) . '_';
}

function wasmer_import_backup_prefix($destination_prefix, $session_id)
{
    return $destination_prefix . 'wmb_' . substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $session_id)), 4, 8) . '_';
}

function wasmer_import_remapped_manifest_tables($manifest, $source_prefix, $target_prefix)
{
    $tables = [];
    foreach (wasmer_import_manifest_database_tables($manifest) as $source_table) {
        $tables[$source_table] = wasmer_import_remap_table_name($source_table, $source_prefix, $target_prefix);
    }
    return $tables;
}

function wasmer_import_drop_tables($tables)
{
    global $wpdb;

    if (!$tables) {
        return true;
    }

    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $result = $wpdb->query('DROP TABLE IF EXISTS ' . wasmer_import_sql_identifier($table));
        if ($result === false) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new WP_Error('wasmer_import_drop_failed', 'Could not prepare database table: ' . $table . '. ' . $wpdb->last_error, ['status' => 500]);
        }
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

    return true;
}

function wasmer_import_table_exists($table)
{
    global $wpdb;

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return is_string($found) && strcasecmp($found, $table) === 0;
}

function wasmer_import_update_option_in_table($table, $option, $value)
{
    global $wpdb;

    $serialized = maybe_serialize($value);
    $updated = $wpdb->update(
        $table,
        ['option_value' => $serialized],
        ['option_name' => $option],
        ['%s'],
        ['%s']
    );
    if ($updated === false || $updated === 0) {
        $exists = $wpdb->get_var($wpdb->prepare('SELECT option_id FROM ' . wasmer_import_sql_identifier($table) . ' WHERE option_name = %s', $option));
        if (!$exists) {
            $wpdb->insert(
                $table,
                [
                    'option_name' => $option,
                    'option_value' => $serialized,
                    'autoload' => 'yes',
                ],
                ['%s', '%s', '%s']
            );
        }
    }
}

function wasmer_import_active_plugins_from_options_table($table)
{
    global $wpdb;

    $serialized = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM ' . wasmer_import_sql_identifier($table) . ' WHERE option_name = %s', 'active_plugins'));
    $active_plugins = maybe_unserialize($serialized);
    return is_array($active_plugins) ? $active_plugins : [];
}

function wasmer_import_prepare_staged_options($options_table, $destination_active_plugins, $destination_siteurl, $destination_home)
{
    if (!wasmer_import_table_exists($options_table)) {
        return new WP_Error('wasmer_import_missing_staged_options', 'Imported options table is missing from the staging database.', ['status' => 500]);
    }

    $imported_active_plugins = wasmer_import_active_plugins_from_options_table($options_table);
    wasmer_import_update_option_in_table($options_table, 'active_plugins', wasmer_import_final_active_plugins($imported_active_plugins, $destination_active_plugins));
    wasmer_import_update_option_in_table($options_table, 'siteurl', $destination_siteurl);
    wasmer_import_update_option_in_table($options_table, 'home', $destination_home);

    return true;
}

function wasmer_import_rewrite_staged_prefixed_keys($manifest, $source_prefix, $staging_prefix, $destination_prefix)
{
    global $wpdb;

    if ($staging_prefix === $destination_prefix) {
        return true;
    }

    $options_table = wasmer_import_remap_table_name($source_prefix . 'options', $source_prefix, $staging_prefix);
    if (wasmer_import_table_exists($options_table)) {
        $result = $wpdb->query($wpdb->prepare(
            'UPDATE ' . wasmer_import_sql_identifier($options_table) . ' SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s',
            $destination_prefix,
            strlen($staging_prefix) + 1,
            $wpdb->esc_like($staging_prefix) . '%'
        ));
        if ($result === false) {
            return new WP_Error('wasmer_import_key_rewrite_failed', 'Could not rewrite staged option keys: ' . $wpdb->last_error, ['status' => 500]);
        }
    }

    $usermeta_table = wasmer_import_remap_table_name($source_prefix . 'usermeta', $source_prefix, $staging_prefix);
    if (wasmer_import_table_exists($usermeta_table)) {
        $result = $wpdb->query($wpdb->prepare(
            'UPDATE ' . wasmer_import_sql_identifier($usermeta_table) . ' SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
            $destination_prefix,
            strlen($staging_prefix) + 1,
            $wpdb->esc_like($staging_prefix) . '%'
        ));
        if ($result === false) {
            return new WP_Error('wasmer_import_key_rewrite_failed', 'Could not rewrite staged usermeta keys: ' . $wpdb->last_error, ['status' => 500]);
        }
    }

    return true;
}

function wasmer_import_table_columns($table)
{
    global $wpdb;

    $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . wasmer_import_sql_identifier($table), 0);
    return is_array($columns) ? $columns : [];
}

function wasmer_import_next_user_id($users_table)
{
    global $wpdb;

    return (int) $wpdb->get_var('SELECT COALESCE(MAX(ID), 0) + 1 FROM ' . wasmer_import_sql_identifier($users_table));
}

function wasmer_import_delete_staged_user($users_table, $usermeta_table, $user_id)
{
    global $wpdb;

    $wpdb->delete($usermeta_table, ['user_id' => (int) $user_id], ['%d']);
    $wpdb->delete($users_table, ['ID' => (int) $user_id], ['%d']);
}

function wasmer_import_staged_user_id_for_destination_user($users_table, $user)
{
    global $wpdb;

    $user_id = $wpdb->get_var($wpdb->prepare(
        'SELECT ID FROM ' . wasmer_import_sql_identifier($users_table) . ' WHERE user_login = %s LIMIT 1',
        (string) $user['user_login']
    ));
    if ($user_id) {
        return (int) $user_id;
    }

    if (!empty($user['user_email'])) {
        $user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT ID FROM ' . wasmer_import_sql_identifier($users_table) . ' WHERE user_email = %s LIMIT 1',
            (string) $user['user_email']
        ));
        if ($user_id) {
            return (int) $user_id;
        }
    }

    $id_available = !$wpdb->get_var($wpdb->prepare(
        'SELECT ID FROM ' . wasmer_import_sql_identifier($users_table) . ' WHERE ID = %d LIMIT 1',
        (int) $user['ID']
    ));

    return $id_available ? (int) $user['ID'] : wasmer_import_next_user_id($users_table);
}

function wasmer_import_preserve_destination_users($manifest, $source_prefix, $staging_prefix, $destination_prefix)
{
    global $wpdb;

    $destination_users_table = $wpdb->users;
    $destination_usermeta_table = $wpdb->usermeta;
    $staged_users_table = wasmer_import_remap_table_name($source_prefix . 'users', $source_prefix, $staging_prefix);
    $staged_usermeta_table = wasmer_import_remap_table_name($source_prefix . 'usermeta', $source_prefix, $staging_prefix);

    if (!wasmer_import_table_exists($staged_users_table) || !wasmer_import_table_exists($staged_usermeta_table)) {
        return true;
    }

    $user_columns = wasmer_import_table_columns($staged_users_table);
    if (!$user_columns) {
        return new WP_Error('wasmer_import_user_merge_failed', 'Could not inspect staged users table.', ['status' => 500]);
    }

    $destination_users = $wpdb->get_results('SELECT * FROM ' . wasmer_import_sql_identifier($destination_users_table), ARRAY_A);
    if (!is_array($destination_users)) {
        return new WP_Error('wasmer_import_user_merge_failed', 'Could not read destination users.', ['status' => 500]);
    }

    foreach ($destination_users as $user) {
        $source_user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT ID FROM ' . wasmer_import_sql_identifier($staged_users_table) . ' WHERE user_login = %s LIMIT 1',
            (string) $user['user_login']
        ));
        if (!$source_user_id && !empty($user['user_email'])) {
            $source_user_id = $wpdb->get_var($wpdb->prepare(
                'SELECT ID FROM ' . wasmer_import_sql_identifier($staged_users_table) . ' WHERE user_email = %s LIMIT 1',
                (string) $user['user_email']
            ));
        }
        if ($source_user_id) {
            // Source credentials and metadata win when the same identity exists
            // on both sites. Destination-only users are still merged below.
            continue;
        }

        $staged_user_id = wasmer_import_staged_user_id_for_destination_user($staged_users_table, $user);

        $conflicting_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT ID FROM ' . wasmer_import_sql_identifier($staged_users_table) . ' WHERE (user_login = %s OR user_email = %s) AND ID <> %d',
            (string) $user['user_login'],
            (string) $user['user_email'],
            (int) $staged_user_id
        ));
        foreach ($conflicting_ids ?: [] as $conflicting_id) {
            wasmer_import_delete_staged_user($staged_users_table, $staged_usermeta_table, (int) $conflicting_id);
        }

        $row = array_intersect_key($user, array_flip($user_columns));
        $row['ID'] = $staged_user_id;

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT ID FROM ' . wasmer_import_sql_identifier($staged_users_table) . ' WHERE ID = %d LIMIT 1',
            (int) $staged_user_id
        ));

        if ($exists) {
            $update = $row;
            unset($update['ID']);
            $result = $wpdb->update($staged_users_table, $update, ['ID' => (int) $staged_user_id]);
        } else {
            $result = $wpdb->insert($staged_users_table, $row);
        }
        if ($result === false) {
            return new WP_Error('wasmer_import_user_merge_failed', 'Could not preserve destination user: ' . $wpdb->last_error, ['status' => 500]);
        }

        $wpdb->delete($staged_usermeta_table, ['user_id' => (int) $staged_user_id], ['%d']);
        $metadata = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_key, meta_value FROM ' . wasmer_import_sql_identifier($destination_usermeta_table) . ' WHERE user_id = %d',
            (int) $user['ID']
        ), ARRAY_A);
        if (!is_array($metadata)) {
            return new WP_Error('wasmer_import_user_merge_failed', 'Could not read destination user metadata.', ['status' => 500]);
        }
        foreach ($metadata as $meta) {
            $result = $wpdb->insert(
                $staged_usermeta_table,
                [
                    'user_id' => (int) $staged_user_id,
                    'meta_key' => (string) $meta['meta_key'],
                    'meta_value' => (string) $meta['meta_value'],
                ],
                ['%d', '%s', '%s']
            );
            if ($result === false) {
                return new WP_Error('wasmer_import_user_merge_failed', 'Could not preserve destination user metadata: ' . $wpdb->last_error, ['status' => 500]);
            }
        }
    }

    return true;
}

function wasmer_import_swap_staged_tables($manifest, $source_prefix, $destination_prefix, $staging_prefix, $backup_prefix, $session_id)
{
    global $wpdb;

    $source_tables = wasmer_import_manifest_database_tables($manifest);
    if (!$source_tables) {
        return true;
    }

    $staging_tables = wasmer_import_remapped_manifest_tables($manifest, $source_prefix, $staging_prefix);
    $destination_tables = wasmer_import_remapped_manifest_tables($manifest, $source_prefix, $destination_prefix);
    $backup_tables = wasmer_import_remapped_manifest_tables($manifest, $source_prefix, $backup_prefix);

    foreach ($staging_tables as $staging_table) {
        if (!wasmer_import_table_exists($staging_table)) {
            return new WP_Error('wasmer_import_missing_staged_table', 'Imported staging table is missing: ' . $staging_table, ['status' => 500]);
        }
    }

    $drop_backups = wasmer_import_drop_tables(array_values($backup_tables));
    if (is_wp_error($drop_backups)) {
        return $drop_backups;
    }

    $renames = [];
    foreach ($source_tables as $source_table) {
        $destination_table = $destination_tables[$source_table];
        if (wasmer_import_table_exists($destination_table)) {
            $renames[] = wasmer_import_sql_identifier($destination_table) . ' TO ' . wasmer_import_sql_identifier($backup_tables[$source_table]);
        }
        $renames[] = wasmer_import_sql_identifier($staging_tables[$source_table]) . ' TO ' . wasmer_import_sql_identifier($destination_table);
    }

    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    $result = $wpdb->query('RENAME TABLE ' . implode(', ', $renames));
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    if ($result === false) {
        return new WP_Error('wasmer_import_swap_failed', 'Could not swap imported database tables into place: ' . $wpdb->last_error, ['status' => 500]);
    }

    $cleanup = wasmer_import_drop_tables(array_values($backup_tables));
    if (is_wp_error($cleanup)) {
        wasmer_import_log($session_id, 'Could not clean old database tables after import.', ['error' => $cleanup->get_error_message()]);
    }

    return true;
}

function wasmer_import_sql_string($value)
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function wasmer_import_sql_identifier($identifier)
{
    return '`' . str_replace('`', '``', (string) $identifier) . '`';
}

function wasmer_import_parse_sql_values($values_sql)
{
    $values = [];
    $length = strlen($values_sql);
    $i = 0;

    while ($i < $length) {
        while ($i < $length && ctype_space($values_sql[$i])) {
            $i++;
        }

        if ($i >= $length) {
            break;
        }

        if ($values_sql[$i] === "'") {
            $i++;
            $value = '';
            while ($i < $length) {
                $char = $values_sql[$i];
                if ($char === '\\' && $i + 1 < $length) {
                    $value .= $values_sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === "'") {
                    $i++;
                    break;
                }
                $value .= $char;
                $i++;
            }
            $values[] = ['type' => 'string', 'value' => $value];
        } else {
            $start = $i;
            while ($i < $length && $values_sql[$i] !== ',') {
                $i++;
            }
            $token = trim(substr($values_sql, $start, $i - $start));
            if (strtoupper($token) === 'NULL') {
                $values[] = ['type' => 'null', 'value' => null];
            } else {
                $values[] = ['type' => 'raw', 'value' => $token];
            }
        }

        while ($i < $length && ctype_space($values_sql[$i])) {
            $i++;
        }
        if ($i < $length && $values_sql[$i] === ',') {
            $i++;
        }
    }

    return $values;
}

function wasmer_import_parse_sql_columns($columns_sql)
{
    $columns = [];
    foreach (explode(',', $columns_sql) as $column) {
        $column = trim($column);
        if (strlen($column) >= 2 && $column[0] === '`' && substr($column, -1) === '`') {
            $column = substr($column, 1, -1);
        }
        $columns[] = str_replace('``', '`', $column);
    }
    return $columns;
}

function wasmer_import_replace_urls_in_value($value, $url_replacements)
{
    foreach ($url_replacements as $source_url => $destination_url) {
        if ($source_url !== '' && $source_url !== $destination_url) {
            $value = str_replace($source_url, $destination_url, $value);
        }
    }
    return $value;
}

function wasmer_import_replace_urls_recursive($value, $url_replacements)
{
    if (is_string($value)) {
        return wasmer_import_replace_urls_in_value($value, $url_replacements);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = wasmer_import_replace_urls_recursive($item, $url_replacements);
        }
        return $value;
    }

    if (is_object($value)) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->$key = wasmer_import_replace_urls_recursive($item, $url_replacements);
        }
    }

    return $value;
}

function wasmer_import_replace_serialized_urls($value, $url_replacements)
{
    if (!is_string($value)) {
        return $value;
    }

    if (is_serialized($value)) {
        $unserialized = @unserialize(trim($value));
        if ($unserialized !== false || trim($value) === 'b:0;') {
            return maybe_serialize(wasmer_import_replace_urls_recursive($unserialized, $url_replacements));
        }
    }

    return wasmer_import_replace_urls_in_value($value, $url_replacements);
}

function wasmer_import_remap_prefixed_key($value, $source_prefix, $destination_prefix)
{
    if ($source_prefix === '' || $source_prefix === $destination_prefix) {
        return $value;
    }

    if (strpos($value, $source_prefix) === 0) {
        return $destination_prefix . substr($value, strlen($source_prefix));
    }

    return $value;
}

function wasmer_import_remap_table_name($table, $source_prefix, $destination_prefix)
{
    if ($source_prefix === '' || $source_prefix === $destination_prefix) {
        return $table;
    }

    if (strpos($table, $source_prefix) === 0) {
        return $destination_prefix . substr($table, strlen($source_prefix));
    }

    return $table;
}

function wasmer_import_table_suffix($table, $source_prefix, $destination_prefix)
{
    if ($source_prefix !== '' && strpos($table, $source_prefix) === 0) {
        return substr($table, strlen($source_prefix));
    }
    if ($destination_prefix !== '' && strpos($table, $destination_prefix) === 0) {
        return substr($table, strlen($destination_prefix));
    }
    return $table;
}

function wasmer_import_remap_statement_identifiers($statement, $source_prefix, $destination_prefix)
{
    if ($source_prefix === '' || $source_prefix === $destination_prefix) {
        return $statement;
    }

    return preg_replace_callback(
        '/^((?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE|LOCK\s+TABLES)\s+)`([^`]+)`/i',
        function ($matches) use ($source_prefix, $destination_prefix) {
            return $matches[1] . wasmer_import_sql_identifier(wasmer_import_remap_table_name($matches[2], $source_prefix, $destination_prefix));
        },
        $statement,
        1
    );
}

function wasmer_import_transform_insert_statement($statement, $source_prefix, $destination_prefix, $url_replacements)
{
    if (!preg_match('/^INSERT\s+INTO\s+`([^`]+)`\s+\((.*)\)\s+VALUES\s+\((.*)\);?$/is', trim($statement), $matches)) {
        return wasmer_import_remap_statement_identifiers($statement, $source_prefix, $destination_prefix);
    }

    $source_table = $matches[1];
    $destination_table = wasmer_import_remap_table_name($source_table, $source_prefix, $destination_prefix);
    $table_suffix = wasmer_import_table_suffix($source_table, $source_prefix, $destination_prefix);
    $columns = wasmer_import_parse_sql_columns($matches[2]);
    $values = wasmer_import_parse_sql_values($matches[3]);

    foreach ($values as $index => $value) {
        if ($value['type'] !== 'string') {
            continue;
        }

        $column = $columns[$index] ?? '';
        $next = wasmer_import_replace_serialized_urls($value['value'], $url_replacements);

        if ($table_suffix === 'options' && $column === 'option_name') {
            $next = wasmer_import_remap_prefixed_key($next, $source_prefix, $destination_prefix);
        }

        if ($table_suffix === 'usermeta' && $column === 'meta_key') {
            $next = wasmer_import_remap_prefixed_key($next, $source_prefix, $destination_prefix);
        }

        $values[$index]['value'] = $next;
    }

    $sql_columns = array_map('wasmer_import_sql_identifier', $columns);
    $sql_values = array_map(function ($value) {
        if ($value['type'] === 'null') {
            return 'NULL';
        }
        if ($value['type'] === 'raw') {
            return $value['value'];
        }
        return wasmer_import_sql_string($value['value']);
    }, $values);

    return 'INSERT INTO ' . wasmer_import_sql_identifier($destination_table) . ' (' . implode(',', $sql_columns) . ') VALUES (' . implode(',', $sql_values) . ');';
}

function wasmer_import_transform_sql_statement($statement, $source_prefix, $destination_prefix, $url_replacements)
{
    if (preg_match('/^INSERT\s+INTO\s+/i', ltrim($statement))) {
        return wasmer_import_transform_insert_statement($statement, $source_prefix, $destination_prefix, $url_replacements);
    }

    return wasmer_import_remap_statement_identifiers($statement, $source_prefix, $destination_prefix);
}

function wasmer_import_url_replacements($manifest, $destination_siteurl, $destination_home)
{
    $source_siteurl = untrailingslashit((string) ($manifest['source']['site_url'] ?? ''));
    $source_home = untrailingslashit((string) ($manifest['source']['home_url'] ?? ''));
    $destination_siteurl = untrailingslashit((string) $destination_siteurl);
    $destination_home = untrailingslashit((string) $destination_home);
    $replacements = [];

    if ($source_home !== '') {
        $replacements[$source_home] = $destination_home;
    }
    if ($source_siteurl !== '') {
        $replacements[$source_siteurl] = $destination_siteurl;
    }

    uksort($replacements, function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    return $replacements;
}

function wasmer_import_start($session_id)
{
    global $wpdb;

    $session = wasmer_import_get_session($session_id);
    if (!$session) {
        return new WP_Error('wasmer_import_unknown_session', 'Unknown import session.', ['status' => 404]);
    }

    if (($session['status'] ?? '') === 'transfer_complete') {
        $verified = wasmer_import_verify_session($session);
        if (is_wp_error($verified)) {
            return $verified;
        }
        $session = $verified;
    }

    if (($session['status'] ?? '') !== 'verified') {
        return new WP_Error('wasmer_import_not_ready', 'Transfer is not complete.', ['status' => 409]);
    }

    $manifest = wasmer_import_load_manifest($session_id);
    if (!$manifest) {
        return new WP_Error('wasmer_import_missing_manifest', 'Import manifest is missing.', ['status' => 409]);
    }
    $core_version_valid = wasmer_import_validate_core_version($manifest);
    if (is_wp_error($core_version_valid)) {
        return $core_version_valid;
    }
    if (is_string($core_version_valid) && $core_version_valid !== '') {
        wasmer_import_log($session_id, $core_version_valid);
    }
    $source_prefix = (string) ($manifest['source']['table_prefix'] ?? '');
    $destination_prefix = (string) $wpdb->prefix;
    $staging_prefix = wasmer_import_staging_prefix($destination_prefix, $session_id);
    $backup_prefix = wasmer_import_backup_prefix($destination_prefix, $session_id);

    $session['status'] = 'importing';
    wasmer_import_save_session($session);
    wasmer_import_log($session_id, 'Import started.');

    $destination_active_plugins = get_option('active_plugins', []);
    if (!is_array($destination_active_plugins)) {
        $destination_active_plugins = [];
    }
    $destination_siteurl = get_option('siteurl');
    $destination_home = get_option('home');
    $url_replacements = wasmer_import_url_replacements($manifest, $destination_siteurl, $destination_home);

    $db_file = wasmer_import_session_dir($session_id) . '/db/database.sql';
    if (is_readable($db_file)) {
        $staged_tables = wasmer_import_remapped_manifest_tables($manifest, $source_prefix, $staging_prefix);
        $clean_staging = wasmer_import_drop_tables(array_values($staged_tables));
        if (is_wp_error($clean_staging)) {
            wasmer_import_fail_session($session, $clean_staging);
            return $clean_staging;
        }

        $sql = file_get_contents($db_file);
        foreach (wasmer_import_split_sql($sql) as $statement) {
            $statement = wasmer_import_transform_sql_statement($statement, $source_prefix, $staging_prefix, $url_replacements);
            $result = $wpdb->query($statement);
            if ($result === false) {
                $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                wasmer_import_drop_tables(array_values($staged_tables));
                $session['status'] = 'failed';
                $session['error'] = $wpdb->last_error;
                wasmer_import_save_session($session);
                wasmer_import_log($session_id, 'Database import failed.', ['error' => $wpdb->last_error]);
                return new WP_Error('wasmer_import_sql_failed', 'Database import failed: ' . $wpdb->last_error, ['status' => 500]);
            }
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        $staged_options = wasmer_import_remap_table_name($source_prefix . 'options', $source_prefix, $staging_prefix);
        $staged_options_ready = wasmer_import_prepare_staged_options($staged_options, $destination_active_plugins, $destination_siteurl, $destination_home);
        if (is_wp_error($staged_options_ready)) {
            wasmer_import_drop_tables(array_values($staged_tables));
            wasmer_import_fail_session($session, $staged_options_ready);
            return $staged_options_ready;
        }
        $staged_keys_ready = wasmer_import_rewrite_staged_prefixed_keys($manifest, $source_prefix, $staging_prefix, $destination_prefix);
        if (is_wp_error($staged_keys_ready)) {
            wasmer_import_drop_tables(array_values($staged_tables));
            wasmer_import_fail_session($session, $staged_keys_ready);
            return $staged_keys_ready;
        }
        $staged_users_ready = wasmer_import_preserve_destination_users($manifest, $source_prefix, $staging_prefix, $destination_prefix);
        if (is_wp_error($staged_users_ready)) {
            wasmer_import_drop_tables(array_values($staged_tables));
            wasmer_import_fail_session($session, $staged_users_ready);
            return $staged_users_ready;
        }
        $swapped = wasmer_import_swap_staged_tables($manifest, $source_prefix, $destination_prefix, $staging_prefix, $backup_prefix, $session_id);
        if (is_wp_error($swapped)) {
            wasmer_import_drop_tables(array_values($staged_tables));
            wasmer_import_fail_session($session, $swapped);
            return $swapped;
        }
        wp_cache_flush();
        wasmer_import_log($session_id, 'Database SQL imported and swapped into place.');
    }

    $uploads_source = wasmer_import_session_dir($session_id) . '/files/wp-content/uploads';
    if (is_dir($uploads_source)) {
        $copied = wasmer_import_copy_dir($uploads_source, WP_CONTENT_DIR . '/uploads');
        if (is_wp_error($copied)) {
            wasmer_import_fail_session($session, $copied);
            return $copied;
        }
        wasmer_import_log($session_id, 'Uploads copied.');
    }
    $themes_source = wasmer_import_session_dir($session_id) . '/files/wp-content/themes';
    if (is_dir($themes_source)) {
        $copied = wasmer_import_copy_dir($themes_source, WP_CONTENT_DIR . '/themes');
        if (is_wp_error($copied)) {
            wasmer_import_fail_session($session, $copied);
            return $copied;
        }
        wasmer_import_log($session_id, 'Themes copied.');
    }
    $plugins_source = wasmer_import_session_dir($session_id) . '/files/wp-content/plugins';
    if (is_dir($plugins_source)) {
        $copied = wasmer_import_copy_dir($plugins_source, WP_CONTENT_DIR . '/plugins', ['wasmer-migrate', 'wp-wasmer']);
        if (is_wp_error($copied)) {
            wasmer_import_fail_session($session, $copied);
            return $copied;
        }
        wasmer_import_log($session_id, 'Plugins copied.');
    }

    wasmer_import_update_imported_option('siteurl', $destination_siteurl);
    wasmer_import_update_imported_option('home', $destination_home);
    wasmer_import_run_core_db_upgrade();
    $content_valid = wasmer_import_validate_copied_content($manifest);
    if (is_wp_error($content_valid)) {
        wasmer_import_fail_session($session, $content_valid);
        return $content_valid;
    }
    $dependencies_valid = wasmer_import_validate_active_dependencies();
    if (is_wp_error($dependencies_valid)) {
        wasmer_import_fail_session($session, $dependencies_valid);
        return $dependencies_valid;
    }
    flush_rewrite_rules(false);

    $session['status'] = 'complete';
    $session['completed'] = time();
    wasmer_import_save_session($session);
    wasmer_import_log($session_id, 'Import completed.');

    return wasmer_import_public_session($session);
}
