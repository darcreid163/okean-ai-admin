# Release integration status — 25 November 2026

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

## Consolidated integration

PR #253 is the single current Draft integration PR, based directly on `main`. Its branch is a clean one-commit tree on top of the current main baseline; the previous stacked branch history remains preserved in the original PRs for audit.

Included in PR #253:
- Robokassa verified payment settlement, idempotent ledger and entitlement issuance.
- Daily UTC quota cutover, retired monthly scheme migration, preserved historical balances and additive optional-schema behavior.
- Partial/full refunds, cumulative/out-of-order refund handling, read-only reconciliation and approved repair.
- WooCommerce HPOS compatibility.
- Encrypted one-time reveal, account ownership checks, registered-account email delivery, bounded retry/exhaustion and locked admin resend.
- Exact tariff prices and monthly/annual offers from the approved contract above.
- Current main's OAuth no-refresh safeguards, lifecycle principal controls and executable-file write restrictions are inherited, not duplicated.

## Release gates

- Exact-head Native and Cloud CI for PR #253 must pass.
- A disposable WooCommerce HPOS + Robokassa Test Mode store must pass purchase, verified callback, duplicate callback, licence activation, one-time reveal, registered email, email-failure retry, partial/full refund, approved repair and concurrency tests.
- Production must not be used for these tests. No real payments or customer records.
- Public sales remain disabled until automated CI, test-shop E2E and manual acceptance all pass.

## Repository cleanup policy

- Old stacked PRs #237–#245 and reconstruction PR #252 are superseded by #253 but remain open until #253 exact-head CI is qualified. Their source branches are retained as audit provenance.
- Do not bulk-delete historical branches. Delete only after checking no open PR, unique commit, release tag or artifact references them. The current GitHub connector does not expose a branch-delete operation.
- Independent repositories remain untouched; their open PRs belong to separate release cycles.
- Test artifacts must record the exact consolidated commit SHA and contain only synthetic data.
