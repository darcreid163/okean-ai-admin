# Changelog

All notable public-release changes are recorded here. Published versions must never be reused for different package bytes.

## 2.1.15 — Release candidate — 2026-10-08

### Security and lifecycle hardening
- Disable AI Lifecycle Control and Test Automation on native plugin deactivation.
- Preserve site identity, installation identity, OAuth/MCP credential state and license state across deactivation/reactivation.
- Add apply/rollback stale-state conflict acceptance coverage.
- Add expired sandbox and human-approval fail-closed coverage.
- Add token/session TTL, revoke, disconnect and multi-site grant tests.
- Add tenant-isolation, canonical URL ownership and credential-binding tests.
- Add quota/idempotency/uncertain-outcome reconciliation tests.
- Add MCP/REST/OAuth negative HTTP boundary tests.
- Add public onboarding and release-provenance gates.
- Add reproducible ZIP builds, SHA-256 provenance and release-payload secret scanning.
- Add Runner Smoke diagnostic workflow.
- Recompute native execution proof when the lifecycle moves from Apply into Rollback, preventing a rollback receipt from reusing a stale Apply proof.

### Commercial truthfulness
- Keep public health mode at approved_beta.
- Keep automatic billing activation, batch receipt accounting and commercial client entitlement enforcement explicitly marked incomplete.

### Infrastructure
- Native candidate version advanced from 2.1.14 because executable bytes changed.
- Cloud Core remains 0.3.6.
- Final live acceptance remains a separate P0 gate.

## 2.1.14 — 2026-10-07
- Native consent callback navigation fix.
- Preserve strict CSP/no-store/no-referrer browser handoff.
- Supported central MCP endpoint: https://wpaiadmin.ru/mcp.

## 2.1.0 — Commercial Beta Candidate
- Freeze validated production safety defaults.
- Direct live writes disabled.
- Human approval default.
- AI Lifecycle Control and Test Automation explicit administrator opt-ins.
