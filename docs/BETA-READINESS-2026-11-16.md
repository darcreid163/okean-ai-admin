# Beta readiness — final five-tier daily tariff policy (16 November 2026)

**Status: tariff implementation in review; production activation is pending.** The owner has approved the final five daily quotas. Implementation is on focused branch `fix/final-five-daily-tariffs-205`, tracked by issue #205. Do not claim that the new catalogue is live until the Cloud Core package is rebuilt, deployed and its runtime API is rechecked.

## 1. Final owner-approved tariff catalogue

| Tariff | Slug | Price | Quota | Reset |
|---|---|---:|---:|---|
| Free | `free` | 0 ₽/month | 50 actions | 00:00 UTC daily |
| Daily | `daily` | not set | 500 actions | 00:00 UTC daily |
| Pro | `pro` | 2,000 ₽/month | 2,000 actions | 00:00 UTC daily |
| PRO+ | `business` | 5,000 ₽/month | 5,000 actions | 00:00 UTC daily |
| PRO++ | `businesspro` | 10,000 ₽/month | 10,000 actions | 00:00 UTC daily |

Daily pricing is intentionally unset; no price is to be invented. Existing monthly prices for Pro, PRO+ and PRO++ remain unchanged. All tools remain available on every plan. Successful single-tool operations count as one action; Batch is charged per successfully processed object. Failed operations, verification, receipts and rollback retain their existing non-billable treatment. Automatic money debits and automatic renewals remain disabled.

## 2. Implementation scope in issue #205

- `wp-ai-admin-cloud/config/plans.json`: versioned five-plan policy (`tariffs-v4-20261116`), all quotas approved and daily UTC in both active `tariffs_v2` catalogue and target `tariffs_v3`.
- Stable slugs remain `free`, `daily`, `pro`, `business`, `businesspro`; display names remain Free, Daily, Pro, PRO+, PRO++.
- `QuotaPolicy::validate()` requires all five active plan slugs and daily periods.
- `/wp-json/wpai-cloud/v1/plans` reports daily fields correctly, returns actual quota approval state, and leaves Daily price null until approved.
- Tests cover five quotas, UTC windows, scheme offers, balances, and signed projections.
- README and release mission record the final decision.

## 3. Account and migration boundaries

- `default_scheme` remains `tariffs_v2` for compatibility; its active catalogue is updated to the final five daily quotas.
- Existing `legacy_monthly_v1` accounts and licences retain their historical monthly terms. No legacy account is silently migrated or repriced.
- Existing current-scheme accounts resolve the newly shipped active catalogue when the new Cloud Core build is deployed. Confirm any production account/license effect before deployment.
- No account migration, licence issuance/replacement, site binding change, OAuth change or payment configuration change is included in issue #205.
- Payment collection, YooKassa, auto-renewal and automatic money debits remain out of scope.

## 4. Live installation state before this change

On 16 November, the user installed Cloud Core `0.3.13-dev` on `wpaiadmin.ru`. The live `/status` endpoint returned success, version `0.3.13-dev`, DB version 2, environment production. The live `/plans` endpoint confirmed the previous catalogue (Free 100, Daily 500, Pro 2,000, PRO+ 5,000; no active PRO++) and the prior target policy (Free 100, Pro 2,000, PRO+ 5,000, PRO++ 5,000). This proves the previous build is active, not the new issue #205 changes.

## 5. Verification gates

| Check | Status |
|---|---|
| Owner approval of five final daily quotas | confirmed |
| Focused issue and branch | created — #205 |
| Cloud Core source/config changes | committed to branch |
| Policy validation and API projection changes | committed to branch |
| Five-tier contract tests | committed to branch; CI pending |
| README and release mission | updated on branch |
| Exact-HEAD Cloud Core CI | pending |
| Exact-HEAD Native CI | pending / run if shared workflow requires it |
| Package build and ZIP inspection | pending |
| Live Cloud Core deployment | not performed |
| Live /status and /plans verification of final five tiers | pending |
| Account/license re-read | pending |
| Legacy account migration | not authorized/performed |
| PRO++ test licence issuance | not performed |
| Live quota consumption/reset test | pending |

## 6. Next steps

1. Complete exact-HEAD Cloud Core CI and inspect failures.
2. Review the final diff for policy/API/test consistency.
3. Build a new installable Cloud Core package from the reviewed branch/merge commit; verify ZIP contents and SHA-256.
4. Deploy only after backup/preflight and explicit production apply confirmation.
5. Recheck `/status` and `/plans`; expected active limits are 50 / 500 / 2,000 / 5,000 / 10,000 daily.
6. Re-read the current account, licence scheme and connected-site bindings. Do not replace the current Free licence or migrate legacy accounts without a separate, explicit decision.
7. Test usage and reset boundaries with a controlled test account; no real payment collection.

**No production changes, account migration, licence replacement or live payment changes have been performed by issue #205.**
