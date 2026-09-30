# WP AI Admin 2.0.71 — Lifecycle conflict replay + Options sandbox

## BUG-UJ-002
A stale Elementor operation correctly became apply_failed/conflict, but a replayed apply surfaced "Operation must be approved".

Acceptance:
1. Create two sandbox edits from the same Elementor baseline.
2. Apply the newer edit.
3. Approve/apply the stale edit.
4. Stale operation becomes apply_failed with reason=conflict.
5. Repeating apply returns oai_conflict again with replayed=true and never rewrites live content.

## BUG-UJ-003
Capabilities advertised option_update but no executable public MCP sandbox tool existed.

Acceptance:
1. wp.option_update_sandbox is exported.
2. Tool count = 96.
3. Self-test = 61/61.
4. Create test option oai_test_user_journey via sandbox only.
5. No live option change before apply.
6. Approve/apply/verify updates only that option.
7. Rollback deletes it when it did not exist before.
8. Test Automation rejects non-test option lifecycle outside allowlist.
9. Existing Elementor/Gutenberg/Woo/files regression remains green.
