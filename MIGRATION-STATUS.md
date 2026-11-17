# WP AI Admin — PUBLICATION BLOCKED (publication and installation)

## Version authority

- Combined integration working candidate: **Native 2.1.17** (`OAI_DB_VERSION=25`), **Cloud Core 0.3.11** as the restored baseline (Gateway schema **4**); **unreleased Cloud 0.3.13-dev** as the explicitly changed candidate, including the hash-only licence registry and the approved plan catalogue.
- Previously recorded ZIP hashes, retained for provenance only (they do not identify the current working tree): Native `4bf534f3a30344fa94cf599d526477887ed89175e494d32b6b35e5a05f1cc1ac`; Cloud `b83ebc098b5ef3e22b7f083df8d26849c233839b226d516725c027984de82793`.
- Site-installed Native versions must be rechecked before deployment; historical checks differed between sites. Installed file hashes remain unverified.
- Canonical endpoints and identity preservation are unchanged. Do not use GitHub Actions credentials to access production.

## Current source state — 14 November 2026

`main` at `436aa3be2865f6f92aa9c7b550a6e2f8c62562cb` (squash of PR #189) passed both hosted workflows:
[Native](https://github.com/darcreid163/okean-ai-admin/actions/runs/43596399424)
and [Cloud](https://github.com/darcreid163/okean-ai-admin/actions/runs/43596399421).
The preceding feature HEAD `518e35557144a50dfaa47541d94246433187b93d` also passed
[Native](https://github.com/darcreid163/okean-ai-admin/actions/runs/43596115991)
and [Cloud](https://github.com/darcreid163/okean-ai-admin/actions/runs/43596116004).
These results establish CI for the cited SHAs only. Follow-up branches, including
documentation-only changes, require their own exact-HEAD checks. Staging,
installed-file parity, external-client acceptance and publication gates are not
implied by these results.

## Current plans and licences

- Target catalogue (`schemes.tariffs_v3`): Free **100 actions/UTC day**; Pro 2,000 ₽/month, PRO+ 5,000 ₽/month and PRO++ 10,000 ₽/month, each `requires_owner_approval` with a `null` limit. PRO+/PRO++ are display names for the stable slugs `business`/`businesspro`.
- The active default remains the frozen `tariffs_v2` until a separately authorized account/tariff migration. Free is 100 actions/UTC day in both schemes.
- Billable calls on a pending paid plan fail closed with `plan_quota_not_configured`; reads, verification, receipts, rollback and usage remain available. No paid limit is invented or carried over.
- Cloud Core alone decides plan, licence, quota and balance. Licence keys are hash-only at rest, shown once, revocable and never trusted from Native input.
- No automatic payments, auto-renewal or money debits. Batch is enabled in the shipped catalogue as `successful_object_v1` (per successful object) for `cloud_site_batch_call`; an explicitly stored `wpai_gateway_batch_object_v1` option remains an operator kill switch. Scheduled `batch_create`/`batch_resume` stay gated until their receipt accounting is ready.

See [the beta-readiness report](docs/BETA-READINESS-2026-11-14.md),
[the licence registry](docs/LICENSE-REGISTRY.md) and
[the mission brief](docs/OPEN-BETA-RELEASE-MISSION.md).

## Mandatory merge gates

- [ ] Native CI exact PR HEAD green: PHP 8.2/8.3/8.4, WordPress smoke/migration 23→24, build archive, **Native-only publication gate**.
- [ ] Cloud CI exact PR HEAD green: PHP 8.2/8.3/8.4, SQLite + MySQL 3→4 migration, OAuth PKCE/refresh/revoke, tenant isolation, atomic Ledger/Batch, recovery tests.
- [ ] Plan catalogue and licence registry contracts pass on SQLite and MySQL: Free daily terms, pending paid quotas fail closed, hash-only one-time keys, lifecycle, activation limits and cross-account isolation.
- [ ] Paid quota numbers are explicitly approved by the owner; no billable paid path is enabled before that approval.
- [ ] Account/tariff migration to `tariffs_v3` is separately authorized, rehearsed and reversible; it has not been performed.
- [ ] Source integrity/secret scan passes and no old Cloud 0.3.6/0.1.0 runtime remains in current tree.
- [ ] Real staging upgrade and rollback, Verify/Receipt/Proof signatures, Elementor/Gutenberg/Woo/Media/Menu e2e matrix.
- [ ] Installed files compared to archived hashes before site upgrade, with backups.
- [ ] Review unique work in older #35/#37/#41/#43/#44/#45 and the separate Native/Cloud recovery PRs; resolve without lost code.
- [ ] Final manual approval to merge `main`, export into public Native/private Cloud release repos, make integration private and deploy to production.

No automatic payments or unapproved quota resets. Never merge the superseded alternate Billing engine in Cloud PR #9. No production changes are performed by this branch. Paid-plan quotas remain fail-closed until the owner supplies numbers.

**Disclosure warning:** Cloud 0.3.11 source is available to anyone with the public repository. Future repo privacy does not retract prior downloads.

## Unified repository transition

As directed 21 October 2026, `darcreid163/okean-ai-admin` is the sole development monorepo until final acceptance. Split publication to Native public and Cloud private happens only afterward. Never merge older PR #46 *as a Native-only destination*: use the reconciled combined integration branch and both CI workflows. Existing PRs remain historical evidence and must not overwrite newer recovered runtime or normalized tariffs. No production writes.
