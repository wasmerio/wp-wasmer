# WP-CLI Commands

The plugin registers two WP-CLI commands when it is loaded under WP-CLI.

## Availability

- The plugin must be installed and active in the target WordPress site.
- The command must run through WP-CLI, not plain PHP.
- The `wasmer` namespace is only registered when `WP_CLI` is defined and truthy.

## `wp wasmer liveconfig`

Prints the same payload returned by `GET /wasmer/v1/liveconfig`.

Example:

```bash
wp wasmer liveconfig
```

Supported options:

- `--format=json`
  The only supported output format.

Behavior:

- The command calls the same `wasmer_get_liveconfig_data()` helper used by the REST endpoint.
- Output is emitted as JSON to standard output.
- `wordpress.plugins[].name` and `wordpress.themes[].name` use the same identifier convention as `wp plugin list --json` and `wp theme list --json`.
- Plugin and theme display labels are available as `title`.
- Any format other than `json` returns a WP-CLI error.

## `wp wasmer purge-cdn-cache`

Purges the whole Wasmer CDN cache for the app.

Example:

```bash
wp wasmer purge-cdn-cache
```

Behavior:

- Requires the `WASMER_API_TOKEN` and `WASMER_GRAPHQL_URL` environment variables; errors otherwise.
- Calls the `purgeAppCdnCache` GraphQL mutation and reports success or failure.

## `wp wasmer-aio-install install`

Runs the plugin's all-in-one install helper.

Example:

```bash
wp wasmer-aio-install install --theme=/path/to/theme.zip --locale=de_DE
```

Required options:

- `--theme`
  Path passed to `wp theme install`.
- `--locale`
  Locale passed to both WordPress core language install and theme language install.

Behavior:

- Installs the provided theme.
- Installs and activates the given core language.
- Installs the theme language for all themes.

This command is part of the current public CLI surface even though the newer `wasmer` namespace is the primary entrypoint for `liveconfig`.

## Migration CLI Flow

The WordPress admin migration screens create import sessions and show status, but they do not run long export, transfer, or import work in the browser request.
The default transfer includes the database plus `wp-content/uploads`, `wp-content/themes`, and `wp-content/plugins`.

Target site:

```bash
wp wasmer import session create --expires=2h
wp wasmer import start <session_id>
```

Source site with the Wasmer Migrate plugin active:

```bash
wp wasmer-migrate connect '<import_code>'
wp wasmer-migrate plan
wp wasmer-migrate start
```

If a source transfer is interrupted, resume it with:

```bash
wp wasmer-migrate resume
```

Before the target import starts, `wp wasmer import start` checks the source manifest for the active source theme and active source plugins. If a future transfer omits those files and matching themes or plugins are missing on the destination, the command fails before applying the database import.
