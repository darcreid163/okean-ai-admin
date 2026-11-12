# ChatGPT token renewal qualification (#92)

Status: **blocked on a live ChatGPT client**. This document is the executable
qualification procedure, not evidence that the gate has passed. Do not mark
#92, #40 or #32 as passed from automated fixtures or from this document alone.

The observed 2026-10-22 reconnect recovered after the owner refreshed ChatGPT
tools. That recovery does not prove automatic renewal after the 3600-second
access-token expiry, and the earlier immediate reconnect loop has no confirmed
cause. Current consent requested `mcp:read mcp:write`; refresh credentials are
issued only for `offline_access` (see `CHATGPT-CONNECTION-CONTRACT.md` §3.B.5).

## Non-goals and safety rules

- Do not shorten or extend the server access-token TTL, change refresh
  eligibility, rotate signing/Vault keys, reset site credentials, or edit the
  Cloud configuration to force a pass.
- Do not retry an uncertain write under a fresh idempotency key.
- Do not publish access/refresh tokens, client secrets, authorization codes,
  PKCE verifiers, opaque callback paths, account/user identifiers, raw
  authorization logs or customer data.
- Do not use production content for mutation checks. Use a disposable draft or
  sandbox artifact and preserve the original state.
- A failed or ambiguous observation stays a failure. Record it and stop; do not
  reclassify it as "reconnect expected" without evidence.

## Preconditions

1. Owner access to the ChatGPT app/MCP plugin configuration, including whether
   the client can explicitly request `offline_access`.
2. One central account with two registered sites (for example
   `wpaiadmin.ru` and `okean.market`) and explicit selection of both sites.
3. Native 2.1.17 and Cloud Core 0.3.13-dev (or the exact candidate under test);
   record the actual installed versions and file hashes separately.
4. Sanitized access to central/native logs that shows status codes and error
   codes but never token or code values.
5. A disposable draft page/product/media item for the read/write probe.
6. Enough time to observe a real 3600-second access-token expiry. Do not fake
   expiry by editing the database or server clock.

## Step 0 — inspect the client request

Before connecting, inspect the ChatGPT app configuration and record:

- whether an `offline_access` scope can be requested;
- the exact scopes requested by the client;
- the scopes displayed on the consent screen and the scopes actually granted;
- whether the consent/connection UI shows a refresh or "stay connected" option;
- the client/build identifier shown by ChatGPT.

If the client cannot request `offline_access`, Scenario A is the required path.
If it can, run Scenario A and then Scenario B with a fresh consent.

## Scenario A — consent without `offline_access`

1. Disconnect/revoke any existing ChatGPT grant for this client so the test
   starts from a clean consent. Do not revoke unrelated site credentials.
2. Connect with OAuth/PKCE. Record the consent timestamp (UTC), requested and
   granted scopes, selected sites and the sanitized redirect status.
3. Confirm the baseline: list sites, read each selected site's catalog and read
   each site's manifest/URL. Confirm no cross-site response and no unselected
   site.
4. Record the access-token expiry time from sanitized metadata if available;
   otherwise record the connection time and wait at least 3600 seconds plus a
   safety margin.
5. After expiry, perform one read tool call against each selected site. Record
   only: success/failure, sanitized HTTP/JSON-RPC status, error code, whether
   ChatGPT prompted reconnect, whether a new consent was required, and elapsed
   time.
6. Verify the selected sites, scopes and registration identity are unchanged.
   A duplicate site registration, widened scope, unselected-site access or
   silent fallback to another credential is a failure.
7. Perform one disposable sandbox → approval → apply → verify → rollback flow
   after renewal/reconnect. Confirm the original artifact is restored and no
   duplicate operation or charge is created.
8. Revoke the ChatGPT client grant centrally. Confirm the next call is denied
   and that a new consent is required; confirm other clients/accounts are not
   affected.

Expected outcomes (record which one was observed):

- **A1 — automatic renewal:** the post-expiry call succeeds through the
  intended refresh/reconnect flow without user re-consent, and scopes/sites are
  unchanged.
- **A2 — explicit reconnect:** the post-expiry call fails with a clear,
  sanitized re-authorization signal and the user can reconnect through the
  normal consent flow without a loop or duplicate registration.
- **A3 — fail closed:** the call is denied and the UI explains that the
  connection expired; no silent success and no data leak.

A1 is not required for every client; A2/A3 are acceptable continuity outcomes
only if the user-facing flow is clear and no security invariant is weakened.
An immediate reconnect loop, a 500, a generic "internal error", or a successful
call that bypasses consent is a failure.

## Scenario B — consent with `offline_access` (only if the client supports it)

1. Start from a fresh consent and request `offline_access` explicitly.
2. Record the granted scopes, grant lifetime metadata (30 days per contract) and
   whether a refresh credential was issued. Do not record the credential.
3. Repeat the post-expiry call in Scenario A step 5. Confirm refresh rotation,
   that replay of an old refresh credential revokes/denies the grant, and that
   renewal does not widen site selection or scopes.
4. Revoke the grant and confirm the refresh credential no longer works.
5. Do not wait 30 days to test grant expiry; verify the configured lifetime and
   the revocation path, and record that the 30-day boundary was not observed.

## Two-account and selected-site isolation

Run these checks with the second account, not only the owner account:

1. The second account cannot list, read or call the first account's sites.
2. A site not selected in the current grant is denied even if it belongs to the
   same account.
3. Revoking one client grant does not grant access to another client/account.
4. After reconnect, old grants for the same registration remain denied where
   the contract requires it.

## Evidence record

Attach this table to #92 (one row per scenario/attempt). Keep it sanitized.

| Field | Value |
|---|---|
| Date/time UTC | |
| Tester / role | |
| ChatGPT client/build | |
| Native version + file hash | |
| Cloud Core version + file hash | |
| Requested scopes | |
| Granted scopes | |
| `offline_access` requested/granted | |
| Selected sites (labels only) | |
| Access expiry observed (yes/no, method) | |
| Post-expiry result (A1/A2/A3/fail) | |
| Sanitized HTTP/JSON-RPC status + error code | |
| Reconnect prompt/loop observed | |
| Scopes/sites unchanged | |
| Duplicate registration | |
| Disposable mutation/verify/rollback result | |
| Revocation result | |
| Two-account/unselected-site result | |
| Notes / limitations | |

## Pass criteria and release mapping

The #92 gate passes only when:

- the client request/consent scopes and `offline_access` behavior are recorded;
- a real access-token expiry was observed (not simulated);
- the post-expiry behavior is A1/A2/A3 with no silent success, loop, scope/site
  widening, duplicate registration or credential leak;
- the disposable mutation lifecycle and revocation checks pass;
- the two-account/unselected-site checks pass;
- the evidence row is attached to #92 and reviewed by the owner.

This gate maps to #40 ordered gate 5 and #32 "Clean external ChatGPT
OAuth/MCP authorization gate". Automated fixtures in Native/Cloud CI remain
necessary but cannot substitute for this live observation.

## If the test fails

1. Stop and preserve sanitized evidence (status/error codes, timestamps, client
   build, versions). Do not publish secrets.
2. Do not rotate keys, extend grants or change server TTL to make the symptom
   disappear.
3. Open a focused issue with the observed scenario, expected behavior and
   reproduction steps; link it to #92.
4. Restore only the intended access state and confirm the other site/account is
   unaffected.

## References

- `docs/CHATGPT-CONNECTION-CONTRACT.md` §3.B, §4 and §8.
- `docs/PUBLIC-RELEASE-READINESS.md` external OAuth gate.
- `docs/OPEN-BETA-AUDIT-2026-11-06.md` live-client limitation.
- `docs/PRE-INSTALL-CHECKLIST-2026-10-23.md` OAuth/expiry row.
