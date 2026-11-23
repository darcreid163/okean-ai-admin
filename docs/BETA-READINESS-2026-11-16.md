# Beta readiness — final five-tier daily tariff policy (16 November 2026)

**Status: final decision implemented on a focused candidate; production activation is pending.** PR #206 (`0874c1c5…`) merged the five daily quotas. The owner then set the **final** terms on 16 November 2026: Daily **800 ₽**, every tariff counted per UTC day, and all other schemes retired. That decision is implemented on a focused branch; exact-HEAD CI results belong to the pull request, not to this document. Do not claim the catalogue is live until Cloud Core is deployed and `/status` + `/plans` are rechecked.

## 1. Final owner-approved tariff catalogue

| Tariff | Slug | Price | Quota | Reset |
|---|---|---:|---:|---|
| Free | `free` | 0 ₽/month | 50 actions | 00:00 UTC daily |
| Daily | `daily` | 800 ₽/month | 500 actions | 00:00 UTC daily |
| Pro | `pro` | 2,000 ₽/month | 2,000 actions | 00:00 UTC daily |
| PRO+ | `business` | 5,000 ₽/month | 5,000 actions | 00:00 UTC daily |
| PRO++ | `businesspro` | 10,000 ₽/month | 10,000 actions | 00:00 UTC daily |

All five quotas are owner-approved and counted per UTC day; `prices_approved` is `true`. All tools remain available on every plan. Successful single-tool operations count as one action; Batch is charged per successfully processed object. Failed operations, verification, receipts and rollback retain their existing non-billable treatment. Automatic money debits and automatic renewals remain disabled.

## 2. Implementation scope of the final decision

- `wp-ai-admin-cloud/config/plans.json`: policy `tariffs-v5-20261116`, `prices_approved: true`, single active scheme `schemes.tariffs_v3` (also `default_scheme`), no `legacy_plans` block, no `power`/`agency`/`scale` slugs.
- `QuotaPolicy::validate()` rejects monthly periods, retired offers and undefined schemes; `offers()` is strict, and `normalizePlan()`/`assignmentScheme()` provide the transfer map.
- `Core::migrateRetiredSchemes()` performs the one-time, idempotent, audited transfer of retired account **and** licence rows (plan slugs mapped to the closest final plan, unknown slugs skipped and audited, historical balances untouched).
- `Licenses::migrateRetiredScheme()` and the billable path guarantee a stale licence cannot revert a migrated account.
- `/wp-json/wpai-cloud/v1/plans` reports the approved catalogue, Daily 800 ₽ and the final default scheme.
- Tests cover the five quotas, UTC windows, scheme offers, retired-scheme resolution, cutover idempotence/audit, balances and signed projections.
- README, mission, migration status, licence registry and the Billing skill record the final decision.

## 3. Account and migration boundaries

- Monthly accounting is retired: `legacy_monthly_v1` and `tariffs_v2` are no longer offered or assignable, and read-time resolution maps them to the final daily terms.
- Historical accounts and licences are rewritten only by the audited cutover; it records `quota_scheme_retired`/`plan_slug_retired` per account and `scheme_retired` per licence. Unknown slugs are never guessed.
- Historical monthly balance rows are preserved untouched for audit; the current daily window starts at zero. No balance is transferred or invented.
- Production cutover timing, licence re-issuance, site binding changes, OAuth changes and payment configuration remain separate, explicitly authorized operations.
- Payment collection, YooKassa, auto-renewal and automatic money debits remain out of scope.

## 4. Live installation state before this change

On 16 November, the user installed Cloud Core `0.3.13-dev` on `wpaiadmin.ru`. The live `/status` endpoint returned success, version `0.3.13-dev`, DB version 2, environment production. The live `/plans` endpoint confirmed the previous catalogue (Free 100, Daily 500, Pro 2,000, PRO+ 5,000; no active PRO++) and the prior target policy (Free 100, Pro 2,000, PRO+ 5,000, PRO++ 5,000). This proves the previously installed build is active, not the catalogue shipped in this repository.

## 5. Verification gates

Rows for PR #206 are the historical merge record. The final Daily-800 / single-scheme change is verified by its own pull request; this document does not convert a pending or failed check into a pass.

| Check | Status |
|---|---|
| Owner approval of five daily quotas (16 Nov) | confirmed |
| PR #206 five-tier daily catalogue | merged — `0874c1c51a8fe367cc7f0ad27fc37bc9cd432cb7`; Cloud CI #334, Native CI #344 |
| Final decision: Daily 800 ₽, daily-only, retired schemes | implemented on a focused candidate branch |
| Final-decision Cloud tests (SQLite) | run locally; exact-HEAD CI in the pull request |
| Final-decision Native tests | run locally; exact-HEAD CI in the pull request |
| Cutover against a real production/restored database | not run; disposable-WordPress upgrade job is CI-only |
| Live Cloud Core deployment | not performed |
| Live /status and /plans verification of the final five tiers | pending |
| Account/licence re-read | pending |
| Production account/licence cutover | not authorized/performed |
| PRO++ test licence issuance | not performed |
| Live quota consumption/reset test | pending |

## 6. Next steps

1. Merge the focused final-tariff PR only after exact-HEAD Cloud and Native checks pass.
2. Deploy the prepared Cloud Core package only after Beget backup/preflight and explicit production apply confirmation.
3. Recheck `/status` and `/plans`; expected active limits are 50 / 500 / 2,000 / 5,000 / 10,000 daily, Daily 800 ₽ and `default_scheme: tariffs_v3`.
4. Re-read accounts, licence schemes and connected-site bindings; confirm the audited cutover audit rows (`quota_scheme_retired`, `plan_slug_retired`, `scheme_retired`) and preserved historical balances.
5. Test usage and reset boundaries with a controlled test account; no real payment collection.

**No production changes, production account migration, licence replacement or live payment changes have been performed by this work.**
