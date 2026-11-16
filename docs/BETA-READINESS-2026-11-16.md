# Beta readiness — owner-approved daily quotas (16 November 2026)

**Status: NOT READY for deployment or licence issuance.** The quota-policy candidate has been committed to a focused branch. Exact-HEAD CI and runtime verification are still required. This report does not claim that the candidate is merged, deployed, or active on any account.

**Baseline:** `main` at `d90f4de3c0e71f1eb08848e782ce8f3c1fbfc1f3`; candidate branch `feat/approved-daily-tariffs-v3`; Cloud Core remains `0.3.13-dev`. Native code and production WordPress data are untouched.

## 1. Owner-approved catalogue

| Tariff | Slug | Price | Quota | Reset |
|---|---|---:|---:|---|
| Free | `free` | 0 ₽/month | 100 actions | 00:00 UTC daily |
| Pro | `pro` | 2,000 ₽/month | 2,000 actions | 00:00 UTC daily |
| PRO+ | `business` | 5,000 ₽/month | 5,000 actions | 00:00 UTC daily |
| PRO++ | `businesspro` | 10,000 ₽/month | 5,000 actions | 00:00 UTC daily |

All tools remain available on every tariff. Successful single-tool operations count as one action; Batch is charged per successfully processed object. Failed operations, verification, receipts and rollback retain their existing non-billable treatment. There are no automatic money debits or automatic renewals.

## 2. Candidate changes

- `wp-ai-admin-cloud/config/plans.json`: target `tariffs_v3` now contains all four approved numeric limits and daily UTC periods; catalogue version advanced to `tariffs-v3-20261116`.
- `wp-ai-admin-cloud/src/QuotaPolicy.php`: an old persisted target row marked pending can no longer downgrade a quota explicitly approved in the shipped catalogue. Persisted approved overrides remain available only when the shipped catalogue still marks that plan pending.
- `wp-ai-admin-cloud/tests/plan-catalogue-v3.php`: target tariff values, approval states, daily periods, UTC reset window, stale-pending-row protection and fail-closed behavior are covered.
- `docs/OPEN-BETA-RELEASE-MISSION.md`: updated to the owner's 16 November quota decision.

## 3. Compatibility and migration boundaries

- `default_scheme` remains `tariffs_v2`. This change does not silently move existing accounts or alter historical `legacy_monthly_v1` entitlements.
- A new PRO++ test licence must explicitly use `tariffs_v3`; it must be issued only after the candidate is merged and deployed to Cloud Core.
- The current Free licence and both site bindings are not modified by this branch.
- Existing v3 database rows that still say `requires_owner_approval` are ignored when the shipped catalogue now explicitly approves that plan. No destructive database migration is included.
- Payments, YooKassa, auto-renewal and automatic money debits remain out of scope.

## 4. Verification status

| Check | Status |
|---|---|
| Read root `AGENTS.md` and release mission | done |
| Inspect current main and open PRs | done |
| Update target quota source | committed to candidate branch |
| Add regression assertions | committed to candidate branch |
| PHP syntax and local contract suite | not yet run in this environment |
| Exact-HEAD Cloud Core CI | pending PR |
| MySQL quota and concurrency suite | pending CI |
| Runtime policy endpoint / signed usage projection | pending deployment verification |
| Production deployment / licence issuance | not performed |

The historic test counts in [the 14 November report](BETA-READINESS-2026-11-14.md) apply only to the SHAs listed there and are not evidence for this candidate.

## 5. Next steps

1. Open a focused PR and wait for exact-HEAD Cloud Core CI.
2. Review the diff and verify the daily quota assertions, stale-row precedence and UTC reset metadata.
3. Merge only after all required checks pass.
4. Deploy the Cloud Core candidate separately with rollback evidence.
5. Re-read the account and licence state, then issue the 30-day PRO++ test entitlement under `tariffs_v3` for 5,000 successful actions per UTC day. Verify signed usage and both connected sites before testing.

**No production changes, account migration, licence replacement or live payment changes have been performed.**
