# Cloud Abilities integration gate — proposed 0.3.13-dev

**Status:** contract test added, Cloud runtime unchanged. No deployment or release approval.

Native 2.1.17 main includes `ability.list` and `ability.get` in the MCP read catalog after PR #57. Cloud recovered 0.3.11 Core currently classifies any unlisted tool as write, so these tools are **not yet usable under a read-only OAuth grant through the central gateway**.

The historical `docs/RECOVERED-CLOUD-RUNTIME.sha256` manifest MUST remain immutable. The current 0.3.12-dev recovery gate permits only explicit Gateway/bootstrap changes. Do not silently alter Core.php and claim the recovered source still matches. A separate versioned 0.3.13-dev candidate must update the delta inventory and CI qualification first.

## Acceptance

1. Only `ability.list` and `ability.get` classify as reads, unknown `ability.run` and `ability.execute` remain writes (and are *not* executable without typed adapters).
2. `Core::catalog()` checks selected site authorization before exposing its catalog; `call()` checks the same site and OAuth read scope, reserves one quota unit, never grants extra site IDs.
3. Deterministic retries return cached receipts with no duplicate charge; failure and uncertain outcome follow existing Ledger settlement rules.
4. Add `tests/abilities-read-contract.php` to Cloud CI **only after** a reviewed 0.3.13-dev implementation and integrity-gate update; this test is currently intentionally red and not in CI.
5. Test SQLite/MySQL and PHP 8.2–8.4, tenant A/B, revoked grants, selected-site restrictions, provider catalog. No production install until staging accepts.
6. Existing Cloud 0.3.11 manifest must continue to verify its original unchanged files and source hashes.

Changes to recovered Core were intentionally not applied in this working branch. The new test is a forward contract, not evidence of passing runtime behavior.
