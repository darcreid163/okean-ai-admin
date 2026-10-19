# Scale Gateway v2 — DEFERRED / parked plan

Decision date: 2026-10-19
Status: **ON HOLD — future milestone, not part of current commercial launch**.
Do not resume implementation, merge or deploy without a new explicit project decision.

## Current basis
- Repo: darcreid163/okean-ai-admin
- Isolated branch: codex/scale-gateway-v2-audit
- Design: docs/SCALE-GATEWAY-V2-RFC.md
- Prototype: wp-ai-admin-cloud/scale/AdmissionPolicy.php
- Test fixture: wp-ai-admin-cloud/tests/scale-admission.php (created, execution not verified).
- Baseline in main audited earlier: Native 2.1.14, Cloud Core 0.3.6.
- Latest native live read on wpaiadmin.ru reported Native 2.1.17 (build 2.1.17-usage-batch-v1-20261019). Installed Cloud Core version NOT independently confirmed; never downgrade live installation based on old main.
- Production is ordinary Beget shared hosting. No scaling code has been deployed there.

## Deferred scope
1. Multi-tenant Request Manager; derive owner/site from validated OAuth and reauthorize on dispatch.
2. Global, per-account, per-site and per-operation concurrency limits; no separate process per user.
3. Fair Scheduler and backpressure (429/Retry-After); short interactive and durable batch modes.
4. Durable queue, job repository, worker leases/fencing, dead-letter handling, uncertain-write reconciliation.
5. Shared, transactional Quota Engine + Usage Ledger with versioned accounting; object-based batch charging only after contract/tests, no Redis/telemetry as balance source.
6. Horizontal Worker Pool and VPS Gateway separated from existing WordPress account/billing site.
7. Metrics and performance targets: validate 1,000 connected clients separately from 1,000 in-flight requests; later plan for 10,000.
8. Safe gateway routing while preserving https://wpaiadmin.ru/mcp, issuer and two independent OAuth/pairing consent flows.

## Resume prerequisites / gates
- User explicitly authorizes renewed scaling work.
- Check current main, branch, installed Native and installed Cloud Core; reconcile changes made since this note.
- Re-read docs/CHATGPT-CONNECTION-CONTRACT.md and AGENTS.md.
- No production changes until tenant isolation, OAuth, quota, idempotency, batch accounting, retry/uncertain-write and two-site regression tests pass.
- Test isolated VPS/staging with synthetic tenants and disposable WordPress sites.
- Test load under controlled conditions; define P95, throughput, failure rates and recovery, not guessed concurrency promises.
- Draft migration, reversible deployment, monitoring and rollback checklist before canary.

## Active priorities outside this on-hold scope
Complete commercial release requirements: account subscription state, YooKassa billing and webhooks, license/entitlement binding, quota accuracy, account dashboard, release QA. Scaling does not block these unless load measurements demonstrate immediate resource constraints.

## Safety
This file is only a planning record in the isolated branch. Do not activate flags, change DNS/routes, migrate DB, restart services, or install a plugin as part of filing this decision.
