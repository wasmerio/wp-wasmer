<?php

if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

$session_id = $args[0] ?? '';
$version = $args[1] ?? '';

if ($session_id === '' || $version === '') {
    WP_CLI::error('Usage: force-source-version.php <session-id> <version>');
}

$manifest = wasmer_import_load_manifest($session_id);
if (!$manifest) {
    WP_CLI::error('Import manifest is missing.');
}

$manifest['source']['wp_version'] = $version;
$written = file_put_contents(
    wasmer_import_manifest_path($session_id),
    wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($written === false) {
    WP_CLI::error('Could not update import manifest.');
}

$report = wasmer_import_dependency_report($session_id);
if (!is_array($report)) {
    WP_CLI::error('Dependency report did not return a report.');
}
if (!empty($report['errors'])) {
    WP_CLI::error('Newer source WordPress version should not block import: ' . implode(' ', $report['errors']));
}

$warning = 'The source site uses WordPress ' . $version;
$warnings = implode("\n", array_map('strval', $report['warnings'] ?? []));
if (strpos($warnings, $warning) === false) {
    WP_CLI::error('Dependency report did not include the newer source WordPress version warning.');
}
if (strpos($warnings, 'custom wp-content config file') !== false) {
    WP_CLI::error('Dependency report still includes the noisy wp-content config warning.');
}

WP_CLI::success('Forced source WordPress version metadata.');
