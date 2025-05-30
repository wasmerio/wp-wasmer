<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function wasmer_base_url() {
    if (!WASMER_GRAPHQL_URL) {
        return 'https://wasmer.io';
    }
    $host = parse_url(WASMER_GRAPHQL_URL, PHP_URL_HOST);
    $host = str_replace('registry.', '', $host);

    return "https://$host";
}

define("WASMER_APP_ID", getenv('WASMER_APP_ID'));
define("WASMER_PERISHABLE_TIMESTAMP", getenv('WASMER_PERISHABLE_TIMESTAMP'));
define("WASMER_GRAPHQL_URL", getenv('WASMER_GRAPHQL_URL'));
define("WASMER_CLI", defined('WP_CLI') && WP_CLI);
