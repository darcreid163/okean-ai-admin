# Scale Gateway v2 — safe development RFC 0.1

Status: design / isolated prototype. Baseline: main Native 2.1.14, Cloud Core 0.3.6. This branch does not deploy to wpaiadmin.ru, alter main, install routes or migrate data.

## Current code findings
- `src/Gateway.php` exports four central MCP tools, delegates `cloud_site_tool_call` to Core, and requires an explicit site_id and idempotency_key.
- `src/Core.php` atomically reserves/settles single-call quota in `balances` and `requests`, retains uncertain outcomes; central batch mutation tools currently fail closed.
- `src/Store.php` supports MySQL InnoDB + SQLite test fixtures, with transactional locking. No durable job/lease schema exists.
- `src/OAuth.php` and connection contract 1.2 require explicit site grants, PKCE, issuer/audience binding, revocation and distinct pairing consent.
- `src/Settings.php` preserves `calendar_month_utc`, `successful_single_tool_call`, and batch disabled; historical plan schema contains batch_objects_per_action=5. Do not activate object-based billing before versioned change.

## Invariants
1. Canonical public resource is https://wpaiadmin.ru/mcp; issuer https://wpaiadmin.ru/wpai-cloud; two independent consent flows and all existing site identities/grants must survive.
2. Tenant identity is derived from server-verified OAuth token, never from client body. Every queued dispatch must reauthorize owner, site and grant immediately before execution. Do not cache positive authorization past revocation.
3. Existing native sandbox/approval/apply/verify/receipt/rollback enforcement must remain.
4. Unknown write outcomes cannot automatically retry; mark uncertain, retain reservation and reconcile using authoritative receipts.
5. Account quota remains transactional in database; Redis/cache/telemetry cannot own balances.
6. No production traffic/routing/schema change in this development stage.

## Proposed stages
- Stage A (this branch): isolated, pure concurrency policy prototype and tests; NO WordPress hooks.
- Stage B: production-compatible interfaces for tenancy, dispatch, admission, queue and storage; integration test with real Core/OAuth fixtures.
- Stage C: durable job schema, lease, worker fencing tokens, per-item receipt and versioned billing-policy migration. Fail closed on stale lease and revoked site/grant.
- Stage D: staging VPS with isolated data and disposable WordPress sites; two-site OAuth and negative regression matrix.
- Stage E: explicit contract amendment, canonical-path reverse proxy feasibility proof, staged cutover with rollback. No change of issuer or existing OAuth audience.

## Queue contract (future)
Job envelope: request_id, immutable verified owner identifier, site_id, OAuth grant reference (never access token), tool name, validated args reference, logical idempotency key, policy version, deadline, created_at.
DB is authoritative for job status; a Redis queue message only refers to a persisted job ID. Worker claims a fenced lease transactionally, reauthorizes, then dispatches. Lease expiry after uncertain upstream writes must not trigger a second write. Read-only retries require explicit policy.

## Concurrency requirements
Three ceilings: global, per owner, and per site; optional read/write limits. Admission is atomic and fail-closed; release is bounded and never drops a counter below zero. This isolated prototype validates policy only — process-safe locking and distributed fencing are NOT yet implemented. No account-specific process is created.

## Acceptance gates
- Preserve `wp-ai-admin-cloud/tests/run.php`, `compatibility.php`, `oauth.php` plus contract 1.2 test matrix.
- New: tenant forgery, site grant revocation while queued, 100 concurrent replays, crash/timeout uncertain state, per-site fairness, job cancel, batch 20 of 17 successes = 17 units after approved policy migration, negative balance forbidden.
- Run SQLite and MySQL, PHP 8.2/8.3/8.4, separate load benchmark; record executed vs designed tests.
- Target of 1,000 active clients is a benchmark goal, NOT a verified capacity.

## Migration blockers
Do not route production to new workers until current installed Core version and deployed files are compared with main (0.3.6); earlier discussions mention 0.3.8 but no authoritative running version response was obtained. Never downgrade installed packages to match main.
