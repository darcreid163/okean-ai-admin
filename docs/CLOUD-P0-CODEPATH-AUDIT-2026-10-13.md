# Cloud Core P0 code-path audit — 2026-10-13

Scope: reviewed **current** `wp-ai-admin-cloud/src/Core.php`, `OAuth.php`, `Store.php` at integration branch, before two-owner live acceptance. This is **source inspection, not a PDO or two-user runtime PASS**.

| Control | Source evidence | Remaining runtime gate |
|---|---|---|
| Per-call bearer revocation and scope | `Core::authorize` re-queries token and requires token owner, scope, selected site membership, account active and `ownedSite` | Real owner A token denied for owner B site; revoked token denied, no quota charge |
| Per-call grant-bound OAuth | `OAuth::binding` checks owner, resource, grant not revoked, expiry | Revocation and suspension on two independently authorized clients |
| Single-use authorization code / PKCE | `OAuth::exchange` checks code used=0, exact client/redirect/resource and SHA256 code verifier under transaction | Concurrent exchange and invalid verifier/replay |
| Refresh rotation and reuse detection | `OAuth::exchange` locks refresh record, marks used, revokes prior access; used refresh causes whole-grant revocation and explicit error after commit | Concurrent refresh and previous-token reuse; ensure fresh access becomes unusable on replay |
| Idempotency | `Core::call` request key owner+nonce, fingerprint site+tool+arguments, cached terminal result, conflict for different fingerprint | Same nonce/payload does not charge twice; different payload same nonce fails; cross-owner nonces isolated |
| Billing | `Core::call` first reserves, `finish` settles success vs failure, free units for verify/rollback. Quota check before transport | used/reserved snapshots before+after success/failure; no surprise charge |
| Uncertain request | `Core::call` preserves reservation as uncertain and forbids blind write retry; privileged `reconcile` requires evidence | Network failure mid-write, reconcile and no duplicated mutation |
| Account suspension | `Core::suspendAccount` revokes tokens, site credentials, OAuth grants | Verify all old sessions rejected without affecting another owner |
| Tenant quota keys | `Store` balances are owner+period, requests owner+nonce hash | Concurrent two-owner balances; SQL transaction behavior on MySQL |

**Potential risk to verify explicitly:** a successful read is billable (not free), and an unknown site tool returns an error after quota reservation, which should settle back to zero. The free-tools set and lifecycle tool mapping require behavior checks, not just source claims.

**CI:** GitHub hosted jobs for this branch reported failure with no step records and BlobNotFound log downloads. Neither a passing nor failing PHP test result can be inferred.

**Release status:** P0 external two-owner, OAuth and transactional quota checks remain **OPEN**. No merge to main or public release from this audit alone.
