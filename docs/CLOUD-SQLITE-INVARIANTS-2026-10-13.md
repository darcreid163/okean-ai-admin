# Isolated SQLite SQL-invariant qualification — 2026-10-13

Cloud Core 0.3.8 release branch, test harness in Python sqlite3 (SQLite 3.46.1), **not actual PHP PDO Core/OAuth runtime**.

**23 PASS / 0 FAIL**. Test extracted nine SQLite CREATE TABLE definitions from packaged `wp-ai-admin-cloud/src/Store.php`; uses identical balance reservation/settlement UPDATE SQL as `Core.php`. A 12-worker concurrent workload issued 35 reservation attempts against limit 10 and confirmed exactly 10 successful reservations, 0 overdraft. All 10 resolved; 5 succeeded and charged, 5 failed and refunded. Owner B balance stayed at 0. Duplicate request primary key denied, authorization code / refresh used flags enforced once in SQL, grant revocation visible, explicit transaction rollback restored prior value.

**Not covered:** PHP PDO transaction implementation; full OAuth::exchange / PKCE / refresh replay behavior; Core::call gateway and transport; real MySQL/InnoDB under concurrency; real ChatGPT two-owner isolation; billing through actual installed Cloud. Those remain release P0.

Local PHP 8.4.24 has no pdo_sqlite or pdo_mysql drivers, and apt-get package metadata fetch timed out. Never describe this result as full end-to-end integration PASS.

Source test artifact: `sqlite_invariants.py` (Python script with identical schema definitions extracted from `Store.php`). PHP and site files remain unchanged.
