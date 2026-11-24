<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define("WASMER_WEBSITE_URL", getenv('WASMER_WEBSITE_URL'));
define("WASMER_APP_ID", getenv('WASMER_APP_ID'));
define("WASMER_PERISHABLE_TIMESTAMP", getenv('WASMER_PERISHABLE_TIMESTAMP'));
define("WASMER_GRAPHQL_URL", getenv('WASMER_GRAPHQL_URL'));
define("WASMER_API_TOKEN", getenv('WASMER_API_TOKEN'));
define("WASMER_MIGRATIONS_UI_ENABLED", getenv('WASMER_MIGRATIONS_UI_ENABLED') === 'true');
define("WASMER_CLI", defined('WP_CLI') && WP_CLI);

function wasmer_is_managed_environment()
{
    return (bool) (WASMER_APP_ID && WASMER_GRAPHQL_URL);
}
