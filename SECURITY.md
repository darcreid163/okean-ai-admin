# Security Policy

## Supported release line

Security fixes are prepared against the current public-beta candidate and the latest published commercial-beta baseline when practical. Historical development branches and superseded test artifacts are not security-supported release channels.

Current candidate (unreleased):
- WP AI Admin native: **2.1.18** (DB schema 25; build `2.1.18-native-rate-limit-hpos-v1-20261117`)
- WP AI Admin Cloud Core: **0.3.13-dev** (Gateway schema 4, derived from the recovered 0.3.11 baseline)

This candidate is not an approved production release. The current Draft integration is PR [#232](https://github.com/darcreid163/okean-ai-admin/pull/232). Native 2.1.18, Cloud Store/key-preservation changes (#226/#224) and SSE close-race hardening are integrated in the release branch; exact-head CI passed at `5ae4e9d9f478e289b7cae954d94de5350b3b61ad` (Native [44478492524](https://github.com/darcreid163/okean-ai-admin/actions/runs/44478492524), Cloud [44478492520](https://github.com/darcreid163/okean-ai-admin/actions/runs/44478492520)); the all-ref secret history scan [44478492445](https://github.com/darcreid163/okean-ai-admin/actions/runs/44478492445) also passed after reviewing 1,855 commits. These are CI results for the current candidate only; the Actions artifacts are not signed public releases. External OAuth expiry, two-owner acceptance, staging and artifact freeze remain outstanding. See [#40](https://github.com/darcreid163/okean-ai-admin/issues/40) and [the 18 November release control](docs/RELEASE-CONTROL-2026-11-18.md).

Quota and per-object Batch accounting are implemented in Cloud Core, but production activation/account migration is a separate gate. Automatic money debits and auto-renewal remain disabled. Do not represent this candidate as a self-service paid service: payment-to-license fulfillment is not qualified.

Current repository visibility and scan limitations are recorded in [the 18 November repository security audit](docs/REPOSITORY-SECURITY-AUDIT-2026-11-18.md). Use the [documentation index](docs/README.md) to distinguish current release authority from historical snapshots.

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

## Scope and visibility of this repository

`darcreid163/okean-ai-admin` is currently PUBLIC. Native and Cloud source in any public branch, PR, commit, Actions log or artifact must be treated as disclosed. Never commit production credentials, server keys, customer data, database dumps or private backups. A private visibility change does not make already downloaded copies, public forks or local clones private.

## Scope of this repository

`darcreid163/okean-ai-admin` is temporarily public for development CI and contains both Native and Cloud Core source. Since all committed source is publicly accessible, this repository must never receive production tokens, OAuth secret keys, server key material, payment credentials, customer information, private WordPress backups or database exports. See `AGENTS.md` and `docs/REPOSITORY-BOUNDARIES.md`.
