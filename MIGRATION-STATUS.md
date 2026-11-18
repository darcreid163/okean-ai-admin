# WP AI Admin — release status and migration boundaries

**PUBLICATION BLOCKED — release qualification in progress. No production release, merge to main or deployment is approved.**

## 1. Version authority

| Component | Candidate | Notes |
|---|---|---|
| Native | 2.1.18, DB schema 25 | Build `2.1.18-native-rate-limit-hpos-v1-20261117` |
| Cloud Core | 0.3.13-dev | Recovered 0.3.11 baseline; Gateway Store schema 4; Cloud plugin DB version 2 |
| Main | `1e459dc6fd6ede2db6e15145c6aa66c9b4d81f5a` | Remains unchanged |
| Integration PR | #232 Draft | Native 2.1.18 + Cloud Store + key preservation, not merged |

Exact combined candidate SHA `e71bf7bd30ac4ad48be90d0478efca115305b9cb` passed:
- Native run [44255676940](https://github.com/darcreid163/okean-ai-admin/actions/runs/44255676940): PHP 8.2/8.3/8.4, three WordPress smoke profiles, Native artifact and package install on WP 7.0.6/latest.
- Cloud run [44255676930](https://github.com/darcreid163/okean-ai-admin/actions/runs/44255676930): PHP 8.2/8.3/8.4, SQLite/MySQL contracts including SSE queue/index migration, browser contracts, WordPress upgrade/restore and Cloud artifact.

This exact combined head passed both workflows. CI is not evidence of production installation or live-client acceptance.

## 2. Integrated Native changes

PR #232 includes Native 2.1.18 / DB25 atomic rate-limit admission and HPOS support, uninstall data preservation, OAuth registry hardening, REST Bearer hash-at-rest migration, media creation single-winner admission, read-only Abilities discovery/schema, file path/snapshot/staged-write hardening, editor snapshot guards and file lifecycle/recovery tests.

File replacement uses same-directory staging, full write/hash verification and rename. There is no delete-before-rename or copy/unlink fallback. Replacement fails closed if unsupported. Windows semantics and power-loss durability are not yet platform-qualified.

## 3. Cloud Store and key-preservation integration

The selected changes from PR #226 and stacked PR #224 are now integrated into the current PR #232 release branch:
- Atomic, bounded legacy SSE queue with reservation-before-execution, atomic publish/drain, expiry and single-delivery semantics.
- Prefix-scoped SSE indexes, owner-scoped Store indexes with exact schema checks, SQLite index-collision guard and MySQL table-prefix boundary.
- CLI-only key-preservation export utility, private output restrictions and key-management/recovery documentation.
- Regression coverage for concurrent SSE producers/drainers, queue capacity, prefix isolation, index migration/idempotency, artifact allowlists and Cloud upgrade/restore.

The combined integration tree passed exact-head Native and Cloud CI. The key exporter preserves exact active keys; it is not a vault/signing-key rotation mechanism. Do not perform AUTH_KEY/AUTH_SALT rotation or destructive restore without following the verified key-preservation procedure.

## 4. Plans, quota and account migration

Current owner-approved daily UTC quota catalogue:

| Plan | Stable slug | Quota/day | Price |
|---|---|---:|---:|
| Free | `free` | 50 | 0 ₽/month |
| Daily | `daily` | 500 | not set |
| Pro | `pro` | 2,000 | 2,000 ₽/month |
| PRO+ | `business` | 5,000 | 5,000 ₽/month |
| PRO++ | `businesspro` | 10,000 | 10,000 ₽/month |

- Cloud Core is the sole authority for plan, license, quota and balance.
- Successful single-tool operations count as one action; each successfully processed Batch object counts as one action.
- Failed operations, retries already settled, Verify and Rollback do not create additional successful-action charges.
- Automatic money debits and auto-renewal are disabled. Payment-to-license fulfillment is not implemented/qualified.
- Existing `legacy_monthly_v1` accounts retain historical terms. Do not silently migrate or reprice accounts.
- The default scheme and live account state must be checked separately before any activation/migration. No production tariff migration is authorized by this branch.
- The last recorded live Cloud observation is dated 16 November 2026; this review did not reconnect to any site, so current live activation is unknown.

## 5. Remaining release gates

- Complete real ChatGPT token expiry/reconnect qualification (#92).
- Complete two-owner tenant isolation and clean external ChatGPT/MCP acceptance.
- Complete disposable staging lifecycle, clean install, upgrade, paired backup/restore and post-restore identity checks.
- Freeze Native-only and separate Cloud artifacts from exact committed source; verify provenance, checksums, dependency/license notices and secret scans.
- Confirm legal/privacy/terms/retention and support process.
- Decide whether the first release is a closed/free beta or a self-service paid launch. Paid launch additionally requires the payment-to-license workflow in #203; shipping/tax/store-settings coverage (#168/#169) can remain post-beta unless advertised as a full WooCommerce suite.

## 6. Repository and production boundaries

- The repository is public and contains both Native and Cloud development source. Making it private later cannot retract existing clones, forks or downloads.
- Intended final split remains public Native-only repository and private Cloud Core repository, only after qualification and explicit owner approval.
- Do not commit production keys, credentials, customer data, database snapshots or deployment configuration.
- No live WordPress site, production database, billing account or license was accessed or modified in this review.
- Do not merge, tag, publish, deploy, migrate accounts or change repository visibility without separate explicit approval.

## Canonical status

Use [docs/RELEASE-CONTROL-2026-11-18.md](docs/RELEASE-CONTROL-2026-11-18.md) and [issue #40](https://github.com/darcreid163/okean-ai-admin/issues/40) as the current source of release truth. Older dated reports remain historical evidence, not current installation instructions.
