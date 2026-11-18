# WP AI Admin — единый основной репозиторий разработки

**Current integrated candidate:** Native **2.1.18** (DB schema 25; build `2.1.18-native-rate-limit-hpos-v1-20261117`) + Cloud Core **0.3.13-dev** (Gateway Store schema 4, recovered 0.3.11 baseline). The current combined candidate, including Native 2.1.18, Cloud Store atomic SSE/index hardening, Cloud key preservation and SSE close-race protection, passed exact-head Native and Cloud CI on `fea7f8d2e4f2517fa0c5221b13df0a80f0e83162` (Native run [44257146243](https://github.com/darcreid163/okean-ai-admin/actions/runs/44257146243), Cloud run [44257146236](https://github.com/darcreid163/okean-ai-admin/actions/runs/44257146236)). PR [#232](https://github.com/darcreid163/okean-ai-admin/pull/232) remains Draft and is not merged. **This is not an approved production release.**

**Current release blockers and authoritative status:** [Release Control — 18 November 2026](docs/RELEASE-CONTROL-2026-11-18.md) and [canonical tracker #40](https://github.com/darcreid163/okean-ai-admin/issues/40). Cloud Store and key-preservation work are integrated into PR #232; source PRs #226/#224 are superseded, not merged. Live OAuth expiry, two-owner isolation, staging acceptance and artifact freeze are still required.

[Таблица возможностей Native и Cloud на 23 октября](docs/CAPABILITIES-2026-10-23.md) — реализация, новые функции и условия установки. [Что осталось до установки на рабочие сайты](docs/PRE-INSTALL-CHECKLIST-2026-10-23.md). [Актуальная готовность планов, квот и лицензий на 16 ноября](docs/BETA-READINESS-2026-11-16.md). Актуальный контроль выпуска: [#40](https://github.com/darcreid163/okean-ai-admin/issues/40).

## Один основной репозиторий до завершения проекта

As explicitly directed by the maintainer, `darcreid163/okean-ai-admin` is the current **PUBLIC** single development repository with GitHub-hosted CI for both Native and Cloud. The current 0.3.11 Cloud source and synthetic tests are included in `wp-ai-admin-cloud/`. Production keys, credentials, accounts, private database snapshots and deployment configuration are prohibited.

**Irreversible visibility warning:** all source committed to a public Git branch is publicly accessible. Making this repository private later does NOT retract clones, forks or historic downloads. This includes the 0.3.11 server algorithms. Treat this as an explicit temporary development exposure, not a secret-preserving mirror.

## Target repositories after release qualification

- [`darcreid163/wp-ai-admin`](https://github.com/darcreid163/wp-ai-admin) — public **Native-only** WordPress plugin, GPL/licensing notices, final installable ZIP.
- [`darcreid163/wp-ai-admin-cloud-core`](https://github.com/darcreid163/wp-ai-admin-cloud-core) — private **Cloud Core-only** implementation, billing, OAuth and Gateway distribution.
- `darcreid163/okean-ai-admin` — temporary shared CI/integration; make private only with the owner's separately performed visibility change after release acceptance.
- `darcreid163/universal-mcp-platform` — unrelated future product; never mix its code into the release.

## One current runtime per component

The old `wp-ai-cloud` pilot 0.1.0 and former bundled Cloud Core 0.3.6 are **not** current source in this branch. There is only one `wp-ai-admin-cloud/`: immutable recovered 0.3.11 baseline plus explicitly versioned 0.3.13-dev changes. Historic commits still exist.

## Commercial contract

The owner-approved final five-tier daily quota catalogue is merged in PR #206 (16 November 2026):

| Plan | Price | Approved quota | Quota period |
|---|---:|---:|---|
| Free | 0 ₽/month | 50 actions | UTC calendar day |
| Daily | not set | 500 actions | UTC calendar day |
| Pro | 2,000 ₽/month | 2,000 actions | UTC calendar day |
| PRO+ | 5,000 ₽/month | 5,000 actions | UTC calendar day |
| PRO++ | 10,000 ₽/month | 10,000 actions | UTC calendar day |

Display names **PRO+** and **PRO++** map to the stable internal slugs `business` and `businesspro`. The other stable slugs are `free`, `daily` and `pro`. Slugs remain stable for persisted accounts, licences and API payloads. Daily price is deliberately unset pending a separate owner decision; existing Pro/PRO+/PRO++ monthly prices remain unchanged.

Canonical server source: `wp-ai-admin-cloud/config/plans.json`. The five final daily quotas are present in the active `tariffs_v2` catalogue and target `schemes.tariffs_v3`; all reset at 00:00 UTC daily. `default_scheme` remains `tariffs_v2` for compatibility. Existing `legacy_monthly_v1` accounts retain historical monthly terms until an explicit audited transfer; no legacy account is silently migrated or repriced. Production activation of the updated catalogue still requires a separately verified Cloud Core deployment; see the 16 November readiness report.

Cloud Core alone decides plan, licence, quota and balance; clients never submit their own plan, quota, account or licence identity. Licence keys use `wpai_lic_` plus 43 base64url characters, only SHA-256 is stored at rest, and the plaintext is shown once. The Native dashboard shows the server-signed plan label, licence prefix/status/activations and quota values, and never derives a different balance. Successful reads/Sandbox calls count; every successful Batch object is one action; failed calls/rollback/idempotent retry are not billed again. Batch is enabled in the shipped catalogue as `successful_object_v1`; an explicitly stored `wpai_gateway_batch_object_v1` option remains an operator kill switch. **Gateway Ledger is sole charging authority, no telemetry charging, no automatic payment debit.** The live v2→v3 account/tariff migration is not assumed complete.

## Safe development and release

`READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK`. No direct live writes. No unregistered full-document Elementor mutations, paid plan conversion, subscription state migrations or site installation without staging and explicit approval.

Run hosted [Native CI](.github/workflows/native-validate.yml) and [Cloud CI](.github/workflows/cloud-validate.yml), including PHP 8.2/8.3/8.4, MySQL and SQLite, OAuth isolation, Billing/Batch, WordPress smoke and upgrade. Candidate Native ZIP MUST exclude `wp-ai-admin-cloud/`. No CI workflow may use production secrets.

Generic option writes require ASCII identifiers of at most 191 bytes. Protected core/security settings, Native/Cloud control-plane keys, credential names and transient caches include case aliases under WordPress's database collation. The same target policy is checked again before AI or WP-admin approval/apply can authorize a new write from a historical operation. Read-only reconciliation of interrupted writes, rejection and conflict-checked rollback remain available; ordinary safe option changes retain their full approval/verify/rollback lifecycle.

Media sandbox uploads and URL imports accept decodable JPEG, PNG, GIF and WebP images up to 10 MiB and 20 megapixels, subject to the site's allowed MIME types. Direct uploads require the filename extension, optional declared MIME and image bytes to agree. A working WordPress image editor (GD or Imagick with support for the format) and writable temporary storage are required. Validation precedes Media Library writes; accepted images remain private pending artifacts until the normal approval/apply flow. The WordPress CI suite checks rejection without persistent writes and the full approval/verify/rollback lifecycle.

Public metadata and evidence responses redact server filesystem paths, including paths inside historical JSON receipt/proof fields. Redacted objects carry `_wpai_projection`: hashes and `verified` attest the original evidence and its verification result, not the redacted presentation; this presentation cannot reconstruct the canonical payload. Stored rows, audit records and proof hashes are not migrated or re-signed. Runtime fingerprints retain relative file identifiers and source hashes. Authorized file/content reads retain their exact editable bytes; internal Apply/Verify/Rollback use original data.

Cloud Gateway applies the same presentation contract to known Native metadata/evidence and diagnostic error replies, including old cache hits and individual items in mixed batches. Core authorization, encrypted receipts, original per-child digests, settlement and replay charges remain unchanged. Raw editable file/content and arbitrary third-party tool content remain exact. See [Cloud cached evidence checks](wp-ai-admin-cloud/docs/CACHED-EVIDENCE-PROJECTION.md).

[Current audit, 22 October](docs/AUDIT-2026-10-22.md) · [Recovery history, 21 October](docs/RELEASE-STATE-2026-10-21.md) · [Migration / release checklist](MIGRATION-STATUS.md) · [Repository policies](docs/REPOSITORY-BOUNDARIES.md).

Historical dated release notes are preserved as references, not active installation instructions.

## Uninstall и сохранность данных

По умолчанию удаление плагина сохраняет локальную историю операций, аудит, OAuth-подключения, pairing и настройки лицензии. На панели WP AI Admin можно отдельно включить **«Разрешить удаление локальных данных при удалении плагина»**. Только при этом явном согласии `uninstall.php` удаляет allowlist таблиц и настроек WP AI Admin. Деактивация и обновление данные не удаляют; страницы, товары, медиафайлы, пользователи и данные других плагинов не затрагиваются. В Multisite согласие проверяется отдельно для каждого сайта.

## Историческая точка проверки (22.10.2026)

Основная ветка — `main` этого репозитория. Проверенный при аудите SHA:
`1fb3f98c1ec87013990e4f35c0c02e8e39c65556`. Native 2.1.17 и кандидат
Cloud 0.3.13-dev уже объединены; старые интеграционные ветки остаются историей.

Оба GitHub CI на этом SHA завершились успешно: [Native](https://github.com/darcreid163/okean-ai-admin/actions/runs/39735258115)
и [Cloud](https://github.com/darcreid163/okean-ai-admin/actions/runs/39735258143).
Cloud включает классификацию `ability.list`/`ability.get` как чтения, проверки
OAuth/site/tenant, квот, повторов и неопределённых исходов. Это подтверждение
проверенного исходного кода и автоматических тестов; состояние установленных
файлов и приёмка внешним клиентом проверяются отдельно.

Новые исправления аудита #73/#74 требуют собственных проверок точного SHA перед
слиянием. Кандидат 0.3.13-dev не получает статус production из-за зелёного CI.
Платежи, миграция тарифов, публикация релиза и установка требуют отдельного
разрешения. Исторический SHA-манифест Cloud 0.3.11 сохраняется без изменения.
