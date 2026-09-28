# WP AI Admin: Control Plane contract v1 (design)

Status: design for the commercial service. No remote Control Plane is configured in the tested installation. Plugin 2.0.57 contains local license, plan, quota, identity and security-status foundations; it does not implement this remote protocol.

## Responsibilities

| Component | Owns |
| --- | --- |
| WordPress plugin | Site installation ID, local sandbox and approval, execution, receipts, idempotent local request accounting, safe cached entitlement enforcement. |
| Control Plane | Account and installation registry, subscriptions, payment provider integration, authoritative plan and quota, signing keys, revocation and freeze policy. |
| Customer cabinet | Sign-in, installations, billing status, plan changes, usage view and support. It calls the Control Plane, not the WordPress database. |

All public plans currently expose the same feature set. Current local daily limits are Free 100, Daily 500, Pro 2,000 and Business 5,000. Commercial prices, billing periods and quota policy need separate product decisions. The `local-owner` mode is an internal engineering path and must not be presented as a paid entitlement.

## Protocol draft

All requests use HTTPS and versioned `/v1/` endpoints. Enrollment must authenticate a customer and prove control of the WordPress installation through a one-time challenge. The plugin stores only a short-lived installation credential and a pinned trusted public key; the signing private key remains on the server. Never accept a server URL or public key from an unauthenticated MCP caller.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `POST` | `/v1/installations/challenge` | Issue bounded, single-use enrollment challenge. |
| `POST` | `/v1/installations` | Bind account, `site_id`, `installation_id`, canonical site URL and challenge proof. Return registration ID and trust material. |
| `POST` | `/v1/installations/{id}/entitlements` | Return signed, short-lived entitlement snapshot for the installation. |
| `POST` | `/v1/usage/reservations` | Reserve units by a stable request ID; duplicate requests return the same reservation. |
| `POST` | `/v1/usage/reservations/{id}/commit` | Commit or release a reservation after the operation's terminal result. |
| `GET` | `/v1/installations/{id}/policy` | Fetch signed revocation, installation freeze and global write freeze state. |

An entitlement carries `contract_version`, issuer, audience, account ID, `site_id`, `installation_id`, registration ID, plan, features, quota period and remaining allowance, `issued_at`, `not_before`, `expires_at`, `policy_sequence`, `key_id` and signature. Verify the signature, audience, site/install binding, timestamps and monotonically increasing policy sequence before caching it. Rotate keys with an overlap window and an explicit trust update; reject unknown keys. No private key or raw payment token belongs in WordPress options, logs or MCP responses.

Usage reservation contains `installation_id`, `request_id`, channel, units, period and a hash of the normalized operation. The server enforces uniqueness on `(installation_id, request_id)`. The plugin uses the same request ID across retries and reconciles unresolved reservations; the local quota event table remains a guard and audit source, not the commercial ledger. Test Automation usage is separately labeled and controlled by the server policy.

## Failure and enforcement rules

- New write scheduling requires a valid, unexpired signed entitlement and policy permitting writes. Local sandbox, approval, conflict checks, verification and rollback still apply.
- On a timeout, retry with the same request ID. Never turn a failed entitlement fetch or ambiguous reservation into an implicit paid grant. Document an explicit bounded read-only grace policy, if the business requires one.
- Revocation/freeze takes precedence over cached plan grants. Maintain a short policy TTL and force refresh before sensitive lifecycle transitions. Define how rollback works while writes are frozen so a customer can safely recover a prior operation.
- Persist no customer payment details in the plugin. The payment provider calls the Control Plane webhook; the server verifies event signatures, deduplicates event IDs and updates subscription state.
- Log installation ID, request ID, policy sequence and decision code without credentials, raw tokens, sensitive content or payment data.

## Acceptance gates before commercial rollout

1. Threat model and protocol review; cross-site token substitution, replay, expired entitlement, rollback during freeze, key rotation and stolen installation credential tests.
2. Real Control Plane and cabinet deployed with sandbox billing provider; signed webhook and subscription state tests.
3. Plugin integration tests for all network failure states, quota races and idempotency across two concurrent PHP processes.
4. Upgrade/migration and backup restore tests that preserve site and installation IDs, OAuth clients, operations, audit and receipts.
5. WordPress compatibility and load tests, security review, observability, support and incident procedures.

This contract is a design checkpoint. The current build must not be sold as an operating subscription service until the acceptance gates pass.
