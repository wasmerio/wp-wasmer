<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/* -------------------------------------------------------------------------
 *  Wasmer CDN cache purging
 *
 *  The Wasmer CDN cache can only be purged as a whole (no per-URL purge),
 *  so every content change schedules a single full purge that runs once on
 *  shutdown, coalescing all triggers of a request into one API call.
 * ---------------------------------------------------------------------- */

function wasmer_cdn_cache_enabled()
{
    return (bool) (WASMER_API_TOKEN && WASMER_APP_ID && WASMER_GRAPHQL_URL);
}

/**
 * Whether automatic purging on content changes is enabled.
 *
 * Site-level setting, toggled from the Wasmer > CDN Cache admin page.
 * Defaults to enabled. Manual purging is not affected by this setting.
 */
function wasmer_cdn_auto_purge_enabled()
{
    return get_option('wasmer_cdn_auto_purge_enabled', '1') !== '0';
}

/**
 * Purge the whole Wasmer CDN cache for this app.
 *
 * @return bool True if the purge succeeded.
 */
function wasmer_cdn_purge_cache()
{
    if (!wasmer_cdn_cache_enabled()) {
        return false;
    }

    // The app id is inlined as a string literal so the mutation works
    // regardless of the exact argument type exposed by the API.
    $query = sprintf(
        'mutation { purgeAppCdnCache(app: %s) { success } }',
        wp_json_encode((string) WASMER_APP_ID)
    );

    $response = wasmer_graphql_query(
        WASMER_GRAPHQL_URL,
        $query,
        null,
        WASMER_API_TOKEN
    );

    $success = (bool) ($response['data']['purgeAppCdnCache']['success'] ?? false);

    if ($success) {
        update_option('wasmer_cdn_cache_last_purged', time(), false);
        do_action('wasmer_cdn_cache_purged');
    } else {
        do_action('wasmer_cdn_cache_purge_failed', $response);
    }

    return $success;
}

/**
 * Schedule a full CDN purge to run once on shutdown.
 *
 * All automatic purge triggers funnel through here so that a request firing
 * multiple hooks (e.g. saving a post) results in a single purge call.
 */
function wasmer_cdn_schedule_purge()
{
    static $scheduled = false;

    if ($scheduled || !wasmer_cdn_cache_enabled()) {
        return;
    }
    if (!apply_filters('wasmer_cdn_cache_purge_enabled', wasmer_cdn_auto_purge_enabled())) {
        return;
    }

    $scheduled = true;
    add_action('shutdown', 'wasmer_cdn_purge_cache', PHP_INT_MAX);
}

/**
 * Whether a change to this post warrants a CDN purge.
 */
function wasmer_cdn_is_purgeable_post($post_id)
{
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return false;
    }

    $post_type = get_post_type_object(get_post_type($post_id));
    if (!$post_type || !is_post_type_viewable($post_type)) {
        return false;
    }

    return true;
}

/**
 * Purge when a post is published, unpublished, or a published post is edited.
 */
function wasmer_cdn_purge_on_post_status_change($new_status, $old_status, $post)
{
    if ('publish' !== $new_status && 'publish' !== $old_status) {
        return;
    }
    if (!wasmer_cdn_is_purgeable_post($post->ID)) {
        return;
    }
    wasmer_cdn_schedule_purge();
}

/**
 * Purge when a post or attachment is deleted.
 */
function wasmer_cdn_purge_on_post_delete($post_id)
{
    if (!wasmer_cdn_is_purgeable_post($post_id)) {
        return;
    }
    wasmer_cdn_schedule_purge();
}

/**
 * Purge when a comment switches into or out of the approved state.
 */
function wasmer_cdn_purge_on_comment_status_change($new_status, $old_status, $comment)
{
    if ($new_status === $old_status) {
        return;
    }
    if ('approved' !== $new_status && 'approved' !== $old_status) {
        return;
    }
    wasmer_cdn_schedule_purge();
}

/**
 * Purge when a new comment is posted and immediately approved.
 */
function wasmer_cdn_purge_on_new_comment($comment_id, $comment_approved)
{
    if (1 != $comment_approved) {
        return;
    }
    wasmer_cdn_schedule_purge();
}

function wasmer_cdn_register_purge_hooks()
{
    if (!wasmer_cdn_cache_enabled()) {
        return;
    }

    add_action('transition_post_status', 'wasmer_cdn_purge_on_post_status_change', PHP_INT_MAX, 3);
    add_action('deleted_post', 'wasmer_cdn_purge_on_post_delete', PHP_INT_MAX);
    add_action('delete_attachment', 'wasmer_cdn_purge_on_post_delete', PHP_INT_MAX);
    add_action('transition_comment_status', 'wasmer_cdn_purge_on_comment_status_change', PHP_INT_MAX, 3);
    add_action('comment_post', 'wasmer_cdn_purge_on_new_comment', PHP_INT_MAX, 2);

    // Events that change site-wide output and always warrant a full purge.
    $actions = [
        'switch_theme',                    // Switch theme
        'customize_save_after',            // Save in the customizer
        'wp_update_nav_menu',              // Menu changes
        'activated_plugin',                // Plugin (de)activation
        'deactivated_plugin',
        'upgrader_process_complete',       // Core/plugin/theme updates
        'update_option_permalink_structure', // Permalink structure changes
        'autoptimize_action_cachepurged',  // Compat with https://wordpress.org/plugins/autoptimize
        'wasmer_cdn_cache_purge',          // Public action for themes/plugins to request a purge
    ];

    foreach (apply_filters('wasmer_cdn_purge_everything_actions', $actions) as $action) {
        add_action($action, 'wasmer_cdn_schedule_purge', PHP_INT_MAX, 0);
    }
}
wasmer_cdn_register_purge_hooks();

/* -------------------------------------------------------------------------
 *  Manual purge: admin bar button + admin-post handler
 * ---------------------------------------------------------------------- */

// Priority 101 so the item attaches after the Wasmer top-level menu (100).
add_action('admin_bar_menu', 'wasmer_cdn_add_admin_bar_purge', 101);
function wasmer_cdn_add_admin_bar_purge($admin_bar)
{
    if (!wasmer_cdn_cache_enabled() || !is_user_logged_in() || !is_admin()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $admin_bar->add_menu(array(
        'id'     => 'wasmer-purge-cdn-cache',
        'parent' => 'wasmer-top-menu',
        'title'  => 'Purge CDN Cache',
        'href'   => wp_nonce_url(admin_url('admin-post.php?action=wasmer_purge_cdn_cache'), 'wasmer_purge_cdn_cache'),
        'meta'   => array(
            'title' => 'Purge the Wasmer CDN cache for this site',
        ),
    ));
}

add_action('admin_post_wasmer_purge_cdn_cache', 'wasmer_cdn_handle_manual_purge');
function wasmer_cdn_handle_manual_purge()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to purge the CDN cache.', 'wasmer-hosting-integration'), '', array('response' => 403));
    }
    check_admin_referer('wasmer_purge_cdn_cache');

    $success = wasmer_cdn_purge_cache();

    $redirect = wp_get_referer();
    if (!$redirect) {
        $redirect = admin_url();
    }
    wp_safe_redirect(add_query_arg('wasmer-cdn-purged', $success ? '1' : '0', $redirect));
    exit;
}

add_action('admin_post_wasmer_cdn_save_settings', 'wasmer_cdn_handle_save_settings');
function wasmer_cdn_handle_save_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to change CDN cache settings.', 'wasmer-hosting-integration'), '', array('response' => 403));
    }
    check_admin_referer('wasmer_cdn_save_settings');

    $enabled = isset($_POST['wasmer_cdn_auto_purge'])
        && '1' === sanitize_key(wp_unslash($_POST['wasmer_cdn_auto_purge']));
    update_option('wasmer_cdn_auto_purge_enabled', $enabled ? '1' : '0', false);

    wp_safe_redirect(admin_url('admin.php?page=wasmer-cdn-cache&wasmer-cdn-settings-saved=1'));
    exit;
}

/* -------------------------------------------------------------------------
 *  Admin page: Wasmer > CDN Cache
 * ---------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', 'wasmer_cdn_cache_admin_enqueue');
function wasmer_cdn_cache_admin_enqueue($hook)
{
    if ($hook !== 'toplevel_page_wasmer-cdn-cache') {
        return;
    }
    $style_path = WP_WASMER_PLUGIN_DIR_PATH . 'wasmer/cdn-cache.css';
    $style_version = file_exists($style_path) ? filemtime($style_path) : WP_WASMER_PLUGIN_VERSION;
    wp_enqueue_style('wasmer-cdn-cache', WP_WASMER_PLUGIN_DIR_URL . 'wasmer/cdn-cache.css', [], $style_version);
}

function wasmer_cdn_cache_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $enabled = wasmer_cdn_cache_enabled();
    // Same URL as the "Wasmer Control Panel" button on the dashboard widget.
    $configure_url = WASMER_APP_ID ? wasmer_app_dashboard_url(WASMER_APP_ID) : '';
    $last_purged = (int) get_option('wasmer_cdn_cache_last_purged');
    ?>
    <div class="wrap wasmer-cdn-page">
        <div class="wasmer-cdn-header">
            <h1>
                <?php echo wp_kses(wasmer_icon(), wasmer_svg_kses_allowed_html()); ?> CDN Cache
                <?php if ($enabled) : ?>
                    <span class="wasmer-cdn-status is-active">Active</span>
                <?php else : ?>
                    <span class="wasmer-cdn-status is-inactive">Not enabled</span>
                <?php endif; ?>
            </h1>
            <p>Manage the Wasmer CDN cache for this app.</p>
        </div>

        <?php if (!$enabled) : ?>
            <div class="notice notice-warning inline wasmer-cdn-notice">
                <p>
                    <strong>CDN caching is not enabled for this app.</strong>
                    Enable it in the Wasmer Control Panel to serve cached pages and assets from the edge.
                </p>
            </div>
        <?php endif; ?>

        <div class="wasmer-cdn-panel">
            <h2>Purge cache</h2>
            <p>
                The Wasmer CDN serves cached copies of your pages and assets from edge locations
                close to your visitors.
                <a href="https://docs.wasmer.io/edge/learn/cdn-cache" target="_blank" rel="noopener noreferrer">Learn more about the CDN cache</a>.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wasmer_purge_cdn_cache'); ?>
                <input type="hidden" name="action" value="wasmer_purge_cdn_cache">
                <p class="wasmer-cdn-actions">
                    <button type="submit" class="button button-primary button-hero" <?php disabled(!$enabled); ?>>
                        Purge CDN Cache
                    </button>
                    <?php if ($enabled && $last_purged) : ?>
                        <span class="wasmer-cdn-meta">
                            Last purged <?php echo esc_html(human_time_diff($last_purged, time())); ?> ago
                        </span>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <div class="wasmer-cdn-panel">
            <h2>Configuration</h2>
            <div class="wasmer-cdn-section">
                <h3>Automatic purging</h3>
                <p>Purge the cache automatically when content changes (posts, comments, themes, plugins, menus).</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wasmer_cdn_save_settings'); ?>
                    <input type="hidden" name="action" value="wasmer_cdn_save_settings">
                    <p class="wasmer-cdn-actions">
                        <label for="wasmer-cdn-auto-purge">Automatic purging</label>
                        <select name="wasmer_cdn_auto_purge" id="wasmer-cdn-auto-purge" <?php disabled(!$enabled); ?>>
                            <option value="1" <?php selected(wasmer_cdn_auto_purge_enabled()); ?>>Enabled</option>
                            <option value="0" <?php selected(!wasmer_cdn_auto_purge_enabled()); ?>>Disabled</option>
                        </select>
                        <button type="submit" class="button" <?php disabled(!$enabled); ?>>Save</button>
                    </p>
                </form>
            </div>
            <div class="wasmer-cdn-section">
                <h3>Wasmer Control Panel</h3>
                <p>Enable, disable, and fine-tune CDN caching for this app.</p>
                <p class="wasmer-cdn-actions">
                    <?php if ($configure_url) : ?>
                        <a class="button" href="<?php echo esc_url($configure_url); ?>" target="_blank" rel="noopener noreferrer">
                            Configure CDN Cache
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            <span class="screen-reader-text">(opens in a new tab)</span>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" disabled>Configure CDN Cache</button>
                        <span class="wasmer-cdn-meta">No Wasmer app is associated with this site.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    <?php
}

add_action('admin_notices', 'wasmer_cdn_purge_admin_notice');
function wasmer_cdn_purge_admin_notice()
{
    $settings_saved = isset($_GET['wasmer-cdn-settings-saved'])
        ? sanitize_key(wp_unslash($_GET['wasmer-cdn-settings-saved']))
        : '';
    if ($settings_saved !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__('CDN cache settings saved.', 'wasmer-hosting-integration') .
            '</p></div>';
        return;
    }

    $purged = isset($_GET['wasmer-cdn-purged'])
        ? sanitize_key(wp_unslash($_GET['wasmer-cdn-purged']))
        : '';
    if ($purged === '') {
        return;
    }

    if ('1' === $purged) {
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__('Wasmer CDN cache purged.', 'wasmer-hosting-integration') .
            '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' .
            esc_html__('Wasmer CDN cache purge failed. Please try again or check the site logs.', 'wasmer-hosting-integration') .
            '</p></div>';
    }
}
