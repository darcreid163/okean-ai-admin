---
name: wordpress-plugin-dev
description: Secure WordPress plugin development using core APIs, hooks, REST, capabilities, admin UI, settings, media and lifecycle practices.
---
# WordPress Plugin Development
Read AGENTS.md and docs/OPEN-BETA-RELEASE-MISSION.md first. Trace each feature end-to-end: registration → authorization → validation → operation → verification → receipt → server usage response → UI.
- Prefer public WordPress APIs and existing project abstractions; search before adding helpers.
- Preserve declared minimum WP/PHP compatibility; guard optional APIs and plugins.
- Enforce capabilities and object/site ownership server-side. Nonces do not replace authorization.
- Sanitize inputs, validate schemas, escape output, use prepared SQL and safe filesystem APIs.
- Preserve unknown metadata and third-party data. Never silently discard fields you do not own.
- Make mutations idempotent; handle retries, races, partial failures and uncertain outcomes.
- Cloud Core is authoritative for identity, tariff, quota and ledger. Native only displays returned values.
- Add regression tests; report unsupported cases, changed files, tests, risks and rollback.
- Never commit credentials, customer data, production exports or generated archives.
