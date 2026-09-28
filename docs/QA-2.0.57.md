# 2.0.57 QA checkpoint

Candidate build: `2.0.57-oauth-schema-upgrade-gate-20260928`. Expected MCP catalog: 90 with Content Navigation enabled. Expected self-test: 50 checks.

## Installed 2.0.56 baseline

- Same site ID and installation ID across 2.0.55 to 2.0.56 upgrade; MCP connection and scoped Test Automation remained active.
- Live catalog 90/90, self-test 49/49, Security Fuzz 200/200, two-iteration Regression Matrix 90/90 with baseline restored.
- Read-only 50-step batch completed in 20 + 20 + 10. Concurrent second resume was rejected by version conflict; no duplicate attempts; receipt and proof matched.
- Test draft #35419 excerpt empty, product #35127 stock 0 and price empty, pending operations 0.

## 2.0.57 installed verification (2026-09-28)

- Installed build ID `2.0.57-oauth-schema-upgrade-gate-20260928`; site ID `b1066132-4c8f-4f85-b85f-83329e1162a8` and installation ID `inst_635ac36e-9204-4032-92f9-eb1843b21f26` unchanged.
- MCP connector exports 90 tools, server catalog exports 90, and the self-test passes 50/50 including `oauth.schema_version`. The client name `wp_file_outline` maps to the canonical `wp.file.outline` tool ID.
- Security Fuzz `fz_20260928_193108_3bbef358`: PASS 200/200.
- Regression Matrix `mx_20260928_193124_6d1d252a`: PASS 90/90, two mutation iterations 8/8 each, baseline restored. Rechecked test draft #35419 (empty excerpt), product #35127 (stock 0, empty price) and pending operations (0).
- Scoped Test Automation remained active after upgrade. CI runs [36472177491](https://github.com/darcreid163/okean-ai-admin/actions/runs/36472177491) and [36472170282](https://github.com/darcreid163/okean-ai-admin/actions/runs/36472170282) succeeded.

## Candidate checks

- CI: PHP 8.2/8.3/8.4 syntax, plugin ZIP integrity, MySQL two-process batch mutex, and MySQL OAuth migration retry/skip/client retention.
- OAuth client access through a fresh authorization-code flow has not been re-tested on 2.0.57; the existing MCP session works and the schema-version check passes.

Remote commercial Control Plane is still unconfigured.
