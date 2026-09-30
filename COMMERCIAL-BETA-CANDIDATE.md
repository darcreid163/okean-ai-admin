# WP AI Admin 2.1.0 — Commercial Beta Candidate

## Release intent

WP AI Admin 2.1.0 is the first commercial beta candidate. The goal is not to add a larger tool catalog; the goal is to ship the already-tested WordPress/Elementor/Gutenberg/WooCommerce lifecycle behind production-safe defaults.

## Frozen technical baseline

The candidate is based on WP AI Admin 2.0.78, which reached the following runtime gate on the connected test site:

- 111 exported MCP tools
- self-test: 71/71 PASS
- security fuzz: 220/220 PASS
- extended matrix: 109 PASS / 0 FAIL / 2 intentional SKIP / 0 BLOCKED
- mutation runner: 16/16 PASS
- three consecutive full matrix iterations: PASS with baseline restored on every run
- no residual test WooCommerce products, media attachments or temporary menus after cleanup

The two intentional matrix skips are:
1. `wp.visual_qa_result` — requires a real browser to execute the signed visual probe.
2. `menu.assign_location_sandbox` — automated regression must not reassign a live production menu location.

## Production safety defaults

- direct live writes: disabled
- human approval: default
- every supported write goes through sandbox/lifecycle controls
- verify after apply: required by lifecycle contract
- rollback/conflict guards: preserved
- AI Lifecycle Control: explicit administrator opt-in only, administrator/client-bound, build-bound
- Test Automation Mode: explicit administrator opt-in only, short-lived, client/session scoped, build-bound
- Test Automation commercial defaults contain no Okean-specific post/product IDs
- Test Automation file prefix is generic: `wp-ai-admin-tests/`

## Upgrade and connection contract

Normal plugin updates are designed to preserve:

- `site_id`
- `installation_id`
- OAuth client registrations
- OAuth token tables
- operation/audit ledger
- quota/history tables

The OAuth schema is migrated in place with WordPress `dbDelta`. A build change intentionally deactivates any previously enabled AI Lifecycle Control or Test Automation session until an administrator explicitly enables it again.

## Commercial beta scope

Included for beta:

- WordPress read/navigation and bounded REST reads
- safe content patch lifecycle
- Gutenberg read/locate/patch
- Elementor create + precise widget/fragment/settings/container/page-layout patching
- WooCommerce product read/search/update/create with rollback and normalization-aware verification
- media lifecycle
- menu create/add/move/remove lifecycle
- safe theme file read/write sandbox
- options sandbox with protected/sensitive option denylist
- resumable batch executor for its supported subset
- receipts, proof, verify, preview and rollback
- OAuth Authorization Code + PKCE
- operation history/pagination
- diagnostics and security fuzz/regression tooling

Not required to block the local commercial beta:

- remote Control Plane entitlement enforcement
- billing/subscriptions/customer portal
- partner program
- browser-completed visual QA automation
- automatic regression against a live production menu location
- support for every third-party page builder

## Commercial beta release gates

A candidate build must pass all of the following before distribution:

1. PHP syntax/archive checks on supported PHP versions.
2. ZIP integrity check.
3. OAuth schema/client-retention CI.
4. batch mutex/concurrency CI.
5. runtime self-test with zero failures.
6. runtime security fuzz with zero failures.
7. regression matrix with zero FAIL/BLOCKED and only documented intentional SKIP entries.
8. mutation regression with baseline restored.
9. no residual temporary products/media/menus/files after regression.
10. direct live writes remain disabled.
11. human approval remains the default lifecycle.
12. no site-specific IDs, paths, customer PII or absolute server filesystem paths in default commercial responses/UI.
13. a CI job is valid only if its workflow steps actually execute; a GitHub Actions job that terminates with an empty step list is classified as infrastructure failure and must be re-run, not treated as a product regression.

## Known beta limitations

- Control Plane cryptographic verification foundation exists, but remote entitlement enforcement is not yet required for local beta.
- Visual QA phase 2 requires opening the signed browser probe.
- Resumable batch executor supports a bounded subset of tools; unsupported mutators must be rejected rather than reclassified as reads.
- Legacy REST/MCP namespace `okean-ai` is retained for connection compatibility during beta.

## Change policy during beta

2.1.x beta changes should prioritize:

1. data integrity
2. security/privacy
3. targeting correctness
4. verify/proof/rollback correctness
5. upgrade/reconnect reliability
6. diagnostics and UX
7. performance
8. only then new features

A defect is not closed until FIX -> RETEST -> REGRESSION is complete.
