# WP AI Admin — Open Beta Release Mission

> **Purpose:** This document is the shared product, engineering, QA and release brief for every human and AI agent working in this repository. Read it together with the root `AGENTS.md` before changing code. If an instruction conflicts with a security or data-integrity rule in `AGENTS.md`, the stricter safety rule wins.

## 1. Mission and definition of done

Bring WP AI Admin to a **usable, installable Open Beta** that the project owner can install on real WordPress test sites and exercise end to end.

The beta must provide reliable, tested coverage of the agreed essential workflows for:

- WordPress core administration and content: site information, users/roles/capabilities (within the caller's permissions), posts, pages, comments, taxonomies, menus, settings, plugins, themes, options, media and supported REST/API operations.
- WooCommerce: products and variations, categories/tags/attributes, inventory and stock, prices, customers, orders, coupons, shipping/tax settings and store configuration, subject to explicit permission checks and safe execution policies.
- Elementor: inspect/read documents and templates, work with supported widgets/containers and global settings, preserve unknown/unsupported data, and safely create/update supported page structures without corrupting layouts.
- Classic WordPress themes and the Woodmart theme: theme-agnostic WordPress operations must work regardless of theme; theme-specific Woodmart workflows must be detected and tested separately. Do not assume that a theme exposes Elementor or a particular editor.
- The WP AI Admin plugin dashboard: connection status, selected site/account, current tariff, quota limit, used quota, remaining quota, usage history/last operation and clear explanations of charges. Displayed figures must reconcile with the authoritative server ledger.

“100%” means **100% of the explicitly documented beta acceptance tests pass** across the supported compatibility matrix. Do not claim universal support for every third-party plugin, theme, custom field, builder extension or undocumented WordPress behavior. Detect unsupported cases, explain them, preserve data and fail safely.

The beta is not ready merely because the plugin activates or an MCP endpoint responds. The complete user journey must work: connect → discover tools → execute authorized operation → receive result → record server-side usage → show the same usage in the plugin dashboard → verify/audit the outcome.

## 2. Mandatory start-of-task procedure

At the start of every task, every agent must:

1. Read root `AGENTS.md` and this document.
2. Inspect the actual repository state: current branch, clean/dirty working tree, latest `main`, recent commits, open issues/PRs, and GitHub Actions status. Never rely on an old chat summary as proof of current state.
3. Identify Native plugin and Cloud Core boundaries, versions, API/compatibility contracts, migrations and the authoritative quota implementation.
4. Search for existing implementations and tests before adding a new abstraction or duplicating functionality.
5. Write a short plan: affected components, risks, tests, rollback path and acceptance criteria.
6. Work on a focused branch/issue. Do not make unrelated refactors in a release fix.

At handoff, report changed files, commit/PR, test commands and results, known gaps, and the exact next action. Distinguish verified facts from assumptions.

## 3. Product architecture and source of truth

- Native WordPress plugin is the site-side adapter, UI and execution client. Cloud Core/Gateway owns account identity, tariff entitlements, quota policy and the authoritative usage ledger.
- The **server ledger is the only source of truth** for quota calculation and balance. The plugin must never independently calculate, reserve, increment or decrement the authoritative balance.
- The plugin dashboard displays server-returned values and the server's usage/receipt data. It may format values for presentation but must not derive a different balance locally.
- Preserve tenant/account/site isolation: one account must never read or mutate another account's sites, tools, tokens, receipts, usage or billing data.
- Preserve OAuth/PKCE, site-owner binding, signed receipts/proofs, capability checks, Approval Engine, mutation conflict guards, Verify, Rollback and idempotency protections.
- Maintain clear versioned contracts between Native and Cloud Core. Changes to request/response schemas, authentication, tool names, billing events or receipts require compatibility tests and migration notes.
- Keep Native and Cloud Core release artifacts separate. Native ZIP must not contain Cloud server code; Cloud distribution must be exported separately from its private source repository according to the existing repository policy.

## 4. Tariffs and quota accounting

Use the current approved tariff policy as the starting baseline; verify it against the actual Cloud configuration and tests before changing it:

| Tariff | Included quota |
|---|---:|
| Free | 100 actions / month |
| Pro | 2,000 actions / month |
| Business | 5,000 actions / month |
| BusinessPro | 10,000 actions / month |

All tools remain available on Free unless the owner explicitly changes this product decision. Tariffs primarily differentiate included quota. Do not introduce automatic billing or charge a payment method. Paid plan changes and payments require the user's explicit confirmation through the account cabinet. YooKassa is the planned payment provider; do not activate live payments as part of beta without separate approval.

### Accounting rules

1. Count successful billable operations on the server. A successful read operation is one action (examples: site info, list plugins, WooCommerce product search).
2. A successfully created sandbox operation with a successful server response counts according to the agreed policy; merely opening a preview or a failed request does not.
3. Batch operations are charged **per successfully processed object**. Failed/skipped objects do not count. Do not revive the obsolete “one action per five objects” formula.
4. Validation failures, permission denials, rejected operations, server errors and failed objects must not be charged.
5. Retries, duplicate delivery, client timeouts and lost responses must not double-charge. Use atomic reservation/finalization, stable idempotency keys and explicit handling of uncertain outcomes.
6. The ledger must record enough detail to audit each charge: account/site, operation/tool, request or idempotency identifier, object-level success/failure counts, tariff/policy version, timestamp, final charge and receipt/proof reference. Do not store secrets or unnecessary personal data.
7. Define and test behavior for concurrency, quota exhaustion mid-batch, partial success, rollback, retry, timeout, duplicate request, server restart and ledger/database failure. Never silently create a negative balance or an untracked successful mutation.
8. Do not use lossy telemetry or client-reported counters as billing authority. Telemetry may be diagnostic only and must never alter a balance.
9. Return a clear usage result from the server for every billable request: charged actions, successful/failed object counts where relevant, remaining quota, limit, period/reset metadata and receipt/reference when available.
10. The plugin dashboard must show server values and reconcile exactly with the ledger after refresh/reconnect. Clearly distinguish “this operation charged X” from “current period used Y / remaining Z”.

Add automated tests for every rule above, including boundary conditions at 0, 1, limit-1, exact limit and over-limit, plus parallel requests and retries.

## 5. Compatibility and functional test matrix

Create and maintain a versioned compatibility matrix in the repository. At minimum test:

- Supported WordPress versions: current stable and the declared minimum supported version.
- Supported PHP versions: every version declared in plugin metadata/documentation and CI.
- WooCommerce: current stable and the declared minimum supported version.
- Elementor and Elementor Pro: supported current versions, plus behavior when Pro is absent.
- Classic editor / block editor (Gutenberg).
- A stock WordPress classic theme and a stock block theme.
- Woodmart (with and without Elementor where the setup permits).
- Clean install, upgrade from the previous beta, reconnect, deactivation/reactivation and uninstall behavior.

For each combination, test activation, health checks, authentication, tool discovery, read operations, safe sandbox operations, approved writes, verification, receipts, quota accounting, dashboard reconciliation and graceful unsupported-feature handling.

### Required workflow coverage

- WordPress: site/health info; list/read/create/update supported posts/pages; taxonomies; media upload/search/metadata; plugin/theme inventory; options/settings with strict allowlists; user/role inspection; REST API access with capability and endpoint restrictions.
- WooCommerce: search/list/read products; create/update simple and variable products; variations; SKU uniqueness; price/stock/status; categories/attributes; orders/customers read access; safe order updates only with explicit approval; coupons and store settings where supported.
- Elementor: read and preserve existing document JSON; supported widget/container changes; template/global settings workflows; round-trip serialization; revision/backup and rollback; ensure no data loss when encountering unknown widgets, dynamic tags, custom fields or third-party extensions.
- Classic/block editor: preserve block markup, nested blocks, reusable/synced patterns, freeform HTML and unknown blocks; never flatten or discard content as a fallback.
- Woodmart: theme detection and compatibility checks; preserve theme options, header/footer builders, layouts, product templates and custom theme data. Never overwrite theme-specific settings with generic defaults.

For destructive, high-impact or ambiguous mutations use the established READ → PLAN/SANDBOX → DIFF → explicit approval → APPLY → VERIFY → ROLLBACK workflow. Keep direct live writes disabled by default. Read-only operations may execute directly when authorized.

## 6. Security, safety and reliability gates

- Enforce least privilege, WordPress capabilities, Cloud tenant isolation and site ownership on the server side; UI hiding is not authorization.
- Validate and sanitize all input at trust boundaries. Escape output. Use prepared database queries and safe filesystem paths.
- Protect secrets, OAuth tokens, API keys, payment credentials, signed keys, backups, customer data and production data. Never commit them to Git, logs, fixtures or CI artifacts.
- Prevent SSRF, arbitrary file read/write, command injection, SQL injection, privilege escalation, replay, cross-tenant access, CSRF and unsafe deserialization.
- Restrict WP-CLI and filesystem tools with explicit allowlists, path boundaries, timeouts and audit records. Never expose unrestricted shell execution to an agent.
- Make operations idempotent where possible; handle race conditions and partial failures explicitly.
- Do not weaken security, approval, verification, proof, rollback or isolation controls just to make tests pass.
- Tests must use synthetic fixtures and isolated disposable environments. Never run destructive tests against production or a real customer site.

## 7. Agent roles, skills and tools

Agents should work as a coordinated release team. One lead/release agent owns the plan, dependency ordering, integration and final acceptance; specialist agents work in isolated branches or clearly separated tasks.

### Recommended agent roles

1. **Release Lead / Architect** — owns scope, architecture, task breakdown, API contracts, risk register, compatibility matrix and release decision.
2. **WordPress Core Specialist** — core APIs, REST, capabilities, posts/pages, media, users/roles, settings, plugins/themes, Gutenberg and classic editor.
3. **WooCommerce Specialist** — products/variations, inventory, SKU, pricing, orders, customers, coupons, store settings and WooCommerce compatibility.
4. **Elementor & Theme Specialist** — Elementor document model, widgets/containers, templates, global settings, classic themes, Woodmart and safe round-trip preservation.
5. **Cloud Gateway & Billing Specialist** — OAuth/PKCE, tenants/sites, tariffs, quota policy, ledger, idempotency, concurrency, receipts and dashboard API contract.
6. **Security Reviewer** — threat modeling, permissions, tenant isolation, input/output boundaries, secrets, filesystem/WP-CLI restrictions and mutation safety.
7. **QA / Automation Engineer** — test matrix, fixtures, integration/e2e tests, regression tests, CI diagnostics and reproducible bug reports.
8. **Release Engineer / Documentation Agent** — versioning, changelog, packaging, checksums, install/upgrade instructions, beta notes and CI artifacts.

### Skills and engineering tools to use

Use repository-provided skills and instructions first. Select specialist skills for WordPress/PHP, WooCommerce, Elementor, security review, test automation, CI/CD, release packaging and documentation when available. Do not invent a skill or claim it ran unless it was actually available and used.

Preferred tools and environments:

- GitHub: issues, focused branches, pull requests, code review, commit history, protected checks and release artifacts.
- GitHub Actions: lint/static analysis, unit/integration tests, build/package validation, security/dependency checks, compatibility jobs and scheduled regression runs.
- Disposable virtual test environment: Docker Compose with isolated WordPress, MariaDB, PHP, web server and optional Redis; install real plugin ZIPs into the test WordPress instance. Use WP-CLI for setup and assertions.
- PHP quality: PHP syntax checks, PHPCS with WordPress standards, PHPStan/Psalm where practical, PHPUnit and WordPress/WooCommerce integration tests.
- Browser/e2e: Playwright (or an already established browser test framework) for plugin dashboard, connection flow, quota display and critical admin workflows.
- API/contract tests: OAuth/PKCE, MCP tool discovery/invocation, schema compatibility, tenant isolation, idempotency and server-ledger reconciliation.
- Supply-chain checks: dependency audit, secret scanning, artifact inspection and reproducible build metadata.

Do not add paid infrastructure or require a paid GitHub plan. Prefer the existing available GitHub Actions and self-hosted/virtual runner setup. If a test requires unavailable infrastructure, document the gap and provide a runnable local Docker alternative.

## 8. GitHub Actions and virtual testing strategy

Build a layered CI pipeline:

- **Fast PR checks:** PHP lint, formatting/PHPCS, static analysis, unit tests, schema/contract validation, secret scan and changed-file policy checks.
- **Integration matrix:** WordPress/PHP/WooCommerce/Elementor combinations supported by the declared compatibility policy.
- **End-to-end:** provision disposable WordPress + database, install the generated Native ZIP and a separately provisioned Cloud Core test service, connect using synthetic OAuth credentials, run workflows, verify ledger and dashboard.
- **Security/regression:** permission matrix, tenant-isolation tests, malformed input, replay/idempotency, filesystem boundaries, rollback and migration tests.
- **Packaging:** build Native and Cloud artifacts separately; inspect ZIP contents; verify plugin headers, versions, required files, no development fixtures/secrets, checksums and installability.
- **Scheduled runs:** nightly or weekly regression against the supported matrix, and on-demand full test runs before beta snapshots.

CI must report actionable failures and retain safe logs/artifacts. Use least-privilege workflow permissions, pinned third-party actions where practical, no privileged execution of untrusted pull-request code and synthetic data only.

## 9. Code audit and remediation process

Perform a systematic audit of the entire relevant codebase, not just the files implicated by the current issue:

1. Map modules, entry points, hooks, REST/MCP routes, tool registry, dispatch, auth, billing, storage, migrations, UI and build scripts.
2. Run syntax, static analysis, unit, integration, contract, security and end-to-end tests. Record baseline failures before editing.
3. Search for TODO/FIXME, dead code, duplicate implementations, stale versions, inconsistent schemas, missing capability checks, unhandled exceptions, unsafe defaults, race conditions, unbounded queries, N+1 requests, logging of secrets and mismatched error handling.
4. Trace each tool end-to-end: discovery → authorization → validation → execution → verification → receipt → quota ledger → UI.
5. Audit all quota paths and prove that no Native/client/telemetry path can change the authoritative balance.
6. Audit migrations and upgrades from the actual previous beta versions using disposable database snapshots. Test repeat execution and interrupted migration recovery.
7. Fix confirmed defects with regression tests. Avoid speculative rewrites and unrelated refactoring. Never hide a failing test or weaken an assertion to get a green build.
8. Produce an audit register with severity (P0/P1/P2/P3), evidence, affected versions, reproduction, fix/PR and verification status.

Priority: P0 data loss, security, cross-tenant access, incorrect billing/quota, broken authentication or unrecoverable upgrade; P1 core workflows and compatibility failures; P2 usability/performance/diagnostics; P3 cosmetic/documentation issues.

## 10. Beta build cadence and owner handoff

The project owner needs installable builds for hands-on testing throughout development, not only at the end.

- Produce a new **versioned, installable beta snapshot after every meaningful milestone** (for example, billing, WordPress core workflows, WooCommerce, Elementor/Woodmart, security hardening and final release candidate).
- Also run scheduled regression and publish a fresh beta snapshot at least weekly while active development continues, provided mandatory CI gates pass.
- Publish Native plugin ZIP and Cloud Core package as separate artifacts, with version numbers, commit SHA, build date, checksums, changelog, known limitations and exact installation/upgrade instructions.
- Mark builds as TEST/OPEN BETA until all release gates pass. Never label a build production-ready based on packaging alone.
- Make artifacts easy for the owner to download from the GitHub Actions run or approved release page. Do not send an unverified or locally stale ZIP.
- Do not deploy to production, change repository visibility, migrate live billing/account data, create public release tags or enable live payment processing without separate explicit owner approval.

## 11. Open Beta release gates

A candidate can be marked **Open Beta Ready** only when all are true:

- [ ] Native and Cloud Core build and install independently from clean artifacts.
- [ ] Clean install, upgrade, reconnect, reactivation and rollback paths are tested.
- [ ] All documented P0/P1 workflows pass on the declared compatibility matrix.
- [ ] WordPress, WooCommerce, Elementor, classic editor and Woodmart tests pass or have explicit documented limitations.
- [ ] Server ledger is authoritative; all tariff, batch, retry, concurrency and quota-boundary tests pass.
- [ ] Plugin dashboard exactly reconciles with server usage and remaining quota.
- [ ] OAuth/PKCE, tenant/site isolation, capabilities and mutation approvals pass security tests.
- [ ] No known P0/P1 defects, leaked secrets, unsafe CI permissions or production data in fixtures.
- [ ] GitHub Actions required checks pass on the exact candidate commit.
- [ ] ZIP contents, versions, checksums, changelog and install instructions are verified.
- [ ] Owner receives the installable artifacts and a concise test checklist.

If a gate fails, report **NOT READY**, identify the blocker and continue remediation. Never convert a failed or skipped test into a pass by assumption.

## 12. Required reporting format

For each milestone, report:

- **Status:** READY / NOT READY / BLOCKED.
- **Baseline:** branch, commit SHA, Native version, Cloud Core version.
- **Scope:** completed and untouched areas.
- **Audit:** defects found by severity and their status.
- **Tests:** exact commands/jobs, environment matrix, passed/failed/skipped counts and links to CI runs.
- **Billing proof:** tariff, operation, expected charge, ledger charge, dashboard display and reconciliation result.
- **Artifacts:** exact filenames, versions, SHA-256 checksums and verified download locations.
- **Risks/limitations:** concrete and reproducible, not generic assurances.
- **Next milestone:** one prioritized next action.

## 13. First execution task

Before implementing new features, inspect the current `main`, `AGENTS.md`, open issues/PRs, workflows and exact installed/release versions. Establish a verified baseline and test environment. Then produce:

1. a component and dependency map;
2. a compatibility and acceptance-test matrix;
3. a P0–P3 code-audit register;
4. a quota/ledger reconciliation test plan;
5. a prioritized release backlog with one focused issue/branch per change;
6. a first installable test build only after the existing build pipeline and its contents are verified.

Do not assume historic version numbers, commits, branches, test results or prior chat statements are still current. Verify them from the repository and CI before acting.
