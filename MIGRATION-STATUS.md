# WP AI Admin — PUBLICATION BLOCKED (publication and installation)

## Version authority

- Combined integration working candidate: **Native 2.1.17** (`OAI_DB_VERSION=24`), **Cloud Core 0.3.11** as the restored baseline (Gateway schema **4**); **unreleased Cloud 0.3.13-dev** as the explicitly changed candidate.
- Archived Native ZIP SHA-256: `4bf534f3a30344fa94cf599d526477887ed89175e494d32b6b35e5a05f1cc1ac`; Cloud ZIP SHA-256: `b83ebc098b5ef3e22b7f083df8d26849c233839b226d516725c027984de82793`.
- Site-installed Native versions must be rechecked before deployment; historical checks differed between sites. Installed file hashes remain unverified.
- Canonical endpoints and identity preservation are unchanged. Do not use GitHub Actions credentials to access production.

## Audited source state — 22 October 2026

`main` at `1fb3f98c1ec87013990e4f35c0c02e8e39c65556` passed both hosted
[Native](https://github.com/darcreid163/okean-ai-admin/actions/runs/39735258115)
and [Cloud](https://github.com/darcreid163/okean-ai-admin/actions/runs/39735258143)
workflows. This establishes the CI result for that SHA only. Follow-up branches
require their own exact-HEAD checks. Remaining staging, installed-file parity,
external-client and publication gates are not implied by this result.

See [the current audit](docs/AUDIT-2026-10-22.md) for findings and local fixes.

## Mandatory merge gates

- [ ] Native CI exact PR HEAD green: PHP 8.2/8.3/8.4, WordPress smoke/migration 23→24, build archive, **Native-only publication gate**.
- [ ] Cloud CI exact PR HEAD green: PHP 8.2/8.3/8.4, SQLite + MySQL 3→4 migration, OAuth PKCE/refresh/revoke, tenant isolation, atomic Ledger/Batch, recovery tests.
- [ ] Source integrity/secret scan passes and no old Cloud 0.3.6/0.1.0 runtime remains in current tree.
- [ ] Real staging upgrade and rollback, Verify/Receipt/Proof signatures, Elementor/Gutenberg/Woo/Media/Menu e2e matrix.
- [ ] Installed files compared to archived hashes before site upgrade, with backups.
- [ ] Review unique work in older #35/#37/#41/#43/#44/#45 and the separate Native/Cloud recovery PRs; resolve without lost code.
- [ ] Final manual approval to merge `main`, export into public Native/private Cloud release repos, make integration private and deploy to production.

No automatic payments or unapproved quota resets. Never merge the superseded alternate Billing engine in Cloud PR #9. No production changes are performed by this branch.

**Disclosure warning:** Cloud 0.3.11 source is available to anyone with the public repository. Future repo privacy does not retract prior downloads.

## Unified repository transition

As directed 21 October 2026, `darcreid163/okean-ai-admin` is the sole development monorepo until final acceptance. Split publication to Native public and Cloud private happens only afterward. Never merge older PR #46 *as a Native-only destination*: use the reconciled combined integration branch and both CI workflows. Existing PRs remain historical evidence and must not overwrite newer recovered runtime or normalized tariffs. No production writes.
