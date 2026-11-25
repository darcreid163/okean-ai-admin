# ChatGPT OAuth expiry and re-authorization qualification (#92)

Status: **owner-run live-client acceptance pending**. The server policy is fixed:
Native and Cloud Core support Authorization Code + PKCE only. They do not
support `offline_access`, do not issue refresh tokens, and reject every
`refresh_token` grant. After a 3600-second access token expires, the user must
authorize again. This document qualifies the external ChatGPT client experience;
it does not authorize a different server token policy.

## Fixed server contract

- Allowed scopes: `mcp:read` and `mcp:write`; `offline_access` is rejected.
- Allowed OAuth grant: `authorization_code` only.
- Refresh-token issuance and exchange are disabled in Native and Cloud Core.
- Access tokens remain short-lived (3600 seconds).
- After expiry, the client must request a new authorization-code flow and obtain
  fresh user consent. Silent renewal is not an expected or supported outcome.
- Existing legacy refresh credentials are not usable after this cutover. A still
  live access token remains valid until its own expiry or revocation.
- Do not shorten or extend token TTL, re-enable refresh, rotate keys, or alter
  production credentials to force a test result.

## Preconditions

1. Owner access to a clean external ChatGPT account and its MCP connection UI.
2. Two independently owned central accounts and explicitly selected sites, when
   the second tester is available.
3. Exact candidate versions and file hashes recorded separately.
4. Sanitized Native/Cloud logs showing status and error codes only.
5. A disposable draft page/product/media item for the sandbox lifecycle probe.
6. Enough time to observe a real access-token expiry. Do not fake expiry by
   editing the database or changing the server clock.

## Scenario A — initial authorization

1. Start with a clean ChatGPT connection. Revoke only the old grant being tested;
   do not revoke unrelated site credentials.
2. Connect through OAuth/PKCE and record requested/granted scopes, consent time,
   selected sites and sanitized redirect result.
3. Confirm the client receives only an access token and that no refresh token is
   issued or stored.
4. Confirm `offline_access` is absent from discovery and consent scopes. A
   request containing it must be rejected.
5. List sites, read each selected site's catalog and manifest, and confirm that
   no unselected or other-account site is visible.
6. Perform one disposable sandbox → approval → apply → verify → rollback flow.
   Confirm the original object is restored and no duplicate operation or charge
   is created.

## Scenario B — real access-token expiry

1. Record the access-token expiry from sanitized metadata if available; otherwise
   record connection time and wait at least 3600 seconds plus a safety margin.
2. After expiry, make one read call. Record the sanitized HTTP/JSON-RPC status,
   error code, whether ChatGPT presents a reconnect/authorization prompt, and
   whether a fresh consent is required.
3. Complete a fresh Authorization Code + PKCE flow. Confirm the same account,
   site identity and explicitly selected sites are preserved without duplicate
   registration or scope widening.
4. Confirm the new access token works and the expired token remains rejected.
5. Confirm a `refresh_token` grant is rejected with
   `unsupported_grant_type`, including when a legacy token value is supplied.

Expected outcome: **explicit re-authorization after expiry**. A clear fail-closed
expiry response is acceptable while the client is waiting for the user to
re-authorize. Silent access after expiry, hidden credential fallback, a reconnect
loop, a 500, widened scopes/sites, or duplicate registration is a failure.

## Scenario C — revocation and tenant isolation

1. Revoke the tested client grant centrally. Confirm the next call is denied.
2. Confirm another client/account remains unaffected.
3. With a second independently owned account, prove cross-account and
   unselected-site reads/calls are denied.
4. Confirm a newly registered site is not implicitly added to an old grant.
5. Confirm no access token or authorization code is exposed in logs or evidence.

## Evidence record

Attach one sanitized row to issue #92:

| Field | Value |
|---|---|
| Date/time UTC | |
| Tester / role | |
| ChatGPT client/build | |
| Native version + file hash | |
| Cloud Core version + file hash | |
| Requested / granted scopes | |
| `offline_access` rejected | |
| Refresh token issued | Must be no |
| Refresh grant rejected | |
| Selected sites (labels only) | |
| Real access-token expiry observed | |
| Expired-token result | |
| Fresh consent / reconnect result | |
| Old token rejected after expiry | |
| No duplicate registration / scope widening | |
| Disposable lifecycle / rollback result | |
| Revocation result | |
| Two-account / unselected-site result | |
| Notes / limitations | |

## Pass criteria and release mapping

The #92 gate passes only when:
- discovery, authorization and registration expose no `offline_access`;
- no refresh token is issued or persisted and the refresh grant is rejected;
- a real access-token expiry is observed;
- the external client requires fresh user authorization and reconnects without a
  loop, duplicate registration, scope/site widening or credential leak;
- revocation, two-account isolation and unselected-site denial pass;
- the disposable mutation lifecycle and rollback pass; and
- sanitized evidence is attached to #92 and reviewed by the owner.

Automated Native/Cloud tests are necessary but cannot substitute for this
owner-run external-client observation.

## If the test fails

1. Stop and preserve sanitized evidence (status/error codes, timestamps, client
   build and versions). Do not publish secrets.
2. Do not re-enable refresh tokens, extend token lifetime, rotate keys or reset
   unrelated credentials to make the symptom disappear.
3. Open a focused issue with the observed behavior and reproduction steps.
4. Restore only the intended access state and confirm other accounts/sites are
   unaffected.

## References

- `docs/CHATGPT-CONNECTION-CONTRACT.md` (historical clauses are superseded by
  the strict no-refresh policy above).
- `docs/CHATGPT-CONNECTION-CONTRACT-ADDENDUM-2026-11-18.md`.
- `docs/PUBLIC-RELEASE-READINESS.md` external OAuth gate.
