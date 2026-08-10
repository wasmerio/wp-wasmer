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
    return '';
}

function wasmer_migrate_auto_random_app_name()
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $suffix = '';
    $max_index = strlen($alphabet) - 1;
    for ($index = 0; $index < 8; $index++) {
        $suffix .= $alphabet[wp_rand(0, $max_index)];
    }

    return 'wordpress-' . $suffix;
}

function wasmer_migrate_auto_app_name_candidate($base_name, $attempt)
{
    $base_name = sanitize_title($base_name);
    if ($base_name === '') {
        $base_name = wasmer_migrate_auto_random_app_name();
    }

    $max_length = 36;
    if ($attempt <= 0) {
        return substr($base_name, 0, $max_length);
    }

    $suffix = '-' . ($attempt + 1);
    return rtrim(substr($base_name, 0, $max_length - strlen($suffix)), '-') . $suffix;
}

function wasmer_migrate_auto_is_app_name_conflict($error)
{
    if (!is_wp_error($error)) {
        return false;
    }
    $message = strtolower($error->get_error_message());
    return strpos($message, 'already exists') !== false;
}

function wasmer_migrate_auto_admin_username()
{
    $user = wp_get_current_user();
    if ($user instanceof WP_User && $user->exists() && user_can($user, 'manage_options')) {
        return (string) $user->user_login;
    }

    $admin_email = sanitize_email((string) get_option('admin_email'));
    if ($admin_email !== '') {
        $user = get_user_by('email', $admin_email);
        if ($user instanceof WP_User && user_can($user, 'manage_options')) {
            return (string) $user->user_login;
        }
    }

    $administrators = get_users([
        'role' => 'administrator',
        'orderby' => 'ID',
        'order' => 'ASC',
        'number' => 1,
    ]);
    if (!empty($administrators[0]) && $administrators[0] instanceof WP_User) {
        return (string) $administrators[0]->user_login;
    }

    return 'admin';
}

function wasmer_migrate_auto_options($options = [])
{
    $value = function ($key, $default) use ($options) {
        return isset($options[$key]) && (string) $options[$key] !== '' ? $options[$key] : $default;
    };
    $app_name = isset($options['app_name']) && (string) $options['app_name'] !== ''
        ? $options['app_name']
        : wasmer_migrate_auto_random_app_name();
    $app_name = wasmer_migrate_auto_app_name_candidate($app_name, 0);
    if ($app_name === '') {
        $app_name = wasmer_migrate_auto_random_app_name();
    }

    $token = sanitize_text_field(trim((string) $value('token', wasmer_migrate_auto_default_token())));

    return [
        'graphql_url' => esc_url_raw($value('graphql_url', wasmer_migrate_auto_default_graphql_url())),
        'token' => $token,
        'authenticated' => $token !== '',
        'owner' => sanitize_key($value('owner', '')),
        'region' => sanitize_text_field($value('region', '')),
        'perish_at' => sanitize_text_field($value('perish_at', $token !== '' ? '' : 'PT2H')),
        'app_name' => $app_name,
        'site_name' => wp_strip_all_tags(get_bloginfo('name') ?: 'Migrated WordPress site'),
        'admin_email' => sanitize_email(get_option('admin_email') ?: 'admin@example.com'),
        'admin_username' => wasmer_migrate_auto_admin_username(),
        'admin_password' => wp_generate_password(24, true, true),
    ];
}

function wasmer_migrate_auto_state($updates, $run_id = '', $run_token = '')
{
    $state = wasmer_migrate_get_state();
    if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $state['auto_app'] = array_merge($auto, $updates);
    if (!empty($updates['status'])) {
        $state['status'] = $updates['status'];
    }
    $saved = ($run_id !== '' && $run_token !== '')
        ? wasmer_migrate_save_state_for_run($state, $run_id, $run_token)
        : wasmer_migrate_save_state($state);
    if (is_wp_error($saved)) {
        return $saved;
    }
    if (!empty($state['id']) && !empty($updates['log'])) {
        if ($run_id !== '' && $run_token !== '') {
            wasmer_migrate_log_for_run($state['id'], $run_token, $updates['log'], $updates['context'] ?? []);
        } else {
            wasmer_migrate_log($state['id'], $updates['log'], $updates['context'] ?? []);
        }
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
        'repoUrl' => 'https://github.com/wordpress/wordpress',
        'branch' => $wp_version['github_tag'],
        'enableDatabase' => true,
        'managed' => true,
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
    if (!empty($options['perish_at'])) {
        $input['perishAt'] = $options['perish_at'];
    }
    if (!empty($options['owner'])) {
        $input['owner'] = $options['owner'];
    }
    if (!empty($options['region'])) {
        $input['region'] = $options['region'];
    }

    $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
mutation CreateWordpressApp($input: DeployViaAutobuildInput!) {
  deployViaAutobuild(input: $input) {
    success
    buildId
    appToken
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
    if (!empty($options['perish_at']) && empty($payload['appToken'])) {
        return new WP_Error('wasmer_migrate_app_token_missing', 'Wasmer did not return a command token for the new perishable app.');
    }
    return [
        'build_id' => (string) $payload['buildId'],
        'app_token' => (string) ($payload['appToken'] ?? ''),
    ];
}

function wasmer_migrate_auto_get_ready_app($options)
{
    $alias_data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
query ReadyDeployAppByAlias($alias: String!) {
  getAppByGlobalAlias(alias: $alias) {
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
GRAPHQL, ['alias' => $options['app_name']]);
    if (!is_wp_error($alias_data)) {
        $alias_app = $alias_data['getAppByGlobalAlias'] ?? null;
        if (is_array($alias_app) && !empty($alias_app['id']) && !empty($alias_app['activeVersion']['id'])) {
            return $alias_app;
        }
    }

    $variables = [
        'name' => $options['app_name'],
        'owner' => !empty($options['owner']) ? $options['owner'] : null,
    ];
    $data = wasmer_migrate_auto_graphql($options, <<<'GRAPHQL'
query ReadyDeployApp($name: String!, $owner: String) {
  getDeployApp(name: $name, owner: $owner) {
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
GRAPHQL, $variables);
    if (is_wp_error($data)) {
        return $data;
    }

    $app = $data['getDeployApp'] ?? null;
    if (!is_array($app) || empty($app['id']) || empty($app['activeVersion']['id'])) {
        return null;
    }

    return $app;
}

function wasmer_migrate_auto_wait_for_app($options, $build_id, $run_id = '', $run_token = '')
{
    $deadline = time() + 900;
    while (time() < $deadline) {
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
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
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if (is_wp_error($data)) {
            $app = wasmer_migrate_auto_get_ready_app($options);
            if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
                return wasmer_migrate_stale_run_error();
            }
            if (is_array($app)) {
                $saved = wasmer_migrate_auto_state([
                    'status' => 'auto_waiting',
                    'app' => $app,
                    'build_status' => 'ACTIVE_VERSION_READY',
                ], $run_id, $run_token);
                if (is_wp_error($saved)) {
                    return $saved;
                }
                return $app;
            }
            return $data;
        }
        $status = (string) ($data['autobuildDeploymentStatus']['status'] ?? '');
        $app = $data['autobuildDeploymentStatus']['appVersion']['app'] ?? null;
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'build_status' => $status,
            'app' => is_array($app) ? $app : null,
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
        if (is_array($app) && !empty($app['id']) && (!empty($app['activeVersion']['id']) || $status === 'SUCCESS')) {
            return $app;
        }
        $ready_app = wasmer_migrate_auto_get_ready_app($options);
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if (is_array($ready_app)) {
            $saved = wasmer_migrate_auto_state([
                'status' => 'auto_waiting',
                'build_status' => $status !== '' ? $status : 'ACTIVE_VERSION_READY',
                'app' => $ready_app,
            ], $run_id, $run_token);
            if (is_wp_error($saved)) {
                return $saved;
            }
            return $ready_app;
        }
        if (in_array($status, ['FAILED', 'CANCELLED', 'INTERNAL_ERROR', 'TIMEOUT'], true)) {
            return new WP_Error('wasmer_migrate_app_build_failed', 'Wasmer app build failed with status ' . $status . '.');
        }
        sleep(5);
    }
    $app = wasmer_migrate_auto_get_ready_app($options);
    if (is_array($app)) {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'build_status' => 'ACTIVE_VERSION_READY',
            'app' => $app,
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
        return $app;
    }
    return new WP_Error('wasmer_migrate_app_build_timeout', 'Timed out waiting for the Wasmer app to become ready.');
}

function wasmer_migrate_auto_wait_for_wordpress($options, $app_id, $run_id = '', $run_token = '')
{
    $deadline = time() + 360;
    $last = null;
    while (time() < $deadline) {
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
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
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if (is_wp_error($data)) {
            $last = $data->get_error_message();
        } else {
            $live = $data['node']['kind']['liveConfig'] ?? null;
            if (is_array($live)) {
                $saved = wasmer_migrate_auto_state([
                    'status' => 'auto_waiting',
                    'live_config' => $live,
                ], $run_id, $run_token);
                if (is_wp_error($saved)) {
                    return $saved;
                }
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
    if (is_array($script)) {
        $script = implode("\n", $script);
    }

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

function wasmer_migrate_auto_log_excerpt($text, $max = 1200)
{
    $text = trim((string) $text);
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, $max) . '...';
}

function wasmer_migrate_auto_create_import_code($options, $app_id, $app_url = '')
{
    $result = wasmer_migrate_auto_run_edge_command($options, $app_id, wasmer_migrate_auto_wp_command('wasmer import session create --expires=8h'), 120);
    if (is_wp_error($result)) {
        return $result;
    }
    $json = wasmer_migrate_auto_extract_json($result['stdout'] ?? '');
    if ($json && !empty($json['code'])) {
        return $json['code'];
    }

    // WP-CLI can terminate a fresh WASI command instance before its output is
    // returned. Use a random, secret-guarded bootstrap in persistent wp-content
    // so the normal web runtime can create the session, then delete it.
    if ($app_url === '') {
        return new WP_Error('wasmer_migrate_import_code_missing', 'The new Wasmer app did not return an import code.');
    }
    $suffix = sanitize_key(wp_generate_uuid4());
    $secret = wp_generate_password(32, false, false);
    $filename = 'wasmer-import-bootstrap-' . $suffix . '.php';
    $result_path = '/app/wp-content/' . $filename;
    $bootstrap = '<?php '
        . 'if (!hash_equals(' . var_export($secret, true) . ', (string) ($_POST["key"] ?? ""))) { http_response_code(403); exit; } '
        . 'require_once dirname(__DIR__) . "/wp-load.php"; '
        . 'wasmer_import_load_import_dependencies(); '
        . 'add_filter("wasmer_import_max_total_size", static function () { return PHP_INT_SIZE >= 8 ? 20 * 1024 * 1024 * 1024 : PHP_INT_MAX; }); '
        . '$created = wasmer_import_create_session(8 * HOUR_IN_SECONDS); '
        . 'header("Content-Type: application/json"); '
        . 'echo wp_json_encode(["session" => wasmer_import_public_session($created["session"]), "code" => $created["code"]], JSON_UNESCAPED_SLASHES);';
    $written = wasmer_migrate_auto_run_edge_command(
        $options,
        $app_id,
        wasmer_migrate_auto_bash_command(
            'printf %s ' . escapeshellarg(base64_encode($bootstrap))
            . ' | base64 -d > ' . escapeshellarg($result_path)
        ),
        30
    );
    if (is_wp_error($written)) {
        return $written;
    }

    $response = wp_remote_post(trailingslashit($app_url) . 'wp-content/' . rawurlencode($filename), [
        'timeout' => 60,
        'body' => ['key' => $secret],
    ]);
    wasmer_migrate_auto_run_edge_command(
        $options,
        $app_id,
        wasmer_migrate_auto_bash_command('rm -f ' . escapeshellarg($result_path)),
        30
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('wasmer_migrate_import_bootstrap_failed', 'The new Wasmer app could not create an import session.', ['status' => $status]);
    }

    $json = wasmer_migrate_auto_extract_json(wp_remote_retrieve_body($response));
    if (!$json || empty($json['code'])) {
        return new WP_Error('wasmer_migrate_import_code_missing', 'The new Wasmer app did not return an import code.');
    }
    return $json['code'];
}

function wasmer_migrate_auto_start_import($options, $app_id, $app_url, $session_id)
{
    $suffix = sanitize_key(wp_generate_uuid4());
    $secret = wp_generate_password(32, false, false);
    $filename = 'wasmer-import-bootstrap-' . $suffix . '.php';
    $bootstrap_path = '/app/wp-content/' . $filename;
    $bootstrap = '<?php '
        . 'if (!hash_equals(' . var_export($secret, true) . ', (string) ($_POST["key"] ?? ""))) { http_response_code(403); exit; } '
        . 'require_once dirname(__DIR__) . "/wp-load.php"; '
        . 'wasmer_import_load_import_dependencies(); '
        . '$result = wasmer_import_start(' . var_export($session_id, true) . '); '
        . 'header("Content-Type: application/json"); '
        . 'if (is_wp_error($result)) { http_response_code(500); echo wp_json_encode(["error" => $result->get_error_message()]); exit; } '
        . 'echo wp_json_encode(["ok" => true], JSON_UNESCAPED_SLASHES);';
    $written = wasmer_migrate_auto_run_edge_command(
        $options,
        $app_id,
        wasmer_migrate_auto_bash_command(
            'printf %s ' . escapeshellarg(base64_encode($bootstrap))
            . ' | base64 -d > ' . escapeshellarg($bootstrap_path)
        ),
        30
    );
    if (is_wp_error($written)) {
        return $written;
    }

    $response = wp_remote_post(trailingslashit($app_url) . 'wp-content/' . rawurlencode($filename), [
        'timeout' => 900,
        'body' => ['key' => $secret],
    ]);
    wasmer_migrate_auto_run_edge_command(
        $options,
        $app_id,
        wasmer_migrate_auto_bash_command('rm -f ' . escapeshellarg($bootstrap_path)),
        30
    );
    if (is_wp_error($response)) {
        return $response;
    }
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($status < 200 || $status >= 300) {
        $json = wasmer_migrate_auto_extract_json($body);
        return new WP_Error(
            'wasmer_migrate_import_start_failed',
            (string) ($json['error'] ?? 'The new Wasmer app could not start the import.'),
            ['status' => $status]
        );
    }

    return [
        'exitCode' => 0,
        'stdout' => $body,
        'stderr' => '',
    ];
}

function wasmer_migrate_auto_wait_for_wp_cli($options, $app_id, $run_id = '', $run_token = '')
{
    $deadline = time() + 600;
    $last = '';
    $probe_commands = [
        'wp core is-installed',
        'wp core version',
    ];
    while (time() < $deadline) {
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'log' => 'Checking whether the new app can run WordPress commands.',
            'context' => ['command' => implode(' && ', $probe_commands)],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $result = wasmer_migrate_auto_run_edge_command($options, $app_id, wasmer_migrate_auto_bash_command(array_merge([
            'set -e',
            'if [ -d /app ]; then cd /app; fi',
        ], $probe_commands)), 120);
        if ($run_id !== '' && $run_token !== '' && !wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if (!is_wp_error($result)) {
            $saved = wasmer_migrate_auto_state([
                'status' => 'auto_waiting',
                'log' => 'WordPress command check succeeded.',
                'context' => [
                    'exit_code' => (int) ($result['exitCode'] ?? 0),
                    'stdout' => wasmer_migrate_auto_log_excerpt($result['stdout'] ?? ''),
                    'stderr' => wasmer_migrate_auto_log_excerpt($result['stderr'] ?? ''),
                ],
            ], $run_id, $run_token);
            if (is_wp_error($saved)) {
                return $saved;
            }
            return $result;
        }
        $data = $result->get_error_data();
        $command = is_array($data) ? ($data['result'] ?? []) : [];
        $last = trim((string) (($command['stderr'] ?? '') ?: ($command['stdout'] ?? '') ?: $result->get_error_message()));
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'wp_cli_probe' => $last,
            'log' => 'WordPress command check failed.',
            'context' => [
                'error' => wasmer_migrate_auto_log_excerpt($result->get_error_message()),
                'exit_code' => isset($command['exitCode']) ? (int) $command['exitCode'] : null,
                'stdout' => wasmer_migrate_auto_log_excerpt($command['stdout'] ?? ''),
                'stderr' => wasmer_migrate_auto_log_excerpt($command['stderr'] ?? ''),
            ],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
        sleep(10);
    }

    return new WP_Error(
        'wasmer_migrate_wp_cli_not_ready',
        'The new Wasmer app did not become ready to run WordPress commands before the timeout.' . ($last ? ' Last probe: ' . $last : '')
    );
}

function wasmer_migrate_auto_app_import($options = [])
{
    @set_time_limit(0);

    $resume = filter_var($options['resume'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $options = wasmer_migrate_auto_options($options);
    $state = wasmer_migrate_get_state();
    $existing_auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $has_resume_checkpoint = !empty($state['id'])
        && !empty($state['run_token'])
        && (
            !empty($existing_auto['build_id'])
            || !empty($existing_auto['app'])
            || !empty($state['destination'])
            || in_array(($state['status'] ?? ''), ['auto_waiting', 'auto_session', 'auto_transferring', 'auto_importing', 'transfer_complete'], true)
        );
    $can_resume = $resume && $has_resume_checkpoint;

    if (!$can_resume) {
        $state = wasmer_migrate_begin_run();
        $existing_auto = [];
    } elseif (($state['status'] ?? '') === 'auto_complete') {
        return $state;
    }

    $run_id = (string) ($state['id'] ?? '');
    $run_token = (string) ($state['run_token'] ?? '');
    $resume_after_transfer = $can_resume && in_array(($state['status'] ?? ''), ['transfer_complete', 'auto_importing'], true);

    if ($can_resume) {
        foreach (['graphql_url', 'token', 'owner', 'region', 'perish_at', 'app_name'] as $key) {
            if (array_key_exists($key, $existing_auto)) {
                $options[$key] = $existing_auto[$key];
            }
        }
        $options['authenticated'] = !empty($options['token']);
    }

    $state['auto_app'] = array_merge($existing_auto, [
        'status' => 'auto_exporting',
        'graphql_url' => $options['graphql_url'],
        'token' => $options['token'],
        'authenticated' => $options['authenticated'],
        'owner' => $options['owner'],
        'region' => $options['region'],
        'perish_at' => $options['perish_at'],
        'app_name' => $options['app_name'],
    ]);
    $state['status'] = 'auto_exporting';
    $state['error'] = '';
    $state['resume_allowed'] = false;
    $saved = wasmer_migrate_save_state_for_run($state, $run_id, $run_token);
    if (is_wp_error($saved)) {
        return $saved;
    }

    if (!$can_resume || empty($state['manifest']) || empty($state['database']) || !is_array($state['files'] ?? null)) {
        wasmer_migrate_log_for_run($run_id, $run_token, 'Preparing export before creating a Wasmer app.');
        $prepared = wasmer_migrate_prepare_export($run_id, $run_token);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        $state = $prepared;
    } else {
        wasmer_migrate_log_for_run($run_id, $run_token, 'Resuming with existing prepared export.');
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $source_wp = (string) ($state['manifest']['source']['wp_version'] ?? get_bloginfo('version'));
    $source_php = (string) ($state['manifest']['source']['php_version'] ?? phpversion());
    $wp_version = $can_resume && is_array($auto['target_wp_version'] ?? null)
        ? $auto['target_wp_version']
        : wasmer_migrate_auto_select_wordpress_version($options, $source_wp);

    $build_id = $can_resume ? (string) ($auto['build_id'] ?? '') : '';
    $app_token = $can_resume ? trim((string) ($auto['app_token'] ?? '')) : '';
    if ($build_id === '') {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_creating',
            'source_wp_version' => $source_wp,
            'source_php_version' => $source_php,
            'target_wp_version' => $wp_version,
            'log' => $options['authenticated']
                ? 'Creating Wasmer WordPress app for the authenticated account.'
                : 'Creating temporary Wasmer WordPress app.',
            'context' => [
                'app_name' => $options['app_name'],
                'wordpress_version' => $wp_version['version'],
            ],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $base_app_name = $options['app_name'];
        $created_app = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
                return wasmer_migrate_stale_run_error();
            }
            $options['app_name'] = wasmer_migrate_auto_app_name_candidate($base_app_name, $attempt);
            if ($attempt > 0) {
                $saved = wasmer_migrate_auto_state([
                    'status' => 'auto_creating',
                    'app_name' => $options['app_name'],
                    'log' => 'Retrying Wasmer app creation with a different app name.',
                    'context' => ['app_name' => $options['app_name']],
                ], $run_id, $run_token);
                if (is_wp_error($saved)) {
                    return $saved;
                }
            }

            $created_app = wasmer_migrate_auto_create_wordpress_app($options, $wp_version);
            if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
                return wasmer_migrate_stale_run_error();
            }
            if (!is_wp_error($created_app)) {
                break;
            }
            if (!wasmer_migrate_auto_is_app_name_conflict($created_app)) {
                return $created_app;
            }
        }
        if (is_wp_error($created_app)) {
            return new WP_Error(
                'wasmer_migrate_app_name_conflict',
                'Could not find an available Wasmer app name after 5 attempts.',
                ['last_error' => $created_app->get_error_message()]
            );
        }
        $build_id = (string) $created_app['build_id'];
        $app_token = trim((string) $created_app['app_token']);
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'app_name' => $options['app_name'],
            'build_id' => $build_id,
            'app_token' => $app_token,
            'log' => 'Waiting for Wasmer app build.',
            'context' => ['build_id' => $build_id],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
    } else {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'build_id' => $build_id,
            'log' => 'Resuming Wasmer app build.',
            'context' => ['build_id' => $build_id],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }

    $command_token = $options['authenticated'] ? trim((string) $options['token']) : $app_token;
    if ($command_token === '') {
        return new WP_Error(
            'wasmer_migrate_app_token_missing',
            'The command token for the new perishable app is missing. Start the migration again to create a new app.'
        );
    }
    $command_options = $options;
    $command_options['token'] = $command_token;

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $app = $can_resume && is_array($auto['app'] ?? null) ? $auto['app'] : null;
    if (!$app || empty($app['id'])) {
        $app = wasmer_migrate_auto_wait_for_app($options, $build_id, $run_id, $run_token);
        if (is_wp_error($app)) {
            return $app;
        }
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_waiting',
            'app' => $app,
            'log' => 'Wasmer app build completed.',
            'context' => ['app_id' => $app['id'], 'url' => $app['url'] ?? ''],
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $auto = is_array($state['auto_app'] ?? null) ? $state['auto_app'] : [];
    $live_config = $can_resume && is_array($auto['live_config'] ?? null)
        ? $auto['live_config']
        : wasmer_migrate_auto_wait_for_wordpress($options, $app['id'], $run_id, $run_token);
    if (is_wp_error($live_config)) {
        return $live_config;
    }
    $saved = wasmer_migrate_auto_state([
        'status' => 'auto_session',
        'app' => $app,
        'live_config' => $live_config,
        'log' => 'Waiting until the new Wasmer app can run WordPress commands.',
    ], $run_id, $run_token);
    if (is_wp_error($saved)) {
        return $saved;
    }
    $ready = wasmer_migrate_auto_wait_for_wp_cli($command_options, $app['id'], $run_id, $run_token);
    if (is_wp_error($ready)) {
        return $ready;
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    if (!empty($state['destination'])
        && isset($state['destination']['limits']['max_total_size'])
        && (int) $state['destination']['limits']['max_total_size'] <= 0) {
        $state['destination'] = null;
        $saved = wasmer_migrate_save_state_for_run($state, $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
        wasmer_migrate_log_for_run($run_id, $run_token, 'Discarded import session with an invalid destination size limit.');
    }
    if (empty($state['destination'])) {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_session',
            'log' => 'Creating import session in the new Wasmer app.',
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $code = wasmer_migrate_auto_create_import_code($command_options, $app['id'], $app['url'] ?? '');
        if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
            return wasmer_migrate_stale_run_error();
        }
        if (is_wp_error($code)) {
            return $code;
        }

        $connected = wasmer_migrate_connect($code, $run_id, $run_token);
        if (is_wp_error($connected)) {
            return $connected;
        }
    } else {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_session',
            'log' => 'Resuming existing import session.',
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    if (!$resume_after_transfer) {
        $saved = wasmer_migrate_auto_state([
            'status' => 'auto_transferring',
            'app' => $app,
            'log' => 'Transferring site into the new Wasmer app.',
        ], $run_id, $run_token);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $transferred = wasmer_migrate_transfer($run_id, $run_token);
        if (is_wp_error($transferred)) {
            return $transferred;
        }
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $session_id = $state['destination']['session'] ?? '';
    if ($session_id === '') {
        return new WP_Error('wasmer_migrate_missing_remote_session', 'The remote import session id was lost before import.');
    }
    $saved = wasmer_migrate_auto_state([
        'status' => 'auto_importing',
        'log' => 'Starting WordPress import in the new Wasmer app.',
        'context' => ['session' => $session_id],
    ], $run_id, $run_token);
    if (is_wp_error($saved)) {
        return $saved;
    }
    $import = wasmer_migrate_auto_start_import($command_options, $app['id'], $app['url'] ?? '', $session_id);
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    if (is_wp_error($import)) {
        return $import;
    }

    $state = wasmer_migrate_get_state();
    if (!wasmer_migrate_is_active_run($run_id, $run_token)) {
        return wasmer_migrate_stale_run_error();
    }
    $state['status'] = 'auto_complete';
    $state['auto_app']['status'] = 'auto_complete';
    $state['auto_app']['app'] = $app;
    $state['destination']['session'] = $session_id;
    $state['auto_app']['import_stdout'] = trim((string) ($import['stdout'] ?? ''));
    $state['auto_app']['import_stderr'] = trim((string) ($import['stderr'] ?? ''));
    $state['auto_app']['completed'] = time();
    unset($state['auto_app']['token'], $state['auto_app']['app_token']);
    $state['resume_allowed'] = false;
    $saved = wasmer_migrate_save_state_for_run($state, $run_id, $run_token);
    if (is_wp_error($saved)) {
        return $saved;
    }
    wasmer_migrate_log_for_run($state['id'], $run_token, 'Automatic Wasmer import completed.', ['url' => $app['url'] ?? '']);

    return $state;
}
