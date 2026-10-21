# WP AI Admin — единый основной репозиторий разработки

**Current integrated candidate:** Native **2.1.17** (DB schema 24; build `2.1.17-usage-batch-v1-20261019`) + recovered Cloud Core **0.3.11** (Gateway schema 4); current **unreleased Cloud 0.3.12-dev** adds scoped Skills/Media discovery. This branch is **not** an approved production release.

## Один основной репозиторий до завершения проекта

As explicitly directed by the maintainer, `darcreid163/okean-ai-admin` is the current **PUBLIC** single development repository with GitHub-hosted CI for both Native and Cloud. The current 0.3.11 Cloud source and synthetic tests are included in `wp-ai-admin-cloud/`. Production keys, credentials, accounts, private database snapshots and deployment configuration are prohibited.

**Irreversible visibility warning:** all source committed to a public Git branch is publicly accessible. Making this repository private later does NOT retract clones, forks or historic downloads. This includes the 0.3.11 server algorithms. Treat this as an explicit temporary development exposure, not a secret-preserving mirror.

## Target repositories after release qualification

- [`darcreid163/wp-ai-admin`](https://github.com/darcreid163/wp-ai-admin) — public **Native-only** WordPress plugin, GPL/licensing notices, final installable ZIP.
- [`darcreid163/wp-ai-admin-cloud-core`](https://github.com/darcreid163/wp-ai-admin-cloud-core) — private **Cloud Core-only** implementation, billing, OAuth and Gateway distribution.
- `darcreid163/okean-ai-admin` — temporary shared CI/integration; make private only with the owner's separately performed visibility change after release acceptance.
- `darcreid163/universal-mcp-platform` — unrelated future product; never mix its code into the release.

## One current runtime per component

The old `wp-ai-cloud` pilot 0.1.0 and former bundled Cloud Core 0.3.6 are **not** current source in this branch. There is only one `wp-ai-admin-cloud/`: immutable recovered 0.3.11 baseline plus explicitly versioned 0.3.12-dev changes. Historic commits still exist.

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

[One current audit](docs/RELEASE-STATE-2026-10-21.md) · [Migration / release checklist](MIGRATION-STATUS.md) · [Repository policies](docs/REPOSITORY-BOUNDARIES.md).

Historical dated release notes are preserved as references, not active installation instructions.

## Текущая интеграционная ветка и runner (21.10.2026)

**Основная разработка только здесь:** `integration/unified-monorepo-runner-20261021`. Native и Cloud хранятся и проверяются вместе, без копирования старых деревьев Cloud 0.1.0/0.3.6. Отдельные репозитории служат источниками исторически проверенных изменений; переносить туда готовые компоненты будем после релизных тестов.

**CI:** `.github/workflows/native-validate.yml` (PHP 8.2–8.4, реальный WordPress, ZIP Native без Cloud) и `.github/workflows/cloud-validate.yml` (PHP 8.2–8.4, SQLite/MySQL, OAuth, tenant, Batch, Skills/Media). Оба используют доступный GitHub-hosted `ubuntu-latest`, собственного runner не требуется. Рабочий кандидат Cloud 0.3.12-dev **не установлен** на production 0.3.11.

**Важное предупреждение:** репозиторий фактически PUBLIC. Исходники уже могли быть скопированы третьими лицами. Делать закрытым после выпуска не означает устранить прежнее раскрытие. Никогда не коммитить секреты/production DB, приватные ключи или платежные данные. Для защиты новых внутренних алгоритмов предпочтительна приватность *сейчас*, а не позднее.

**Merge gate:** только после двух зелёных CI на точном SHA, review матрицы миграций и решения владельца. Рабочие сайты не меняются. Изменения Cloud GateWay не считаются точным восстановлением 0.3.11: исторический SHA манифест сохраняется отдельно.
