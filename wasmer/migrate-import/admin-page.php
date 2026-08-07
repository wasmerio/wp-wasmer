<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_admin_menu()
{
    if (!WASMER_MIGRATIONS_UI_ENABLED) {
        return;
    }

    add_submenu_page(
        'wasmer-cdn-cache',
        'Import Site',
        'Import Site',
        'manage_options',
        'wasmer-import-site',
        'wasmer_import_admin_page'
    );
}
add_action('admin_menu', 'wasmer_import_admin_menu', 20);

function wasmer_import_admin_enqueue($hook)
{
    if ($hook !== 'wasmer_page_wasmer-import-site') {
        return;
    }
    $style_path = WP_WASMER_PLUGIN_DIR_PATH . 'wasmer/migrate-import/ui.css';
    $script_path = WP_WASMER_PLUGIN_DIR_PATH . 'wasmer/migrate-import/ui.js';
    $style_version = file_exists($style_path) ? filemtime($style_path) : WP_WASMER_PLUGIN_VERSION;
    $script_version = file_exists($script_path) ? filemtime($script_path) : WP_WASMER_PLUGIN_VERSION;
    wp_enqueue_style('wasmer-import-ui', WP_WASMER_PLUGIN_DIR_URL . 'wasmer/migrate-import/ui.css', [], $style_version);
    wp_enqueue_script('wasmer-import-ui', WP_WASMER_PLUGIN_DIR_URL . 'wasmer/migrate-import/ui.js', [], $script_version, true);
    wp_localize_script('wasmer-import-ui', 'wasmerImport', [
        'restUrl' => esc_url_raw(rest_url('wasmer/v1/import')),
        'nonce' => wp_create_nonce('wp_rest'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'dependencyNonce' => wp_create_nonce('wasmer_import_dependency_report'),
        'adminUrl' => admin_url('admin.php?page=wasmer-import-site'),
    ]);
}
add_action('admin_enqueue_scripts', 'wasmer_import_admin_enqueue');

function wasmer_import_dependency_report($session_id)
{
    if (function_exists('wasmer_import_load_import_dependencies')) {
        wasmer_import_load_import_dependencies();
    }

    $report = [
        'warnings' => [],
        'errors' => [],
        'missing_plugins' => [],
        'missing_themes' => [],
        'present_plugins' => [],
        'present_themes' => [],
        'blocked' => false,
    ];

    $session = function_exists('wasmer_import_get_session') ? wasmer_import_get_session($session_id) : null;
    if (!$session) {
        $report['errors'][] = 'Unknown import session.';
        $report['blocked'] = true;
        return $report;
    }

    $manifest = function_exists('wasmer_import_load_manifest') ? wasmer_import_load_manifest($session_id) : null;
    if (!$manifest) {
        $report['warnings'][] = 'Source dependency preflight will run after the transfer manifest is received.';
        return $report;
    }

    $options = is_array($manifest['options'] ?? null) ? $manifest['options'] : [];
    $preflight = is_array($manifest['preflight'] ?? null) ? $manifest['preflight'] : [];
    $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
    $source_wp_version = (string) ($source['wp_version'] ?? '');
    $destination_wp_version = get_bloginfo('version');
    if ($source_wp_version !== '' && version_compare($source_wp_version, $destination_wp_version, '>')) {
        $report['warnings'][] = 'The source site uses WordPress ' . $source_wp_version . ', while this Wasmer app package runs WordPress ' . $destination_wp_version . '. Import can continue, but review the site after import and upgrade the Wasmer WordPress package when available.';
    }
    if (!$preflight) {
        $report['warnings'][] = 'This transfer does not include source dependency preflight metadata.';
        $report['blocked'] = !empty($report['errors']);
        return $report;
    }

    if (empty($options['include_themes'])) {
        $active_theme = is_array($preflight['active_theme'] ?? null) ? $preflight['active_theme'] : [];
        $theme_slugs = array_values(array_unique(array_filter([
            sanitize_key($active_theme['stylesheet'] ?? ''),
            sanitize_key($active_theme['template'] ?? ''),
        ])));

        if ($theme_slugs) {
            if (!function_exists('wp_get_themes')) {
                require_once ABSPATH . 'wp-includes/theme.php';
            }
            $installed_themes = wp_get_themes();
            foreach ($theme_slugs as $theme_slug) {
                if (isset($installed_themes[$theme_slug])) {
                    $report['present_themes'][] = $theme_slug;
                } else {
                    $report['missing_themes'][] = $theme_slug;
                }
            }

            if ($report['missing_themes']) {
                $report['errors'][] = 'The active source theme is not included in the transfer and is missing on the destination: ' . implode(', ', $report['missing_themes']) . '.';
            } else {
                $report['warnings'][] = 'The active source theme is not included in the transfer, but a matching theme exists on the destination.';
            }
        }
    }

    if (empty($options['include_plugins'])) {
        $active_plugins = is_array($preflight['active_plugins'] ?? null) ? $preflight['active_plugins'] : [];
        $active_plugins = array_values(array_filter($active_plugins, function ($plugin) {
            return ($plugin['file'] ?? '') !== 'wasmer-migrate/wasmer-migrate.php';
        }));

        if ($active_plugins) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed_plugins = get_plugins();
            $installed_slugs = [];
            foreach (array_keys($installed_plugins) as $plugin_file) {
                $installed_slugs[] = dirname($plugin_file) === '.' ? basename($plugin_file, '.php') : dirname($plugin_file);
            }

            foreach ($active_plugins as $plugin) {
                $plugin_file = (string) ($plugin['file'] ?? '');
                $plugin_slug = (string) ($plugin['slug'] ?? '');
                $plugin_label = (string) ($plugin['name'] ?? $plugin_file);
                $present = isset($installed_plugins[$plugin_file]) || ($plugin_slug && in_array($plugin_slug, $installed_slugs, true));
                if ($present) {
                    $report['present_plugins'][] = $plugin_label;
                } else {
                    $report['missing_plugins'][] = $plugin_label . ($plugin_file ? ' (' . $plugin_file . ')' : '');
                }
            }

            if ($report['missing_plugins']) {
                $report['errors'][] = 'Active source plugins are not included in the transfer and are missing on the destination: ' . implode(', ', $report['missing_plugins']) . '.';
            } else {
                $report['warnings'][] = 'Active source plugins are not included in the transfer, but matching plugins exist on the destination.';
            }
        }
    }

    $report['blocked'] = !empty($report['errors']);
    return $report;
}

function wasmer_import_ajax_dependency_report()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    check_ajax_referer('wasmer_import_dependency_report', 'nonce');

    $session_id = sanitize_key($_GET['session'] ?? '');
    wp_send_json_success(wasmer_import_dependency_report($session_id));
}
add_action('wp_ajax_wasmer_import_dependency_report', 'wasmer_import_ajax_dependency_report');

function wasmer_import_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap wasmer-import-page">
        <div class="wasmer-import-header">
            <div>
                <h1><?php echo wp_kses(wasmer_icon(), wasmer_svg_kses_allowed_html()); ?> Import Site</h1>
                <p>Move an existing WordPress site into this Wasmer app.</p>
            </div>
        </div>
        <div class="wasmer-import-stepper" aria-label="Import steps">
            <span data-step="install"><b>1</b> Install</span>
            <span data-step="code"><b>2</b> Connect</span>
            <span data-step="transfer"><b>3</b> Transfer</span>
            <span data-step="finish"><b>4</b> Finish</span>
        </div>
        <div id="wasmer-import-message" class="notice inline" hidden></div>
        <div id="wasmer-import-dependency-report" class="notice inline wasmer-import-report" hidden></div>
        <section class="wasmer-import-panel" data-panel="install">
            <h2>Install the source plugin</h2>
            <p>In the existing WordPress site, install and activate the Wasmer Migrate plugin. Open its Migrate to Wasmer page when it is ready.</p>
            <p><button type="button" class="button button-primary" id="wasmer-import-next-install">Next</button></p>
        </section>
        <section class="wasmer-import-panel" data-panel="code" hidden>
            <h2>Connect the source site</h2>
            <p>Generate an import code, then paste it into the source site's Migrate to Wasmer wizard.</p>
            <p><button type="button" class="button button-primary" id="wasmer-import-create-session">Generate import code</button></p>
            <div id="wasmer-import-session" class="wasmer-import-session" hidden>
                <label for="wasmer-import-code"><strong>Import code</strong></label>
                <textarea id="wasmer-import-code" readonly rows="5"></textarea>
                <p class="wasmer-import-actions">
                    <button type="button" class="button" id="wasmer-import-copy-code">Copy code</button>
                    <button type="button" class="button" id="wasmer-import-cancel-session">Revoke</button>
                </p>
            </div>
        </section>
        <section class="wasmer-import-panel" data-panel="transfer" hidden>
            <h2>Waiting for transfer</h2>
            <div class="wasmer-import-progress-bar"><span id="wasmer-import-progress-bar"></span></div>
            <div class="wasmer-import-progress">
                <div><strong>Status</strong><span id="wasmer-import-status">created</span></div>
                <div><strong>Database</strong><span id="wasmer-import-database">0 bytes</span></div>
                <div><strong>Files</strong><span id="wasmer-import-files">0</span></div>
                <div><strong>Expires</strong><span id="wasmer-import-expires"></span></div>
            </div>
        </section>
        <section class="wasmer-import-panel" data-panel="finish" hidden>
            <h2>Finish import</h2>
            <p>The transfer has completed and the target app has verified the received files.</p>
            <p class="wasmer-import-actions">
                <button type="button" class="button button-primary" id="wasmer-import-start-import">Import into this site</button>
                <button type="button" class="button" id="wasmer-import-new">Start over</button>
            </p>
        </section>
        <section class="wasmer-import-panel" data-panel="complete" hidden>
            <h2>Import complete</h2>
            <p>The migrated site is now installed in this Wasmer app.</p>
        </section>
        <details class="wasmer-import-logs">
            <summary>Logs</summary>
            <pre id="wasmer-import-logs"></pre>
        </details>
    </div>
    <?php
}
