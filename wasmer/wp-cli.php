<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/defines.php';

if (!WASMER_CLI) {
    exit; // Exit if WASMER_CLI is not defined.
}

class Wasmer_Command
{
    /**
     * Print the same payload returned by the Wasmer liveconfig REST endpoint.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: json
     * options:
     *   - json
     * ---
     */
    public function liveconfig($args, $assoc_args)
    {
        $format = $assoc_args['format'] ?? 'json';

        if ('json' !== $format) {
            WP_CLI::error('Unsupported format. Only json is currently supported.');
        }

        $json = wp_json_encode(
            wasmer_get_liveconfig_data(),
            JSON_UNESCAPED_SLASHES
        );

        if (false === $json) {
            WP_CLI::error('Failed to encode liveconfig output as JSON.');
        }

        WP_CLI::line($json);
    }
}

class Wasmer_Aio_Install_Command
{
    /**
     * All-in-one install command.
     *
     * ## OPTIONS
     *
     * --locale=<locale>
     * : The locale/language for the installation (e.g. `de_DE`).
     *
     * --theme=<theme>
     * : Path to the theme to install.
     */
    public function install($args, $assoc_args)
    {
        WP_CLI::line('Installing theme');
        $command = 'theme install ' . $assoc_args['theme'];
        WP_CLI::line('Running: ' . $command);
        WP_CLI::runcommand($command, ['launch' => false]);

        WP_CLI::line('Installing language');
        $command = 'language core install --activate ' . $assoc_args['locale'];
        WP_CLI::line('Running: ' . $command);
        WP_CLI::runcommand($command, ['launch' => false]);

        WP_CLI::line('Installing theme language');
        $command = 'language theme install --all ' . $assoc_args['locale'];
        WP_CLI::line('Running: ' . $command);
        WP_CLI::runcommand($command, ['launch' => false]);

        WP_CLI::success('All done!');
    }
}

WP_CLI::add_command('wasmer', 'Wasmer_Command');
WP_CLI::add_command('wasmer-aio-install', 'Wasmer_Aio_Install_Command');
