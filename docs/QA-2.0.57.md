# 2.0.57 QA checkpoint

Candidate build: `2.0.57-oauth-schema-upgrade-gate-20260928`. Expected MCP catalog: 90 with Content Navigation enabled. Expected self-test: 50 checks.

## Installed 2.0.56 baseline

- Same site ID and installation ID across 2.0.55 to 2.0.56 upgrade; MCP connection and scoped Test Automation remained active.
- Live catalog 90/90, self-test 49/49, Security Fuzz 200/200, two-iteration Regression Matrix 90/90 with baseline restored.
- Read-only 50-step batch completed in 20 + 20 + 10. Concurrent second resume was rejected by version conflict; no duplicate attempts; receipt and proof matched.
- Test draft #35419 excerpt empty, product #35127 stock 0 and price empty, pending operations 0.

## Candidate checks

- CI: PHP 8.2/8.3/8.4 syntax, plugin ZIP integrity, MySQL two-process batch mutex, and MySQL OAuth migration retry/skip/client retention.
- Pending installation: verify build ID, unchanged site/installation identity and OAuth client access, 90/90 catalog and 50/50 self-test; run read-only matrix then reversible lifecycle tests with restored baseline.

The installed baseline results do not assert that 2.0.57 has passed live tests. Remote commercial Control Plane is still unconfigured.
