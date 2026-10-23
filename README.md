# WP AI Admin — единый основной репозиторий разработки

**Current integrated candidate:** Native **2.1.17** (DB schema 24; build `2.1.17-usage-batch-v1-20261019`) + recovered Cloud Core **0.3.11** (Gateway schema 4); current **unreleased Cloud 0.3.13-dev** adds scoped Skills/Media and read-only Abilities metadata discovery. This branch is **not** an approved production release.

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

| Plan | Successful actions | Quota period |
|---|---:|---|
| Free | 100 | month |
| Pro | 500 | day |
| Business | 2,000 | day |
| BusinessPro | 5,000 | day |

Successful reads/Sandbox calls count; every successful Batch object is one action; failed calls/rollback/idempotent retry are not billed again. **Gateway Ledger is sole charging authority, no telemetry charging, no automatic payment debit.** These are required rules; the live v2 tariff migration and Batch activation are not assumed complete.

## Safe development and release

`READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK`. No direct live writes. No unregistered full-document Elementor mutations, paid plan conversion, subscription state migrations or site installation without staging and explicit approval.

Run hosted [Native CI](.github/workflows/native-validate.yml) and [Cloud CI](.github/workflows/cloud-validate.yml), including PHP 8.2/8.3/8.4, MySQL and SQLite, OAuth isolation, Billing/Batch, WordPress smoke and upgrade. Candidate Native ZIP MUST exclude `wp-ai-admin-cloud/`. No CI workflow may use production secrets.

Generic option writes require ASCII identifiers of at most 191 bytes. Protected core/security settings, Native/Cloud control-plane keys, credential names and transient caches include case aliases under WordPress's database collation. The same target policy is checked again before AI or WP-admin approval/apply can authorize a new write from a historical operation. Read-only reconciliation of interrupted writes, rejection and conflict-checked rollback remain available; ordinary safe option changes retain their full approval/verify/rollback lifecycle.

Media sandbox uploads and URL imports accept decodable JPEG, PNG, GIF and WebP images up to 10 MiB and 20 megapixels, subject to the site's allowed MIME types. Direct uploads require the filename extension, optional declared MIME and image bytes to agree. A working WordPress image editor (GD or Imagick with support for the format) and writable temporary storage are required. Validation precedes Media Library writes; accepted images remain private pending artifacts until the normal approval/apply flow. The WordPress CI suite checks rejection without persistent writes and the full approval/verify/rollback lifecycle.

[Current audit, 22 October](docs/AUDIT-2026-10-22.md) · [Recovery history, 21 October](docs/RELEASE-STATE-2026-10-21.md) · [Migration / release checklist](MIGRATION-STATUS.md) · [Repository policies](docs/REPOSITORY-BOUNDARIES.md).

Historical dated release notes are preserved as references, not active installation instructions.

## Текущая точка разработки (22.10.2026)

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
