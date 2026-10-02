# Cloud Core 0.2.0 validation — 2026-10-02

Recovered source: wp-ai-admin-cloud-0.1.0.zip (6052 bytes), exported in the other chat and matching live service name, version, namespace, schema version and plan values.

Local PHP 8.3: 125 core/security/concurrency assertions PASS. 49 compatibility assertions PASS. PHP syntax: 20 files PASS before adding this document; final packaging checks all PHP files again.

The compatibility fixture executes the recovered Core installer and preserves snapshots of all 11 legacy tables, including existing account and usage balance. SQLite translates MySQL DDL only in tests. CI repeats with the original MySQL DDL on MySQL 8. Test HTTP transport is stubbed; actual public HTTPS, WordPress authentication and native plugin compatibility remain to be validated on wpaiadmin.ru after installation.

No server deployment, site content changes, OAuth release or payment activation performed by this change.

First integration CI run 37048146442 passed PHP 8.2/8.3/8.4, SQLite and MySQL 8. It covered 125 core assertions and the initial 37 compatibility assertions. The final additional HTTP adapter checks increase compatibility to 49; final commit CI is required before handing over this ZIP.
