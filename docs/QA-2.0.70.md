# WP AI Admin 2.0.70 — Cleanup failed terminal statuses

Regression for BUG-UJ-001.

Acceptance:
1. Version/build = 2.0.70.
2. Catalog remains 95 tools.
3. Self-test remains 60/60.
4. operations_cleanup.contract includes failed_terminal_statuses_supported=true.
5. dry-run includes apply_failed and rollback_failed rows.
6. execute deletes those rows when selected.
7. active pending/approved/applying/rolling_back rows remain protected.
8. audit log is preserved.
