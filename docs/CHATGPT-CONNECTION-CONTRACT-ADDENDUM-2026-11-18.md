# Connection contract addendum — Native 2.1.18 / Cloud Core 0.3.13-dev

Recorded: 2026-11-18  
Applies to candidate source: `f7b984d1ca224405428aa84726babf4c2bedf10f` and later commits on PR #232  
Status: candidate qualification; **not a production approval**. Strict no-refresh OAuth policy was approved on 2026-11-24 and implemented on the lifecycle branch by PR #251.

## 1. Relationship to the historical contract

`CHATGPT-CONNECTION-CONTRACT.md` records the original 1.2 onboarding/authorization baseline from 2026-10-07 (Native 2.1.14 / Cloud Core 0.3.6). Its security invariants and historical amendments remain relevant unless explicitly superseded below. Its version numbers are historical and must not be read as the current candidate versions.

Current candidate under PR #232:
- Native WP AI Admin 2.1.18, database schema 25.
- Cloud Core 0.3.13-dev, Gateway Store schema 4.
- Integration PR #232 is Draft and unmerged. Main is unchanged.
- Current candidate packages are CI artifacts, not signed public releases.

The 2026-11-24 owner-approved OAuth decision explicitly supersedes the historical refresh eligibility described below: `offline_access` and refresh-token grants are disabled in both Native and Cloud Core. Other site-selection, identity, quota and revocation invariants remain unchanged.

## 2. Preserved connection and security invariants

The following remain mandatory:
- Separate Native site-pairing credentials from ChatGPT-to-gateway OAuth credentials. Never expose a durable Native site credential to ChatGPT.
- Authorization Code + PKCE S256, exact redirect/resource binding, state/nonce checks, single-use expiring codes, and fail-closed validation.
- Every tool invocation rechecks account ownership, token audience, expiry, scopes, and selected-site grants. No global mutable active-site shortcut.
- A newly registered site is not implicitly added to an existing ChatGPT grant.
- Reconnect must preserve the stable site/installation identity and must not create duplicate registrations or silently widen scopes/sites.
- Revocation, replay denial, owner isolation, and unselected-site denial remain separate, testable security boundaries.
- Preserve sandbox → approval → apply → verify/rollback. Direct live writes remain disabled.
- Never log or attach tokens, client secrets, authorization codes, PKCE verifiers, raw callback query strings, or account identifiers.

## 3. Token lifetime and renewal — unchanged, live qualification outstanding

The historical contract specifies 3600-second access tokens, a 30-day grant lifetime, and refresh credentials only when `offline_access` is granted. This addendum does not change those values or eligibility.

The observed ChatGPT consent without `offline_access` does not establish automatic renewal after the access token expires. Acceptable live outcomes are:
- A1: intended automatic renewal without new consent, with scopes/sites unchanged;
- A2: a clear explicit reconnect and fresh consent, without a loop or duplicate registration;
- A3: fail closed with a clear expiry/reconnect explanation.

A 500, generic internal error, immediate reconnect loop, silent fallback credential, widened scope/site selection, or access after revocation is a failure. Do not simulate expiry by changing the database or system clock, extend token TTL, rotate keys, or reset credentials to force a pass.

The complete owner-run procedure and sanitized evidence table remain in `CHATGPT-TOKEN-RENEWAL-QUALIFICATION.md` and issue #92. CI/browser tests do not close this gate.

## 4. Candidate evidence (CI only)

At candidate head `f7b984d1ca224405428aa84726babf4c2bedf10f`:
- Native workflow 44294730031 passed all executed jobs, including PHP 8.2/8.3/8.4, WordPress compatibility smoke, HPOS smoke, deterministic package/provenance checks, and install/boot of the built package on WordPress 7.0.6 and latest. The release job was skipped under PR policy.
- Cloud workflow 44294730043 passed all jobs, including PHP contracts, SQLite/MySQL migration/OAuth/tenant-isolation/billing contracts, browser pairing/OAuth navigation, WordPress upgrade/restore checks, and Cloud candidate build.

Artifacts:
- Native artifact 14423292340, SHA-256 `c356521be8cb951c32fa1bf1a3271ba033ddbefdd62a41bc656e6113ca32943f`.
- Cloud artifact 14423002503, SHA-256 `a679a0809510b966ec5d1893650b7d5061d9fadfc7f1322b8aa44954fdd5577d`.

These are expiring GitHub Actions artifacts, not signed public releases. The workflow evidence is recorded in release tracker issue #40.

## 5. Owner-run acceptance still required

Before any release approval, two independently owned external accounts must be tested with a clean external ChatGPT/MCP client:
1. Confirm requested and granted scopes, selected sites, consent and PKCE behavior.
2. Observe a real 3600-second access-token expiry and record A1/A2/A3 behavior.
3. Prove account-to-account and selected-site isolation, including denied cross-tenant reads/calls.
4. Prove revocation and replay denial without affecting the other account/client.
5. Complete a disposable sandbox → approval → apply → verify → receipt/proof → rollback → verify cycle.
6. Verify no duplicate registration, unexpected quota charge, scope widening, or identity reset.
7. Attach sanitized evidence to issue #92 and obtain owner review.

Do not include credentials, tokens, callback secrets, raw logs, personal account identifiers, or production customer content in the evidence.

## 6. Release status

PR #232 remains Draft and unmerged. No deployment, tag, public release, production-site reconnection, or production mutation is authorized by this addendum. A green exact-head CI result means **green-CI release candidate only**. Manual external acceptance, staging lifecycle/restore, final artifact/security/license review, and release-owner approval remain separate gates.
