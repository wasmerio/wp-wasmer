# Wasmer WordPress Migration Plan

Date: 2026-07-01

## Goal

Build a Wasmer-native migration flow for importing an existing WordPress site into a Wasmer WordPress app.

The primary requirement is that large backup content must never depend on PHP multipart upload temp files on the destination app. On Wasmer Edge, `/tmp` is memory-backed, so the destination must write incoming data directly into persistent storage under `wp-content`.

The intended product flow:

1. User opens the new Wasmer WordPress app.
2. The auto-installed Wasmer plugin shows an import page and generates a short-lived import code.
3. User installs a new `Wasmer Migrate` plugin on the old WordPress site.
4. User pastes the import code into the old site.
5. The old site exports database and files, then transfers them to the new site in small resumable chunks.
6. The new site verifies the transfer and imports the received content.

## Source-Backed Findings

Before implementation, download and inspect the relevant plugin sources into a local research directory so behavior is verified against code, not docs:

```bash
mkdir -p .research/wordpress-migration-plugins
cd .research/wordpress-migration-plugins

curl -L -o all-in-one-wp-migration.zip https://downloads.wordpress.org/plugin/all-in-one-wp-migration.latest-stable.zip
curl -L -o wpvivid-backuprestore.zip https://downloads.wordpress.org/plugin/wpvivid-backuprestore.latest-stable.zip
curl -L -o duplicator.zip https://downloads.wordpress.org/plugin/duplicator.latest-stable.zip
curl -L -o updraftplus.zip https://downloads.wordpress.org/plugin/updraftplus.latest-stable.zip
curl -L -o modular-connector.zip https://downloads.wordpress.org/plugin/modular-connector.latest-stable.zip
curl -L -o migrate-guru.zip https://downloads.wordpress.org/plugin/migrate-guru.latest-stable.zip
curl -L -o hostinger.zip https://downloads.wordpress.org/plugin/hostinger.latest-stable.zip

for f in *.zip; do unzip -q "$f" -d "${f%.zip}"; done
```

Findings from the 2026-06-30 inspection:

- All-in-One WP Migration stores final `.wpress` archives under `wp-content/ai1wm-backups`, but the normal UI upload first reads `$_FILES['upload_file']['tmp_name']`. This does not satisfy the Edge requirement unless PHP `upload_tmp_dir` is moved under `wp-content` before request parsing, or the upload route is bypassed.
- All-in-One can import an out-of-band `.wpress` file by calling the lower-level import pipeline with `ai1wm_manual_restore=1`, but the free UI restore path is gated behind the Unlimited Extension.
- UpdraftPlus stores local backup sets under `wp-content/updraft`, but it uses multiple component files per backup set, not one whole-site archive. Premium has WP-CLI restore paths, but this is not the ideal first-party UI flow.
- Duplicator Lite stores generated packages under `wp-content/backups-dup-lite`, but its WordPress admin import UI is an upsell mock. Its real free migration path is the classic installer plus archive placed on the target filesystem.
- Hostinger Tools is not a migration plugin in the WordPress.org package.
- Modular Connector is useful as an architecture reference: it creates bounded backup parts and uploads them through a manager-driven workflow.
- Migrate Guru is useful as a protocol reference: it exposes authenticated remote filesystem/database operations and supports streamed/chunked file writes.
- WPvivid Backup & Migration is the closest UX reference: destination generates a transfer key, source user pastes it, source creates a backup, and destination writes received chunks directly under `wp-content` using `fopen`, `fseek`, and `fwrite`. WPvivid browser upload still uses PHP temp files, so copy the site-to-site pattern, not the browser-upload path.

## Repository Structure

Keep all code in the `wp-wasmer` repository, but separate the source-site migration plugin from the destination Wasmer integration.

Proposed layout:

```text
wasmer/
  wasmer.php
  admin.php
  rest-api.php
  migrate-import/
    admin-page.php
    dashboard-panel.php
    rest-routes.php
    sessions.php
    auth.php
    storage.php
    manifest.php
    importer.php
    logs.php
    ui.css
    ui.js

wasmer-migrate/
  wasmer-migrate.php
  admin-page.php
  code-parser.php
  exporter.php
  scanner.php
  db-export.php
  transfer-client.php
  state.php
  storage.php
  logs.php
  ui.css
  ui.js
```

`wasmer/` remains the auto-installed destination plugin.

`wasmer-migrate/` is the plugin users install on the old site. It can live in the same repo and be packaged separately.

## Destination Plugin Changes

Extend the existing `wp-wasmer` plugin with a migration entry point.

Admin UI:

- Add `Wasmer > Import Site`.
- Add a hideable dashboard panel that links to the import page.
- Gate all admin actions behind `manage_options`.
- Store the dashboard-panel hidden state in user meta, for example `wasmer_hide_import_dashboard_panel`.

Import page wizard:

1. Overview: explain that this imports an existing WordPress site into the current Wasmer app.
2. Install: instruct the user to install `Wasmer Migrate` on the old site.
3. Connect: generate and show a copyable import code.
4. Transfer: show connection, export, upload, verification, and import progress.
5. Finish: show final checks, migrated site link, and cleanup controls.

The UI should be Wasmer branded, but scoped to the plugin page. Avoid introducing a heavy frontend framework unless `wp-wasmer` already uses one.

REST routes under `wasmer/v1/import`:

```text
POST /session
GET  /session/<id>
POST /session/<id>/connect
POST /session/<id>/manifest
POST /session/<id>/chunk
POST /session/<id>/complete
POST /session/<id>/start-import
POST /session/<id>/cancel
```

Permissions:

- `POST /session`, `GET /session/<id>`, `POST /start-import`, and `POST /cancel` require logged-in admin capability.
- `POST /connect`, `POST /manifest`, `POST /chunk`, and `POST /complete` are public routes authenticated by the import code token.

## Import Code

The destination app generates a short-lived code that the source plugin can paste and parse.

Payload:

```json
{
  "v": 1,
  "target": "https://new-site.wasmer.app",
  "rest": "https://new-site.wasmer.app/wp-json/wasmer/v1/import",
  "session": "wmi_...",
  "token": "random_secret_token",
  "expires": 1782920000,
  "app_id": "..."
}
```

Encoding:

- JSON payload.
- Base64url encode for copy/paste.
- Prefix with a stable marker, for example `wasmer-import:v1:`.

Token storage:

- Store only a token hash on the destination.
- Example: `hash_hmac('sha256', $token, AUTH_SALT)`.
- Store session metadata in an option or custom file under `wp-content/wasmer-import/sessions`.

Request authentication:

- Source sends `Authorization: WasmerImport <session>:<timestamp>:<signature>`.
- Signature covers method, route path, body hash, and timestamp.
- Reject expired codes, stale timestamps, invalid signatures, cancelled sessions, completed sessions, and replayed finalization requests.

MVP alternative:

- Use a simple bearer token over HTTPS for the first version if HMAC request signing slows implementation.
- Still store only a hash at rest.
- Keep the auth layer isolated so HMAC can be added without changing transfer/storage code.

## Destination Storage

All received content must be written under persistent `wp-content`, never `/tmp`.

Default directory:

```text
wp-content/wasmer-import/
  sessions/
    <session>.json
  <session>/
    manifest.json
    db/
      database.sql.part
      database.sql
    files/
      wp-content/
        uploads/
        themes/
        plugins/
        mu-plugins/
    chunks/
    logs/
```

Storage rules:

- Do not use `$_FILES`.
- Do not use `wp_handle_upload`.
- Do not require browser multipart uploads.
- Prefer raw request bodies for chunk data.
- If using JSON/base64 chunks for compatibility, keep chunk size conservative.
- Write with `fopen`, `fseek`, `fwrite`, and `fflush`.
- Validate each path before writing.
- Reject absolute paths, `..`, symlinks, stream wrappers, and paths outside the session directory.
- Write to `.part` files and rename only after size and hash verification.
- Make chunk writes idempotent by verifying offset and hash before accepting duplicates.

Recommended chunk size:

- Start with 512 KiB or 1 MiB.
- Allow the destination to advertise a max chunk size during `/connect`.
- Keep max chunk size configurable.

## Source Plugin: Wasmer Migrate

Create a new plugin named `Wasmer Migrate`.

Admin UI:

- Add top-level or Tools menu entry `Wasmer Migrate`.
- Wizard steps:
  1. Paste import code.
  2. Validate destination connection.
  3. Select content to migrate.
  4. Export database and scan files.
  5. Transfer with progress and retry controls.
  6. Complete and show destination import status.

The source plugin should persist state so a failed request, reload, or PHP timeout can resume the migration.

State storage:

- Store active migration state in `wp_options`.
- Store export artifacts under `wp-content/wasmer-migrate/<migration-id>`.
- Store logs with redaction.
- Avoid storing the raw token longer than needed. If resume requires it, store it only in the active migration option and delete it on completion/cancel.

File scanner:

- Include by default:
  - `wp-content/uploads`
  - active theme
  - installed themes if selected
  - installed plugins if selected
  - `mu-plugins` if present
- Exclude by default:
  - cache directories
  - backup directories
  - `wp-content/wasmer-import`
  - `wp-content/wasmer-migrate`
  - `wp-content/ai1wm-backups`
  - `wp-content/wpvividbackups`
  - `wp-content/updraft`
  - `wp-content/backups-dup-lite`
  - logs and temporary files

Database export:

- Implement a PHP table-by-table SQL dump for portability.
- Prefer `mysqldump` only as an optional optimization if available and safe.
- Split database output into chunks or stream ranges to the destination.
- Include table prefix and site URL metadata in the manifest.

Transfer client:

- Parse the import code.
- Call `/connect` to validate token, expiry, protocol version, and destination limits.
- Send manifest.
- Send database stream.
- Send file streams.
- Retry transient failures with backoff.
- Resume by querying destination session status and continuing from the next missing offset or file.

## Manifest

The source plugin sends a manifest before transfer starts.

Manifest contents:

```json
{
  "version": 1,
  "source": {
    "site_url": "https://old-site.example",
    "home_url": "https://old-site.example",
    "wp_version": "6.x",
    "php_version": "8.x",
    "table_prefix": "wp_"
  },
  "database": {
    "tables": [],
    "size": 123456,
    "sha256": "..."
  },
  "files": [
    {
      "path": "wp-content/uploads/2026/01/image.jpg",
      "size": 12345,
      "mtime": 1782920000,
      "sha256": "..."
    }
  ],
  "options": {
    "include_uploads": true,
    "include_themes": true,
    "include_plugins": true,
    "include_mu_plugins": true
  }
}
```

For large sites, the first MVP can send file hashes lazily:

- Manifest contains paths and sizes first.
- Destination accepts transfer.
- Source computes hashes per file before finalizing each file.
- Destination stores final hashes in session status.

## Import Execution

Destination import steps:

1. Verify manifest exists.
2. Verify all required files and database chunks exist.
3. Verify final sizes and hashes.
4. Put site into import/maintenance mode.
5. Import database.
6. Replace source URLs with destination URLs.
7. Install/copy migrated `wp-content` files.
8. Activate migrated plugins only if selected and safe.
9. Flush rewrite rules.
10. Clear object/page caches where possible.
11. Mark session complete.
12. Schedule cleanup of transfer artifacts after a retention period.

Database import:

- Use `$wpdb` for MVP import execution, chunked by statements.
- Handle table prefix replacement deliberately.
- Preserve destination Wasmer-required options and plugin configuration.
- Do not overwrite Wasmer-specific management options blindly.

URL replacement:

- Implement serialized-safe search/replace.
- Replace `siteurl`, `home`, GUIDs only if intentionally selected.
- Preserve the destination domain and Wasmer app configuration.

File import:

- MVP should prioritize `uploads` and database.
- Add plugins/themes migration as an explicit option because plugin compatibility failures can break the destination site.
- Never replace WordPress core files. Wasmer controls core.

Rollback:

- MVP can document limited rollback and rely on pre-import platform backups if available.
- Before broad file/database replacement, create a small restore marker containing destination URL, table prefix, Wasmer options, and active plugin list.
- Later versions should integrate with Wasmer platform snapshots.

## UI Requirements

Both pages should feel first-party and Wasmer branded.

Destination page:

- Clear stepper.
- Copyable import code.
- Expiry indicator.
- Regenerate/revoke controls.
- Live status polling.
- Progress bars for connection, manifest, database, files, verification, and import.
- Advanced log drawer.

Source page:

- Paste-code field.
- Destination preview after validation.
- Content selection controls.
- Transfer progress with file/database counters.
- Retry and cancel controls.
- Link back to destination after transfer completes.

Implementation:

- Use scoped CSS classes, for example `.wasmer-import-*` and `.wasmer-migrate-*`.
- Use WordPress admin components and conventions where practical.
- Avoid global CSS leakage.
- Do not expose secrets in UI logs.

## WP-CLI

Add WP-CLI commands for support and automation.

Destination:

```bash
wp wasmer import session create --expires=8h
wp wasmer import session list
wp wasmer import session status <session>
wp wasmer import session revoke <session>
wp wasmer import start <session>
wp wasmer import cleanup --older-than=7d
```

Source:

```bash
wp wasmer-migrate connect '<import-code>'
wp wasmer-migrate plan
wp wasmer-migrate start
wp wasmer-migrate status
wp wasmer-migrate resume
wp wasmer-migrate cancel
```

These commands should share the same service classes as the UI.

## Security

Required controls:

- Admin-only session creation.
- Short-lived import codes.
- Token hashes at rest.
- HTTPS required except local development.
- Strict path normalization.
- Session-scoped writes only.
- Maximum chunk size.
- Maximum total migration size, configurable.
- Rate limiting per session and source IP where feasible.
- Redacted logs.
- Cancel/revoke support.
- Cleanup of expired sessions.

Do not ask for old-site credentials in the MVP. The plugin-to-plugin flow avoids storing third-party admin credentials and is easier to explain.

A future assisted migration flow can accept old-site credentials once, install the source plugin automatically, exchange them for a short-lived migration token, and discard the credentials immediately.

## Error Handling And Resume

Session status should be durable and explicit.

Suggested states:

```text
created
connected
manifest_received
transferring
transfer_complete
verifying
verified
importing
complete
failed
cancelled
expired
```

Chunk errors:

- Hash mismatch: reject chunk and request retry.
- Offset mismatch: return expected offset.
- Duplicate chunk: accept if existing data hash matches.
- Session expired: stop transfer and require a new code.
- Destination full/unwritable: fail with actionable admin error.

Source resume:

- Query destination status.
- Re-send manifest if needed.
- Continue each stream from the destination-reported offset.
- Retry only bounded times per chunk before surfacing the error.

## Testing

Unit tests:

- Import-code generation and parsing.
- Expiry validation.
- Token hash validation.
- Request signature validation if HMAC is implemented.
- Path traversal rejection.
- Chunk offset handling.
- Duplicate chunk handling.
- Hash mismatch handling.
- Manifest validation.
- State transitions.
- Exclusion rules for scanner.

Integration-style tests with mocked WordPress boundaries:

- Destination session creation.
- Destination chunk write to a temporary `wp-content` fixture directory.
- Source connect/manifest/chunk flow against mocked HTTP responses.
- Database dump chunking.
- Import preserving Wasmer options.

Manual/E2E cases:

- Small site: database plus uploads.
- Large uploads directory.
- Interrupted transfer and resume.
- Expired import code.
- Wrong token.
- Duplicate chunks.
- Low `max_execution_time`.
- Missing write permissions.
- Destination disk full simulation where possible.
- Plugin/theme migration disabled.
- Plugin/theme migration enabled with incompatible plugin.

## Implementation Order

1. Re-download and inspect reference plugins, saving the exact versions inspected.
2. Locate the real local `wp-wasmer` repository and confirm current plugin structure.
3. Add destination session, token, and storage helpers.
4. Add destination REST route skeletons.
5. Add destination admin import page and dashboard panel.
6. Implement destination `/session` and `/connect`.
7. Scaffold `wasmer-migrate` plugin.
8. Implement source import-code parser and connection validation.
9. Implement manifest generation for database and uploads.
10. Implement destination manifest storage and validation.
11. Implement raw/chunked destination writes under `wp-content/wasmer-import`.
12. Implement source chunk transfer with resume state.
13. Implement destination verification.
14. Implement database import MVP.
15. Implement uploads import MVP.
16. Add progress polling and UI status updates.
17. Add cancel, revoke, expiry, and cleanup.
18. Add WP-CLI commands.
19. Add tests around token, path, chunk, manifest, and state handling.
20. Run manual end-to-end migrations on local WordPress fixtures.

## MVP Scope

Ship first:

- Destination import page in `wp-wasmer`.
- Hideable dashboard panel.
- Import-code generation.
- Source `Wasmer Migrate` plugin.
- Source paste-code flow.
- Database export and import.
- Uploads migration.
- Chunked POST transfer directly into `wp-content`.
- Resume and retry for transfer.
- Basic progress UI.
- Cleanup and revoke.

Defer:

- Full plugin/theme migration by default.
- WordPress core replacement.
- Browser upload of archive files.
- All-in-One `.wpress` compatibility.
- Duplicator installer compatibility.
- Updraft backup-set compatibility.
- Assisted migration using old-site credentials.
- Platform snapshot integration.

## Open Questions

- Should `wasmer-migrate` be distributed through WordPress.org, directly from Wasmer, or both?
- What maximum import size should be allowed per app plan?
- Should the destination app require an explicit platform backup before import?
- Should plugins/themes be opt-in in MVP or deferred entirely?
- Which Wasmer options must be preserved during database import?
- Should the transfer protocol use raw binary chunks or JSON/base64 chunks for the first release?
- Should import execution start automatically after transfer, or require a final destination-admin click?

## Recommendation

Build the Wasmer-native plugin-to-plugin flow and use WPvivid as the UX/protocol inspiration. Do not depend on All-in-One, Duplicator Lite, or UpdraftPlus for the primary product path.

This gives Wasmer the best Edge-compatible behavior:

- no PHP multipart upload on the destination;
- direct writes to `wp-content`;
- resumable transfer;
- first-party Wasmer UI;
- no need to collect old-site credentials;
- room for later compatibility with `.wpress`, Duplicator, or Updraft backup formats.
