# WP AI Admin — Public Release Readiness

Status: **IN PROGRESS**  
Baseline frozen on 2026-10-07: native **2.1.14**, Cloud Core **0.3.6**  
Current public-release candidate: native **2.1.15**, Cloud Core **0.3.7**  
Canonical public MCP: **https://wpaiadmin.ru/mcp**

Work qualification update, 2026-10-12: isolated SQLite/MySQL 8.0 and real WordPress 7.0.6/7.1.3 install, upgrade, reactivation, native lifecycle, OAuth, isolation, quota, schema and gateway gates have executed successfully. Native lease/recovery races found during qualification were reproduced and fixed with real WordPress regressions. Syntax was checked on PHP 8.2/8.3/8.4; full database/WordPress execution used PHP 8.3. Chromium navigation remains unqualified in Work because local UNIX socket creation is denied. GitHub-hosted green workflows and actual external ChatGPT/two-owner/two-site live acceptance remain separate outstanding gates. See the committed Work qualification report for exact scope, artifact provenance and exclusions; this is not public-release approval.

This document is the release-gate checklist for moving WP AI Admin from approved-user beta toward public publication. It does not change the connection contract. Any connection-affecting change must still follow `docs/CHATGPT-CONNECTION-CONTRACT.md`.

## Release principle

Public release is blocked by safety, isolation, reproducibility, onboarding or rollback failures. New feature count is secondary until all P0 gates are closed.

## P0 — must pass before public publication

### 1. Live mutation lifecycle acceptance — BLOCKED / pending

Run one real central-gateway mutation against a disposable draft or test object:

1. read baseline;
2. create sandbox operation;
3. inspect diff;
4. approve;
5. apply;
6. verify authoritative state;
7. fetch receipt/proof;
8. rollback;
9. verify rollback;
10. confirm original state restored exactly.

Required evidence:
- central account/client used;
- native/core versions;
- site registration id (sanitized);
- operation id;
- verification result;
- receipt/proof presence and integrity;
- rollback result;
- final baseline comparison;
- no secrets in logs.

Do not mark this gate passed from unit/integration tests alone.

### 2. Multi-Tenant Isolation Gate — AUTOMATED COVERAGE STRONG / real two-account acceptance pending

Validate with two independently owned central accounts when the second tester is available. Until then, keep automated two-owner fixture coverage mandatory.

Account A must be unable to:
- enumerate Account B sites;
- read Account B site catalog;
- call any tool on Account B site;
- substitute or tamper with `site_id`;
- use a revoked site registration;
- use an expired/revoked/replayed OAuth token;
- reuse a refresh token after rotation;
- reuse another owner’s idempotency key to obtain data or mutate state;
- fetch another owner’s operations, receipts, proof or logs;
- exceed granted OAuth scopes;
- regain a site through an old grant after disconnect/reconnect;
- transfer a site registration between owners without explicit transfer flow.

Required result: deny-by-default on every boundary, with stable sanitized errors and no cross-tenant metadata leakage.

### 3. OAuth / MCP public-client gate — AUTOMATED COVERAGE STRONG / clean external client acceptance pending

Confirm from a clean external ChatGPT account:
- DCR succeeds;
- authorization-code + PKCE S256 succeeds;
- exact resource audience is enforced;
- explicit site selection is required;
- unselected site access is denied;
- refresh rotation works;
- revoke works;
- reconnect does not silently expand old grants;
- `tools/list` / site catalog remains stable enough for the client;
- central endpoint works without manual WordPress passwords, copied site tokens or provider API keys.

### 4. Release CI gate — BLOCKED by GitHub-hosted runner allocation

Runner diagnosis as of 2026-10-08:
- a dedicated minimal `Runner Smoke` workflow (allocate `ubuntu-latest` → echo → checkout → `php -v`) repeatedly fails before execution with `steps=[]`;
- re-running the failed smoke is accepted by GitHub but again fails with zero steps;
- GitHub public status currently reports Actions operational, so this is tracked as an account/repository-level hosted-runner allocation/billing/budget/policy blocker until disproved;
- product test failures cannot be evaluated while no runner is allocated.

The following workflows must be green from the exact release commit:
- `.github/workflows/validate.yml`
- `.github/workflows/wp-ai-cloud.yml`

Required:
- PHP 8.2 / 8.3 / 8.4 syntax;
- WordPress integration;
- WooCommerce / Elementor / Yoast integration baseline;
- SQLite and MySQL;
- batch mutex concurrency;
- OAuth schema upgrade;
- browser pairing;
- real Chromium cross-origin navigation regression;
- multisite-account tests;
- ZIP integrity.

No release from a commit with skipped required checks or a package produced from different source bytes.

### 5. Release artifact provenance — AUTOMATED GATE IMPLEMENTED / execution pending runner

For native and Cloud Core packages:
- version is unique and immutable;
- source commit is recorded;
- SHA-256 is recorded;
- ZIP contents are deterministic enough to reproduce and audit;
- package version/header/build-signature agree;
- no development-only secrets, fixtures or test credentials ship;
- public download instructions point to the exact approved artifacts.

### 6. Secrets and logging gate — AUTOMATED GATE IMPLEMENTED / execution pending runner

Before publication:
- scan repository and release artifacts for credentials/tokens/private keys;
- verify OAuth codes, PKCE verifiers, bearer tokens and full callback query strings are never logged;
- verify sanitized errors do not expose account/site secrets;
- verify debug/test switches are disabled by default;
- confirm AI Lifecycle Control and Test Automation remain explicit opt-in and reset on build change.

### 7. Destructive-operation safety gate — AUTOMATED COVERAGE STRONG / live acceptance pending

Production defaults:
- direct live writes OFF;
- human approval ON;
- destructive actions require the normal lifecycle or an explicit administrator-controlled bypass mode;
- stale expected_version fails closed;
- uncertain write outcome is reconciled before retry;
- interrupted Apply/Rollback is reconciled against authoritative before/after state before any replay;
- concurrent lifecycle transitions are single-winner atomic (Approve/Reject/Expiry/Apply/Rollback);
- rollback must reject stale/conflicting state instead of overwriting newer changes.

### 8. Public onboarding gate — DOCUMENTED / clean-user walkthrough pending

A new user must be able to complete, from documentation alone:
1. install native plugin;
2. enable MCP intentionally;
3. create/sign into central account;
4. connect one WordPress site through browser consent;
5. add https://wpaiadmin.ru/mcp to ChatGPT;
6. authorize selected site(s);
7. list sites;
8. perform a read;
9. create a safe sandbox operation;
10. revoke access.

No PowerShell, SSH, Application Password or manual token copying in the normal path.

### 9. Privacy / legal / commercial disclosures — BLOCKED / product decision

Before general-public enrollment, publish and link:
- Privacy Policy;
- Terms of Service;
- security contact / vulnerability reporting policy;
- data-retention statement;
- subprocessors/hosting disclosure where applicable;
- pricing/quota rules;
- refund/cancellation rules if paid plans are enabled;
- explicit statement of what data traverses Cloud Core;
- deletion/account closure procedure.

`SECURITY.md`, `CHANGELOG.md`, public onboarding guidance and a release-candidate data-retention/uninstall policy are now present. Root LICENSE is still intentionally absent: do not choose a software license implicitly; license selection remains a product/legal decision. Privacy Policy, Terms, subprocessors/hosting disclosure, account deletion procedure and paid-plan refund/cancellation terms are still pending.

### 10. Billing and entitlement truthfulness gate — BETA TRUTHFULNESS AUTOMATED / paid commercial enforcement pending

Current contract says public enrollment, automatic payment activation, commercial entitlement enforcement and batch receipt accounting are unfinished. Public UI/docs must not imply completion until implemented and tested.

For a free/public beta, clearly label:
- beta status;
- usage limits;
- supported providers;
- support expectations;
- known exclusions.

For paid public release, complete billing, entitlement, quota and receipt accounting before charging customers.

## P1 — immediately after P0 is green

These are competitive-gap tasks, but none may delay security/isolation fixes.

### Capability Gateway v1
Introduce a compact discovery/execution layer:
- `wpai.discover`
- `wpai.run`

Keep existing tools for backward compatibility. The goal is smaller MCP context, better tool selection and lower client latency.

### Connection Doctor
Create deterministic checks for:
- HTTPS;
- public DNS;
- OAuth discovery;
- protected-resource metadata;
- DCR;
- token exchange;
- MCP transport;
- tools/list;
- site catalog;
- WAF/Cloudflare/header stripping;
- canonical URL mismatches.

Return stable remediation codes without exposing secrets.

### Integration Registry
Detect installed integrations and expose only applicable capability families:
- WordPress core;
- WooCommerce;
- Elementor;
- Yoast;
- ACF;
- Woodmart;
- future builders/plugins.

### WooCommerce P1
Add/finish guarded support for:
- orders;
- coupons;
- refunds;
- customers;
- shipping;
- taxes;
- store reports.

### Elementor V4 depth
Add schema-driven support for:
- Atomic elements;
- global classes;
- variables/design tokens;
- version-aware widget/control discovery.

### WP-CLI compatibility layer
Map a safe subset of familiar WP-CLI syntax to internal bounded capabilities. Never provide arbitrary shell execution.

### Session-level rollback
Allow the administrator to revert all compatible AI mutations from one logical session with conflict checks and verification.

### Safe plugin/theme updater
Checkpoint → update → health check → verify → automatic/manual rollback.

### Fleet jobs
Run one approved operation across explicitly selected owned sites, with:
- per-site authorization;
- bounded concurrency;
- per-site result;
- receipt;
- resumability;
- no cross-tenant state.

## Public-release exit criteria

A release candidate is publishable only when:
- every P0 technical gate is PASS or explicitly declared out-of-scope with public beta wording;
- live mutation → verify → receipt/proof → rollback is recorded;
- real two-account isolation is recorded;
- exact release commit CI is green;
- public onboarding is tested from a clean user account;
- privacy/security/legal links exist;
- release artifact hashes and source correspondence are recorded;
- native and Cloud ZIP builds are reproducible across runner executions using commit-derived timestamps and stable file ordering;
- docs describe only capabilities actually enabled in production.

## Current next actions

1. Resolve GitHub-hosted runner allocation blocker. First account-level checks: Actions usage/minutes, Actions budget/spending limit, valid payment method if quota is exhausted, and repository/account Actions policy. Keep `.github/workflows/runner-smoke.yml` until a real runner executes its steps.
2. Once runner executes, run the exact **2.1.15 / Cloud Core 0.3.7** commit through Validate, Cloud Core checks, Public Release Sandbox and Runner Smoke; fix only real test failures from logs.
3. Run the final live mutation lifecycle acceptance against a dedicated disposable WordPress site.
4. Run real two-account isolation and a clean external ChatGPT OAuth/onboarding walkthrough.
5. Publish/approve Privacy Policy, Terms, subprocessors/data-flow disclosure and permanent deletion process. Revoke-first account suspension is now implemented; destructive data erasure remains intentionally pending retention-policy approval.
6. Make the explicit software-license/repository-visibility decision separately.
7. If public beta is free, keep billing/commercial entitlement/batch accounting claims disabled. Paid launch remains blocked until billing/entitlement implementation is complete.

## Automated coverage added on the 2.1.15 release-candidate branch

- tenant isolation and canonical URL ownership;
- token TTL/revoke/disconnect/multi-site grants;
- OAuth PKCE/refresh/replay/concurrency/privacy;
- quota/idempotency/month rollover/reconciliation;
- sealed credential and reconciliation-evidence tamper resistance;
- JSON-RPC/MCP protocol and HTTP transport boundaries;
- SSRF/DNS/IP/canonical-home boundaries;
- signed Ed25519 compatibility foundation proof;
- apply/rollback conflict and expired sandbox safety;
- human approval and MCP-toggle safety;
- plugin deactivation safety reset;
- native operation cleanup and quota-event retention;
- public onboarding contract;
- reproducible native/Cloud ZIPs, SHA-256 provenance and payload secret scans;
- SECURITY/changelog/data-retention release metadata;
- public health/initialize metadata secret-leak checks;
- atomic lifecycle transition/race coverage, including authoritative post-claim conflict rechecks for Apply/Rollback;
- crash-safe interrupted Apply/Rollback reconciliation with no blind replay;
- 2.1.13 → 2.1.15 upgrade preserves identity/OAuth/license/pending operation evidence while resetting automation;
- cross-run deterministic ZIP metadata/order;
- canonical release builder shared by install/upgrade/provenance jobs;
- real Cloud Core 0.3.6 → 0.3.7 state-preserving ZIP upgrade;
- revoke-first central account suspension and closure preview;
- gateway schema fail-fast checks for incomplete tables and non-InnoDB MySQL;
- revoke/suspension process races for bearer mint, OAuth consent, OAuth exchange and already-dispatched tool calls;
- guarded operator account reactivation with fresh reconnect/OAuth requirement;
- historical upgrade ZIP blob/size pinning;
- provisioning/OAuth/MCP request-size enforcement and namespace-wide no-store/nosniff response hardening;
- real HTTP OAuth discovery metadata/header checks.

