# Live Central MCP Acceptance — 2026-10-07

Status: **PASS**

Baseline:
- Native WP AI Admin: **2.1.14**
- Cloud Core: **0.3.6**
- Public MCP: `https://wpaiadmin.ru/mcp`
- Target site: `https://wpaiadmin.ru/`

Operation:
- DB ID: **35**
- Operation ID: `OAI-20261007-113647-4262`
- Type: `option_update`
- Target: `wp_ai_admin_acceptance_probe`

Acceptance path:
1. Central ChatGPT connection created the sandbox operation through the public Cloud MCP.
2. Human administrator approved the operation in WP Admin.
3. Apply succeeded.
4. Independent verification returned `verified=true`.
5. Expected value matched current value.
6. Receipt was generated.
7. Execution proof was generated with SHA-256 hash:
   `98c9bd940e483b4ce9c8682bbcbdb98580d7a55861f37582424670d5a4385654`
8. Rollback succeeded.
9. Rollback verification returned `verified=true`.
10. Final existence check confirmed the probe option no longer exists.

Important result:
- Live central route `ChatGPT → wpaiadmin.ru/mcp → Cloud Core → native WP AI Admin → mutation → verify → receipt/proof → rollback → rollback verify` passed end-to-end.
- The test was deliberately performed on a disposable option and restored the site to the original state.
- This closes the live mutation/verify/rollback release gate for the current commercial-beta baseline.

Next release gate:
- Multi-Tenant Isolation Gate with two independent WP AI Admin accounts and two independently owned WordPress sites.
