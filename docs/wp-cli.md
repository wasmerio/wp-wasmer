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
- Any format other than `json` returns a WP-CLI error.

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
