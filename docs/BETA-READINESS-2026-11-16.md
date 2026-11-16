# Beta readiness — owner-approved daily quotas (16 November 2026)

**Status: NOT READY for production deployment or licence issuance.** The daily quota policy is merged into `main` and exact-HEAD Cloud Core and Native CI passed. The policy has **not** yet been deployed to the live Cloud Core service, and no account or licence has been changed.

**Merged baseline:** `main` merge commit `ba018858d3d13aa9d087dfdbdf217b10917880b8` (PR #204; source HEAD `56f24df159b12f9f65f68e7a63446c3c6014b3ca`). Cloud Core runtime remains `0.3.13-dev` until a separately verified deployment. Native code and production WordPress data were not changed by PR #204.

## 1. Owner-approved catalogue

| Tariff | Slug | Price | Quota | Reset |
|---|---|---:|---:|---|
| Free | `free` | 0 ₽/month | 100 actions | 00:00 UTC daily |
| Pro | `pro` | 2,000 ₽/month | 2,000 actions | 00:00 UTC daily |
| PRO+ | `business` | 5,000 ₽/month | 5,000 actions | 00:00 UTC daily |
| PRO++ | `businesspro` | 10,000 ₽/month | 5,000 actions | 00:00 UTC daily |

All tools remain available on every tariff. Successful single-tool operations count as one action; Batch is charged per successfully processed object. Failed operations, verification, receipts and rollback retain their existing non-billable treatment. There are no automatic money debits or automatic renewals.

## 2. Merged implementation

- `wp-ai-admin-cloud/config/plans.json`: target `tariffs_v3` contains all four approved numeric limits and daily UTC periods; catalogue version is `tariffs-v3-20261116`.
- `wp-ai-admin-cloud/src/QuotaPolicy.php`: stale persisted pending rows cannot downgrade a quota explicitly approved in the shipped catalogue. Persisted approved overrides remain effective only when the shipped catalogue still marks that plan pending.
- `wp-ai-admin-cloud/tests/plan-catalogue-v3.php`: tariff values, approval states, daily periods, UTC reset window, stale-pending-row protection and fail-closed behavior are covered.
- `docs/OPEN-BETA-RELEASE-MISSION.md`: reflects the owner's confirmed 16 November quota decision.

## 3. Compatibility and migration boundaries

- `default_scheme` remains `tariffs_v2`. This change does not silently move existing accounts or alter historical `legacy_monthly_v1` entitlements.
- A new PRO++ test licence must explicitly use `tariffs_v3`; it must be issued only after deployment and live runtime verification.
- The existing Free licence and site bindings have not been modified by PR #204.
- Existing v3 database rows that still say `requires_owner_approval` are ignored when the shipped catalogue explicitly approves that plan. No destructive database migration is included.
- Payments, YooKassa, auto-renewal and automatic money debits remain out of scope.

## 4. Verification status

| Check | Status |
|---|---|
| Read root `AGENTS.md` and release mission | done |
| Inspect main, PR and exact HEAD | done |
| PR #204 merged to main | done — `ba018858d3d13aa9d087dfdbdf217b10917880b8` |
| Exact-HEAD Cloud Core CI | passed — run #322 |
| Exact-HEAD Native CI | passed — run #332 |
| Cloud PHP 8.2/8.3/8.4, SQLite/MySQL, OAuth, tenant isolation, Batch, browser contracts | passed in run #322 |
| Native PHP 8.2/8.3/8.4, WordPress pinned/latest, Elementor/WooCommerce/Gutenberg, package build/install | passed in run #332 |
| Runtime policy endpoint / signed usage projection | pending live deployment |
| Live Cloud Core deployment and rollback evidence | not performed |
| Account re-read and PRO++ licence issuance | not performed |
| Live quota consumption/reset test | not performed |

CI artifacts are test artifacts only; they are not proof of production deployment.

## 5. Next steps

1. Identify and verify the approved Cloud Core deployment target and rollback method; do not deploy the public monorepo source as a substitute for the private Cloud Core distribution.
2. Build/export the Cloud Core package from the merged reviewed source, verify its contents and checksum, and deploy separately with explicit production safeguards.
3. Verify live health/version, effective `tariffs_v3` policy, UTC period boundaries, signed usage projection, tenant isolation and rollback readiness.
4. Re-read the current account/licence and connected-site bindings. Do not replace the Free entitlement until its effect is understood and the new licence can be bound safely.
5. Issue the 30-day PRO++ test entitlement under `tariffs_v3` for 5,000 successful actions per UTC day, then verify usage and reset behavior with controlled tests.

**No production changes, account migration, licence replacement or live payment changes have been performed.**
