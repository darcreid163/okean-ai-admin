

## 2.0.38 — Self-Test + Security/Control Plane foundation
- Product name updated to WP AI Admin.
- system.tool_catalog exposes the canonical expected MCP tool IDs and catalog hash.
- system.self_test performs safe read-only runtime/identity/database/catalog/integration checks without mutations.
- system.security_status exposes installation identity and the Control Plane security contract without exposing secrets.
- system.manifest schema v2 includes expected tool IDs for client-vs-server export audits.
- batch.plan rejects unknown tools and distinguishes read-only vs human-approved child operations.
- Control Plane contract reserves short-lived signed tokens, site/workspace binding, replay protection, revocation and freeze controls. Remote cryptographic enforcement is not claimed until a Control Plane is configured.

## 2.0.39 — Audit fixes
- Map `wp.read_content` post_type/per_page/page to REST type/limit/page and return pagination metadata.
- Reject absolute file paths and enforce canonical scope checks for new files and recursive searches; skip symlinks in listings.
- Guard REST rejection with the same human context as approval/apply/rollback.
- Make `system.self_test` probe the human approval guard instead of reporting a constant PASS.
- Classify read items correctly in `batch.plan`.

## 2.0.40 — Content navigation
- `wp.file.outline` lists bounded file landmarks and line numbers; `file.read` accepts an optional bounded line range and expected SHA-256.
- Elementor widget reads support `view=outline` and `view=html_range` with a content hash. Existing full reads remain available.
- HTML inspector supports `mode=outline` and bounded selector output; fixed #id lookup.
- WooCommerce search supports pagination and field selection.
- Experimental `content_navigation` flag can be switched in wp-admin; disabling it leaves legacy full reads available.

## 2.0.41 — MCP Basic auth and WooCommerce REST pagination
- Administrator Application Password sessions now carry `mcp:read` and `mcp:write` scopes, matching the existing MCP Basic authentication policy. Sandbox and wp-admin human approval remain mandatory for writes.
- `/read/products` delegates requests with `page`, `fields`, `query`, or `sku` to the bounded WooCommerce search; old calls without these parameters retain their original response.

## 2.0.42 — Boolean projection fix
- `wp.read_post` now respects JSON boolean `false` for content, Elementor data and inline CSS. The self-test checks the omitted fields on an existing page when one is available.

## 2.0.43 — File rollback repair
- Use the WordPress Filesystem `delete()` method for file rollback and workspace restore, checking the result.
- Allow a human administrator to retry rollback after a failed attempt only when the operation was applied; the live-state conflict check still protects subsequent edits.
- Retain the 2.0.42 boolean projection fix; runtime validation is required after installation.

## 2.0.44 — Command read hardening
- `option get` uses the same secret redaction policy as the options API.
- `db query` only accepts `SELECT COUNT(*) [AS alias] FROM table` for approved WordPress tables; arbitrary SELECT is blocked.
- Reject command separators/metacharacters and unexpected positional arguments for read commands.
- Add read-only self-test probes for option redaction, command shape and database query scope.

## 2.0.45 — Sensitive reads and list pagination
- Redact known license and key-hash options, sensitive nested option fields, and command metadata values in `post get` and `cron event list`.
- Add `page`, `total`, `total_pages` and `has_more` for media, users and comments, with explicit MCP input schemas.
- Add read-only self-test checks for redaction and command projections.
- Reject negative product prices and a sale price above the regular price before sandbox creation.
- Revalidate WooCommerce payloads before apply, including operations created by older builds; redact theme mods and never return a truncated raw value sample.
- Gutenberg reads accept `view=outline` to return paths, attribute names, sizes and hashes without repeating large HTML and raw content. Legacy full reads remain available.


## 2.0.46 — Scoped Test Automation Mode
- Human approval remains the default. A WordPress administrator may temporarily enable Test Automation Mode for 15, 30, 60 or 120 minutes.
- The session is pinned to the current build, administrator, MCP client and a generated session ID; it automatically becomes inactive on expiry or build change.
- Only sandbox operations created through the same MCP client while the session is active are tagged for automated lifecycle actions. Existing pending operations are never adopted automatically.
- MCP `operation.approve`, `operation.apply`, `operation.reject` and `operation.rollback` remain blocked outside the active scoped test session. Inside it, the normal sandbox snapshot, expiry, conflict detection, Woo validation, double verification, proof/receipt and rollback guards still execute.
- Existing post/content and Elementor operations are eligible only for configured test post/page IDs or draft/private posts whose title contains TEST/ТЕСТ. WooCommerce operations require an explicit product allowlist.
- Automated file lifecycle is limited to `child_theme`/`theme` paths under `okean-core/wp-ai-admin-tests/`. Automated option writes are limited to `oai_test_*` and `wp_ai_admin_test_*`. Plugin/theme/user arbitrary write actions are not enabled.
- `system.test_automation_status` exposes the active session policy without secrets. `system.security_status` reports whether the temporary exception is active.
- The wp-admin dashboard adds enable/disable controls, TTL, fixture allowlists and a visible warning while automation is active. Turning it off immediately restores the original human-only lifecycle behavior.
- Added read-only self-test contract checks for the automation boundary.

## 2.0.47 — Sequential Regression Runner
- Added `system.regression_run`, available only inside an active scoped Test Automation session. It executes one lifecycle scenario at a time and stops immediately if cleanup cannot be proven.
- Regression scenarios cover content, WooCommerce stock normalization, Elementor HTML widgets, scoped test files, optional Gutenberg fixtures and a reject-only guard. Each live mutation follows sandbox → approve → apply → verify → proof/receipt → rollback → rollback verify before the next scenario starts.
- WooCommerce sandbox snapshots now predict the stock-status transition caused by a stock quantity change, and Apply accepts only runtime differences that are valid consequences of the explicitly requested Woo fields.
- Verification uses `applied_data` when WooCommerce normalized the runtime snapshot, so later Verify/rollback checks compare against the state that was actually persisted.
- File sandbox writes may create missing intermediate directories only after the full path passes the scoped no-traversal/no-symlink write resolver. This enables `okean-core/wp-ai-admin-tests/` fixtures without weakening ordinary file scopes.
- `system.test_automation_status` exposes the last compact regression result. The normal human-approval model remains the default and the runner is unavailable when Test Automation Mode is inactive.


## 2.0.48 — Extended Regression Suite
- Added a managed Gutenberg draft fixture that is created when Test Automation Mode is enabled, auto-allowlisted for the session, and removed when the administrator disables the mode.
- Added explicit `gutenberg.get_outline` so compact Gutenberg reads are visible as a first-class MCP tool even on clients that do not refresh optional schema properties reliably.
- Extended `system.regression_run` with Elementor settings and Elementor draft-create lifecycle scenarios; supported preview types are checked while the operation is still pending.
- Added `system.regression_matrix`, which combines bounded read checks, pagination/redaction/command guards, file/Elementor/Gutenberg/Woo reads, schema/export checks, sequential reversible mutation runs, preview/proof/receipt checks and a per-tool `PASS/FAIL/SKIP/BLOCKED` matrix.
- The matrix reuses the same scoped Test Automation session and normal sandbox lifecycle; it cannot run when Test Automation is inactive and still stops mutation sequencing if cleanup cannot be proven.
- Expected MCP catalog with Content Navigation enabled: 71 tools.

## 2.0.49 — Created-object rollback verification repair
- Fixed `elementor_page_create` rollback verification: permanent absence of the AI-created page is now the expected successful rollback state instead of a false `404` failure.
- Rollback deletion verification uses both WordPress object lookup and a direct posts-table existence check after cache cleanup (`dual_absence`) so stale cache cannot hide a surviving page or misreport successful cleanup.
- Retrying rollback for an already-deleted AI-created Elementor draft is idempotent: expected absence is accepted and the operation can complete verification instead of being blocked as a post-apply conflict.
- External verification of `rolled_back` / `rollback_failed` operations now evaluates the rollback target state by default rather than incorrectly re-checking the former applied state.
- Corrected cache refresh for `elementor_page_create` to use the created preview page ID rather than parsing the `elementor_page:<id>` target prefix as a numeric post ID.
- Managed Gutenberg fixtures now store real newline separators in newly created test sessions.
- Successful regression scenarios expose `cleanup_effective=true` and the cleanup verification source when available.
- Added read-only self-test checks for created-page rollback absence semantics and managed Gutenberg fixture newline construction.
- Expected MCP catalog remains 71 tools with Content Navigation enabled.



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

## 2.0.52 — Next-stage read foundations, 1000-action Test Automation and paginated operation ledger
- Test Automation session budget increased from 400 to 1000 lifecycle actions.
- wp-admin operation UI now has a dedicated action queue showing up to 50 pending/approved operations and a complete paginated ledger with 50 operations per page, so older operations remain accessible.
- Added `wp.rest_routes` and `wp.rest_read`: same-site registered GET-only REST discovery and bounded redacted reads. External URLs, traversal and sensitive/internal routes are blocked.
- Added privacy-safe WooCommerce deep reads: `woocommerce.get_variations` and `woocommerce.search_orders`; order results omit customer PII.
- Added `batch.preflight`: dependency graph validation/topological ordering for up to 100 steps without executing child operations.
- Regression Matrix covers the new read tools; Security Fuzz adds REST external/traversal/sensitive-route negative cases.

## 2.0.53 — Resumable Batch Executor, 100000-action Test Automation, REST user hardening
- Test Automation session budget increased from 1000 to 100000 lifecycle actions. TTL, administrator ownership, MCP-client binding, session tagging and fixture allowlists remain unchanged.
- Universal REST now blocks the complete `/wp/v2/users` route family. User reads must use the dedicated minimized `wp.list_users` surface; application-password and other sensitive routes remain blocked.
- Added real MCP pagination to `wp.list_operations`: `page`, `per_page` (1–100) and optional status filtering, while preserving the wp-admin 50-row action queue and 50-row paginated full ledger.
- Added the durable resumable batch foundation: `batch.create`, `batch.list`, `batch.status`, `batch.resume`, `batch.retry`, `batch.cancel`, `batch.rollback`.
- Batch dependency order is validated before persistence. Jobs retain up to 50 recent entries, up to 100 steps per job, a bounded 512 KiB input payload and bounded per-step result summaries.
- Read steps execute immediately in bounded chunks. Write steps never perform direct live writes: they create normal sandbox child operations and the batch pauses for the existing approval/apply lifecycle.
- A resumed batch reconciles child-operation status before continuing. Failed steps can be retried up to five times without duplicating an active child operation.
- Batch cancellation does not silently reject or apply child operations. Existing child operations remain visible in the normal operation ledger.
- Batch rollback reconciles applied child operations in reverse dependency order. Outside Test Automation it returns the exact operation IDs requiring wp-admin rollback confirmation; inside scoped Test Automation it may use only the existing session-tagged rollback exception.
- Regression Matrix covers the new batch job lifecycle and MCP operation pagination. Security Fuzz adds collection and single-user Universal REST blocks.
- Expected MCP catalog with Content Navigation enabled: 86 tools.

## 2.0.54 — Hardened Batch State Machine, Pause, Checkpoints, Receipts and Proof
- Preserves the 100000-action scoped Test Automation budget and all 2.0.53 REST privacy/pagination protections.
- Fixes the runtime-discovered batch retry edge case: `batch.retry` is blocked while any child operation in the job remains unresolved.
- Adds `batch.pause` to stop future scheduling without cancelling steps or mutating child operations.
- Adds optimistic batch `version` checks, bounded 60-second resume leases, checkpoint sequencing and up to 100 bounded job events.
- Adds `batch.dry_run` for executor support, dependency depth, payload sizing and expected sandbox pause-point estimates without creating a job.
- Adds `batch.receipt` and `batch.proof` for batch-level execution evidence and SHA-256 proof bound to site/install/build context.
- Existing safety remains unchanged: batch write steps create sandbox child operations only; direct live writes stay disabled and normal human approval remains the default.

## 2.0.55 — Batch child state reconciliation
- Batch status, list and receipt now read the current child-operation state and recalculate summaries without mutating stored checkpoints during reads. A cancelled batch whose child is later rejected reports that step as failed, with no stale waiting-approval count.
- Pause, retry, cancel and rollback persist reconciled child states in their next checkpoint. The strict retry Regression Matrix case checks rejection, current status/receipt, terminal-child retry and subsequent cancel.
- MCP catalog remains 90 tools and self-test remains 49 checks. The direct ChatGPT `batch.proof` routing issue was observed outside the plugin; server tool export and REST invocation are present.

## 2.0.56 — Atomic Batch Mutation Mutex
- Uses a database-scoped MySQL named lock around create, resume, pause, retry, cancel and rollback. A competing request receives 409 before reading or changing a job; an unavailable lock fails closed with 503. The 60-second lease remains as job checkpoint metadata.
- Adds a MySQL integration test with two independent PHP processes and CI for PHP 8.2–8.4 lint, ZIP integrity and mutex contention.
- The MCP catalog remains 90 tools; the self-test remains 49 checks.
