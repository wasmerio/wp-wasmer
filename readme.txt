=== Wasmer Hosting Integration ===
Contributors: wasmer
Tags: wasmer, hosting, cloud, cdn, migration
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
<!-- x-release-please-start-version -->
Stable tag: 0.5.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hosting integration for WordPress sites running on Wasmer, including CDN cache controls and secure platform management.

== Description ==

Wasmer Hosting Integration connects WordPress sites running on Wasmer with the Wasmer hosting platform. On a managed Wasmer app it provides CDN cache controls, hosting and update status, secure dashboard login, WP-CLI integration, and the destination side of Wasmer site migrations.

Outside a managed Wasmer environment, hosting-specific behavior is inactive and normal WordPress core update behavior is unchanged.

= External services =

This plugin connects to the Wasmer service when the hosting environment supplies Wasmer configuration:

* It sends authenticated GraphQL requests to the configured Wasmer API when an administrator purges the CDN cache or when the plugin automatically purges the cache after content changes. These requests include the Wasmer app identifier and an environment-provided API token.
* A Wasmer dashboard magic-login request sends its short-lived bearer token and configured app identifier to the Wasmer GraphQL API. Wasmer validates that the viewer can deploy the exact app. The plugin only signs in a local administrator whose email exactly matches the validated viewer email.
* Wasmer can request an authenticated live-configuration report. The report contains WordPress, PHP, database, plugin and theme versions; plugin and theme inventory; server and debug configuration; filesystem paths and URLs; content counts; and aggregate user and administrator counts. It is used to operate and display the managed WordPress app in the Wasmer dashboard.
* The migration endpoints receive a database and site files from the separately installed Wasmer Migrate plugin. Transfer tokens are generated per migration session, and transferred staging data is removed after completion, cancellation, an expired-session request, scheduled expiry cleanup, or uninstall. Cleanup failures are retained in the session status and logs for an administrator to resolve.

The service is provided by Wasmer, Inc. Review the [Wasmer Terms of Service](https://wasmer.io/terms) and [Wasmer Privacy Policy](https://wasmer.io/policies/privacy).

== Installation ==

1. Upload the `wasmer` directory to `/wp-content/plugins/`, or install the ZIP from the Plugins screen.
2. Activate Wasmer through the Plugins screen.
3. Wasmer hosting automatically supplies the environment configuration used by the integration.

== Frequently Asked Questions ==

= Does this plugin disable WordPress updates? =

Only on a positively identified Wasmer-managed app, where WordPress core updates are provided by the hosting platform. A regular WordPress installation keeps its normal update behavior.

= Does the plugin send data to Wasmer on a regular WordPress host? =

No. Wasmer-specific API routes and automatic cache-purge requests require configuration supplied by a managed Wasmer environment.

== Changelog ==

= 0.4.5 =

* Add authenticated migration support for perishable Wasmer apps.
* Require authentication for live configuration and strengthen magic-login authorization.
* Scope hosting-managed core update behavior to Wasmer environments.
* Automatically clean migration staging data.

== Upgrade Notice ==

= 0.4.5 =

Adds authenticated perishable-app migrations and important endpoint, login, update-management, and staging-data security improvements.
