<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_import_add_dashboard_panel()
{
    if (!WASMER_MIGRATIONS_UI_ENABLED) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }
    if (get_user_meta(get_current_user_id(), 'wasmer_hide_import_dashboard_panel', true)) {
        return;
    }
    wp_add_dashboard_widget('wasmer_import_dashboard_panel', 'Import a site to Wasmer', 'wasmer_import_dashboard_panel_display');
}

function wasmer_import_dashboard_panel_display()
{
    if (isset($_POST['wasmer_import_hide_panel']) && check_admin_referer('wasmer_import_hide_panel')) {
        update_user_meta(get_current_user_id(), 'wasmer_hide_import_dashboard_panel', 1);
        echo '<p>Import panel hidden.</p>';
        return;
    }
    ?>
    <p>Move an existing WordPress site into this Wasmer app with chunked transfer that writes directly to persistent <code>wp-content</code> storage.</p>
    <p>
        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wasmer-import-site')); ?>">Open Import Site</a>
    </p>
    <form method="post">
        <?php wp_nonce_field('wasmer_import_hide_panel'); ?>
        <button class="button-link" type="submit" name="wasmer_import_hide_panel" value="1">Hide this panel</button>
    </form>
    <?php
}
