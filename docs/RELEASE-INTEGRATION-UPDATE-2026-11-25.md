# Release integration update — 25 November 2026

This addendum supplements the canonical repository reconciliation dated 2026-11-24. It records the latest changes without rewriting the shared main-branch authority document.

## OAuth strict no-refresh decision

The owner approved the strict policy on 2026-11-24. PR #251 was squash-merged into the lifecycle branch as `a7e1ffc9feb1f6bf3e3ae39233e395035b912075`.

- Native and Cloud Core reject `offline_access`.
- OAuth discovery and client registration advertise only `authorization_code`.
- Neither implementation issues or returns refresh tokens.
- Every `refresh_token` grant is rejected with `unsupported_grant_type`.
- Access tokens remain short-lived (3600 seconds); users must complete fresh Authorization Code + PKCE consent after expiry.
- A still-live access token remains valid until its expiry or revocation; legacy refresh credentials are no longer usable.

Exact-head CI on PR #251 passed:
- Native PHP 8.2/8.3/8.4, WordPress latest/HPOS/pinned smoke, ZIP build and package installation.
- Cloud PHP 8.2/8.3/8.4, SQLite/MySQL contracts, WordPress upgrade/restore and browser contracts.

This code is on the integration branch only, not yet on `main` or deployed. Real external ChatGPT expiry/re-authorization remains an owner-run release gate.

## Media creation concurrency finding

The docs-only PR #249 run against `main` failed the latest WordPress/Imagick smoke in the six-process media-creation test. Inspection found that `main` still used `add_option()` as the media-claim admission point. The Native 2.1.18 integration branch uses a strict first-writer SQL insert and its latest-Imagick smoke passed on the exact PR #232 source head.

Do not remove or weaken the six-process assertion. After Native integration reaches `main`, rerun the full smoke and confirm exactly one artifact, one attachment, one operation and five sanitized duplicate/busy denials.

## Current integration state

- PR #232: Native 2.1.18 + Cloud integration baseline; source code checks passed on its original source head. The branch has been synchronized with the five canonical repository-status files from main (#246); exact-head CI should be rerun before merge.
- PR #235: lifecycle approval propagation, strict OAuth PR #251 merge and updated OAuth contract docs; currently stacked on PR #232. Reconcile its base/head and run exact-head CI before merge.
- PR #238–#245: dependent Robokassa/licence stack remains unmerged. Test-mode payment processing is reported working after correcting the Robokassa MerchantLogin, but Result URL settlement, one-time key reveal, email delivery/retry, duplicate callback and refund/revocation still require disposable HPOS end-to-end acceptance.
- No production deployment, live payment, licence mutation or release is included.
