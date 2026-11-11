# WP AI Admin — Open Beta audit, 06.11.2026

Status: **COMPLETE for stage 1** (audit deliverable); follow-up fixes are separate issues.
Baseline commit: `54c4767` (`#128`), updated to `14bf09e` (`#129`) — docs-only after `#128`.
Native: **2.1.17** (`OAI_DB_VERSION=24`, `OAI_MCP_VERSION=2025-06-18`).
Cloud Core: **0.3.13-dev** (Gateway schema **4**, derived from recovered 0.3.11).

This document is the deliverable required by `docs/OPEN-BETA-RELEASE-MISSION.md` §13 and issue
[#130](https://github.com/darcreid163/okean-ai-admin/issues/130). It records what was verified from
code and CI, not from earlier reports. The WordPress-core/content, WooCommerce, billing, Cloud
transport and packaging tracks were audited independently and cross-checked against the code by the
release lead; every finding below cites a file and line that was read during this audit.

## 1. Verified baseline

| Item | Value | Evidence |
|---|---|---|
| `main` | `14bf09e` | `git log`, GitHub API |
| Native version | 2.1.17 | `okean-ai-admin.php:5,12` |
| Native DB schema | 24 | `okean-ai-admin.php:14` |
| Cloud version | 0.3.13-dev | `wp-ai-admin-cloud/wp-ai-admin-cloud.php` |
| Gateway schema | 4 | `wp-ai-admin-cloud/src/Core.php`, `WordPress.php` |
| Release channel | `commercial_beta` / `commercial_beta_candidate` | `okean-ai-admin.php:17-19` |

CI on the last code-changing HEAD `dbb4cc3`:

- Native: https://github.com/darcreid163/okean-ai-admin/actions/runs/42166016161 — PHP 8.2/8.3/8.4, `wordpress-smoke` pinned + latest, artifact build. **PASS**
- Cloud: https://github.com/darcreid163/okean-ai-admin/actions/runs/42166016211 — contracts 8.2/8.3/8.4, browser contracts, WordPress upgrade pinned + latest, artifact. **PASS**

Manual acceptance lab on `main` (real WordPress + MySQL + Elementor + WooCommerce):

- https://github.com/darcreid163/okean-ai-admin/actions/runs/42166731453
- WordPress 7.1.4, Elementor 4.3.6, WooCommerce 11.2.2, Twenty Twenty-One, PHP 8.3
- `wordpress-creation-intent.php` 37 checks, `wordpress-creation-claim-audit.php` 97 checks,
  `wordpress-pending-intent.php` 55 checks — **PASS**

## 2. Component and dependency map

### 2.1 Native plugin (site side) — 68 classes in `includes/`

| Layer | Classes | Responsibility |
|---|---|---|
| Bootstrap / data | `class-oai-db.php`, `class-oai-platform.php`, `class-oai-errors.php` | schema 24, migrations, manifests, error contract |
| Identity / access | `class-oai-license.php`, `class-oai-gateway-pairing.php`, `class-oai-mcp-oauth.php`, `class-oai-capabilities.php`, `class-oai-permission-resolver.php`, `class-oai-rate-limit.php` | local license, site pairing, OAuth tokens, capability map |
| Surfaces | `class-oai-rest.php`, `class-oai-mcp.php`, `class-oai-admin.php`, `class-oai-surfaces.php` | REST `okean-ai/v1`, MCP `okean-ai/mcp`, wp-admin dashboard/routes |
| Intent / lifecycle | `class-oai-intent.php`, `class-oai-creation-intent.php`, `class-oai-pending-intent*`, `class-oai-operations.php`, `class-oai-lifecycle.php`, `class-oai-verify.php`, `class-oai-receipts.php`, `class-oai-proof.php`, `class-oai-audit.php`, `class-oai-authoritative.php` | sandbox → approval → apply → verify → receipt/proof → rollback |
| Execution | `class-oai-command-registry.php`, `class-oai-execution-dispatcher.php`, `class-oai-command-normalizer.php`, `class-oai-argument-parser.php` | command grammar, plan-only/write gating |
| Content adapters | `class-oai-content.php`, `class-oai-wp*.php`, `class-oai-gutenberg*.php`, `class-oai-elementor*.php`, `class-oai-builder.php`, `class-oai-woocommerce.php`, `class-oai-woodmart.php`, `class-oai-theme-mods.php` | WordPress, Gutenberg, Elementor, WooCommerce, Woodmart |
| Media / files | `class-oai-files.php`, `class-oai-workspace.php`, `class-oai-media*` (in `class-oai-p0.php`) | uploads, scopes, path boundaries |
| Quota / billing (local) | `class-oai-quota.php`, `class-oai-authoritative.php`, `class-oai-batch-executor.php` | **local** meter, reservations, batch execution |
| Read layer | `class-oai-read.php`, `class-oai-read-engine.php`, `class-oai-rest-resolver.php`, `class-oai-wp-post-resolver.php`, `class-oai-gutenberg-resolver.php` | read-only projections |
| QA / diagnostics | `class-oai-diagnostics.php`, `class-oai-visual-qa.php`, `class-oai-page-qa.php`, `class-oai-regression-*.php`, `class-oai-security-fuzz.php`, `class-oai-qa-fixtures.php`, `class-oai-test-automation.php` | self-test, regression, fixtures |

### 2.2 Cloud Core (server side) — 18 `src/` + 4 `includes/`

| Layer | Classes | Responsibility |
|---|---|---|
| Gateway | `Gateway.php`, `Core.php`, `Transport.php`, `Sse.php` | MCP surface, accounts, tokens, ledger, upstream calls |
| Billing | `QuotaPolicy.php`, `BatchAccounting.php` | plan terms, UTC windows, per-object batch settlement |
| Identity | `OAuth.php`, `Pairing.php`, `Security.php` | PKCE, site pairing, canonicalization/signing |
| Policy | `AbilitiesPolicy.php`, `MediaPolicy.php`, `Skills.php`, `Settings.php` | allowlists and account eligibility |
| Storage | `Store.php`, `Vault.php` (in `Security.php`/`Core.php`), `includes/class-wpai-cloud-db.php` | SQLite/MySQL store, sealed secrets |
| Presentation | `Admin.php`, `Surface.php` | operator cabinet and account overview |

### 2.3 Contracts and boundaries

- Native ↔ Cloud: site pairing credential (`oai_pair_…`) → Gateway `transport->rpc()` → site `/wp-json/okean-ai/mcp`.
- `quota_authority=central_gateway` is set **only** from a verified pairing credential (`includes/class-oai-mcp.php:42-43`), so a Cloud-mediated call bypasses local metering (`:98`). A direct OAuth/legacy token is metered **locally**.
- Cloud never stores tool output; receipts keep state/units/fingerprints only (`docs/PROOF-SEMANTICS.md`).

## 3. Compatibility and acceptance matrix — what actually runs

| Dimension | Executed today | Gap |
|---|---|---|
| PHP | 8.2, 8.3, 8.4 | no `Requires PHP` header, no declared minimum |
| WordPress | 7.0.6 (pinned), 7.1.4 (latest) | no `Requires at least` / `Tested up to`, no declared minimum |
| WooCommerce | 11.1.2 (pinned), 11.2.2 (latest) | no `WC requires at least` / `WC tested up to`; no HPOS on/off matrix |
| Elementor | 4.3.3 (pinned), 4.3.6 (latest) | **Elementor Pro is never tested** |
| Editors | Gutenberg block content, classic content | block themes only implicit (default theme) |
| Themes | Twenty Twenty-One (classic), default block theme | **Woodmart suite exists but never runs** (`tests/wordpress-woodmart.php` requires `WOODMART_MCP_ZIP_BASE64`) |
| Cloud | SQLite + MySQL, schema 3→4, PKCE/refresh/revoke, tenant isolation, browser pairing | no live external ChatGPT client (issue #92) |
| Upgrade | Native 2.1.x → 2.1.17 with paired code+DB restore; Cloud 0.3.11 → 0.3.13-dev | no upgrade *from the previous beta build* (no beta build exists yet) |

## 4. P0–P3 register

Severity: **P0** = data loss, security, cross-tenant access, incorrect billing/quota, broken auth or
unrecoverable upgrade; **P1** = core workflow/compatibility failure; **P2** = usability/diagnostics;
**P3** = cosmetic/documentation.

### P0

| ID | Area | Defect | Evidence | Impact |
|---|---|---|---|---|
| P0-1 | Billing / dashboard | The plugin dashboard shows a **local** Native meter, not the server ledger, and states the platform tariff is not transmitted. After pairing, local-owner mode makes it display a fixed `Free 100 / day` with `used = 0`. | `includes/class-oai-gateway-pairing.php:34-35` activates local-owner; `includes/class-oai-quota.php:29-34,53-57` maps it to `free`; `includes/class-oai-admin.php:145,163` renders it with “Лимит тарифа ChatGPT не передаётся плагину”. | Open Beta gate “dashboard reconciles with server usage” fails; owner and testers see figures that are not the ones being charged. |
| P0-2 | Tariffs | Three conflicting tariff definitions existed. **Owner decision 06.11.2026: the Native table is canonical** — `free` 100/day, `daily` 500/day, `pro` 2000/day, `business` 5000/day, no BusinessPro tier. `plans.json` and mission §4 are still the old tables. | Mission §4: Free 100/mo, Pro 2000/mo, Business 5000/mo, BusinessPro 10000/mo. `wp-ai-admin-cloud/config/plans.json:5-16`: free 100, pro 500, biznes 2000, biznespro 5000, free=month/paid=day. `includes/class-oai-quota.php:15-20`: native table. `src/QuotaPolicy.php:13-21` still validates the old key set. | Until the server policy, config and mission are rewritten to the Native table, the server charges a different limit than the product and the plugin display. Two consequences to accept explicitly: Free becomes 100/**day** (~30× the previous monthly grant) and the `BusinessPro` tier disappears. |
| P0-3 | Billing proof | No automated test proves “a failed/permission-denied operation is not charged end-to-end through the Gateway”. Unit coverage exists for settlement math, but not for the real permission-denied/validation-error path. | `wp-ai-admin-cloud/src/Core.php:267-269` charges on `!isset($reply['error']) && isError===false`; Native returns business failures as content with **no `isError` flag** (`includes/class-oai-mcp.php:607`). | Charging for errors is not provable; mission rule 4 is unverified. |
| P0-4 | Gutenberg content | `gutenberg.patch` silently destroys block structure on an ordinary content edit: `attrs` are replaced wholesale (derived/unnamed attributes are dropped, removing an attribute is only possible by omitting it from the new map) and `innerContent` collapses to a single element, so multi-part content (classic/mixed blocks, inner blocks with wrapper HTML) loses its markers. The container guard only fires when `innerBlocks` is non-empty. | `includes/class-oai-gutenberg-resolver.php:27-28`: `$node['attrs']=is_array($new_attrs)?$new_attrs:$node['attrs'];` and `$node['innerContent']=array((string)$new_html);`; guard `:28` `if(!empty($node['innerBlocks']))`. Round-trip validation `:29-30` re-serialises the already-collapsed node, so it cannot detect the loss. | Silent data loss on the most common WordPress write path, violating “preserve unknown/unsupported data”. |


### P1

| ID | Area | Defect | Evidence | Impact |
|---|---|---|---|---|
| P1-1 | Release engineering | The shipped Native ZIP is never installed or booted in CI; only `unzip -tq` runs, and the smoke suite installs from the checkout instead of the artifact. | `.github/workflows/native-validate.yml:112-128`; `tests/wordpress-native-smoke.sh:25-27` copies `okean-ai-admin.php includes starter-themes` from the workspace. | A broken artifact can be published with green CI. The artifact also drops `includes/class-oai-elementor-schema-save.php` (`native-validate.yml:121`) that is still in Git, so artifact ≠ tested tree. |
| P1-2 | Release engineering | No weekly/nightly regression and no scheduled beta snapshot. | No `schedule:` in any workflow (`.github/workflows/*.yml`). | Mission §8/§10 cadence unimplemented. |
| P1-3 | Compatibility | No declared minimum supported WordPress/WooCommerce/PHP; plugin header lacks `Requires at least`, `Requires PHP`, `Tested up to`, `WC requires at least`. | `okean-ai-admin.php:2-10`. | “Supported minimum” cannot be tested or promised; WP.org publication metadata incomplete. |
| P1-4 | Woodmart | Woodmart acceptance never runs in CI and there is no proof the theme path works. | `tests/wordpress-woodmart.php`; `tests/wordpress-ci-lab.sh:69-78` requires the owner secret. | Woodmart claim in the mission is unverified for Open Beta. |
| P1-5 | Elementor | Elementor Pro is never exercised; no free/Pro matrix. | No `elementor-pro` reference in `tests/`. | “Elementor and Elementor Pro” acceptance criterion unproven. |
| P1-6 | Packaging | Native artifact build is non-reproducible (current mtimes, no provenance) while Cloud has a deterministic builder. | `.github/workflows/native-validate.yml:112-128` vs `wp-ai-admin-cloud/tools/build-candidate.py`. | Checksums cannot be reproduced; supply-chain story is asymmetric. |
| P1-7 | Documentation | `readme.txt` is a changelog fragment, not an installable plugin readme (no stable tag, description, installation, FAQ, changelog headings). | `readme.txt:1-14`. | Testers cannot install/upgrade from the readme; WP.org-ready metadata missing. |
| P1-8 | WordPress content | **No post/page create or update path exists** on any MCP or `wordpress.execute` surface. `post create`/`post update` are registered but have no handler and no sandbox type; the only content-creation tool is `elementor.create_draft`. | `includes/class-oai-command-registry.php:23-24`; `includes/class-oai-wp-cli-execution.php:5,88` (`handler_implemented=false`); `includes/class-oai-mcp.php:383`. | Mission §5 “create/update supported posts/pages” cannot pass on a classic or block theme; agents can only patch pre-existing content. |
| P1-9 | WooCommerce | **Variation price updates always fail verification and auto-rollback.** `patch()` never recomputes the derived `price` for variations, but `runtime_matches_changes()` compares the whole `variations` array against the approved `after`. | `includes/class-oai-woocommerce.php:42,152,158,177-183`; failure → `includes/class-oai-rest.php:1283-1287`. | A mission-required workflow (variation price change) is impossible; `sale_price` always fails, `regular_price` fails unless masked by an active sale. |
| P1-10 | WooCommerce | Variation validation is far weaker than product validation: no price validation, no sale≤regular, no `stock_status`/`status` enum, no attribute shape check, comma decimals and negative prices survive into the approved snapshot. | `includes/class-oai-woocommerce.php:79-88,200,230-236`; MCP schema `includes/class-oai-mcp.php:291-292`. | Bad input fails late at `WC_Data_Exception` or produces permanent verify mismatches. |
| P1-11 | WooCommerce | **Rollback conflict check is narrower than what rollback writes.** Apply-time conflicts compare the full snapshot; rollback compares 10 fields, while `apply()` also writes variations, categories, tags, attributes, images, description, slug, featured, visibility and SEO. | Compare list `includes/class-oai-rest.php:1570-1571`; write set `includes/class-oai-woocommerce.php:204-226`. | A newer WooCommerce edit made after Apply can be silently overwritten by Rollback — the exact case the guard exists to prevent. |
| P1-12 | WooCommerce | Uncaught `WC_Data_Exception` in the variation-creation loop: the loop runs after the `try/catch` that protects product fields, with no cleanup. | `includes/class-oai-woocommerce.php:203-240` (try) vs `:276-278` (unguarded loop). | Duplicate/invalid child SKU or negative child price fatals mid-loop, leaving an orphan draft product plus a burnt idempotency claim. |
| P1-13 | WooCommerce | Mission §5 Woo surface is incomplete: no order mutation under approval, no customers, coupons, shipping/tax or store-settings tools; orders are read-only summaries. | `includes/class-oai-woocommerce.php:114-122`; no `WC_Coupon`/`WC_Customer`/`shipping_zone`/`tax_class` usage in `includes/`. | “Safe order updates only with explicit approval” and store configuration are unimplemented, not merely untested. |


### P2

| ID | Area | Defect | Evidence | Impact |
|---|---|---|---|---|
| P2-1 | Billing robustness | Native MCP never sets `isError:true` for non-`WP_Error` business failures; Cloud trusts `isError`. | `includes/class-oai-mcp.php:606-607`, `src/Core.php:267`. | Any future array-shaped failure is billed as success. Harden and test. |
| P2-2 | Billing consistency | Native plan keys (`free/daily/pro/business`) and Cloud keys (`free/pro/biznes/biznespro`) differ, with different periods. | `includes/class-oai-quota.php:15-20`, `config/plans.json`. | Migration/entitlement mapping ambiguity. |
| P2-3 | Observability | No Native REST endpoint exposes plan/usage, so no client or dashboard can reconcile against the server. | No `quota`/`usage` route in `includes/class-oai-rest.php`. | Cannot build reconciliation or support tooling. |
| P2-4 | Request limits | Native MCP `handle()` has no request-body size limit (Cloud enforces 128 KiB). | `includes/class-oai-mcp.php:71-74`. | Memory/abuse risk on a public endpoint. |
| P2-5 | Info disclosure | `GET /okean-ai/mcp` returns license plan/expiry without authentication (route permission is `__return_true`; auth only inside POST `handle`). | `includes/class-oai-mcp.php:10-13,54`. | Minor disclosure of commercial state. |
| P2-6 | Usage history reliability | One inconsistent or uncertain batch row makes the whole `cloud_usage_history` call fail with 503 instead of degrading that row. | `src/Core.php:339-341` calls `BatchAccounting::receipt()` per row; `src/BatchAccounting.php:75` throws `batch_request_unavailable`, `:64`/`:68-69` throw `batch_reservation_invalid`/`billing_receipt_invalid`. | A single bad batch hides the entire billing history exactly when support needs it. |
| P2-7 | Usage history performance | Ledger listing opens a locking transaction and unseals a receipt per batch row (N+1). | `src/Core.php:314-346`, `src/BatchAccounting.php:71-78,68`. | Slow and contended history for accounts with many batches. |
| P2-8 | Gutenberg proof | The Gutenberg rollback baseline is not byte-exact: `before` is `serialize_blocks(parse_blocks($content))`, while `content.patch` stores the raw bytes; verification then canonicalises block content before comparing, hiding the difference. | `includes/class-oai-gutenberg-resolver.php:26`; `includes/class-oai-content.php:6`; `includes/class-oai-verify.php:172-173`. | A “verified” receipt or rollback can attest a document that differs from the pre-change bytes. |
| P2-9 | Recovery evidence | `system.operations_cleanup` treats `rollback_failed` and `verification_failed` as deletable terminal states, although they are the only remaining copy of `before_data` for a change that may still be live. The tool description only warns about `applied`. | `includes/class-oai-operations-cleanup.php:7,77-78`; tool text `includes/class-oai-mcp.php:392`. | A failed rollback leaves the site diverged and cleanup then deletes the recovery evidence. |
| P2-10 | Options read | Option **reads** have no positive allowlist: `wp.list_options` accepts arbitrary `prefix`/`keys` and relies on a name-pattern redaction regex. | `includes/class-oai-read.php:114-137`, redaction `:18`. | Mission requires “options/settings with strict allowlists”; any non-matching secret option (e.g. `aws_access_key_id`, `mailchimp`) is returned in clear. |
| P2-11 | Privacy | `wp.list_comments` returns full comment body and author with no `moderate_comments`/`edit_post` check, while the CLI handler deliberately returns only `content_length`. | `includes/class-oai-read.php:154-166`; `includes/class-oai-wp-cli-execution.php:33`. | Unmoderated comment text (spam/PII) is exposed through the higher-traffic MCP path with weaker redaction. |
| P2-12 | Capability boundaries | Secondary mutation handlers do not re-check the object capability: `media.update_alt/title/set_featured` (no `upload_files`), `menu.remove_item/move_item/assign_location` (no `edit_theme_options`), `content.patch`/`gutenberg.patch` (no `edit_post`). | `includes/class-oai-p0.php:44-59,274-289`; `includes/class-oai-rest.php:1732,1786`; contrast `p0.php:91,118,172,227`. | Not exploitable today (every auth path ends at `manage_options`), but breaks the mutation invariant and blocks any future lower-privileged auth mode. |
| P2-13 | Command surface | `wordpress.execute` write commands are a dead end: the dispatcher refuses anything but read/plan, every write reports `plan_only` + `handler_implemented=false`, and no sandbox type maps to `post_create`, `plugin_activate`, `theme_activate`, `option_update` or `cache_purge`. | `includes/class-oai-execution-dispatcher.php:5`; `includes/class-oai-wp-cli-execution.php:5,88`; `includes/class-oai-mcp.php:186,365`. | Callers receive a plan with no continuation; plugin/theme activation and cache purge have no executable, approvable, verifiable path. |
| P2-14 | WooCommerce intents | The product creation claim stores only actor/created_at/state/claim — no `operation_id`/product id — so the documented operator reconciliation cannot correlate claim → product → operation (media claims do store the binding). | `includes/class-oai-woocommerce.php:267`; contrast `includes/class-oai-db.php:178,234,263`; `docs/PRODUCT-CREATION-INTENTS.md:21-29`. | Same-key retries return 409 forever, even when the operation exists; reconciliation has no pointer. |
| P2-15 | WooCommerce verify | Product Verify is self-referential: `$verification_expected` is set from the post-write read, so Verify compares a read to a read; drift against the approved `after` is logged as `runtime_normalized`, never failed. There is no authoritative WooCommerce snapshot. | `includes/class-oai-rest.php:1280-1293`; `includes/class-oai-verify.php:132-144`. | Approved-snapshot drift outside the requested `changes` keys is invisible to verification. |
| P2-16 | WooCommerce attributes | `normalize_attributes` accepts any name/options without checking `taxonomy_exists('pa_*')` or term existence. | `includes/class-oai-woocommerce.php:71-78,222-223`. | A typo silently creates a custom attribute instead of the global one, producing divergent product data. |

### P3

| ID | Area | Defect | Evidence |
|---|---|---|---|
| P3-1 | Docs | Duplicate/competing release documents (`MIGRATION-STATUS.md`, `docs/RELEASE-STATE-2026-10-21.md`, `docs/POST-MERGE-2026-10-22.md`) still carry superseded SHA/version claims. | `MIGRATION-STATUS.md:3-9` |
| P3-2 | Build | `OAI_BUILD_ID` is a hand-written date string that will not change with the next commit. | `okean-ai-admin.php:13` |
| P3-3 | Protocol drift | Three MCP protocol versions are in play: Native advertises `2025-06-18`, the Gateway transport sends `2025-03-26` upstream, and the Gateway advertises/defaults to `2025-11-25` and its own smoke test expects `2025-11-25`. | `okean-ai-admin.php:15`; `src/Transport.php:65`; `src/Gateway.php:71,125`; `src/Admin.php:141` |
| P3-4 | Tool advertising | `cloud_site_batch_call` is always listed even when server batch policy is disabled, so a client that follows the catalog gets a deterministic `batch_receipt_accounting_not_ready` (the description does warn "server opt-in is required"). | `src/Gateway.php:103-104,152`; `src/BatchAccounting.php:90`; `src/Core.php:197` (`batch_available`) |
| P3-5 | Scope model | `wp.visual_qa_start` and `system.page_qa_run` write server state (transients, probes) but are absent from `tool_requires_write`, so `mcp:read` alone is enough and the `mcp_apply` licence gate is skipped. | `includes/class-oai-mcp.php:65,345-346`; `includes/class-oai-visual-qa.php:36`. |
| P3-6 | Diagnostics | `handler_audit()` only inspects read handlers, so `execution_readiness()['registry_audit']['ok']` can report green while 8 write commands have no handler. | `includes/class-oai-command-registry.php:87-90`; `includes/class-oai-rest.php:1755`. |
| P3-7 | Licence asymmetry | Product update requires `OAI_License::can('woocommerce')`; product creation over MCP requires only `mcp_apply`. | `includes/class-oai-rest.php:1733` vs `includes/class-oai-mcp.php:88,550-553`. |
| P3-8 | Schema drift | `woocommerce.update_product_sandbox` is declared with a narrow field set and then silently overwritten later in the same file, so the first definition is dead code. | `includes/class-oai-mcp.php:213,296`. |
| P3-9 | WooCommerce variations | Variation `status` is ignored on creation (hardcoded `publish`) and unvalidated on update. | `includes/class-oai-woocommerce.php:83,235,277`. |

### 4a. WordPress core / content coverage against mission §5

| Mission item | Implemented | Tested | Notes |
|---|---|---|---|
| site/health info, diagnostics | yes | yes (redaction-level) | `wp.get_site`, `wp.health`, `wp.diagnostics` |
| list/read posts and pages | yes | **no** happy-path test | `wp.read_content`, `wp.read_post`, `wp.read_meta`, `wp.rest_read` |
| **create/update posts and pages** | **no** | no | P1-8: `post create`/`post update` are plan-only, no sandbox type |
| patch existing content | yes (sandbox) | partial (direct call, not full lifecycle) | `content.patch` bypasses approval in `tests/wordpress-classic-theme.php:100-102` |
| Gutenberg blocks | yes (sandbox) | partial | `gutenberg.patch` corrupts structure (P0-4) |
| taxonomies / terms | yes (read) | no | `wp.list_taxonomies`, `term list/get` |
| comments | yes (read) | no | PII shape untested (P2-11) |
| media upload/search/metadata | yes | yes (upload + creation suites) | alt/title/featured mutation untested |
| plugin/theme inventory | yes (read) | no | activation/update are plan-only (P2-13) |
| options/settings allowlist | write: yes; read: **no allowlist** | write only | P2-10 |
| user/role inspection | yes (read) | no | `wp.list_users` requires `list_users` |
| menus | yes (sandbox) | yes (creation + item suites) | remove/move/assign-location untested |
| REST access with restrictions | yes | partial | `wp.rest_routes`, `wp.rest_read` |

### 4b. WooCommerce coverage against mission §5

| Mission item | Implemented | Tested |
|---|---|---|
| search/list/read products | yes | yes |
| create simple + variable product | yes (draft artifact + lifecycle) | yes (creation admission/concurrency) |
| update product (price, sale, stock, status, categories, tags, images, attributes, SEO) | yes | yes (price + stock lifecycle) |
| variations: read | yes | yes |
| variations: create | inline in product creation only | creation suite |
| variations: update | **broken for price** (P1-9) | **no apply-path test** |
| variations: delete | **no** | — |
| SKU uniqueness | product: yes; variation: **uncaught fatal** (P1-12) | product only |
| orders: read | yes (summary + status allowlist) | no |
| orders: approved updates | **no** (P1-13) | — |
| customers | **no** | — |
| coupons | **no** | — |
| shipping / tax settings | **no** | — |
| store configuration | indirect via generic option write only | no |

### 4c. Missing tests confirmed by this audit

1. Any happy-path MCP read of posts, options, users, comments, media, plugins, themes, REST routes.
2. Any full lifecycle (approve → apply → verify → rollback) for `post_content` or `gutenberg_patch`;
   `tests/wordpress-classic-theme.php:100-102` calls `OAI_Content::patch` + `apply_patch` directly.
3. Block-preservation fixtures: nested blocks, `wp_block` synced patterns, unknown blocks with custom
   attributes, childless containers, multi-part `innerContent`.
4. Cleanup of `rollback_failed`/`verification_failed` rows and protection of their `before_data`.
5. Capability denial for secondary media/menu writers and for `content.patch`/`gutenberg.patch`.
6. WooCommerce variation apply/verify/rollback (`regular_price`, `sale_price`, sale clearing).
7. WooCommerce variation validation (negative/comma price, sale>regular, invalid enums, unknown keys).
8. WooCommerce duplicate child SKU → structured error, no orphan product, no burnt claim.
9. WooCommerce rollback conflict after a post-apply edit to variations/categories/images.
10. That `wordpress.execute` write commands cannot be coerced out of plan-only mode.

### 4d. Verified correct in the WordPress/WooCommerce paths (do not regress)

- Approval cannot be skipped: approve/apply/rollback/reject require a `wp_rest` nonce unless one of
  three narrow, session-scoped bypasses applies (`includes/class-oai-rest.php:1131-1136`), and the
  human-approval constant is defined only on the CSRF-protected admin page.
- Conflict guards are ordered correctly: pre-claim check, lease acquisition, atomic state claim,
  post-claim re-check, and a replayed conflict stays `oai_conflict` rather than being downgraded
  (`includes/class-oai-rest.php:1208-1218,1224-1249,1430`).
- Apply and rollback are dual-verified against persistent state, with receipt and proof
  (`rest.php:1297,1306,1396,1406`; `includes/class-oai-authoritative.php`).
- Expiry and lease handling are safe: stale snapshots are claimed atomically and a still-running
  apply/rollback cannot be “recovered” by a second request (`rest.php:1151-1180`).
- `content.patch` field surface is closed to `post_title|post_excerpt|post_content`
  (`includes/class-oai-content.php:6`), and option writes are gated by protected-core, control-plane
  prefix, credential-pattern and length checks with a re-check on apply (`rest.php:930-958,1143-1145`).
- `db query` is a SELECT-COUNT-only reader over an explicit table allowlist with no caller SQL
  interpolation (`includes/class-oai-wp-cli-execution.php:76-84`); `menu.remove_item` is a soft
  removal (`post_status=draft`, `p0.php:276`); media creation is journal-bound and protected from
  cleanup (`includes/class-oai-operations-cleanup.php:9,42-48`).
- WooCommerce sandbox planning writes nothing (`class-oai-woocommerce.php:123-161`,
  `tests/wordpress-woocommerce-lifecycle.php:47-49`); product creation uses an atomic pre-save claim
  with a committed read and unique-option admission barrier, proven by a six-worker test
  (`:249-270`, `tests/wordpress-product-creation.php:39-56`).
- Product price/stock validation, deterministic half-up stock rounding, enum allowlists, term
  resolution for categories/tags, attachment validation and variation parentage guards are real
  (`class-oai-woocommerce.php:6-25,60-70,135-152,177-183,228`; `tests/woocommerce-stock-rounding.php`).
- Order reads use a status allowlist and a privacy projection without line-item PII
  (`class-oai-woocommerce.php:117,121`).


## 5. Quota/ledger reconciliation plan

### 5.1 Verified correct (do not re-litigate)

- Cloud is the charging authority for gateway calls: reservation, settlement and sealing are transactional (`src/Core.php:221-245,280-292`).
- Charging happens **only** on success; failures settle with `charged_units = 0` (`src/Core.php:268`).
- A timeout/unknown outcome becomes `uncertain` and is **never retried automatically** (`src/Core.php:271-275`); resolution needs operator evidence (`src/BatchAccounting.php:167-178`).
- Batch charges **per successfully processed object**, reserves `n` atomically, releases never-started items, keeps dispatched-unknown items as `uncertain`, and validates the sealed receipt before reporting (`src/BatchAccounting.php:94-101,134-164`).
- `used` is per account, not per site (`src/Core.php:193-197`; `wp-ai-admin-cloud/tests/multisite-account.php:43`).
- Native does **not** double-charge gateway traffic: `quota_authority=central_gateway` comes from a verified pairing credential and disables local metering (`includes/class-oai-mcp.php:42-43,98`).
- Local Native metering is transactional, single-winner, rejects idempotency conflicts and preserves `uncertain` (`includes/class-oai-quota.php:191-239`).

### 5.2 Required reconciliation work

1. **One tariff table.** The owner decided on 06.11.2026 that the **Native table is canonical**:
   `free` 100/day, `daily` 500/day, `pro` 2000/day, `business` 5000/day, no BusinessPro tier.
   The work is therefore:
   - rewrite `wp-ai-admin-cloud/config/plans.json` to these keys, limits and `per_plan_utc` → all-day periods,
     with a policy-version bump and a documented migration of `biznes`/`biznespro` entitlements;
   - update `QuotaPolicy::validate()` key validation and `::window()`/`::terms()` to the new key set;
   - rewrite `docs/OPEN-BETA-RELEASE-MISSION.md` §4 so the product brief and the code agree;
   - keep a single machine-readable source that the plugin displays — do not hard-code a third copy.
   Two consequences are accepted by that decision and must be stated in the release notes: Free becomes
   100/day (~30× the previous monthly grant), and the BusinessPro tier no longer exists.
2. **Server usage in the plugin.** Add a signed, site-scoped usage projection:
   - Cloud: authenticated endpoint for the paired site credential returning `limit/used/reserved/remaining/period/period_end_utc/policy_version`;
   - Native: store the last projection with its signature and timestamp, display it, and show `unknown` (not a fabricated local number) when absent or stale;
   - never derive a balance locally.
3. **Reconciliation test** (new suite, both sides):
   - run N successful gateway calls with distinct and repeated nonces; assert ledger `used == N` and dashboard shows the same `used/remaining`;
   - forced permission-denied and validation-error calls: ledger unchanged, dashboard unchanged;
   - batch of 5 with 2 failures: charged 3, receipt reports 2 failed, dashboard moves by 3;
   - duplicate delivery of one nonce: charged once, second response is the cached receipt;
   - client timeout during a write: state `uncertain`, ledger does not charge, no automatic retry, operator reconciliation charges or releases exactly once;
   - parallel calls at `limit-1`: exactly one succeeds, the rest get 429, `used` ends at `limit`;
   - period rollover at the UTC day boundary for every plan.
4. **Honesty gate.** Remove “Лимит тарифа ChatGPT не передаётся плагину” only when (3) passes; until
   then label the card “локальный счётчик, не тариф аккаунта”.

## 6. Release engineering findings

- Native ZIP: `dist/wp-ai-admin-<version>.zip` + `.sha256`, built in `build-artifact`, uploaded as an
  Actions artifact, published only on an exact `v<version>` tag via `tools/native-release.sh`
  (`validate` enforces tag == header == constant; `publish` refuses to overwrite an existing release).
- Cloud ZIP: deterministic (stored, sorted, commit-time), with `PROVENANCE.json` and an allowlist
  (`wp-ai-admin-cloud/tools/build-candidate.py`, `artifact-allowlist.php`).
- **Gap:** no job installed either ZIP into a clean WordPress. Native is now addressed by
  `package-install` in PR #133 (issue #132): it downloads the artifact from the same run, verifies
  the published checksum, activates it on pinned + latest WordPress, asserts header/schema/class
  loading, the read-only status call, artifact contents and a clean reactivate.
- Still open: reproducible Native build with provenance (P1-6) and the equivalent install check for
  the Cloud candidate ZIP.

## 7. Prioritized backlog

| Order | Issue | Scope | Depends on |
|---|---|---|---|
| 1 | #134 | Align the server tariff policy, `plans.json` and mission §4 to the approved Native table (P0-2) | owner decision (done) |
| 2 | #135 | Server usage projection + dashboard reconciliation (P0-1) | #134 |
| 3 | #136 | End-to-end “no charge for errors” + boundary/parallel/timeout tests (P0-3) | #135 |
| 4 | #137 | Gutenberg block preservation: attrs merge, `innerContent` array, raw `before` bytes (P0-4, P2-8) | — |
| 5 | #132 / #133 | Install-the-artifact CI job (P1-1) | — |
| 6 | #138 | WordPress post/page create+update lifecycle (P1-8, P2-13) | #137 |
| 7 | #139 | WooCommerce variation price apply/verify (P1-9) | — |
| 8 | #141 | WooCommerce rollback conflict parity (P1-11) | #139 |
| 9 | #140 | WooCommerce variation validation + atomic creation (P1-10, P1-12) | #139 |
| 10 | #142 | WooCommerce order/customer/coupon/shipping/tax surface (P1-13) | #139 |
| 11 | #143 | Recovery-evidence protection in operations cleanup (P2-9) | — |
| 12 | new | Option read allowlist + comment privacy parity (P2-10, P2-11) | — |
| 13 | new | Capability boundaries on secondary mutations (P2-12) | — |
| 14 | #144 | Declare and test minimum versions (P1-3), fix plugin/readme metadata (P1-7) | — |
| 15 | new | Native provenance/reproducible build (P1-6) | #133 |
| 16 | new | Woodmart CI enablement (owner secret) (P1-4) | owner secret |
| 17 | new | Elementor Pro matrix (P1-5) | license/CI capacity |
| 18 | new | Scheduled regression + weekly beta snapshot (P1-2) | #133 |
| 19 | #124 | Transport/idempotency against Gateway ledger | #135, #136 |
| 20 | #123 | Operator reconciliation and retention | #136 |

## 8. What this audit did not verify

- Live ChatGPT OAuth expiry without `offline_access` (#92) — needs a real client.
- A production-like stand (TLS, proxy, object cache, GD+Imagick together) — Actions cannot reproduce it.
- Two real accounts and a private backup/restore drill.
- Woodmart behaviour (no licensed source available in CI).
- Elementor Pro behaviour (no licence in CI).
- Cloud tenant isolation under real multi-account traffic (only synthetic isolation tests were read).

## 9. Release readiness verdict

**Status: NOT READY for Open Beta.**

| Gate (mission §11) | State |
|---|---|
| Native and Cloud build and install independently from clean artifacts | Native install now covered by #133; Cloud install check missing |
| Clean install, upgrade, reconnect, reactivation and rollback tested | partial: no upgrade from a previous beta build (none exists yet) |
| All documented P0/P1 workflows pass | **fail** — P0-1…P0-4 open, P1-8…P1-13 open |
| WordPress, WooCommerce, Elementor, classic editor and Woodmart pass or have documented limits | **fail** — no post creation, Woo variation update broken, Woodmart/Pro untested |
| Server ledger authoritative, all tariff/batch/retry/concurrency/boundary tests pass | ledger design verified; **tests and tariff alignment missing** (#134, #136) |
| Dashboard reconciles with server usage | **fail** — local meter (P0-1) |
| OAuth/PKCE, isolation, capabilities, mutation approvals pass security tests | core controls verified by reading; capability and privacy gaps documented (P2-11, P2-12) |
| No known P0/P1 defects, leaked secrets, unsafe CI permissions, production data | **fail** — open P0/P1 list |
| Required checks pass on the exact candidate commit | yes for the last code HEAD, but it is not a release candidate |
| ZIP contents, versions, checksums, changelog and install instructions verified | partial (#132/#133, #144) |
| Owner receives installable artifacts and a test checklist | no — see next section |

**Next action:** #134 (tariff alignment) — it unblocks #135, #136 and every quota test, and it is the
only P0 that needs no further product decision.

## 10. Remediation log

| Issue | Status | Change | Verification |
|---|---|---|---|
| #134 | merged (`5b9f00a`) | `config/plans.json` and `QuotaPolicy` now use the canonical Native table `free` 100 / `daily` 500 / `pro` 2000 / `business` 5000, all as UTC calendar days; policy version prefix bumped to `tariffs-v3-`. Retired current-scheme slugs `biznes`/`biznespro` are migrated to `business` by an idempotent, audited helper; `legacy_monthly_v1` accounts are untouched and `wpai_gateway_db_version` stays `4`. Mission §4 rewritten. | `policy-preflight-static`, `quota-migration-v4` (alias migration, legacy preservation, idempotency), `run.php`, `multisite-account`, `compatibility`, `frontend-session.py`; CI on the PR HEAD. |
| #137 | implemented (pending merge) | `OAI_Gutenberg_Resolver::patch()` now merges `attrs` with `array_replace` and removes attributes only through an explicit `attrs_remove` list. An HTML patch replaces exactly one `innerContent` part; a container with inner blocks, or a block with several parts and no `inner_content_index`, fails closed with 422. `before` is the raw `post_content` byte string. The patch prefers a byte-exact `block_splice` of the matched block and falls back to document re-serialisation only when the source markup is not a unique substring; both paths must survive `serialize_blocks(parse_blocks($after)) === $normalized_after`. No-op patches return 409 `oai_noop`. `gutenberg.patch` exposes `attrs_remove`/`inner_content_index` (REST + both MCP schemas), records `patch_scope` and raw/normalised hashes in operation meta, and `OAI_Verify` adds a `byte_exact_post_content` check for `gutenberg_block_patch`. | `tests/wordpress-gutenberg-preservation.php` (nested blocks, non-canonical sibling preserved, third-party attr merge/removal, synced pattern, container/index guards, multi-part reflection, freeform ambiguity, arg/no-op guards, apply -> verify -> rollback byte-exact), wired into `tests/wordpress-native-smoke.sh`; CI on the PR HEAD. |
| #135 | merged (`90a5c2f`) | **Server projection, no local fallback.** Cloud: `Core::connect()` accepts an optional pairing-derived `status_hash`, sealed with the site token (`{token,status_hash}`; legacy raw tokens still open transparently). `Signer::siteUsage()` signs a `wpai-site-usage-v1` claim set (site, owner, plan, limit, used, reserved, remaining, period, period start/end, quota scheme, policy version, UTC, `issued_at/expires_at`) with the Ed25519 key and `OAI-SITE-USAGE-V1\\n` domain; `Core::siteUsage()` looks the site up by `sha256(home)`, requires an active site and a matching `sha256(status_key)`, throttles the read, and signs only that site's owner ledger projection. `Pairing::descriptor()` publishes `signing_public_key`, `signing_key_id`, `usage_projection=wpai-site-usage-v1`; `Pairing::complete()` derives the read key `HMAC-SHA256("wpai-site-status-v1|"+state, verifier)` and passes only its SHA-256 to Cloud. Public `POST /wpai-cloud/v1/site/usage` takes `{site_url,status_key}` with no WP cookie or Cloud bearer. Native: pairing stores the raw read key, signing key and projection contract in the non-autoloaded credential record; `OAI_Cloud_Usage::verify()` is a pure Ed25519/claims/freshness/balance-consistency check; `fetch()` posts to the fixed HTTPS endpoint (8 s timeout, no redirects, 16 KiB, no-store) and caches the result per credential; `status()` returns `verified`/`stale`/`unknown`/`unpaired`, never a locally derived balance. The dashboard card renders only the signed projection (remaining/limit, used/reserved, plan, period, policy) or explicitly “no confirmed server data”, plus a separately labelled local diagnostic counter and a nonce-protected refresh action. `revoke()` deletes the cached projection. | Cloud `tests/site-usage-projection.php` (SQLite+MySQL: signature, ledger reconciliation, tamper, owner/site/wrong-key/unknown-url denial, legacy registration, revoke, reconnect rotation, no credential leak); Native `tests/cloud-usage-projection.php` (signature, tamper, site/account binding, freshness/expiry/stale, remaining/period/scheme guards, dashboard states); `tests/browser-pairing.php` and `tests/wordpress-browser-pairing.php` extended for key derivation, descriptor publication, projection read and revoke cleanup; both suites wired into Native/Cloud CI. |
| #136 | merged (`a565db8`) | **One failure contract on both sides of the transport.** Native `OAI_Errors::failure()/failed()` enumerates every business-failure marker (`WP_Error`, `WP_REST_Response` >= 400, `isError`, `ok:false`, `success:false`, `http_status`/integer `status` >= 400, non-empty `error`, malformed JSON-RPC result). `OAI_MCP::tool_result()` is the single tools/call boundary and sets `isError:true` for every non-`WP_Error` business failure; `OAI_Quota::successful_result()` now uses the same contract, so the local meter cannot charge a failed tool either. Cloud `RpcReply::succeeded()` is the billing-grade decision used by `Core::call()` and `BatchAccounting`: it honours `isError`, JSON-RPC errors and legacy object-shaped content markers, while never interpreting a **list** of rows or a string entity status (`publish`) as a failed request. Failed, invalidated, oversized, uncertain and settlement-failed requests stay uncharged. | `wp-ai-admin-cloud/tests/billing-proof.php` (SQLite+MySQL): per-case expected-vs-ledger output for isError, JSON-RPC, legacy `ok:false`/`success:false`/`error`, HTTP 422/500, list and entity-status false positives, duplicate delivery, 5-object batch with 2 failures, timeout + operator reconciliation, boundary 0/1/limit-1/limit/over-limit, UTC rollover, settlement DB failure, and a MySQL-only real parallel race at `used=limit-1` via `billing-parallel-worker.php`. Native `tests/mcp-result-contract.php` (81 shape assertions) and real-WordPress `tests/wordpress-mcp-error-contract.php` (synthetic shapes + real `wp_get_site`/`wp_read_post` calls) wired into Native/Cloud CI. |
| #103 | verified already fixed (`eee6498`, `89a5e1c`, `54c4767`); acceptance extended | Duplicate pending existing-target edits are rejected atomically by `OAI_DB::insert_pending_intent()`: canonical, actor/type/target/payload fingerprint, `GET_LOCK` plus an `INSERT … SELECT … WHERE IS_USED_LOCK()=CONNECTION_ID() AND NOT EXISTS(...)` guard, so a lost lock, reconnect, outer transaction or storage error cannot insert an unguarded row. Equal canonical payloads (key order, `1` vs `1.0`, list order) collide; different payload/snapshot/actor do not; terminal/expired states admit a new intent; a duplicate returns only `oai_duplicate_pending` without the existing operation identifier, audit trail or evidence. Workspace/media/Elementor/WooCommerce creation paths use the same `OAI_Creation_Intent` + unique reservation claim. | `tests/wordpress-pending-intent.php` extended with MCP `content.patch` parity: an MCP retry of a pending REST intent is rejected with only `status`, no row/audit/content change; first MCP intent creates exactly one row without a live write; immediate retry is rejected; a different payload and a terminal-state retry are admitted; and a new six-process MCP race produces exactly one SQL winner and five duplicates, then leaves live content untouched. The worker now supports both REST and MCP transports. Wired into `tests/wordpress-native-smoke.sh`. |
