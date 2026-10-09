# Changelog

## Cloud Core 0.3.7 — Release candidate — 2026-10-09

- Add revoke-first central account suspension and closure preview.
- Revoke bearer/OAuth/site access and scrub all sealed site credentials on suspension.
- Invalidate existing bearer access when the WordPress owner loses eligibility or is deleted.
- Fail fast on incomplete gateway database schemas.
- Preserve keys/account/site/bearer/OAuth state across real Cloud Core ZIP upgrade from 0.3.6.
- Use the canonical deterministic release artifact builder.
- Serialize Cloud bearer issuance with account suspension so a concurrent mint cannot create a token after the suspension bulk-revoke.
- Re-enabling a suspended account does not resurrect old bearer/OAuth/site access; fresh reconnect and consent are required.
- Support nested Cloud transactions explicitly on both SQLite and MySQL so OAuth exchange can safely call transactional bearer issuance.
- Suspended accounts block normal plan/usage/foundation access; trusted evidence-based reconciliation remains intentionally available only to settle pre-suspension uncertain requests.
- Use account-first lock ordering for OAuth consent, code exchange and refresh rotation to avoid suspension deadlock inversions.
- Validate current-version gateway schema at runtime even when the DB-version marker is already current; optimize MySQL validation to two metadata queries.
- Recheck live conflict state after atomic Apply/Rollback claims so concurrent third-party changes are detected before any write.
- Make Apply/Rollback conflict recording itself CAS-safe so an error handler cannot overwrite a concurrently winning lifecycle state.
- Add per-operation execution leases so a second request cannot mistake an actively running Apply/Rollback for a crashed request; recovery is allowed only after the lease is gone/expired.
- Clear operation execution leases on plugin deactivation and block the entire native `oai_*` control-plane namespace from generic option writes.
- Refresh native build ID to `2.1.15-release-hardening-20261009` after executable October 9 hardening.


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
- Protect `wpai_gateway_*` and `wpai_cloud_*` control-plane options from the generic native option sandbox.
- Re-check administrator capability before native OAuth authorization-code exchange and refresh rotation so demoted users cannot mint new credentials.
- Add signed preview-token binding/expiry tests and native Application Password/Bearer/MCP demotion acceptance.
- Make native lifecycle transitions single-winner atomic for Approve, Reject, Expiry, Apply and Rollback to prevent concurrent requests from double-writing or resurrecting rejected operations.
- Normalize release ZIP timestamps and file ordering for cross-run deterministic artifacts.
- Add crash-safe reconciliation for interrupted Apply/Rollback: verify-before-retry, finalize already-completed writes without replay, return unchanged outcomes to an explicit retryable state, and keep ambiguous outcomes fail-closed.
- Protect `rollback_pending` as an active operation during cleanup.
- Add revoke-first self-service central account suspension with non-destructive closure preview; suspension revokes bearer/OAuth/site access and clears sealed site credentials while retaining ledger/audit/balance evidence.
- Scrub sealed site credentials from all historical registrations during suspension, including legacy rows already marked revoked.
- Add a real Cloud Core 0.3.6 → 0.3.7 ZIP upgrade acceptance preserving gateway keys, account/site identity, bearer and OAuth grants.
- Use one canonical deterministic release builder across fresh install, native upgrade, Cloud upgrade and provenance jobs.
- Fail fast with `gateway_schema_incompatible` on partial gateway schemas and reject non-InnoDB MySQL gateway tables.

### Commercial truthfulness
- Keep public health mode at approved_beta.
- Keep automatic billing activation, batch receipt accounting and commercial client entitlement enforcement explicitly marked incomplete.

### Infrastructure
- Native candidate version advanced from 2.1.14 because executable bytes changed.
- Cloud Core candidate advanced separately to 0.3.7 after executable Cloud hardening.
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
