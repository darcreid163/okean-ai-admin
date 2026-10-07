WP AI Admin 2.1.10 — browser connection
No PowerShell, application password or manual token copying: start at https://wpaiadmin.ru/wpai-cloud/connect, enter your site, log in there as administrator and confirm. Requires central Cloud Core 0.3.5. Revoke paired credentials in WP AI Admin → Подключения к gateway. Existing native/manual OAuth credentials and guarded writes are preserved.

WP AI Admin 2.1.0 — Commercial Beta Candidate
Commercial beta release profile: human approval default, direct live writes disabled, generic QA defaults, build-bound AI/Test automation, upgrade-safe OAuth/site/installation identity.
Release gates and limitations: COMMERCIAL-BETA-CANDIDATE.md

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
2.0.58: verification-only signed Control Plane entitlement contract and cryptographic negative tests; no remote license enforcement yet; 90 MCP tools, 51 self-tests.
2.0.59: Elementor drafts use one authoritative source, source-page layout cloning and exact SHA-guarded fragment edits; 91 MCP tools and 53 self-tests.

2.0.60: preserves all 2.0.59 Elementor single-source/source_post_id/SHA-fragment protections and fixes Test Automation scope inheritance: an Elementor draft created and tagged in the active test session is automatically added to the session allowlist, so later widget/settings/fragment patches on that same draft can be tagged and lifecycle-tested without recreating the page.

2.1.6: Build-reset permissions are disabled before managed fixture cleanup. Cleanup is deferred until wp_loaded and listener exceptions are contained. Added failing-baseline upgrade regression and real WordPress/WooCommerce/Elementor/Yoast bootstrap CI. The reported production fatal error remains unconfirmed without its PHP stack trace.

2.1.7: Option snapshots use native top-level scalar storage strings while preserving nested array types. Numeric sanitizers no longer reject safe baselines or produce false verify conflicts across requests. Real WordPress cross-request apply/rollback and conflict checks added. Regression aliases preserve SKIP when a fixture is outside the active test allowlist. Unsafe legacy option baselines remain blocked.

2.1.8: Yoast option maps may change insertion order when logo caches are rebuilt. Option comparisons now ignore map insertion order while preserving every key, value, nested type and list order. Real Yoast normalization, no-op, apply/verify/rollback, cold-request verification and dropped-legacy-field protection are covered by WordPress CI.

2.1.9: Woodmart base settings adapter (4 MCP tools). Native MCP 1.0.1 normalization/update, transactional full-option/preset conflicts, exact raw/absence rollback, CSS invalidation. Credentials/code are excluded. Headers/layouts are next phase. CI uses actual native MCP and minimal theme fixture; full licensed-theme/visual validation remains installation-site work.

