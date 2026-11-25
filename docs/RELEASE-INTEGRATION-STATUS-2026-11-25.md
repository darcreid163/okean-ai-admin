# Release integration status — 25 November 2026

> Current status snapshot for the canonical WP AI Admin source repository. Historical notes are retained for audit, not as current instructions.

## Source-of-truth boundaries

- `darcreid163/okean-ai-admin` is the canonical Native + Cloud monorepo.
- Current `main` baseline: `32fa28ca2edb3e66dbdc2c24fde09fe36e49237f` (Native 2.1.18 / Cloud Core 0.3.13-dev).
- The public `wp-ai-admin` repository is a historical Native publication snapshot (README release line 2.1.16). It is not the development source.
- The private `wp-ai-admin-cloud-core` repository is a historical Cloud snapshot (README release line 0.3.6). It is not the development source.
- `WP-AI-ADMIN-TEST` is synthetic test-artifact storage only. It is not a source, release or production repository.
- `universal-mcp-platform` is a separate future product. `okean-market-flutter` is the Okean Market mobile app. `BLASTORIA` is independent. None is a WP AI Admin source mirror or release dependency.

Do not copy historical Native/Cloud snapshots over the canonical monorepo. Export publication artifacts only after this monorepo passes release qualification.

## Approved tariff contract

| Plan | Actions per UTC day | Monthly RUB | Annual RUB |
|---|---:|---:|---:|
| Free | 50 | 0 | — |
| Daily | 500 | 899 | 9,999 |
| Pro | 2,000 | 1,499 | 16,999 |
| PRO+ | 5,000 | 3,999 | 46,999 |
| PRO++ | 10,000 | 8,999 | 105,999 |

All tools remain available on every plan. Free is one site; PRO+ supports up to five sites; PRO++ supports up to 50 sites. Public prices and quotas must be identical in Native display, Cloud policy, WooCommerce products and the activation/licensing flow.

The older Daily 800 RUB proposal from PR #237 is superseded. Its safe retired-monthly-scheme migration is retained in the consolidated branch; its stale price values are not.

## Current consolidated integration

- Single current Draft integration PR: [#253](https://github.com/darcreid163/okean-ai-admin/pull/253).
- Base branch: `main`, SHA `32fa28ca2edb3e66dbdc2c24fde09fe36e49237f`.
- Head branch: `codex/robokassa-paid-entitlements-reconciled`.
- Exact head at this snapshot: `4a652713caada1623e65bcb0cd2a89af83b8de29`.
- PR snapshot: 28 commits, 35 changed files, 2,148 additions / 160 deletions.
- GitHub reports PR #253 open, Draft, not merged, and mergeable. Mergeable does not mean release-approved.
- The exact-head CI results below were verified against this SHA. This document update itself will create a new documentation-only commit and therefore require a fresh exact-head CI run.
- No history rewrite or force-push was performed.

Included in PR #253:
- Robokassa verified payment settlement, idempotent ledger and entitlement issuance.
- Daily UTC quota cutover, retired monthly scheme migration, preserved historical balances and additive optional-schema behavior.
- Partial/full refunds, cumulative/out-of-order refund handling, read-only reconciliation and narrowly scoped approved repair.
- WooCommerce HPOS compatibility.
- Encrypted one-time reveal, account ownership checks, registered-account email delivery, bounded retry/exhaustion and locked admin resend.
- Dedicated isolated Robokassa payment/licence lifecycle runner, executed in both SQLite and MySQL Cloud CI.
- Exact tariff prices and monthly/annual offers from the approved contract above.
- Native and Cloud strict OAuth no-refresh safeguards, including rejection of `offline_access` and refresh-token grants; lifecycle principal controls and executable-file write restrictions are inherited from the current integration baseline.

## Exact-head CI — PASS for 4a652713caada1623e65bcb0cd2a89af83b8de29

- [Cloud Core validation #45585336030](https://github.com/darcreid163/okean-ai-admin/actions/runs/45585336030): PASS — PHP 8.2/8.3/8.4; SQLite and MySQL contract suites including the Robokassa lifecycle runner; browser contracts; WordPress upgrade latest and 7.0.6; Cloud candidate artifact.
- [Native validation #45585335950](https://github.com/darcreid163/okean-ai-admin/actions/runs/45585335950): PASS — PHP 8.2/8.3/8.4; all WordPress smoke profiles; WooCommerce HPOS 11.3; Native build artifact; package installation on latest and 7.0.6.
- [Secret history scan PR #45585336130](https://github.com/darcreid163/okean-ai-admin/actions/runs/45585336130): PASS.
- [Secret history scan push #45585323631](https://github.com/darcreid163/okean-ai-admin/actions/runs/45585323631): PASS.
- Native release job: SKIPPED by design because this is a pull request, not an authorized version tag. No release or deployment occurred.

The Robokassa lifecycle runner exercises settlement, duplicate settlement, encrypted one-time reveal/replay denial, registered-owner email, retry/exhaustion, renewal, full/partial/stale/cumulative refunds, reconciliation and approved repair against disposable SQLite and MySQL stores. It uses test doubles for WordPress/WooCommerce APIs; it is not a real Robokassa gateway callback or browser checkout E2E.

## Release gates still open

1. Disposable local WooCommerce HPOS + Robokassa Test Mode end-to-end: create/purchase product, verified ResultURL callback and amount check, duplicate callback, order-scoped one-time reveal, registered-account email, Native plugin activation, failed email/retry, full/partial refunds, approved repair and concurrency.
2. Manual customer purchase/activation acceptance in the disposable local test shop. The local shop is currently unavailable; these tests are planned for the evening when it is accessible.
3. Final release audit, exact artifact/version freeze and explicit approval before merge/release.

Production must not be used for these tests. No real payments, customer records, production deployment, or licence mutation are authorized by this PR. Public sales remain disabled until automated CI, test-shop E2E and manual acceptance all pass.

## Repository cleanup policy

- PR #253 is the current open integration PR in this repository as of this snapshot. Older PR numbers mentioned in historical documents must not be described as still open without a fresh GitHub check.
- Historical integration branches remain audit provenance. Do not bulk-delete them. Delete only after checking no open PR, unique commit, release tag or artifact references them.
- Independent repositories remain untouched; they are separate source/release tracks and must be reconciled independently rather than overwritten from this monorepo.
- Test artifacts must record the exact consolidated commit SHA and contain only synthetic data.
