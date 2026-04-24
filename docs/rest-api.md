# REST API Reference

The plugin registers three public REST endpoints under the `wasmer/v1` namespace:

- `GET /?rest_route=/wasmer/v1/check`
- `GET /?rest_route=/wasmer/v1/liveconfig`
- `GET /?rest_route=/wasmer/v1/magiclogin&magiclogin={token}`

Each route is registered with `permission_callback => __return_true`, so the plugin itself does not require an authenticated WordPress session to call these endpoints.

## Auth And Access Expectations

- `check` and `liveconfig` are public read endpoints.
- `magiclogin` is a public entrypoint, but it only succeeds when the request includes a valid Wasmer token and the server has the required Wasmer environment variables configured.
- The plugin also bypasses Password Protected-style REST authentication failures for requests under `/wasmer/v1/`, so these routes remain reachable when that plugin would normally block anonymous access.

## Response Headers

All three endpoints explicitly send:

- `Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`

The exception is the successful `magiclogin` redirect flow, which sends the cache-control headers but does not use a `WP_REST_Response`, so the response is a `302` redirect with cookies and location headers instead of a JSON body.

## `GET /wasmer/v1/check`

Simple health endpoint used to confirm that the plugin is active and responding.

Example:

```bash
curl "http://localhost:8080/?rest_route=/wasmer/v1/check"
```

Success response:

```json
{
  "status": "success"
}
```

Status codes:

- `200` on success

## `GET /wasmer/v1/liveconfig`

Returns a snapshot of the WordPress deployment metadata collected by the plugin.

Example:

```bash
curl "http://localhost:8080/?rest_route=/wasmer/v1/liveconfig"
```

The response includes:

- `liveconfig_version`
- `wasmer_plugin`
- `wordpress`
- `php`
- `mysql`

Important `wordpress` fields include:

- `version`
- `latest_version`
- `url`
- `language`
- `timezone`
- `debug`
- `debug_log`
- `is_main_site`
- `plugins`
- `themes`
- `users`
- `posts`
- `pages`

This endpoint does not require Wasmer-specific environment variables. It derives its data from the running WordPress installation and the current plugin constants.

See [Wasmer integration](wasmer-integration.md#how-wasmer-uses-liveconfig) for how Wasmer consumes this payload in the dashboard and WordPress settings flow.

Status codes:

- `200` on success

## `GET /wasmer/v1/magiclogin`

Performs token-based login into WordPress admin for Wasmer.

Query parameters:

- `magiclogin`
  Required bearer token value used to authenticate against the Wasmer GraphQL API.

Example:

```bash
curl -L "http://localhost:8080/?rest_route=/wasmer/v1/magiclogin&magiclogin=your-token-here"
```

Required environment variables:

- `WASMER_GRAPHQL_URL`
- `WASMER_APP_ID`

Behavior:

- The plugin sends a GraphQL request to `WASMER_GRAPHQL_URL` with the incoming token as a bearer token.
- The query validates the current viewer and checks that the configured `WASMER_APP_ID` resolves to a `DeployApp`.
- If validation succeeds, the plugin finds an administrator user, sets the WordPress auth cookies, and redirects to `admin_url()` with `platform=wasmer`.
- If no matching administrator email is found, the plugin falls back to the first administrator account.

Success response:

- `302` redirect to `{admin_url}/?platform=wasmer`
- `Set-Cookie` headers for WordPress authentication

Failure responses:

- `400` if the GraphQL request fails
- `403` if the token is invalid or expired
- `500` if `magiclogin` is missing, or if `WASMER_GRAPHQL_URL` / `WASMER_APP_ID` are not configured
