# Security Policy

## Supported release line

Security fixes are prepared against the current public-beta candidate and the latest published commercial-beta baseline when practical. Historical development branches and superseded test artifacts are not security-supported release channels.

Current candidate (unreleased):
- WP AI Admin native: **2.1.17** (DB schema 24)
- WP AI Admin Cloud Core: **0.3.13-dev** (Gateway schema 4, derived from the recovered 0.3.11 baseline)

This candidate is not an approved production release. Installed builds, file hashes and the acceptance state are tracked in issue [#40](https://github.com/darcreid163/okean-ai-admin/issues/40). Billing, Batch accounting and commercial entitlement enforcement are disabled for the beta.

## Reporting a vulnerability

Do not publish credentials, OAuth codes, access/refresh tokens, paired site secrets, PKCE verifiers, private keys, full callback query strings, production database dumps, customer data or reproducible exploit details in a public issue.

Report a suspected vulnerability privately to the project owner/maintainer through the private repository or the designated private support channel. Include:
- affected version/build;
- component and endpoint/tool;
- minimal reproduction steps;
- expected vs actual behavior;
- impact;
- sanitized logs/evidence;
- whether production data or credentials may have been exposed.

## Security model

WP AI Admin uses a fail-closed lifecycle:

READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK

Production defaults:
- direct live writes disabled;
- human approval required;
- AI Lifecycle Control disabled unless an administrator explicitly enables it;
- Test Automation disabled unless an administrator explicitly enables a bounded session;
- site and installation identity preserved across normal upgrades;
- plugin deactivation disables automation modes;
- OAuth uses Authorization Code + PKCE S256 with explicit per-site selection;
- access is tenant/site/scope bound;
- site credentials and OAuth credentials are distinct;
- secrets are stored hashed or sealed where applicable;
- failed operations do not consume successful-action quota;
- create operations use a single-winner admission claim and are never retried blindly on an uncertain outcome.

## Sensitive information handling

Never log or intentionally expose:
- WordPress passwords or Application Passwords;
- raw MCP/site pairing credentials;
- OAuth access or refresh tokens;
- authorization codes;
- PKCE verifiers;
- private keys;
- full OAuth callback URLs containing query parameters;
- database credentials;
- model-provider API keys;
- customer or order data outside the authorized tool response.

Sanitized error codes, hashes, operation IDs and timing information may be retained for audit and reconciliation.

## Security release gate

A public release must not be marked ready solely from unit tests. Required evidence includes:
- multi-tenant isolation;
- OAuth/replay/revoke boundaries, including refresh after real expiry;
- release artifact provenance and SHA-256 correspondence;
- secret scanning of repository and artifacts;
- destructive-operation conflict protection, Verify and rollback;
- fresh install, upgrade, reactivation and paired restore;
- public onboarding checks;
- a final live acceptance against a disposable WordPress site.

CI infrastructure failures are treated separately from product test failures and both must be resolved before closing the release gate.

## Scope of this repository

`darcreid163/okean-ai-admin` is temporarily public for development CI and contains both Native and Cloud Core source. Since all committed source is publicly accessible, this repository must never receive production tokens, OAuth secret keys, server key material, payment credentials, customer information, private WordPress backups or database exports. See `AGENTS.md` and `docs/REPOSITORY-BOUNDARIES.md`.
