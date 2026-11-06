# WP AI Admin — Open Beta audit, 06.11.2026

Status: **IN PROGRESS** (baseline verified, register being completed)
Baseline commit: `54c4767` (`#128`), updated to `14bf09e` (`#129`) — docs-only after `#128`.
Native: **2.1.17** (`OAI_DB_VERSION=24`, `OAI_MCP_VERSION=2025-06-18`).
Cloud Core: **0.3.13-dev** (Gateway schema **4**, derived from recovered 0.3.11).

This document is the deliverable required by `docs/OPEN-BETA-RELEASE-MISSION.md` §13 and issue
[#130](https://github.com/darcreid163/okean-ai-admin/issues/130). It records what was verified from
code and CI, not from earlier reports.

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
| P0-2 | Tariffs | Three conflicting tariff definitions exist. | Mission §4: Free 100/mo, Pro 2000/mo, Business 5000/mo, BusinessPro 10000/mo. `wp-ai-admin-cloud/config/plans.json`: free 100, pro 500, biznes 2000, biznespro 5000. `includes/class-oai-quota.php:15-20`: free 100/day, daily 500/day, pro 2000/day, business 5000/day. `src/QuotaPolicy.php:19-21` enforces free=month, paid=day. | Charged limits do not match the approved product table; plan names (`biznes`/`business`) differ. Needs an owner decision before any test can assert a tariff. |
| P0-3 | Billing proof | No automated test proves “a failed/p denied operation is not charged end-to-end through the Gateway”. Unit coverage exists for settlement math, but not for the real permission-denied/validation-error path. | `wp-ai-admin-cloud/src/Core.php:267-269` charges on `!isset($reply['error']) && isError===false`; Native returns business failures as content with **no `isError` flag** (`includes/class-oai-mcp.php:607`). | Charging for errors is not provable; mission rule 4 is unverified. |

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

### P2

| ID | Area | Defect | Evidence | Impact |
|---|---|---|---|---|
| P2-1 | Billing robustness | Native MCP never sets `isError:true` for non-`WP_Error` business failures; Cloud trusts `isError`. | `includes/class-oai-mcp.php:606-607`, `src/Core.php:267`. | Any future array-shaped failure is billed as success. Harden and test. |
| P2-2 | Billing consistency | Native plan keys (`free/daily/pro/business`) and Cloud keys (`free/pro/biznes/biznespro`) differ, with different periods. | `includes/class-oai-quota.php:15-20`, `config/plans.json`. | Migration/entitlement mapping ambiguity. |
| P2-3 | Observability | No Native REST endpoint exposes plan/usage, so no client or dashboard can reconcile against the server. | No `quota`/`usage` route in `includes/class-oai-rest.php`. | Cannot build reconciliation or support tooling. |
| P2-4 | Request limits | Native MCP `handle()` has no request-body size limit (Cloud enforces 128 KiB). | `includes/class-oai-mcp.php:71-74`. | Memory/abuse risk on a public endpoint. |
| P2-5 | Info disclosure | `GET /okean-ai/mcp` returns license plan/expiry without authentication (route permission is `__return_true`; auth only inside POST `handle`). | `includes/class-oai-mcp.php:10-13,54`. | Minor disclosure of commercial state. |

### P3

| ID | Area | Defect | Evidence |
|---|---|---|---|
| P3-1 | Docs | Duplicate/competing release documents (`MIGRATION-STATUS.md`, `docs/RELEASE-STATE-2026-10-21.md`, `docs/POST-MERGE-2026-10-22.md`) still carry superseded SHA/version claims. | `MIGRATION-STATUS.md:3-9` |
| P3-2 | Build | `OAI_BUILD_ID` is a hand-written date string that will not change with the next commit. | `okean-ai-admin.php:13` |

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

1. **One tariff table.** Decide the authoritative numbers (owner decision, P0-2), put them in one
   versioned source, and make Native fetch/label them rather than hard-code a second table.
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
   - period rollover: free at UTC month boundary, paid at UTC day boundary.
4. **Honesty gate.** Remove “Лимит тарифа ChatGPT не передаётся плагину” only when (3) passes; until
   then label the card “локальный счётчик, не тариф аккаунта”.

## 6. Release engineering findings

- Native ZIP: `dist/wp-ai-admin-<version>.zip` + `.sha256`, built in `build-artifact`, uploaded as an
  Actions artifact, published only on an exact `v<version>` tag via `tools/native-release.sh`
  (`validate` enforces tag == header == constant; `publish` refuses to overwrite an existing release).
- Cloud ZIP: deterministic (stored, sorted, commit-time), with `PROVENANCE.json` and an allowlist
  (`wp-ai-admin-cloud/tools/build-candidate.py`, `artifact-allowlist.php`).
- **Gap:** no job installs either ZIP into a clean WordPress and activates it. Add a `package-install`
  job that unzips the exact artifact, activates it on pinned + latest WordPress, asserts plugin
  version/DB schema and runs a read-only smoke.

## 7. Prioritized backlog

| Order | Issue | Scope | Depends on |
|---|---|---|---|
| 1 | owner decision | Approve one tariff table (P0-2) | — |
| 2 | new | Server usage projection + dashboard reconciliation (P0-1) | 1 |
| 3 | new | End-to-end “no charge for errors” + boundary/parallel/timeout tests (P0-3) | 2 |
| 4 | new | Install-the-artifact CI job + Native provenance build (P1-1, P1-6) | — |
| 5 | new | Declare and test minimum versions (P1-3), fix plugin/readme metadata (P1-7) | — |
| 6 | #124 | Transport/idempotency against Gateway ledger | 2, 3 |
| 7 | #123 | Operator reconciliation and retention | 3 |
| 8 | new | Woodmart CI enablement (owner secret) (P1-4) | owner secret |
| 9 | new | Elementor Pro matrix (P1-5) | license/CI capacity |
| 10 | new | Scheduled regression + weekly beta snapshot (P1-2) | 4 |

## 8. What this audit did not verify

- Live ChatGPT OAuth expiry without `offline_access` (#92) — needs a real client.
- A production-like stand (TLS, proxy, object cache, GD+Imagick together) — Actions cannot reproduce it.
- Two real accounts and a private backup/restore drill.
- Woodmart behaviour (no licensed source available in CI).
