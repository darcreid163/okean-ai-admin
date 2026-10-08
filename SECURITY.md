# Security Policy

## Supported release line

Security fixes are prepared against the current public-release candidate and the latest published commercial-beta baseline when practical. Historical development branches and superseded test artifacts are not security-supported release channels.

Current candidate:
- WP AI Admin native: 2.1.15
- WP AI Admin Cloud Core: 0.3.6

## Reporting a vulnerability

Do not publish credentials, OAuth codes, access/refresh tokens, paired site secrets, PKCE verifiers, private keys, full callback query strings, production database dumps, or reproducible exploit details in a public issue.

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
READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → ROLLBACK.

Production defaults:
- direct live writes disabled;
- human approval required;
- AI Lifecycle Control disabled unless an administrator explicitly enables it;
- Test Automation disabled unless an administrator explicitly enables a bounded session;
- site and installation identity preserved across normal upgrades;
- plugin deactivation disables automation modes;
- OAuth uses Authorization Code + PKCE S256;
- access is tenant/site/scope bound;
- site credentials and OAuth credentials are distinct;
- secrets are stored hashed or sealed where applicable;
- failed operations do not consume successful-action quota.

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
- model-provider API keys.

Sanitized error codes and hashes may be retained for audit and reconciliation.

## Security release gate

A public release must not be marked ready solely from unit tests. Required evidence includes:
- multi-tenant isolation;
- OAuth/replay/revoke boundaries;
- release artifact provenance;
- secret scanning;
- destructive-operation conflict protection;
- fresh install/upgrade/reactivation tests;
- public onboarding checks;
- a final live acceptance against a disposable WordPress site.

GitHub Actions/runner infrastructure failures are treated separately from product test failures and must be resolved before closing the release gate.
