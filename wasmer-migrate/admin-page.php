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
    $public['migration_id'] = (string) ($state['id'] ?? '');
    unset($public['run_token']);
    unset($public['destination']['token']);
    unset($public['auto_app']['token']);
    $public['file_count'] = count($state['files'] ?? []);
    unset($public['files'], $public['manifest'], $public['database']);
    $public['logs'] = !empty($state['id']) ? wasmer_migrate_read_logs($state['id']) : [];
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

function wasmer_migrate_auto_status_is_running($status)
{
    return in_array((string) $status, [
        'auto_exporting',
        'auto_creating',
        'auto_waiting',
        'auto_session',
        'auto_transferring',
        'auto_importing',
        'exported',
        'transferring',
    ], true);
}

function wasmer_migrate_auto_lock_key()
{
    return 'wasmer_migrate_auto_request_lock';
}

function wasmer_migrate_auto_delete_lock_if_matches($expected)
{
    global $wpdb;

    $deleted = $wpdb->delete(
        $wpdb->options,
        [
            'option_name' => wasmer_migrate_auto_lock_key(),
            'option_value' => maybe_serialize($expected),
        ],
        ['%s', '%s']
    );
    if ($deleted) {
        wp_cache_delete(wasmer_migrate_auto_lock_key(), 'options');
    }
    return (bool) $deleted;
}

function wasmer_migrate_auto_acquire_lock()
{
    $key = wasmer_migrate_auto_lock_key();
    $now = time();
    $token = wp_generate_uuid4();
    $lock = [
        'token' => $token,
        'expires' => $now + 1800,
    ];

    if (add_option($key, $lock, '', 'no')) {
        return $token;
    }

    $existing = get_option($key);
    $state = wasmer_migrate_get_state();
    $status = (string) ($state['status'] ?? '');
    $can_replace = in_array($status, ['idle', 'failed', 'transfer_complete', 'auto_complete'], true);
    if (is_array($existing) && $can_replace) {
        wasmer_migrate_auto_delete_lock_if_matches($existing);
        if (add_option($key, $lock, '', 'no')) {
            return $token;
        }
    }

    return '';
}

function wasmer_migrate_auto_release_lock($token)
{
    if ($token === '') {
        return;
    }

    $key = wasmer_migrate_auto_lock_key();
    $existing = get_option($key);
    if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), (string) $token)) {
        wasmer_migrate_auto_delete_lock_if_matches($existing);
    }
}

function wasmer_migrate_auto_clear_lock()
{
    delete_option(wasmer_migrate_auto_lock_key());
}

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
    $state = wasmer_migrate_get_state();
    $resume_raw = isset($_POST['resume']) ? wp_unslash($_POST['resume']) : false;
    $resume = filter_var($resume_raw, FILTER_VALIDATE_BOOLEAN);
    if (!$resume && wasmer_migrate_auto_status_is_running($state['status'] ?? '')) {
        wp_send_json_success(wasmer_migrate_public_state($state));
    }

    $lock = wasmer_migrate_auto_acquire_lock();
    if ($lock === '') {
        wp_send_json_success(wasmer_migrate_public_state($state));
    }

    try {
        $result = wasmer_migrate_auto_app_import([
            'graphql_url' => wp_unslash($_POST['graphql_url'] ?? ''),
            'token' => wp_unslash($_POST['token'] ?? ''),
            'app_name' => sanitize_title(wp_unslash($_POST['app_name'] ?? '')),
            'resume' => $resume,
        ]);
    } finally {
        wasmer_migrate_auto_release_lock($lock);
    }

    if (is_wp_error($result)) {
        if ($result->get_error_code() === 'wasmer_migrate_stale_run') {
            wp_send_json_success(wasmer_migrate_public_state());
        }
        $state = wasmer_migrate_get_state();
        if (empty($state['id']) || empty($state['run_token'])) {
            wp_send_json_success(wasmer_migrate_public_state($state));
        }
        $state['status'] = 'failed';
        $state['error'] = $result->get_error_message();
        $state['resume_allowed'] = in_array($result->get_error_code(), ['wasmer_migrate_app_build_timeout', 'wasmer_migrate_wp_cli_not_ready'], true)
            && !empty($state['id'])
            && !empty($state['run_token']);
        $state['auto_app'] = array_merge(is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [], [
            'status' => 'failed',
            'error' => $result->get_error_message(),
        ]);
        wasmer_migrate_save_state($state);
        if (!empty($state['id'])) {
            wasmer_migrate_log_for_run($state['id'], $state['run_token'] ?? '', 'Automatic Wasmer import failed.', ['error' => $result->get_error_message()]);
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
    wasmer_migrate_auto_clear_lock();
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
            <span data-step="auto"><b>1</b> Start</span>
            <span data-step="transfer"><b>2</b> Copy site</span>
            <span data-step="done"><b>3</b> Ready</span>
        </div>
        <div id="wasmer-migrate-message" class="notice inline" hidden></div>
        <section class="wasmer-migrate-panel" data-panel="auto">
            <div class="wasmer-migrate-start">
                <label class="wasmer-migrate-start-field" for="wasmer-migrate-auto-app-name">
                    <span>Wasmer app name</span>
                    <input type="text" id="wasmer-migrate-auto-app-name" value="<?php echo esc_attr(wasmer_migrate_auto_app_name_from_domain()); ?>" maxlength="36" autocomplete="off" spellcheck="false" aria-describedby="wasmer-migrate-auto-app-name-help">
                    <small id="wasmer-migrate-auto-app-name-help">Used in the app URL. Spaces and unsupported characters will be converted to hyphens.</small>
                </label>
                <label class="wasmer-migrate-start-field" for="wasmer-migrate-auto-token">
                    <span>Wasmer access token <em>(optional)</em></span>
                    <input type="password" id="wasmer-migrate-auto-token" autocomplete="off" spellcheck="false" placeholder="Paste a token to create the app in your account">
                </label>
                <button type="button" class="button button-primary wasmer-migrate-primary-action" id="wasmer-migrate-auto-start">Migrate to Wasmer</button>
                <p class="wasmer-migrate-start-copy">With a token, Wasmer will create the WordPress app in your existing account. Without one, Wasmer will create a temporary app.<strong class="wasmer-migrate-site-unchanged">Your current site will not be changed and will keep working as usual.</strong></p>
                <button type="button" class="button" id="wasmer-migrate-advanced-toggle" aria-expanded="false" aria-controls="wasmer-migrate-advanced">Advanced configuration</button>
            </div>
            <div class="wasmer-migrate-advanced" id="wasmer-migrate-advanced" aria-hidden="true">
                <div class="wasmer-migrate-form-grid">
                    <label>
                        <span>Wasmer registry URL</span>
                        <input type="url" id="wasmer-migrate-auto-graphql-url" value="<?php echo esc_attr(wasmer_migrate_auto_default_graphql_url()); ?>">
                    </label>
                </div>
            </div>
        </section>
        <section class="wasmer-migrate-panel" data-panel="transfer" hidden>
            <h2>Copying your site to Wasmer</h2>
            <ol class="wasmer-migrate-progress-steps" aria-label="Copy progress">
                <li data-progress-step="creating"><span></span>Creating Wasmer app</li>
                <li data-progress-step="preparing"><span></span>Preparing data transfer</li>
                <li data-progress-step="transferring"><span></span>Transferring data</li>
            </ol>
            <p class="wasmer-migrate-progress-detail">
                <span class="wasmer-migrate-spinner" id="wasmer-migrate-spinner" aria-hidden="true" hidden></span>
                <span id="wasmer-migrate-progress-detail">Starting migration...</span>
            </p>
            <div class="wasmer-migrate-progress-bar"><span id="wasmer-migrate-progress-bar"></span></div>
            <div class="wasmer-migrate-summary" id="wasmer-migrate-transfer-summary" hidden>
                <div><strong>Database</strong><span id="wasmer-migrate-database">Not started</span></div>
                <div><strong>Files</strong><span id="wasmer-migrate-files">0 / 0</span></div>
                <div><strong>Data copied</strong><span id="wasmer-migrate-bytes">0 bytes</span></div>
            </div>
        </section>
        <section class="wasmer-migrate-panel wasmer-migrate-failed" data-panel="failed" hidden>
            <h2>Migration could not continue</h2>
            <p id="wasmer-migrate-failed-message">The migration encountered an error.</p>
            <p class="wasmer-migrate-actions">
                <button type="button" class="button button-primary" id="wasmer-migrate-retry">Retry</button>
                <button type="button" class="button" id="wasmer-migrate-failed-start-over">Start over</button>
            </p>
        </section>
        <section class="wasmer-migrate-panel" data-panel="done" hidden>
            <h2>App is ready</h2>
            <p id="wasmer-migrate-done-message">Your WordPress site has been copied to a new Wasmer app.</p>
            <p class="wasmer-migrate-ready-actions">
                <a class="button button-primary wasmer-migrate-app-link" id="wasmer-migrate-app-link" href="#" target="_blank" rel="noopener" hidden>Open your Wasmer app</a>
                <a class="button" id="wasmer-migrate-dashboard-link" href="#" target="_blank" rel="noopener" hidden>Open in Wasmer Dashboard</a>
            </p>
            <div class="wasmer-migrate-warning" id="wasmer-migrate-temporary-warning">
                <strong>Important:</strong> This app is temporary and will disappear soon unless you connect it to a Wasmer account.
                <span id="wasmer-migrate-perish-at" hidden></span>
            </div>
            <div class="wasmer-migrate-next">
                <h3>Next steps</h3>
                <ol>
                    <li>Open the new app and make sure your pages, posts, and media look right.</li>
                    <li id="wasmer-migrate-connect-account-step">Connect the app to a Wasmer account before the temporary app expires.</li>
                    <li>When you are ready, update your domain or hosting settings to point visitors to the new app.</li>
                </ol>
            </div>
            <p><button type="button" class="button" id="wasmer-migrate-start-over">Start over</button></p>
        </section>
        <p class="wasmer-migrate-footer-actions">
            <button type="button" class="button" id="wasmer-migrate-footer-start-over">Start over</button>
        </p>
        <details class="wasmer-migrate-logs">
            <summary>Logs</summary>
            <pre id="wasmer-migrate-logs"></pre>
        </details>
    </div>
    <?php
}

function wasmer_migrate_legacy_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $state = wasmer_migrate_get_state();
    ?>
    <div class="wrap wasmer-migrate-page" data-initial-state="<?php echo esc_attr(wp_json_encode(wasmer_migrate_public_state($state), JSON_UNESCAPED_SLASHES)); ?>">
        <div class="wasmer-migrate-header">
            <div>
                <h1>Migrate with an import code</h1>
                <p>Send this WordPress site to an existing Wasmer WordPress app.</p>
            </div>
        </div>
        <div class="wasmer-migrate-stepper" aria-label="Migration steps">
            <span data-step="code"><b>1</b> Connect</span>
            <span data-step="review"><b>2</b> Review</span>
            <span data-step="transfer"><b>3</b> Transfer</span>
            <span data-step="done"><b>4</b> Done</span>
        </div>
        <div id="wasmer-migrate-message" class="notice inline" hidden></div>
        <section class="wasmer-migrate-panel" data-panel="code">
            <h2>Connect to a Wasmer app</h2>
            <p>Paste the import code from the Wasmer WordPress app that should receive this site.</p>
            <textarea id="wasmer-migrate-import-code" rows="5" placeholder="wasmer-import:v1:..."><?php echo esc_textarea($state['code'] ?? ''); ?></textarea>
            <p class="wasmer-migrate-actions">
                <button type="button" class="button button-primary" id="wasmer-migrate-connect">Validate code</button>
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
            <p id="wasmer-migrate-done-message">The Wasmer app has received the site data.</p>
            <p><button type="button" class="button" id="wasmer-migrate-start-over">Start over</button></p>
        </section>
        <details class="wasmer-migrate-logs">
            <summary>Logs</summary>
            <pre id="wasmer-migrate-logs"></pre>
        </details>
    </div>
    <?php
}
