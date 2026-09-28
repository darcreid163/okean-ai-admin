2.0.35 lifecycle verification fix (2026-09-28): internal post-write verification is allowed during applying/rolling_back while external pending/applying verification remains preflight; authoritative post scalar NULL is normalized to empty string for WordPress-equivalent verification.
AI Admin 2.0.13
Universal Execution Core + WP-CLI CommandRegistry + Gutenberg Resolver + MCP.
Авторские права Андриянов Н.Г.
Direct live writes remain disabled; write commands produce plans requiring sandbox/approval.
Execution input tokenizer replaced with a deterministic quote-aware parser; no regex tokenization dependency.


2.0.13: WP-CLI Registry/Execution audit, DB query hardening, Gutenberg patch ambiguity and round-trip validation.


2.0.34 audit hardening (2026-09-28): secret option redaction, no-op rejection, lifecycle-aware verification/preflight proof semantics, immutable stored execution proof, Gutenberg semantic comparison, builder detection fixes, empty-field content patches, strict WooCommerce validation.

2.0.36: Added safe elementor.patch_widget for existing Elementor HTML widgets. Exact old_html snapshot is required; operation uses the existing sandbox/preview/approval/apply/verify/rollback lifecycle.

2.0.37 Tool Catalog + Control Plane/Batch foundation
- Modernized MCP aliases for WordPress, Elementor, WooCommerce, files, operations, preview/verify/proof.
- Legacy tool names retained for backward compatibility.
- Added WooCommerce product search by text/SKU and strict sandbox update contract.
- Added system.manifest with stable site identity and control-plane/multi-site/batch capability metadata.
- Added batch.plan (plan-only, max 100 items; no live writes) for future mass and multi-site orchestration.
- Added file.read/file.diff/file.write_sandbox and elementor.patch_settings surfaces.
- Human approval remains local and mandatory; direct_live_writes remains false.

2.0.39: fixed read_content parameter mapping/pagination, file scope symlink checks, human reject guard and batch plan classification.

2.0.40: bounded outline/range/field reads for files, Elementor, HTML and WooCommerce, plus a wp-admin content navigation switch.
2.0.41: MCP Application Password scope fix; paginated field-projected WooCommerce REST reads while preserving legacy calls.
2.0.42: MCP post detail projections respect JSON false values; runtime self-test checks omitted fields.

2.0.43: WordPress Filesystem file deletion fix and guarded human retry for applied rollback failures.

2.0.44: command reads redact sensitive options, restrict database diagnostics to approved table counts and reject extra arguments/separators.
2.0.45: redact nested option/theme-mod secrets and command metadata values; paginate media, users and comments; reject invalid WooCommerce prices at sandbox and apply; add Gutenberg outline reads.
2.0.46: short-lived admin-enabled scoped Test Automation Mode lets the same MCP client approve/apply/reject/rollback only session-tagged fixture operations; all normal human approval and sandbox/conflict/verify/rollback guards remain default.

2.0.47: sequential Regression Runner, WooCommerce runtime normalization, nested test-fixture directory creation and runtime verification against normalized applied_data.
2.0.48: managed Gutenberg draft fixture, explicit gutenberg.get_outline, preview-aware lifecycle scenarios and system.regression_matrix with per-tool PASS/FAIL/SKIP/BLOCKED coverage.
2.0.49: created Elementor page rollback treats verified deletion as PASS using dual absence checks; rollback retry is idempotent for already-deleted test pages; rollback-terminal verify semantics, cache refresh and managed Gutenberg fixture newlines repaired.


## 2.0.50 — Persistent QA history, coverage and safe fuzzing
- Added `system.regression_history`: bounded persisted history of the last 50 regression/fuzz runs without raw content or secrets.
- Added `system.coverage_report`: accumulated per-tool and per-stage PASS/FAIL/SKIP/BLOCKED counters.
- Added `system.security_fuzz`: negative-only, no-live-write checks for file traversal/scope, command metacharacters, DB grammar, Woo validation, option redaction and localhost SSRF.
- Regression runner/matrix now record compact history. The matrix exercises the new QA tools as part of its exported-surface coverage.


## 2.0.51 — 400-action Test Automation + reusable Gutenberg fixture
- Test Automation session budget increased from 200 to 400 lifecycle actions.
- Gutenberg regression fixture is now reusable: an existing managed draft/private fixture is reset to baseline and reused; a new fixture is created only when none exists.
- On disable, expiry, or build change the managed fixture is kept as a draft and reset to baseline rather than deleted.
- Human approval remains the default outside an active scoped Test Automation session.

2.0.52: Test Automation budget 1000 actions; admin action queue capped at 50 plus 50-per-page complete operation history; safe same-site GET-only Universal REST resolver; Woo variations and PII-minimized order summaries; dependency-aware batch preflight; regression/fuzz coverage for new tools.
2.0.53: Test Automation budget 100000 lifecycle actions; Universal REST blocks /wp/v2/users* and keeps wp.list_users as the minimized user surface; MCP operation ledger gains page/per_page/status pagination; durable resumable batch jobs add create/list/status/resume/retry/cancel/rollback with sandbox-only write children and human approval preserved.

2.0.54: hardened batch retry state machine; batch.pause; optimistic version/checkpoint tracking; 60-second resume lease; batch.dry_run; batch.receipt and batch.proof; direct live writes remain disabled.

2.0.55: batch status/list/receipt reconcile terminal child-operation states on read; mutations persist reconciled states at checkpoint; strict retry regression now verifies reject, receipt, retry and cancel; 90 MCP tools.

2.0.56: database-scoped MySQL named lock serializes batch mutations; two-process CI contention test; 90 MCP tools and 49 self-tests.
2.0.57: OAuth schema migration gate skips repeated dbDelta on normal requests, retries incomplete migrations, checks table presence and preserves registered clients; 90 MCP tools and 50 self-tests.
