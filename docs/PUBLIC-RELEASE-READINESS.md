# WP AI Admin — Public Release Readiness

Status: **IN PROGRESS**
Current candidate (unreleased): native **2.1.17** (DB24), Cloud Core **0.3.13-dev** (Gateway schema 4, derived from the recovered 0.3.11 baseline).
Canonical public MCP: **https://wpaiadmin.ru/mcp**

This document is the release-gate checklist for moving WP AI Admin from approved-user beta toward public publication. It does not change the connection contract. Any connection-affecting change must still follow `docs/CHATGPT-CONNECTION-CONTRACT.md`.

Exact current commits, CI runs and installed-build hashes are tracked in issue [#40](https://github.com/darcreid163/okean-ai-admin/issues/40). The roadmap to closed beta is `docs/RELEASE-PLAN-2026-11-05.md`.

## Release principle

Public release is blocked by safety, isolation, reproducibility, onboarding or rollback failures. New feature count is secondary until all P0 gates are closed.

## P0 — must pass before public publication

### 1. Live mutation lifecycle acceptance — BLOCKED / staging pending

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

The automated suites cover the same lifecycle on disposable WordPress for content, Gutenberg, Elementor, WooCommerce, media, menu and files. Do not mark this gate passed from automated tests alone: it requires the real gateway, the external MCP client and the production-like stand.

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

### 3. OAuth / MCP public-client gate — AUTOMATED COVERAGE STRONG / clean external client and real expiry pending

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

Real access-token expiry and re-authorization in ChatGPT (#92) require owner action with a live client. Automated fixtures alone do not close this gate.

### 4. Release CI gate — AUTOMATED GREEN / exact release commit still to be frozen

The current workflows are:
- `.github/workflows/native-validate.yml`
- `.github/workflows/cloud-validate.yml`

They run on the exact commit and include:
- PHP 8.2 / 8.3 / 8.4 syntax and contract tests;
- WordPress smoke with pinned (WordPress 7.0.6, WooCommerce 4.3.3, Elementor 11.1.2, GD) and latest (Imagick) profiles;
- WooCommerce / Elementor / Gutenberg integration;
- WordPress upgrade and paired code/database/uploads restore;
- creation-claim concurrency and read-only claim inventory;
- classic (non-block) theme acceptance, including nav-menu locations and theme file scopes;
- browser pairing contracts;
- Cloud contracts (OAuth isolation, Gateway Ledger, Batch, transport) and Cloud artifact checks;
- deterministic Native-only and Cloud-only build artifacts.

The historical GitHub-hosted runner allocation blocker is resolved: exact-SHA runs execute normally. The `release` job is intentionally skipped on pull requests and is not evidence of publication.

No release from a commit with skipped required checks or a package produced from different source bytes.

### 5. Release artifact provenance — AUTOMATED GATE IMPLEMENTED / final artifacts not frozen

For native and Cloud Core packages:
- version is unique and immutable;
- source commit is recorded;
- SHA-256 is recorded;
- ZIP contents are deterministic enough to reproduce and audit;
- package version/header/build-signature agree;
- no development-only secrets, fixtures or test credentials ship;
- public download instructions point to the exact approved artifacts.

The Native archive must contain only Native files. Cloud Core is exported separately and is ultimately maintained in the private `wp-ai-admin-cloud-core` repository. No final tagged release exists yet.

### 6. Secrets and logging gate — AUTOMATED GATE IMPLEMENTED / final scan pending

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
- rollback must reject stale/conflicting state instead of overwriting newer changes;
- create operations use a strict single-winner SQL admission claim; legacy or unresolved claims are never released by age or by a missing reply.

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

`SECURITY.md`, `docs/DATA-FLOW.md`, `docs/DATA-RETENTION.md`, `docs/PROOF-SEMANTICS.md`, public onboarding guidance and the release plan are present. Root LICENSE is still intentionally absent: do not choose a software license implicitly; license selection remains a product/legal decision. Privacy Policy, Terms, subprocessors/hosting disclosure, permanent account deletion procedure and paid-plan refund/cancellation terms are still pending.

### 10. Billing and entitlement truthfulness gate — BETA TRUTHFULNESS AUTOMATED / paid commercial enforcement pending

Current contract says public enrollment, automatic payment activation, commercial entitlement enforcement and batch receipt accounting are unfinished. Public UI/docs must not imply completion until implemented and tested.

The Gateway Ledger is the sole quota/charging authority. For a free/public beta, clearly label:
- beta status;
- usage limits;
- supported providers;
- support expectations;
- known exclusions.

For paid public release, complete billing, entitlement, quota and receipt accounting before charging customers (#32).

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

### WooCommerce depth
Current release covers product search/read, variations, price/stock sandbox edits, draft creation and order search. Add/finish guarded support for:
- coupons;
- refunds;
- shipping;
- taxes;
- store reports;
- order mutations where the write model is closed and verified.

### Elementor depth
Current release covers structure/widget reads, settings, HTML fragments, containers, layout and draft creation through the registered adapter. Add schema-driven support for:
- Atomic elements;
- global classes;
- variables/design tokens;
- version-aware widget/control discovery.

The staged `OAI_Elementor_Schema_Save` module exists in main but is deliberately not wired to any user-callable route; wiring requires the integrated lifecycle work described in `docs/WPVIBE-ELEMENTOR-STAGE1.md`.

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

1. Finish the closed-beta plan in `docs/RELEASE-PLAN-2026-11-05.md`: creation-claim remainder, QA regression classification and operator reconciliation.
2. Raise a production-like stand (PHP 8.3, MySQL 8, GD and Imagick, real plugin/theme versions, TLS/proxy/cache, production DB drop-in) and install the exact candidate.
3. Run the final live mutation lifecycle, classic-theme/Elementor/WooCommerce/media/menu/file acceptance, and real two-account isolation there.
4. Run a clean external ChatGPT OAuth/onboarding walkthrough, including real token expiry (#92).
5. Take a private agreed backup of code, database, configuration/keys and uploads; restore on the stand preserving identity, OAuth, quota and pending evidence.
6. Publish/approve Privacy Policy, Terms, subprocessors/data-flow disclosure and permanent deletion process. Revoke-first account suspension is implemented; destructive data erasure remains pending retention-policy approval.
7. Make the explicit software-license/repository-visibility decision separately.
8. Keep billing, commercial entitlement and Batch accounting disabled for the beta. Paid launch (#32) remains a separate project.

## Automated coverage present on main

- creation admission: strict single-winner SQL claims for workspace, menu, menu item, WooCommerce draft product, Elementor page and media; concurrency workers; read-only privileged claim inventory;
- lifecycle: sandbox → approve → apply → authoritative Verify → receipt/proof → rollback → Verify, including stale/conflict/expiry guards and crash-safe lease recovery;
- content/Gutenberg/Elementor/WooCommerce/media/menu/files real-WordPress regressions, including classic-theme surface;
- media byte/MIME/extension/decode validation and bounded image limits;
- one risk classification policy shared by catalog and creators;
- proof/receipt path projection and historical evidence cleanup;
- WordPress upgrade 23→24, paired code/database/uploads restore and identity preservation;
- tenant isolation, canonical URL ownership, token TTL/revoke/disconnect/multi-site grants;
- OAuth PKCE/refresh/replay/concurrency/privacy and HTTP discovery metadata;
- Gateway Ledger quota/idempotency/month rollover/reconciliation; Batch single-charge semantics;
- sealed credential and reconciliation-evidence tamper resistance;
- JSON-RPC/MCP protocol and HTTP transport boundaries, request-size and no-store/nosniff hardening;
- SSRF/DNS/IP/canonical-home boundaries;
- Ed25519 Foundation compatibility proof;
- deactivation safety reset and automation-mode reset on build change;
- deterministic Native/Cloud artifacts, SHA-256 provenance and payload secret scans;
- public health/initialize metadata secret-leak checks.
