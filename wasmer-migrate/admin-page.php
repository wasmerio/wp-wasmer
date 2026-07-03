<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_admin_menu()
{
    add_menu_page(
        'Migrate to Wasmer',
        'Migrate to Wasmer',
        'manage_options',
        'wasmer-migrate',
        'wasmer_migrate_admin_page',
        'dashicons-migrate',
        3
    );
}
add_action('admin_menu', 'wasmer_migrate_admin_menu');

function wasmer_migrate_admin_enqueue($hook)
{
    if ($hook !== 'toplevel_page_wasmer-migrate') {
        return;
    }
    $style_version = file_exists(WASMER_MIGRATE_PLUGIN_DIR . 'ui.css') ? filemtime(WASMER_MIGRATE_PLUGIN_DIR . 'ui.css') : WASMER_MIGRATE_VERSION;
    $script_version = file_exists(WASMER_MIGRATE_PLUGIN_DIR . 'ui.js') ? filemtime(WASMER_MIGRATE_PLUGIN_DIR . 'ui.js') : WASMER_MIGRATE_VERSION;
    wp_enqueue_style('wasmer-migrate-ui', WASMER_MIGRATE_PLUGIN_URL . 'ui.css', [], $style_version);
    wp_enqueue_script('wasmer-migrate-ui', WASMER_MIGRATE_PLUGIN_URL . 'ui.js', [], $script_version, true);
    wp_localize_script('wasmer-migrate-ui', 'wasmerMigrate', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wasmer_migrate_ui'),
    ]);
}
add_action('admin_enqueue_scripts', 'wasmer_migrate_admin_enqueue');

function wasmer_migrate_public_state($state = null)
{
    $state = $state ?: wasmer_migrate_get_state();
    $public = $state;
    unset($public['destination']['token']);
    $public['logs'] = !empty($state['id']) ? wasmer_migrate_read_logs($state['id']) : [];
    $public['file_count'] = count($state['files'] ?? []);
    return $public;
}

function wasmer_migrate_ajax_require_admin()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    check_ajax_referer('wasmer_migrate_ui', 'nonce');
}

function wasmer_migrate_ajax_status()
{
    wasmer_migrate_ajax_require_admin();
    wp_send_json_success(wasmer_migrate_public_state());
}
add_action('wp_ajax_wasmer_migrate_status', 'wasmer_migrate_ajax_status');

function wasmer_migrate_ajax_connect()
{
    wasmer_migrate_ajax_require_admin();
    $result = wasmer_migrate_connect(wp_unslash($_POST['import_code'] ?? ''));
    if (is_wp_error($result)) {
        $state = wasmer_migrate_get_state();
        $state['status'] = 'failed';
        $state['error'] = $result->get_error_message();
        wasmer_migrate_save_state($state);
        wp_send_json_error(['message' => $result->get_error_message(), 'state' => wasmer_migrate_public_state($state)], 400);
    }
    wp_send_json_success(wasmer_migrate_public_state($result));
}
add_action('wp_ajax_wasmer_migrate_connect', 'wasmer_migrate_ajax_connect');

function wasmer_migrate_ajax_auto()
{
    wasmer_migrate_ajax_require_admin();
    $result = wasmer_migrate_auto_app_import([
        'graphql_url' => wp_unslash($_POST['graphql_url'] ?? ''),
        'token' => wp_unslash($_POST['token'] ?? ''),
        'owner' => wp_unslash($_POST['owner'] ?? ''),
        'region' => wp_unslash($_POST['region'] ?? ''),
        'perish_at' => wp_unslash($_POST['perish_at'] ?? ''),
        'app_name' => wp_unslash($_POST['app_name'] ?? ''),
    ]);
    if (is_wp_error($result)) {
        $state = wasmer_migrate_get_state();
        $state['status'] = 'failed';
        $state['error'] = $result->get_error_message();
        $state['auto_app'] = array_merge(is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [], [
            'status' => 'failed',
            'error' => $result->get_error_message(),
        ]);
        wasmer_migrate_save_state($state);
        if (!empty($state['id'])) {
            wasmer_migrate_log($state['id'], 'Automatic Wasmer import failed.', ['error' => $result->get_error_message()]);
        }
        wp_send_json_error(['message' => $result->get_error_message(), 'state' => wasmer_migrate_public_state($state)], 500);
    }
    wp_send_json_success(wasmer_migrate_public_state($result));
}
add_action('wp_ajax_wasmer_migrate_auto', 'wasmer_migrate_ajax_auto');

function wasmer_migrate_ajax_start()
{
    wasmer_migrate_ajax_require_admin();
    @set_time_limit(0);

    $state = wasmer_migrate_get_state();
    if (empty($state['destination'])) {
        wp_send_json_error(['message' => 'Paste and validate an import code before starting the transfer.', 'state' => wasmer_migrate_public_state($state)], 409);
    }

    $prepared = wasmer_migrate_prepare_export();
    if (is_wp_error($prepared)) {
        $state = wasmer_migrate_get_state();
        $state['status'] = 'failed';
        $state['error'] = $prepared->get_error_message();
        wasmer_migrate_save_state($state);
        wp_send_json_error(['message' => $prepared->get_error_message(), 'state' => wasmer_migrate_public_state($state)], 500);
    }

    $result = wasmer_migrate_transfer();
    if (is_wp_error($result)) {
        $state = wasmer_migrate_get_state();
        $state['status'] = 'failed';
        $state['error'] = $result->get_error_message();
        wasmer_migrate_save_state($state);
        wp_send_json_error(['message' => $result->get_error_message(), 'state' => wasmer_migrate_public_state($state)], 500);
    }

    wp_send_json_success(wasmer_migrate_public_state(wasmer_migrate_get_state()));
}
add_action('wp_ajax_wasmer_migrate_start', 'wasmer_migrate_ajax_start');

function wasmer_migrate_ajax_cancel()
{
    wasmer_migrate_ajax_require_admin();
    wasmer_migrate_reset_state();
    wp_send_json_success(wasmer_migrate_public_state());
}
add_action('wp_ajax_wasmer_migrate_cancel', 'wasmer_migrate_ajax_cancel');

function wasmer_migrate_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $state = wasmer_migrate_get_state();
    ?>
    <div class="wrap wasmer-migrate-page" data-initial-state="<?php echo esc_attr(wp_json_encode(wasmer_migrate_public_state($state), JSON_UNESCAPED_SLASHES)); ?>">
        <div class="wasmer-migrate-header">
            <div>
                <h1>Migrate to Wasmer</h1>
                <p>Send this WordPress site to your new Wasmer app.</p>
            </div>
        </div>
        <div class="wasmer-migrate-stepper" aria-label="Migration steps">
            <span data-step="code"><b>1</b> Connect</span>
            <span data-step="review"><b>2</b> Review</span>
            <span data-step="transfer"><b>3</b> Transfer</span>
            <span data-step="done"><b>4</b> Done</span>
        </div>
        <div id="wasmer-migrate-message" class="notice inline" hidden></div>
        <section class="wasmer-migrate-panel" data-panel="auto">
            <h2>Create a new Wasmer app</h2>
            <p>Create a perishable anonymous Wasmer WordPress app, transfer this site into it, then start the import automatically.</p>
            <div class="wasmer-migrate-form-grid">
                <label>
                    <span>GraphQL endpoint</span>
                    <input type="url" id="wasmer-migrate-auto-graphql-url" value="<?php echo esc_attr(wasmer_migrate_auto_default_graphql_url()); ?>">
                </label>
                <label>
                    <span>API token</span>
                    <input type="password" id="wasmer-migrate-auto-token" value="<?php echo esc_attr(wasmer_migrate_auto_default_token()); ?>" autocomplete="off">
                </label>
                <label>
                    <span>Owner</span>
                    <input type="text" id="wasmer-migrate-auto-owner" value="stackmachine">
                </label>
                <label>
                    <span>Region</span>
                    <input type="text" id="wasmer-migrate-auto-region" placeholder="auto">
                </label>
                <label>
                    <span>Perish after</span>
                    <input type="text" id="wasmer-migrate-auto-perish-at" value="PT2H">
                </label>
                <label>
                    <span>App name</span>
                    <input type="text" id="wasmer-migrate-auto-app-name" placeholder="auto-generated">
                </label>
            </div>
            <p class="wasmer-migrate-actions">
                <button type="button" class="button button-primary" id="wasmer-migrate-auto-start">Create app and import</button>
                <button type="button" class="button" id="wasmer-migrate-use-code">Use an existing import code</button>
            </p>
            <div class="wasmer-migrate-summary">
                <div><strong>App</strong><span id="wasmer-migrate-auto-app">-</span></div>
                <div><strong>Build</strong><span id="wasmer-migrate-auto-build">idle</span></div>
                <div><strong>WordPress</strong><span id="wasmer-migrate-auto-wp">-</span></div>
            </div>
        </section>
        <section class="wasmer-migrate-panel" data-panel="code" hidden>
            <h2>Connect to your Wasmer app</h2>
            <p>Paste the import code generated in the target Wasmer WordPress app.</p>
            <textarea id="wasmer-migrate-import-code" rows="5" placeholder="wasmer-import:v1:..."><?php echo esc_textarea($state['code'] ?? ''); ?></textarea>
            <p class="wasmer-migrate-actions">
                <button type="button" class="button button-primary" id="wasmer-migrate-connect">Validate code</button>
                <button type="button" class="button" id="wasmer-migrate-use-auto">Create a new app instead</button>
            </p>
        </section>
        <section class="wasmer-migrate-panel" data-panel="review" hidden>
            <h2>Review transfer</h2>
            <div class="wasmer-migrate-summary">
                <div><strong>Destination</strong><span id="wasmer-migrate-destination">-</span></div>
                <div><strong>Status</strong><span id="wasmer-migrate-status">idle</span></div>
                <div><strong>Files</strong><span id="wasmer-migrate-file-count">0</span></div>
            </div>
            <p class="wasmer-migrate-actions">
                <button type="button" class="button button-primary" id="wasmer-migrate-start">Start transfer</button>
                <button type="button" class="button" id="wasmer-migrate-clear">Clear</button>
            </p>
        </section>
        <section class="wasmer-migrate-panel" data-panel="transfer" hidden>
            <h2>Transfer in progress</h2>
            <div class="wasmer-migrate-progress-bar"><span id="wasmer-migrate-progress-bar"></span></div>
            <div class="wasmer-migrate-summary">
                <div><strong>Database</strong><span id="wasmer-migrate-database">0 bytes</span></div>
                <div><strong>Files</strong><span id="wasmer-migrate-files">0 / 0</span></div>
                <div><strong>Bytes sent</strong><span id="wasmer-migrate-bytes">0</span></div>
            </div>
        </section>
        <section class="wasmer-migrate-panel" data-panel="done" hidden>
            <h2>Migration complete</h2>
            <p id="wasmer-migrate-done-message">The target app has received and verified the transfer. Finish the import in the Wasmer app.</p>
            <p><button type="button" class="button" id="wasmer-migrate-start-over">Start over</button></p>
        </section>
        <details class="wasmer-migrate-logs">
            <summary>Logs</summary>
            <pre id="wasmer-migrate-logs"></pre>
        </details>
    </div>
    <?php
}
