# Data Retention and Uninstall Policy

Status: release-candidate policy for WP AI Admin 2.1.15 / Cloud Core 0.3.6.

## Principle

Deactivation is not uninstall and must never silently destroy user data, identity, audit history, operation history, OAuth registrations, site registrations, quota/accounting history or recovery evidence.

## Native plugin deactivation

When WP AI Admin is deactivated:
- AI Lifecycle Control is disabled;
- Test Automation is disabled;
- persistent site identity remains;
- installation identity remains;
- MCP/OAuth credential records remain;
- license state remains;
- sandbox/operation history remains;
- user content is not deleted.

On reactivation, autonomous modes remain OFF until explicitly enabled again.

## Uninstall

The current release candidate intentionally has no automatic destructive uninstall routine.

Reason: automatically deleting identities, operation receipts, audit/recovery evidence, OAuth registrations or license/accounting state could make incident investigation, rollback evidence or reconnection unsafe.

Before a future uninstall/data-erasure feature is implemented, it must define:
- exact native tables/options to remove;
- exact Cloud Core records to remove;
- ownership/authorization requirements;
- export/backup behavior;
- retention periods for security/audit/accounting evidence;
- revocation ordering;
- multi-site and shared-account effects;
- confirmation UX;
- recovery/rollback limitations;
- applicable privacy/legal requirements.

## User-requested erasure

Public commercial release must provide a documented account/site deletion process consistent with the final Privacy Policy and Terms. Until that legal/product workflow is finalized, do not represent uninstall as complete data erasure.

## Secrets

Raw access tokens, refresh tokens, pairing credentials, authorization codes and PKCE verifiers must not be retained in plaintext. Hashed/sealed records and sanitized audit evidence may persist only as required by the product's security, reconciliation and accounting model.


## Central account suspension

The release candidate now provides a revoke-first self-service suspension flow.

Before suspension, the authenticated owner can request a non-destructive closure preview containing counts only. It does not return site credentials, bearer tokens or OAuth secrets.

Confirmed suspension:
- disables the Cloud Core account;
- revokes Cloud bearer tokens;
- revokes OAuth grants and invalidates unused authorization codes;
- marks active site registrations revoked;
- clears sealed site credentials from active registrations;
- preserves request ledger, audit and balance evidence;
- does not perform destructive data deletion.

A suspended account is not automatically re-enabled by normal account bootstrap/ensure logic.

Permanent deletion remains a separate future process governed by the final retention/privacy policy.
