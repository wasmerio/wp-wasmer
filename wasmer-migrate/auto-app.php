<?php

if (!defined('ABSPATH')) {
    exit;
}

function wasmer_migrate_auto_default_graphql_url()
{
    if (defined('WASMER_GRAPHQL_URL') && WASMER_GRAPHQL_URL) {
        return WASMER_GRAPHQL_URL;
    }
    $env = getenv('WASMER_GRAPHQL_URL');
    return $env ?: 'https://registry.wasmer.io/graphql';
}

function wasmer_migrate_auto_default_token()
{
    if (defined('WASMER_GRAPHQL_TOKEN') && WASMER_GRAPHQL_TOKEN) {
        return WASMER_GRAPHQL_TOKEN;
    }
    $env = getenv('WASMER_GRAPHQL_TOKEN');
    return $env ?: 'wap_sm_demo';
}

function wasmer_migrate_auto_options($options = [])
{
    $id = wasmer_migrate_new_id();
    $value = function ($key, $default) use ($options) {
        return isset($options[$key]) && (string) $options[$key] !== '' ? $options[$key] : $default;
    };
    $app_name = sanitize_title($value('app_name', 'wp-migrate-' . substr($id, -10)));
    if ($app_name === '') {
        $app_name = 'wp-migrate-' . substr($id, -10);
    }

    return [
        'graphql_url' => esc_url_raw($value('graphql_url', wasmer_migrate_auto_default_graphql_url())),
        'token' => trim((string) $value('token', wasmer_migrate_auto_default_token())),
        'owner' => sanitize_key($value('owner', 'stackmachine')),
        'region' => sanitize_text_field($value('region', '')),
        'perish_at' => sanitize_text_field($value('perish_at', 'PT2H')),
        'app_name' => $app_name,
        'site_name' => wp_strip_all_tags(get_bloginfo('name') ?: 'Migrated WordPress site'),
        'admin_email' => sanitize_email(get_option('admin_email') ?: 'admin@example.com'),
        'admin_username' => 'admin',
        'admin_password' => wp_generate_password(24, true, true),
    ];
}

function wasmer_migrate_auto_state($updates)
{
    $state = wasmer_migrate_get_state();
    $auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $state['auto_app'] = array_merge($auto, $updates);
    if (!empty($updates['status'])) {
        $state['status'] = $updates['status'];
    }
    wasmer_migrate_save_state($state);
    if (!empty($state['id']) && !empty($updates['log'])) {
        wasmer_migrate_log($state['id'], $updates['log'], $updates['context'] ?? []);
    }
    return $state;
}

function wasmer_migrate_auto_graphql($options, $query, $variables = [])
{
    $body = wp_json_encode([
        'query' => $query,
        'variables' => empty($variables) ? (object) [] : $variables,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return new WP_Error('wasmer_migrate_graphql_encode_failed', 'Could not encode Wasmer GraphQL request.');
    }

    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    if (!empty($options['token'])) {
        $headers['Authorization'] = 'Bearer ' . $options['token'];
    }

    $response = wp_remote_post($options['graphql_url'], [
        'timeout' => 90,
        'headers' => $headers,
        'body' => $body,
    ]);
    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('wasmer_migrate_graphql_http_error', 'Wasmer GraphQL request failed.', ['status' => $status, 'body' => $body]);
    }
    if (!is_array($body)) {
        return new WP_Error('wasmer_migrate_graphql_bad_response', 'Wasmer GraphQL response was not JSON.');
    }
    if (!empty($body['errors'])) {
        $message = $body['errors'][0]['message'] ?? 'Wasmer GraphQL returned an error.';
        return new WP_Error('wasmer_migrate_graphql_error', $message, ['errors' => $body['errors']]);
    }

    return $body['data'] ?? [];
}

function wasmer_migrate_auto_select_wordpress_version($options, $source_version)
{
    $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
query WordpressVersions {
  wordpressVersions {
    version
    githubTag
    minPhpVersion
  }
}
GRAPHQL);
    if (is_wp_error($data)) {
        return [
            'version' => $source_version,
            'github_tag' => $source_version,
            'min_php_version' => '',
            'matched' => false,
            'warning' => $data->get_error_message(),
        ];
    }

    $versions = is_array($data['wordpressVersions'] ?? null) ? $data['wordpressVersions'] : [];
    if (!$versions) {
        return [
            'version' => $source_version,
            'github_tag' => $source_version,
            'min_php_version' => '',
            'matched' => false,
        ];
    }

    foreach ($versions as $version) {
        if ((string) ($version['version'] ?? '') === (string) $source_version) {
            return [
                'version' => (string) $version['version'],
                'github_tag' => (string) ($version['githubTag'] ?? $version['version']),
                'min_php_version' => (string) ($version['minPhpVersion'] ?? ''),
                'matched' => true,
            ];
        }
    }

    $source_parts = wasmer_migrate_auto_version_parts($source_version);
    usort($versions, function ($a, $b) use ($source_parts) {
        $a_parts = wasmer_migrate_auto_version_parts((string) ($a['version'] ?? ''));
        $b_parts = wasmer_migrate_auto_version_parts((string) ($b['version'] ?? ''));
        $a_score = wasmer_migrate_auto_version_distance($source_parts, $a_parts);
        $b_score = wasmer_migrate_auto_version_distance($source_parts, $b_parts);
        if ($a_score === $b_score) {
            return version_compare((string) ($b['version'] ?? ''), (string) ($a['version'] ?? ''));
        }
        return $a_score <=> $b_score;
    });
    $best = $versions[0];

    return [
        'version' => (string) ($best['version'] ?? $source_version),
        'github_tag' => (string) ($best['githubTag'] ?? ($best['version'] ?? $source_version)),
        'min_php_version' => (string) ($best['minPhpVersion'] ?? ''),
        'matched' => false,
    ];
}

function wasmer_migrate_auto_version_parts($version)
{
    $parts = preg_split('/[^0-9]+/', (string) $version);
    $parts = array_values(array_filter($parts, 'strlen'));
    return [
        (int) ($parts[0] ?? 0),
        (int) ($parts[1] ?? 0),
        (int) ($parts[2] ?? 0),
    ];
}

function wasmer_migrate_auto_version_distance($source, $candidate)
{
    return abs($source[0] - $candidate[0]) * 1000000
        + abs($source[1] - $candidate[1]) * 1000
        + abs($source[2] - $candidate[2]);
}

function wasmer_migrate_auto_create_wordpress_app($options, $wp_version)
{
    $input = [
        'appName' => $options['app_name'],
        'owner' => $options['owner'],
        'repoUrl' => 'https://github.com/wordpress/wordpress',
        'branch' => $wp_version['github_tag'],
        'enableDatabase' => true,
        'managed' => true,
        'perishAt' => $options['perish_at'],
        'waitForScreenshotGeneration' => false,
        'extraData' => [
            'wordpress' => [
                'adminEmail' => $options['admin_email'],
                'adminPassword' => $options['admin_password'],
                'adminUsername' => $options['admin_username'],
                'language' => get_locale() ?: 'en_US',
                'siteName' => $options['site_name'],
            ],
        ],
    ];
    if (!empty($options['region'])) {
        $input['region'] = $options['region'];
    }

    $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
mutation CreateWordpressApp($input: DeployViaAutobuildInput!) {
  deployViaAutobuild(input: $input) {
    success
    buildId
  }
}
GRAPHQL, ['input' => $input]);
    if (is_wp_error($data)) {
        return $data;
    }
    $payload = $data['deployViaAutobuild'] ?? [];
    if (empty($payload['success']) || empty($payload['buildId'])) {
        return new WP_Error('wasmer_migrate_app_create_failed', 'Wasmer did not return a build id for the new app.');
    }
    return $payload['buildId'];
}

function wasmer_migrate_auto_wait_for_app($options, $build_id)
{
    $deadline = time() + 900;
    while (time() < $deadline) {
        $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
query DeploymentStatus($buildId: UUID!) {
  autobuildDeploymentStatus(buildId: $buildId) {
    status
    appVersion {
      id
      app {
        id
        name
        url
        adminUrl
        willPerishAt
        activeVersion {
          id
        }
      }
    }
  }
}
GRAPHQL, ['buildId' => $build_id]);
        if (is_wp_error($data)) {
            return $data;
        }
        $status = (string) ($data['autobuildDeploymentStatus']['status'] ?? '');
        $app = $data['autobuildDeploymentStatus']['appVersion']['app'] ?? null;
        wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'build_status' => $status,
            'app' => is_array($app) ? $app : null,
        ]);
        if ($status === 'SUCCESS' && is_array($app) && !empty($app['id'])) {
            return $app;
        }
        if (in_array($status, ['FAILED', 'CANCELLED', 'INTERNAL_ERROR', 'TIMEOUT'], true)) {
            return new WP_Error('wasmer_migrate_app_build_failed', 'Wasmer app build failed with status ' . $status . '.');
        }
        sleep(5);
    }
    return new WP_Error('wasmer_migrate_app_build_timeout', 'Timed out waiting for the Wasmer app to become ready.');
}

function wasmer_migrate_auto_wait_for_wordpress($options, $app_id)
{
    $deadline = time() + 360;
    $last = null;
    while (time() < $deadline) {
        $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
query WordpressLiveConfig($appId: ID!) {
  node(id: $appId) {
    ... on DeployApp {
      kind {
        ... on WordpressAppKind {
          liveConfig(forceFetch: true) {
            isLive
            wordpressVersion
            phpVersion
          }
        }
      }
    }
  }
}
GRAPHQL, ['appId' => $app_id]);
        if (is_wp_error($data)) {
            $last = $data->get_error_message();
        } else {
            $live = $data['node']['kind']['liveConfig'] ?? null;
            if (is_array($live)) {
                wasmer_migrate_auto_state([
                    'status' => 'auto_waiting',
                    'live_config' => $live,
                ]);
                if (!empty($live['isLive'])) {
                    return $live;
                }
            }
        }
        sleep(5);
    }

    return [
        'isLive' => false,
        'warning' => $last ?: 'Timed out waiting for WordPress live config; continuing with CLI command checks.',
    ];
}

function wasmer_migrate_auto_run_edge_command($options, $app_id, $command, $timeout = 300)
{
    $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
mutation RunEdgeCommand($input: RunEdgeCommandInput!) {
  runEdgeCommand(input: $input) {
    exitCode
    stdout
    stderr
    app {
      id
    }
  }
}
GRAPHQL, [
        'input' => [
            'appId' => $app_id,
            'command' => $command,
            'timeoutSeconds' => $timeout,
        ],
    ]);
    if (is_wp_error($data)) {
        return $data;
    }
    $result = $data['runEdgeCommand'] ?? null;
    if (!is_array($result)) {
        return new WP_Error('wasmer_migrate_command_missing_result', 'Wasmer command did not return a result.');
    }
    if ((int) ($result['exitCode'] ?? 1) !== 0) {
        $stderr = trim((string) ($result['stderr'] ?? ''));
        return new WP_Error('wasmer_migrate_command_failed', $stderr ?: 'Wasmer command failed.', ['result' => $result]);
    }
    return $result;
}

function wasmer_migrate_auto_bash_command($script)
{
    return 'bash -lc "' . str_replace(
        ["\\", '"', '$', '`'],
        ["\\\\", '\\"', '\\$', '\\`'],
        (string) $script
    ) . '"';
}

function wasmer_migrate_auto_wp_command($wp_args)
{
    return wasmer_migrate_auto_bash_command(implode("\n", [
        'set -e',
        'if [ -d /app ]; then cd /app; fi',
        'wp ' . $wp_args,
    ]));
}

function wasmer_migrate_auto_extract_json($text)
{
    $text = trim((string) $text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function wasmer_migrate_auto_create_import_code($options, $app_id)
{
    $result = wasmer_migrate_auto_run_edge_command($options, $app_id, wasmer_migrate_auto_wp_command('wasmer import session create --expires=8h'), 120);
    if (is_wp_error($result)) {
        return $result;
    }
    $json = wasmer_migrate_auto_extract_json($result['stdout'] ?? '');
    if (!$json || empty($json['code'])) {
        return new WP_Error('wasmer_migrate_import_code_missing', 'The new Wasmer app did not return an import code.', ['stdout' => $result['stdout'] ?? '']);
    }
    return $json['code'];
}

function wasmer_migrate_auto_wait_for_wp_cli($options, $app_id)
{
    $deadline = time() + 600;
    $last = '';
    while (time() < $deadline) {
        $result = wasmer_migrate_auto_run_edge_command($options, $app_id, wasmer_migrate_auto_bash_command(implode("\n", [
            'set -e',
            'if [ -d /app ]; then cd /app; fi',
            'wp core is-installed',
            'wp core version',
            'wp plugin list --format=json',
            'wp help wasmer import >/dev/null',
        ])), 120);
        if (!is_wp_error($result)) {
            return $result;
        }
        $data = $result->get_error_data();
        $command = is_array($data) ? ($data['result'] ?? []) : [];
        $last = trim((string) (($command['stderr'] ?? '') ?: ($command['stdout'] ?? '') ?: $result->get_error_message()));
        wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'wp_cli_probe' => $last,
        ]);
        sleep(10);
    }

    return new WP_Error(
        'wasmer_migrate_wp_cli_not_ready',
        'The new Wasmer app did not expose the required import WP-CLI command before the timeout.' . ($last ? ' Last probe: ' . $last : '')
    );
}

function wasmer_migrate_auto_app_import($options = [])
{
    @set_time_limit(0);

    $options = wasmer_migrate_auto_options($options);
    $state = wasmer_migrate_get_state();
    $state['id'] = $state['id'] ?: wasmer_migrate_new_id();
    $state['auto_app'] = [
        'status' => 'auto_exporting',
        'graphql_url' => $options['graphql_url'],
        'owner' => $options['owner'],
        'region' => $options['region'],
        'perish_at' => $options['perish_at'],
        'app_name' => $options['app_name'],
    ];
    $state['status'] = 'auto_exporting';
    $state['error'] = '';
    wasmer_migrate_save_state($state);
    wasmer_migrate_log($state['id'], 'Preparing export before creating a Wasmer app.');

    $prepared = wasmer_migrate_prepare_export();
    if (is_wp_error($prepared)) {
        return $prepared;
    }

    $source_wp = (string) ($prepared['manifest']['source']['wp_version'] ?? get_bloginfo('version'));
    $source_php = (string) ($prepared['manifest']['source']['php_version'] ?? phpversion());
    $wp_version = wasmer_migrate_auto_select_wordpress_version($options, $source_wp);
    wasmer_migrate_auto_state([
        'status' => 'auto_creating',
        'source_wp_version' => $source_wp,
        'source_php_version' => $source_php,
        'target_wp_version' => $wp_version,
        'log' => 'Creating perishable Wasmer WordPress app.',
        'context' => [
            'app_name' => $options['app_name'],
            'wordpress_version' => $wp_version['version'],
        ],
    ]);

    $build_id = wasmer_migrate_auto_create_wordpress_app($options, $wp_version);
    if (is_wp_error($build_id)) {
        return $build_id;
    }
    wasmer_migrate_auto_state([
        'status' => 'auto_waiting',
        'build_id' => $build_id,
        'log' => 'Waiting for Wasmer app build.',
        'context' => ['build_id' => $build_id],
    ]);

    $app = wasmer_migrate_auto_wait_for_app($options, $build_id);
    if (is_wp_error($app)) {
        return $app;
    }
    wasmer_migrate_auto_state([
        'status' => 'auto_waiting',
        'app' => $app,
        'log' => 'Wasmer app build completed.',
        'context' => ['app_id' => $app['id'], 'url' => $app['url'] ?? ''],
    ]);

    $live_config = wasmer_migrate_auto_wait_for_wordpress($options, $app['id']);
    wasmer_migrate_auto_state([
        'status' => 'auto_session',
        'live_config' => $live_config,
        'log' => 'Waiting for import WP-CLI command in the new Wasmer app.',
    ]);
    $ready = wasmer_migrate_auto_wait_for_wp_cli($options, $app['id']);
    if (is_wp_error($ready)) {
        return $ready;
    }

    wasmer_migrate_auto_state([
        'status' => 'auto_session',
        'log' => 'Creating import session in the new Wasmer app.',
    ]);

    $code = wasmer_migrate_auto_create_import_code($options, $app['id']);
    if (is_wp_error($code)) {
        return $code;
    }

    $connected = wasmer_migrate_connect($code);
    if (is_wp_error($connected)) {
        return $connected;
    }

    wasmer_migrate_auto_state([
        'status' => 'auto_transferring',
        'app' => $app,
        'log' => 'Transferring site into the new Wasmer app.',
    ]);
    $transferred = wasmer_migrate_transfer();
    if (is_wp_error($transferred)) {
        return $transferred;
    }

    $state = wasmer_migrate_get_state();
    $session_id = $state['destination']['session'] ?? '';
    if ($session_id === '') {
        return new WP_Error('wasmer_migrate_missing_remote_session', 'The remote import session id was lost before import.');
    }
    wasmer_migrate_auto_state([
        'status' => 'auto_importing',
        'log' => 'Starting WordPress import in the new Wasmer app.',
        'context' => ['session' => $session_id],
    ]);
    $import = wasmer_migrate_auto_run_edge_command($options, $app['id'], wasmer_migrate_auto_wp_command('wasmer import start ' . escapeshellarg($session_id)), 900);
    if (is_wp_error($import)) {
        return $import;
    }

    $state = wasmer_migrate_get_state();
    $state['status'] = 'auto_complete';
    $state['auto_app']['status'] = 'auto_complete';
    $state['auto_app']['app'] = $app;
    $state['destination']['session'] = $session_id;
    $state['auto_app']['import_stdout'] = trim((string) ($import['stdout'] ?? ''));
    $state['auto_app']['import_stderr'] = trim((string) ($import['stderr'] ?? ''));
    $state['auto_app']['completed'] = time();
    wasmer_migrate_save_state($state);
    wasmer_migrate_log($state['id'], 'Automatic Wasmer import completed.', ['url' => $app['url'] ?? '']);

    return $state;
}
