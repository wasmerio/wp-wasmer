<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'; // Bootstrap WordPress
require_once __DIR__ . '/wasmer-functions.php'; // Ensure your helpers are loaded

class Wasmer_API {
    public static function dispatch() {
        $endpoint = $_GET['endpoint'] ?? null;

        switch ($endpoint) {
            case 'check':
                self::handle_check();
                break;
            case 'liveconfig':
                self::handle_liveconfig();
                break;
            case 'magiclogin':
                self::handle_magiclogin();
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown endpoint']);
        }
    }

    public static function handle_check() {
        echo json_encode(['status' => 'success']);
    }

    public static function handle_liveconfig() {
        $data = wasmer_get_liveconfig_data();
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public static function handle_magiclogin() {
        $token = $_GET['magiclogin'] ?? null;
        $url = getenv("WASMER_GRAPHQL_URL");
        $appid = getenv("WASMER_APP_ID");

        if (!$token || !$url || !$appid) {
            http_response_code(500);
            echo json_encode(['error' => 'Missing token or config']);
            return;
        }

        $query = <<<'GRAPHQL'
        query ($appid: ID!) {
            viewer {
                email
            }
            node(id: $appid) {
                ... on DeployApp {
                    id
                }
            }
        }
        GRAPHQL;

        $response = wasmer_graphql_query($url, $query, ['appid' => $appid], $token);
        if (!$response) {
            http_response_code(400);
            echo json_encode(['error' => 'GraphQL query failed']);
            return;
        }

        $viewer = $response['data']['viewer'] ?? null;
        $node = $response['data']['node'] ?? null;

        if (!$viewer || !$node || !isset($node['id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }

        $login_data = [
            'email' => $viewer['email'],
            'redirect_location' => 'wasmer',
            'client_id' => '',
            'acting_client_id' => '',
            'callback_url' => '',
        ];

        /* echo json_encode([
         *     'status' => 'logged_in',
         *     'redirect_url' => wasmer_get_login_link($login_data),
         * ]); */
        $redirect_url = wasmer_get_login_link($login_data);
        header("Location: $redirect_url");
        exit;        
    }
}

// Execute the handler
header('Content-Type: application/json');
Wasmer_API::dispatch();
