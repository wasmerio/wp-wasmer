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

class Wasmer_Import_Command
{
    /**
     * Create a Wasmer import session.
     *
     * ## OPTIONS
     *
     * [--expires=<duration>]
     * : Expiry duration in seconds, or with h/d suffix.
     * ---
     * default: 8h
     * ---
     */
    public function create($args, $assoc_args)
    {
        $ttl = $this->parse_duration($assoc_args['expires'] ?? '8h');
        $created = wasmer_import_create_session($ttl);
        WP_CLI::line(wp_json_encode([
            'session' => wasmer_import_public_session($created['session']),
            'code' => $created['code'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * List import sessions.
     */
    public function list_($args, $assoc_args)
    {
        WP_CLI::line(wp_json_encode(wasmer_import_list_sessions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Show import session status.
     *
     * ## OPTIONS
     *
     * <session>
     * : Session ID.
     */
    public function status($args, $assoc_args)
    {
        $session = wasmer_import_get_session($args[0] ?? '');
        if (!$session) {
            WP_CLI::error('Unknown import session.');
        }
        WP_CLI::line(wp_json_encode(wasmer_import_public_session($session), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Revoke an import session.
     *
     * ## OPTIONS
     *
     * <session>
     * : Session ID.
     */
    public function revoke($args, $assoc_args)
    {
        $session = wasmer_import_get_session($args[0] ?? '');
        if (!$session) {
            WP_CLI::error('Unknown import session.');
        }
        $session['status'] = 'cancelled';
        $session['cancelled'] = time();
        wasmer_import_save_session($session);
        WP_CLI::success('Import session revoked.');
    }

    /**
     * Start importing a completed transfer.
     *
     * ## OPTIONS
     *
     * <session>
     * : Session ID.
     */
    public function start($args, $assoc_args)
    {
        $session_id = $args[0] ?? '';
        if (function_exists('wasmer_import_dependency_report')) {
            $report = wasmer_import_dependency_report($session_id);
            foreach ($report['warnings'] ?? [] as $warning) {
                if (method_exists('WP_CLI', 'warning')) {
                    WP_CLI::warning($warning);
                } else {
                    WP_CLI::line('Warning: ' . $warning);
                }
            }
            if (!empty($report['errors'])) {
                WP_CLI::error(implode(' ', $report['errors']));
            }
        }

        $result = wasmer_import_start($session_id);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Import completed.');
    }

    /**
     * Remove expired import artifacts.
     *
     * ## OPTIONS
     *
     * [--older-than=<duration>]
     * : Cleanup age in seconds, or with h/d suffix.
     * ---
     * default: 7d
     * ---
     */
    public function cleanup($args, $assoc_args)
    {
        $older_than = $this->parse_duration($assoc_args['older-than'] ?? '7d');
        $cutoff = time() - $older_than;
        $removed = 0;
        foreach (wasmer_import_list_sessions() as $session) {
            if (($session['updated'] ?? 0) > $cutoff) {
                continue;
            }
            $this->delete_dir(wasmer_import_session_dir($session['id']));
            @unlink(wasmer_import_session_file($session['id']));
            $removed++;
        }
        WP_CLI::success('Removed ' . $removed . ' import session(s).');
    }

    private function parse_duration($value)
    {
        $value = trim((string) $value);
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (preg_match('/^(\d+)([hdm])$/', $value, $matches)) {
            $number = (int) $matches[1];
            if ($matches[2] === 'd') {
                return $number * DAY_IN_SECONDS;
            }
            if ($matches[2] === 'h') {
                return $number * HOUR_IN_SECONDS;
            }
            return $number * MINUTE_IN_SECONDS;
        }
        return 8 * HOUR_IN_SECONDS;
    }

    private function delete_dir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

WP_CLI::add_command('wasmer', 'Wasmer_Command');
WP_CLI::add_command('wasmer import session', 'Wasmer_Import_Command');
WP_CLI::add_command('wasmer import', 'Wasmer_Import_Command');
WP_CLI::add_command('wasmer-aio-install', 'Wasmer_Aio_Install_Command');
