<?php

define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);

if (!defined('WP_WASMER_PLUGIN_VERSION')) {
    define('WP_WASMER_PLUGIN_VERSION', '0.4.2');
}

if (!defined('WP_WASMER_PLUGIN_DIR_PATH')) {
    define('WP_WASMER_PLUGIN_DIR_PATH', '/tmp/wp-wasmer/');
}

if (!defined('WP_WASMER_PLUGIN_DIR_URL')) {
    define('WP_WASMER_PLUGIN_DIR_URL', 'http://example.test/wp-content/plugins/wp-wasmer/');
}

class WP_CLI
{
    public static $commands = [];

    public static function add_command($name, $command)
    {
        self::$commands[$name] = $command;
    }

    public static function line($message)
    {
        echo $message;
    }

    public static function runcommand($command, $options = [])
    {
    }

    public static function success($message)
    {
    }

    public static function error($message)
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function wp_json_encode($value, $flags = 0, $depth = 512)
{
    return json_encode($value, $flags, $depth);
}

function wasmer_get_liveconfig_data()
{
    return [
        'liveconfig_version' => '1',
        'wasmer_plugin' => [
            'version' => WP_WASMER_PLUGIN_VERSION,
            'dir' => WP_WASMER_PLUGIN_DIR_PATH,
            'url' => WP_WASMER_PLUGIN_DIR_URL,
        ],
        'wordpress' => [
            'version' => 'test',
        ],
    ];
}

require_once __DIR__ . '/../wp-cli.php';

if (!isset(WP_CLI::$commands['wasmer'])) {
    fwrite(STDERR, "The 'wasmer' command was not registered." . PHP_EOL);
    exit(1);
}

if (!isset(WP_CLI::$commands['wasmer-aio-install'])) {
    fwrite(STDERR, "The legacy 'wasmer-aio-install' command was not registered." . PHP_EOL);
    exit(1);
}

$command_class = WP_CLI::$commands['wasmer'];
$command = new $command_class();

ob_start();
$command->liveconfig([], []);
$output = ob_get_clean();

$decoded = json_decode($output, true);

if ($decoded !== wasmer_get_liveconfig_data()) {
    fwrite(STDERR, "The 'wasmer liveconfig' command output did not match the liveconfig payload." . PHP_EOL);
    exit(1);
}

echo "ok\n";
