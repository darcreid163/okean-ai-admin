# WP AI Admin 2.1.4 — Elementor create schema fix
Date: 2026-10-01. Source: installed 2.1.3-integer-stock-rounding-20261001.
## Reproduced defect
The connected client exported elementor.create_draft data as array<string> because the server's array schema omitted items. Passing a serialized container failed with "Each Elementor element must be an object" before draft persistence.
## Change
- Explicit root/child object schemas for Elementor creation trees.
- Decode cached clients' serialized JSON root nodes before normalization/persistence; reject malformed JSON, scalar and list nodes.
- Preserve nested HTML/settings and the existing builder save, single-source, lifecycle and rollback guards.
- Update both build metadata files (the prior files still named an older 2.1.3 build and 111 tools).
## Live evidence on 2.1.3 (not evidence of installed 2.1.4)
- self-test: 78 PASS / 0 FAIL.
- security fuzz: 220 PASS / 0 FAIL, run fz_20261001_130121_693e18b9.
- 112 tools in current manifest/catalog.
- Isolated hidden draft product 37857, create operation 1669: 0.5 -> 1, approve/apply/verify passed.
- Operations 1670–1673: 43.26 -> 43; 50.7 -> 51; 75.88 -> 76; 0.49 -> 0/outofstock. Sandbox did not change product; apply/read-back/verify and rollback/verify passed; full projected product restored.
- No-op 0.5 -> existing 1 rejected without an operation.
- Two competing snapshots 1674/1675: stale application blocked; 1674 rollback verified. 1675 is terminal apply_failed, preserving conflict evidence.
- Duplicate apply/rollback refused with state errors (no repeated mutation; not an idempotent success response).
- Pending rejection 1676 worked; apply of rejected operation blocked; product unchanged.
- Create 1669 rolled back; absence verified and SKU search returned zero matches. No temporary product remains.
- Product operation visual preview unsupported; preview_link supports content/Elementor widget operations only.
## Existing CI checked
Run 36859574064 completed real steps: PHP 8.2/8.3/8.4 lint, entitlement, Elementor, stock rounding, ZIP integrity, MySQL concurrent mutex and OAuth upgrade/client retention all succeeded. This closes the previously reported pre-step CI infrastructure blocker for 2.1.3.
## Candidate gate
2.1.4 requires its own CI and installed live create/precise-patch/rollback retest. Full regression runner/matrix was not rerun in this session because scoped Test Automation was disabled; AI Lifecycle opt-in permitted isolated manual lifecycle checks. Do not claim full 2.1.4 release readiness before installation and read-back.
