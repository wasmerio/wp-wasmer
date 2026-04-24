# Admin And Update Behavior

This page documents the WordPress admin features and update restrictions added by the plugin.

## Admin Bar And Dashboard

The plugin adds a Wasmer entry to the WordPress admin bar.

Depending on configuration, that menu includes:

- a link to the Wasmer Control Panel for the current app
- a claim-app action when `WASMER_PERISHABLE_TIMESTAMP` is set and the app is expiring soon

The plugin also adds a "Manage on Wasmer" dashboard widget when `WASMER_APP_ID` is configured.

## App Claim Behavior

When `WASMER_PERISHABLE_TIMESTAMP` is present:

- the plugin calculates the remaining time until expiration
- the admin bar shows a notification badge
- the Wasmer menu includes a "Claim app" action linking back to Wasmer

The timestamp may be numeric or parseable as a datetime string.

## WordPress Core Update Restrictions

The plugin prevents manual WordPress core upgrades from the WordPress admin UI.

Behavior includes:

- disabling the automatic updater scheduler
- blocking manual core package downloads through the upgrader
- showing update notices that direct the user to Wasmer WordPress Settings instead
- returning a hard error page if the user attempts a manual core upgrade action

The destination used in those notices is:

```text
{wasmer_base_url}/id/{WASMER_APP_ID}/settings/wordpress
```

## Plugin And Theme Auto-Updates

The plugin disables automatic updates for:

- themes
- plugins

Manual plugin and theme updates remain handled by WordPress.
