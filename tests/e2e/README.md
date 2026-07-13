# WordPress Migration E2E

This suite runs a full plugin-to-plugin migration through the normal REST protocol.

The topology intentionally uses two served WordPress sites:

- `source`: old WordPress site, served by Apache, with `wasmer-migrate` active.
- `target`: new WordPress site, served by Apache, with `wp-wasmer` active.
- `source-cli` and `target-cli`: WP-CLI sidecars that trigger setup and migration commands.
- `db`: shared MySQL server with separate source and target databases.

The source and target use separate WordPress volumes and separate databases. The source transfer path is:

```text
source WP-CLI -> source WordPress runtime -> HTTP REST -> target WordPress webserver -> target wp-content/wasmer-import
```

No migration data is copied directly between containers by the test harness.

The source site is published at `http://localhost:8080` and the target at `http://localhost:8082` by default. Set `SOURCE_PORT` or `TARGET_PORT` to use different host ports.

Run locally:

```bash
tests/e2e/run-migration-e2e.sh
```

Run against another WordPress/PHP image pair:

```bash
WP_VERSION=6.7.2 PHP_VERSION=8.2 tests/e2e/run-migration-e2e.sh
```

Keep containers and volumes after a failure for inspection:

```bash
WP_WASMER_E2E_KEEP=1 tests/e2e/run-migration-e2e.sh
```

The test seeds the source with deterministic posts, a page, an option marker, and upload files. It then creates a target import session, connects from the source plugin, exports, transfers chunks to the target REST API, starts the target import, and validates target content plus uploaded file hashes.

CI runs this through the `migration-e2e` job in `.github/workflows/test.yml`.

Future Wasmer runtime coverage can reuse the same seed and validation scripts with WordPress apps served by packages built through `../shipit`. That should remain a separate backend for this E2E flow; the invariant is that both WordPress sites are web-served and source-to-target transfer uses the public REST protocol.
