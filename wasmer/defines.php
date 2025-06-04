<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define("WASMER_WEBSITE_URL", getenv('WASMER_WEBSITE_URL'));
define("WASMER_APP_ID", getenv('WASMER_APP_ID'));
define("WASMER_PERISHABLE_TIMESTAMP", getenv('WASMER_PERISHABLE_TIMESTAMP'));
define("WASMER_GRAPHQL_URL", getenv('WASMER_GRAPHQL_URL'));
define("WASMER_CLI", defined('WP_CLI') && WP_CLI);
