# WordPress Migration Plugin Research

Downloaded on 2026-07-01 from `downloads.wordpress.org/plugin/<slug>.latest-stable.zip`.

Inspected package versions:

- All-in-One WP Migration and Backup: 7.106
- WPvivid Backup Plugin: 0.9.129
- Duplicator: 1.5.16.1
- UpdraftPlus - Backup/Restore: 1.26.5
- Modular Connector: 3.0.2
- Migrate Guru - Site Migration & Cloning: 6.28
- Hostinger Tools: 3.0.70

Implementation follows the first-party plugin-to-plugin flow from `WP-MIGRATE.md`: destination sessions, short-lived import codes, direct chunk writes under `wp-content/wasmer-import`, and a separate source-site `Wasmer Migrate` plugin.
