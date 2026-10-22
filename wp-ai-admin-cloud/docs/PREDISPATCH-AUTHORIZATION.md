# Reauthorize after catalog lookup

The 0.3.13-dev single-call candidate now rechecks the token, required scope,
selected site, active owner and active registration after `tools/list` returns and
before starting `tools/call`. It accounts for elapsed catalog/reservation time
when checking token expiry, and uses the currently stored sealed site credential.
Previously catalog lookup could outlive revocation or credential rotation while
single-call dispatch still used the earlier authorized site snapshot. Batch
already has a per-step authorization check.

This corrective guard does not extend scopes, site grants, token lifetime or
persistent identity. It uses the existing owner/site checks; no schema migration,
new credential or reset is introduced. Same-owner active reconnection preserves
registration ID but may replace the credential: a subsequent dispatch must use
that current credential, never restore an older sealed copy.

A preparation failure follows #81's zero-charge, never-dispatched failed receipt.
It returns a generic pre-dispatch unavailability error, without site output or
internal transport details. Actual dispatched unknown outcomes still retain their
reservation. Revocation of an already-dispatched call does not erase a confirmed
charge or invalidate safe atomic settlement. This is a check at the dispatch
boundary; it cannot cancel a network request already sent to the remote site.

`tests/predispatch-authorization.php` deterministically revokes tokens, sites and
accounts inside a synthetic catalog response; covers stored expiry and elapsed
clock expiry without sleeping; verifies current credentials after active reconnect;
and checks settlement for a confirmed reply revoked while already in flight.
It uses real Store/Core accounting with disposable SQLite/MySQL fixtures. Cloud
CI declares this gate on PHP 8.2/8.3/8.4. Staging/live acceptance is still separate.
