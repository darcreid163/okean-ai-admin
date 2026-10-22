# Extension tools roadmap and security contract — 2026-10-21

Status: **DESIGN / NOT EXPOSED**. Native candidate 2.1.17, Cloud 0.3.11 baseline / 0.3.12-dev candidate. This document does **not** authorize site mutations, Cloud rollout, payment changes, or new public MCP tools.

## Existing execution boundary (source audit)

- `includes/class-oai-command-registry.php` owns the allowlist, `read`, `approval`, `execution_mode`, positional args and allowed flags.
- `includes/class-oai-wp-cli-execution.php::execute()` explicitly rejects writes. `::plan()` currently returns command metadata with `next_step=create_operation_and_require_approval`; it **does not create a persisted operation**.
- `includes/class-oai-execution-dispatcher.php` routes read to execute and writes to plan; this **alone does not provide Apply/Verify/Rollback**. New write handlers must be implemented as typed operations in the existing persisted OAI lifecycle, with immutable inputs, authorization, approval, receipt, idempotency, verification and compensating rollback.
- `includes/class-oai-operations.php` risk policy must be extended based on actual effect, not only family.
- `includes/class-oai-abilities.php` currently registers limited native abilities; third-party ability enumeration and execution need separate capability and schema guards.

## Tool backlog (10 capabilities)

| Priority | Public tool / CLI canonical | Read/write | Implementation modules | Acceptance / protection |
|---|---|---|---|---|
| P1 | `search_images` / `media search` | read, no approval | Registry, execution, Cloud tool exposure if needed | Only configured provider (Unsplash), scoped server-side API key, TLS/host allowlist, bounded query/results, timeout, attribution, no secret in responses, no site write |
| P0 | `upload_media` / `media upload` | write, approval | Registry, typed media operation, WP media APIs, verify/rollback | Source URL SSRF/DNS+redirect protection, size/MIME/image validation, license/attribution, staging sandbox, attachment creation only after approval; verify attachment and delete **only newly created** attachment on rollback |
| P1 | `discover_abilities` / `ability list` | read | Registry, Abilities API adapter | WP-version feature detection, registered-name allowlist, capability filter, visibility scoped by tenant/site, response bounds |
| P1 | `get_ability_info` / `ability get` | read | Registry, Abilities API adapter | Permissions and allowlist before exposing schema, strip secrets/PII |
| P0 | `run_ability` / `ability run` | mixed; fail closed | Abilities adapter, typed lifecycle ops | NOT universal execute: read-only permitted only for proven side-effect-free allowlisted abilities; unknown/write abilities require explicit per-ability adapter, input/output schema, human approval, verify/rollback; deny nonreversible/external effects by default |
| P1 | `load_skill` / `skill load` | read | Registry, `OAI_Skills` repository | Scoped by account+site+owner, ACL, size cap, sanitize display; skill text is untrusted instructions and cannot elevate tools |
| P1 | `save_skill` / `skill save` | write, approval | Registry, `OAI_Skills`, typed ops | Prefer indexed custom table for scoped ownership/versioning; immutable revision, conflict check, audit, rollback to previous revision |
| P1 | `get_page_html` / `page html` | read | Registry, execution | Same-site canonical public HTTPS URL only, SSRF/DNS/redirect restrictions, no authentication cookies, exclude admin/private pages, bounded sanitized HTML (not credential-bearing headers) |
| P1 | `audit_page` / `page audit` | read (external data disclosure) | Registry, execution, PSI adapter | Public canonical URL only, explicit external API disclosure, quota/rate/timeouts, key server-side, bounded performance/accessibility/SEO/best-practices scores; scores may be null |
| P2 (after P0 release) | `start_fleet_job` / `fleet job start` | write, **high risk** | Registry, `OAI_Fleet_Job`, durable queue | Per-site owner/tenant permissions, multi-site per-target approvals, dry-run, per-object charge receipts, lease/idempotency, cancel/status, checkpoints, pause-on-error, no blanket authorization; prefer Action Scheduler/worker, do not rely on WP-Cron for guaranteed execution |

`fleet job status` and `fleet job cancel` are required companion tools before enabling `start_fleet_job`. A cancellation does not undo already applied steps; each requires an audited compensating operation.

## Integration order and gates

1. **P0 Usage Engine v2**: successful reads and Sandbox requests cost 1; Batch cost equals number of verified successfully changed objects; retries/reversals/errors are not double billed; server Gateway Ledger is charging authority; atomic reservations per account/tariff period; Free 100/month, Pro 500/day, Business 2,000/day, BusinessPro 5,000/day. No telemetry billing and no auto-payment debit. Demonstrate isolation, concurrent reservation, partial Batch, migration and exhaustion tests on SQLite/MySQL.
2. **P0 editor + commerce operations**: complete native typed `upload_media`; Woo product draft/create, variants and controlled updates; menus; Elementor/Gutenberg patch/verify/rollback; preserve Woodmart and third-party theme boundaries. Enable only tested narrow adapters.
3. Add read-only tools individually with bounded output, ACL and SSRF protections; no direct secrets, admin HTML or auth cookie forwarding. Unsplash/PSI credentials belong server-side.
4. Introduce scoped Skills and approved Abilities adapters. A third-party Ability may have arbitrary side effects: never equate registration or `plan_only` metadata with safe execution.
5. Fleet subsystem is deferred until queue, receipts, concurrency caps, tenant separation and compensation are qualified.
6. Validate PHP 8.2/8.3/8.4, WP and Woo pinned/latest, real Elementor/Gutenberg/Media/Menu staging, OAuth revoke/isolation, schema upgrades, sandbox/approval race, ZIP boundary and actual installed-file parity.

## Do not do

- Do not add write command handlers to direct `execute()`; it rejects writes by design.
- Do not claim `plan()` persists Sandbox operations or `plan_only` automatically provides approval and rollback.
- Do not fetch arbitrary URLs with bare `wp_remote_get()` or `download_url()` without safe transport checks, or send private URLs to PageSpeed.
- Do not expose all third-party abilities or their input schemas by default.
- Do not activate new paid plans, migration jobs, cron/fleet jobs or production commands from this documentation branch.

## Proposed paths

`includes/class-oai-command-registry.php`; `includes/class-oai-wp-cli-execution.php`; `includes/class-oai-execution-dispatcher.php` (only when typed lifecycle wiring is required); `includes/class-oai-operations.php`; `includes/class-oai-abilities.php`; new `includes/class-oai-skills.php`, `includes/class-oai-fleet-job.php`; bootstrapping and Cloud tool catalog under explicit versioned change. All implementation must be backed by executable tests before registration.
