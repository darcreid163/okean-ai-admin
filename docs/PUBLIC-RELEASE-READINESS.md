# WP AI Admin — Public Release Readiness

Status: **IN PROGRESS**  
Baseline frozen on 2026-10-07: native **2.1.14**, Cloud Core **0.3.6**  
Canonical public MCP: **https://wpaiadmin.ru/mcp**

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

### 2. Multi-Tenant Isolation Gate — BLOCKED / pending

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

### 3. OAuth / MCP public-client gate

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

### 4. Release CI gate

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

### 5. Release artifact provenance

For native and Cloud Core packages:
- version is unique and immutable;
- source commit is recorded;
- SHA-256 is recorded;
- ZIP contents are deterministic enough to reproduce and audit;
- package version/header/build-signature agree;
- no development-only secrets, fixtures or test credentials ship;
- public download instructions point to the exact approved artifacts.

### 6. Secrets and logging gate

Before publication:
- scan repository and release artifacts for credentials/tokens/private keys;
- verify OAuth codes, PKCE verifiers, bearer tokens and full callback query strings are never logged;
- verify sanitized errors do not expose account/site secrets;
- verify debug/test switches are disabled by default;
- confirm AI Lifecycle Control and Test Automation remain explicit opt-in and reset on build change.

### 7. Destructive-operation safety gate

Production defaults:
- direct live writes OFF;
- human approval ON;
- destructive actions require the normal lifecycle or an explicit administrator-controlled bypass mode;
- stale expected_version fails closed;
- uncertain write outcome is reconciled before retry;
- rollback must reject stale/conflicting state instead of overwriting newer changes.

### 8. Public onboarding gate

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

Repository currently has no root LICENSE or SECURITY.md. Do not choose a software license implicitly; license selection is a product/legal decision.

### 10. Billing and entitlement truthfulness gate

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
- docs describe only capabilities actually enabled in production.

## Current next actions

1. Run automated isolation regression on current main baseline.
2. Run the real live mutation lifecycle acceptance.
3. Recruit/use one external tester account for the real Multi-Tenant Isolation Gate.
4. Add a public-release workflow/report that records release commit, versions, checks and artifact hashes.
5. Draft SECURITY.md and public privacy/data-flow documentation.
6. Decide public-beta licensing and repository visibility separately from software-runtime release.
