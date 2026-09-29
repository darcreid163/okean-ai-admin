# WP AI Admin 2.0.69 — Operations Cleanup

## Goal
Start the next User Journey QA cycle with a clean operation table without deleting active work by accident.

## Tool
`system.operations_cleanup`

### Dry run
Default behavior. Returns eligible counts and sample rows. Does not delete anything.

### Execute
Set `dry_run=false`.

Default removable statuses:
- rolled_back
- rejected
- expired
- cancelled
- failed

Always protected:
- pending
- approved
- applying
- rolling_back

Applied rows are preserved unless `include_applied=true` is explicitly provided.

The security audit log is intentionally preserved.

## Acceptance
1. Version/build match 2.0.69.
2. Catalog = 95 tools.
3. Self-test = 60/60.
4. operations_cleanup.contract PASS.
5. Dry-run deletes 0 rows.
6. Active operations are never eligible.
7. include_applied=false preserves applied rows.
8. include_applied=true includes applied rows.
9. Execute count matches dry-run eligible count when ledger is unchanged.
10. Audit log remains intact.
