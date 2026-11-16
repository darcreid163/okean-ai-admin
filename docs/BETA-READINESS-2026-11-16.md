# Beta readiness — final five-tier daily tariff policy (16 November 2026)

**Status: merged and packaged; production activation is pending.** The owner has approved the final five daily quotas. Implementation merged to `main` via PR #206, squash commit `0874c1c51a8fe367cc7f0ad27fc37bc9cd432cb7`; issue #205 is closed. Exact-head Cloud Core CI run #334 and Native validation run #344 passed. Candidate package built from tested head `d33436f6df3d7b91deb8d4d3f07d01684757f9e9`; do not claim the new catalogue is live until deployed and its runtime API is rechecked.

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
| Focused issue and branch | merged PR #206; issue #205 closed |
| Cloud Core source/config changes | merged to main — `0874c1c51a8fe367cc7f0ad27fc37bc9cd432cb7` |
| Policy validation and API projection changes | merged to main |
| Five-tier contract tests | passed exact-head Cloud Core CI #334 (PHP 8.2/8.3/8.4, SQLite and MySQL) |
| README and release mission | updated and merged |
| Exact-HEAD Cloud Core CI | passed — run #334 |
| Exact-HEAD Native CI | passed — run #344 (PHP matrix, WordPress smoke and package install) |
| Package build and ZIP inspection | passed — artifact #14209607425; ZIP SHA-256 `01d2f986db0a334b8c1e55b2f0fc99759ff822527e800bc4e6ac12b721dccf32` |
| Live Cloud Core deployment | not performed |
| Live /status and /plans verification of final five tiers | pending |
| Account/license re-read | pending |
| Legacy account migration | not authorized/performed |
| PRO++ test licence issuance | not performed |
| Live quota consumption/reset test | pending |

## 6. Next steps

1. Deploy the prepared installable Cloud Core package only after Beget backup/preflight and explicit production apply confirmation.
2. Recheck `/status` and `/plans`; expected active limits are 50 / 500 / 2,000 / 5,000 / 10,000 daily.
3. Re-read the current account, licence scheme and connected-site bindings. Do not replace the current Free licence or migrate legacy accounts without a separate, explicit decision.
4. Test usage and reset boundaries with a controlled test account; no real payment collection.

**No production changes, account migration, licence replacement or live payment changes have been performed by issue #205.**
