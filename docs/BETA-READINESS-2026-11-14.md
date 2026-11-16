# Beta readiness — plans, quotas and licences

**Status: NOT READY for production publication.** The approved plan catalogue and the hash-only licence registry are implemented, tested locally and green in hosted CI. Open Beta publication remains blocked by the release gates in section 8; this document does not convert any gate into a pass.

**Baseline:** `main` `4607a330b3ff2cc5f6983c16fd41082d287faa91` (squash of PR #192), Native **2.1.17** (`OAI_DB_VERSION=24`), unreleased Cloud **0.3.13-dev** on the recovered 0.3.11 baseline (Gateway schema **4**).

## 1. Scope of this milestone

Completed in this slice:

- the approved Free/Pro/PRO+/PRO++ catalogue in `wp-ai-admin-cloud/config/plans.json` (display names; internal slugs `business`/`businesspro` unchanged);
- Free at **100 actions per UTC day** in the active `tariffs_v2` terms and in the target `schemes.tariffs_v3`;
- paid quotas left `null` with `quota_status=requires_owner_approval` and billable calls failing closed;
- the Cloud-owned licence registry with hash-only keys, lifecycle, activation limits, reissue and account fallback;
- Cloud authority over plan, licence, quota and balance, with no client-supplied identity trusted;
- admin/owner UI and HTTP routes for issue, activate, deactivate, reissue, revoke, suspend and reactivate;
- paired-site activation from the Native dashboard, authenticated by the existing `site_url` + `status_key` pairing credential, with Cloud-derived owner/site/plan and a signed-usage refresh after activation;
- Batch enabled in the catalogue as `successful_object_v1` (charged per successful object) for `cloud_site_batch_call`;
- licence, plan-label and quota values in the Native client dashboard, taken only from the server-signed projection;
- documentation, contracts and CI coverage for the above.

Explicitly outside this slice: payments, automatic money debits, auto-renewal, paid quota values, the v2→v3 account/tariff migration, scheduled `batch_create`/`batch_resume`, production deployment, release tags, real key issuance and repository visibility changes.

The Batch enablement, licence-claim projection and dashboard rebuild passed exact-HEAD hosted CI and were merged in PR #192. The paired-site activation change in PR #193 requires its own exact-HEAD hosted CI before merge and is not a production-readiness claim.

## 2. Commercial state

| Plan | Price | Quota | Period | Status |
|---|---:|---:|---|---|
| Free | 0 ₽/month | 100 | UTC calendar day | approved |
| Pro | 2,000 ₽/month | `null` | UTC calendar month | `requires_owner_approval` |
| PRO+ | 5,000 ₽/month | `null` | UTC calendar month | `requires_owner_approval` |
| PRO++ | 10,000 ₽/month | `null` | UTC calendar month | `requires_owner_approval` |

- All tools remain available on every plan; plans differ by included quota.
- Plan and period assignment is manual. There are no automatic debits, auto-renewal or payment-method charges.
- `default_scheme` remains the frozen `tariffs_v2`, so existing and newly provisioned accounts keep their persisted v2 terms. `schemes.tariffs_v3` is the approved target and is not applied by this milestone.
- A pending paid quota signs `limit=null` and `remaining=null` with `quota_status=requires_owner_approval`. It never fabricates zero or unlimited balance.
- Billable calls on a pending paid plan fail with `plan_quota_not_configured` before reaching the site. Reads, verification, receipts, rollback and usage projection remain available.

## 3. Licence and authority model

- Cloud Core alone derives the account, site, plan and licence from the authenticated identity and its own rows. Native, MCP and OAuth clients cannot submit a trusted plan, quota, `account_id`, `site_id`, tenant or `license_id`.
- Licence keys are `wpai_lic_` plus 43 base64url characters (256 bits of entropy). Only `SHA-256(key)` and a 16-character display prefix are stored. The plaintext is returned once on manual issue or reissue; automatic bound keys are discarded.
- States: `unused` (input alias `generated`), `active`, `suspended`, `expired`, `revoked`, `exhausted`, `replaced`.
- An unbound manual licence stays `unused` until activation binds the authenticated owner and one of their sites. A licence bound to another account is rejected. Activation beyond the configured limit fails closed.
- A paired Native site activates a purchased key through `POST /site/license/activate` using its existing read credential. Cloud derives the owner and site from its own row, throttles attempts, returns `wpai-site-license-v1` without the plaintext key or hash, and never accepts a client-supplied plan/account/site/licence id. The Native dashboard shows only the refreshed signed projection.
- An active linked licence is authoritative and reconciles a stale or tampered `accounts.plan` to the licensed terms. An unusable linked licence falls the account back to Free with `license_id=NULL`; accounts without a linked licence keep their historical plan.
- Reissue preserves the remaining expiry, activation limit, plan, scheme and comment, marks the previous row `replaced`, revokes its activations and returns the new key once.
- Licence events are append-only and hashed; they are not yet hash-chained (section 7).

## 4. Verification evidence

### Local suites

Before the Free/UTC-day merge, the following ran from the affected worktrees:

| Area | Result |
|---|---|
| Cloud Core functional suites | **17/17 passed** |
| `tests/license-registry.php` | **260 assertions passed** |
| `tests/plan-catalogue-v3.php` | **98 assertions passed** (Free daily/approved, paid monthly/pending, fail-closed gate) |
| `tests/billing-proof.php` | **58 assertions passed** on SQLite; the deterministic parallel-limit race is MySQL/CI-only |
| `tests/cloud-license-activation.php` (new in #193) | **27 assertions passed** locally: request shape, server-derived identity, signed-usage refresh, pending quota, local refusals, Cloud error mapping, no local key storage and invalid-signature rejection |
| Native suites | **18/18 passed** before this activation test was added |
| Cloud standalone static contracts | **6/6 passed** |
| PHP syntax checks and `git diff --check` | clean |

The 260-assertion licence contract covers key format/hash/one-time display, binding, activation limits, idempotency, cross-account isolation, automatic issuance, legacy compatibility, plan lineage, reissue, suspend/reactivate/revoke, expiry fallback, event evidence and schema/transaction guards. MySQL variants run in CI because no local MySQL is available.

`tests/repository-boundary.php` is a Native-only publication check. It is expected to fail when run from the monorepo root because `wp-ai-admin-cloud/` is present; CI copies the Native files into a staging directory and runs it there. No standalone local pass is claimed for the monorepo root.

### Hosted CI

PR #192 exact head `ee4d370` passed both full workflows and was squash-merged as `main` `4607a33`:

- [Native validation run 43805159573](https://github.com/darcreid163/okean-ai-admin/actions/runs/43805159573)
- [Cloud Core validation run 43805159528](https://github.com/darcreid163/okean-ai-admin/actions/runs/43805159528)

The preceding `main` `436aa3b` and PR #189 evidence remain archived in the workflow history.

The green workflows cover Cloud contracts on PHP 8.2/8.3/8.4 with SQLite and MySQL, including OAuth/site isolation, atomic ledger and batch accounting; WordPress smoke and upgrade; browser contracts; artifact checks; Native PHP and package-install jobs. These results apply to the cited SHAs only. This documentation branch requires its own exact-HEAD CI before merge.

The artifact jobs built and checked packages for the cited SHAs, but no artifact is approved for publication and no new ZIP checksum is claimed here.

### Accounting proof (synthetic)

The plan-catalogue contract exercises the ledger directly:

- a billable call on a pending paid plan returns `plan_quota_not_configured`, reaches no site execution and leaves no request or balance row;
- `operation_verify` and `operation_rollback` remain available and are charged **0**;
- after switching the same account to the approved Free plan, a successful `wp_get_site` returns `charged_units=1`;
- server usage reports `limit=100`, `used=1`, `reserved=0`, `remaining=99`;
- the signed site-usage projection carries the same `100/1/99` values and `quota_status=approved`;
- switching back to a pending paid plan blocks the next billable call before execution.

This proves the automated fail-closed and single-charge paths. It is not a live billing proof: no real customer, payment or production ledger is involved.

## 5. What is intentionally not changed

- No account was migrated to `tariffs_v3` and no plan was re-rated.
- No paid quota number was invented, inferred or carried over.
- No production deployment, release tag, repository visibility change or real licence key issuance was performed.
- No existing account, licence, OAuth connection or site binding was deleted.
- Batch is enabled by the shipped catalogue (`successful_object_v1`, charged per successful object) for `cloud_site_batch_call`; an explicitly stored `wpai_gateway_batch_object_v1` option can disable it. Scheduled `batch_create`/`batch_resume` remain gated until their receipt accounting is ready.
- No telemetry path can change a balance; the Gateway Ledger remains the sole charging authority.

## 6. Compatibility and data safety

- Historical databases without `accounts.license_id` continue to serve read paths without lazy schema creation or warnings.
- The licence schema is created lazily by `Store::ensureLicenseSchema()` only when a licence operation or account provisioning runs. It is deliberately not part of the Gateway DB-version migration, so the historical 0.3.11→0.3.13 upgrade/restore exact-table-set assertion remains intact.
- DDL runs before transactions because MySQL implicitly commits on `CREATE TABLE`/`ALTER TABLE`; using the schema inside an open transaction fails with `license_schema_unavailable`.
- Incompatible persisted plan rows are quarantined per `scheme|plan` instead of being force-licensed.
- The dormant Cloud Core `wpai_cloud_*` tables remain separate from the Gateway `wpai_gateway_*` registry.

## 7. Known limitations and residual risks

| Severity | Item | Status |
|---|---|---|
| P0 | Paid quota numbers for Pro/PRO+/PRO++ are not approved | blocked on owner; fail-closed |
| P0 | v2→v3 account/tariff migration is not authorized or rehearsed | not started |
| P1 | Real staging upgrade/rollback, installed-file parity and external-client acceptance are not complete | gate open |
| P1 | Full Elementor/Gutenberg/WooCommerce/Woodmart end-to-end matrix is not covered by this slice | gate open for beta |
| P2 | Admin activation of an unbound licence binds it to the acting admin account | UX follow-up needed |
| P2 | PRO++ (`businesspro`) is selectable while the default scheme is v2 and then yields `unknown_plan` | UI/validation follow-up needed |
| P2 | Licence events are individually hashed but not hash-chained | hardening follow-up |
| P2 | MySQL coverage exists only in CI; no local MySQL is available | documented environment gap |
| P3 | Historical dated release documents still contain superseded tariff examples | preserved as history; this report and `MIGRATION-STATUS.md` are current |

## 8. Release gates snapshot

- [x] Plan catalogue target fixed at Free 100/UTC day; paid plans pending with null limits.
- [x] Hash-only licence registry, lifecycle and account fallback implemented and covered by SQLite/MySQL CI.
- [x] Billable calls fail closed on unapproved paid plans; non-billable paths remain available.
- [x] Exact-HEAD Native and Cloud CI green for the merged plan/licence baseline.
- [ ] Owner supplies and approves paid quota numbers.
- [ ] Owner authorizes and rehearses the `tariffs_v3` account/tariff migration, including rollback.
- [ ] Real staging install, upgrade, reconnect, reactivation and rollback pass.
- [ ] Documented P0/P1 workflow matrix passes on the declared compatibility set.
- [ ] Installed artifacts match archived hashes and contain no Cloud code in the Native ZIP.
- [ ] Final owner approval for publication, deployment, release tags and real key issuance.

## 9. Next action

The next blocking decision is the owner's: supply the paid quota numbers and explicitly authorize or defer the `tariffs_v3` migration. Until both are resolved, paid billable calls stay fail-closed and the public release remains **NOT READY / PUBLICATION BLOCKED**. In parallel, the two P2 admin UX issues can be fixed as separate focused changes.
