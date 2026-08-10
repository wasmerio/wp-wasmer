<?php

define('ABSPATH', __DIR__ . '/');

$wasmer_migrate_test_result = null;
$wasmer_migrate_test_options = null;

class WP_CLI
{
    public static function line($message)
    {
        echo $message . PHP_EOL;
    }

    public static function error($message)
    {
        throw new RuntimeException($message);
    }
}

function is_wp_error($value)
{
    return false;
}

function wp_json_encode($value, $flags = 0, $depth = 512)
{
    return json_encode($value, $flags, $depth);
}

function wasmer_migrate_auto_app_import($options)
{
    global $wasmer_migrate_test_options, $wasmer_migrate_test_result;

    $wasmer_migrate_test_options = $options;
    return $wasmer_migrate_test_result;
}

function wasmer_migrate_test_fail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function wasmer_migrate_test_expect_error($result, $message)
{
    global $wasmer_migrate_test_result;

    $wasmer_migrate_test_result = $result;
    try {
        wasmer_migrate_cli_auto([]);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $message) {
            return;
        }
        wasmer_migrate_test_fail('Unexpected WP-CLI error: ' . $error->getMessage());
    }
    wasmer_migrate_test_fail('Expected WP-CLI error: ' . $message);
}

require_once __DIR__ . '/../cli.php';

$app = [
    'id' => 'app_123',
    'name' => 'migrated-site',
    'url' => 'https://migrated-site.wasmer.app',
    'adminUrl' => 'https://migrated-site.wasmer.app/wp-admin/',
    'willPerishAt' => null,
    'activeVersion' => ['id' => 'version_456'],
];
$wasmer_migrate_test_result = ['auto_app' => ['app' => $app]];
$args = [
    'graphql-url' => 'https://registry.example.test/graphql',
    'token' => 'existing-token',
    'owner' => 'owner-name',
    'region' => 'ord',
    'perish-at' => 'PT4H',
    'app-name' => 'migrated-site',
];

ob_start();
wasmer_migrate_cli_auto($args);
$output = ob_get_clean();

$expected_options = [
    'graphql_url' => $args['graphql-url'],
    'token' => $args['token'],
    'owner' => $args['owner'],
    'region' => $args['region'],
    'perish_at' => $args['perish-at'],
    'app_name' => $args['app-name'],
];
if ($wasmer_migrate_test_options !== $expected_options) {
    wasmer_migrate_test_fail('The automatic migration flags were not forwarded unchanged.');
}

$decoded = json_decode($output, true);
if (json_last_error() !== JSON_ERROR_NONE || $decoded !== $app) {
    wasmer_migrate_test_fail('Automatic migration stdout was not one standalone app JSON document: ' . $output);
}

wasmer_migrate_test_expect_error(
    ['auto_app' => ['app' => ['name' => 'missing-id']]],
    'Automatic Wasmer import completed without target app information.'
);
wasmer_migrate_test_expect_error(
    ['auto_app' => ['app' => ['id' => 'app_123', 'invalid' => fopen('php://memory', 'r')]]],
    'Could not encode target app information as JSON.'
);

echo "ok\n";
