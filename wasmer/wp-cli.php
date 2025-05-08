<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/defines.php';

if (!WASMER_CLI) {
    exit; // Exit if WASMER_CLI is not defined.
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

WP_CLI::add_command('wasmer-aio-install', 'Wasmer_Aio_Install_Command');
