=== Wasmer Migrate ===
Contributors: wasmer
Tags: wasmer, migration, hosting, cloud
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
<!-- x-release-please-start-version -->
Stable tag: 0.5.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a complete WordPress site to Wasmer, including its database, uploads, plugins, and themes.

== Description ==

Wasmer Migrate copies an existing WordPress site to the Wasmer hosting platform. It creates a Wasmer WordPress app and transfers the complete database, uploads, plugins, and themes. An optional Wasmer access token creates the app in an existing account. Without a token, the plugin creates a perishable app that expires after approximately two hours unless it is claimed.

The source site remains online and unchanged. Temporary export files are access-guarded and removed after transfer, reset, or uninstall.

= External service and data transfer =

This plugin requires the Wasmer service to perform a migration. It uses `https://registry.wasmer.io/graphql` by default; an administrator can configure a different Wasmer-compatible registry endpoint.

When an administrator confirms and starts a migration:

* The plugin sends the requested app name, site name, WordPress locale, administrator email, generated destination administrator credentials, WordPress version, and optional Wasmer access token to the Wasmer GraphQL API to create and configure the destination app.
* It exports and transfers the complete WordPress database. This includes users and password hashes, posts, comments, settings, and data stored by plugins and themes.
* It transfers site files from uploads, plugins, and themes. These files can include personal data and third-party or proprietary code owned or licensed by the site operator.
* It uses an app-scoped, short-lived command token returned by Wasmer to execute the WP-CLI commands needed to initialize the destination import. During setup, it temporarily writes and then removes executable bootstrap files on the destination app.
* Wasmer stores and processes the copied data to build and operate the destination app. The plugin removes its local export after a successful transfer, but Wasmer's retention practices apply to data received by the service.

Do not begin a migration unless you are authorized to transfer the site's data and code. Review the [Wasmer Terms of Service](https://wasmer.io/terms) and [Wasmer Privacy Policy](https://wasmer.io/policies/privacy).

== Installation ==

1. Upload the `wasmer-migrate` directory to `/wp-content/plugins/`, or install the ZIP from the Plugins screen.
2. Activate Wasmer Migrate through the Plugins screen.
3. Open **Migrate to Wasmer** in WordPress admin and review the data-transfer disclosure.
4. Optionally enter a Wasmer access token, confirm the disclosure, and start the migration.

The Wasmer plugin is installed automatically on newly created destination apps as part of the managed WordPress setup.

== Frequently Asked Questions ==

= What is transferred? =

The entire WordPress database plus uploads, plugins, and themes. Review the external-service disclosure above before starting.

= What happens without a Wasmer token? =

Wasmer creates a temporary perishable app and returns an app-scoped command token that is used only for the commands needed to complete that migration. Claim the app before its displayed expiry time if you want to keep it.

= Does the migration modify the source site? =

It reads and exports the source site's content and files. The source continues to operate, and temporary export data is deleted after transfer or reset.

== Changelog ==

= 0.1.0 =

* Initial WordPress.org release.
* Create account-owned or perishable Wasmer apps and transfer the complete site.
* Use perishable-app command tokens for destination setup.
