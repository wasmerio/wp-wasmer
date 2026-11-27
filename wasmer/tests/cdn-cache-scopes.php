<?php

define('ABSPATH', __DIR__ . '/');
define('WASMER_APP_ID', 'abc');
define('WASMER_GRAPHQL_URL', 'http://localhost:4000/graphql');
define('WASMER_API_TOKEN', 'dt_eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJkYV9hYmMiLCJzY29wZXMiOlsiYXBwOndvcmRwcmVzcy1saXZlY29uZmlnOmZldGNoIl19.c2ln');

function add_action() {}
function get_option($name, $default = false) { return $default; }

require __DIR__ . '/../cdn-cache.php';

$cdn_token = 'dt_eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJkYV9hYmMiLCJzY29wZXMiOlsiYXBwOmNkbi1jYWNoZTpwdXJnZSIsImFwcDp3b3JkcHJlc3MtbGl2ZWNvbmZpZzpmZXRjaCJdfQ.c2ln';
$liveconfig_token = 'dt_eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJkYV9hYmMiLCJzY29wZXMiOlsiYXBwOndvcmRwcmVzcy1saXZlY29uZmlnOmZldGNoIl19.c2ln';

$checks = [
    'purge scope' => wasmer_api_token_has_scope($cdn_token, 'app:cdn-cache:purge'),
    'unprefixed token' => wasmer_api_token_has_scope(substr($cdn_token, 3), 'app:cdn-cache:purge'),
    'no purge scope' => !wasmer_api_token_has_scope($liveconfig_token, 'app:cdn-cache:purge'),
    'opaque token' => !wasmer_api_token_has_scope('api-token-123', 'app:cdn-cache:purge'),
    'empty token' => !wasmer_api_token_has_scope('', 'app:cdn-cache:purge'),
    'CDN off without the purge scope' => !wasmer_cdn_cache_enabled(),
];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "failed: $name\n");
        exit(1);
    }
}
echo "ok\n";
