# WP AI Admin — изолированная квалификация в Work

Дата: 12 октября 2026. Итог: запрошенные группы проверок **PASS**, незакрытых ошибок в выполненных проверках **0**. Дополнительные непроверенные гейты перечислены отдельно как **SKIP**. Это результат проверки приватного кандидата, а не разрешение на публичный релиз или production deployment.

## Исходники и коммиты

- Репозиторий: `darcreid163/okean-ai-admin`, PRIVATE.
- Ветка: `codex/private-release-candidate-reconciliation`; [PR #35](https://github.com/darcreid163/okean-ai-admin/pull/35) остаётся draft.
- Исходная интеграционная ветка: [59ac0c1](https://github.com/darcreid163/okean-ai-admin/commit/59ac0c1c6e8cdef7403577bb53301073b97f34bc).
- Полностью сравненный приватный кандидат release-ci: [3d5ecab](https://github.com/darcreid163/wp-ai-admin-release-ci/commit/3d5ecab2fceb233a3ab7a736e60795bde0518ae9).
- Перенос кандидата: [5367ba2](https://github.com/darcreid163/okean-ai-admin/commit/5367ba2beb7834c4d4983ecfd279d5ffb2a46b68).
- Исправления гонок и регрессии: [bb842f2](https://github.com/darcreid163/okean-ai-admin/commit/bb842f2ef6d1e4bfcfdeebee742cb48959662be9).
- **Коммит исходников ZIP:** [64bf281](https://github.com/darcreid163/okean-ai-admin/commit/64bf281411163a4b1f9de1bee0722c8b89308b07). Его дерево `78b614e3364fa46de4c94f98ea0453e46b7c433b` совпало с локальным Git-деревом проверенных файлов.

Исходники материализованы через авторизованный GitHub connector, без передачи GitHub credentials в shell. Каждый исходный файл, включая бинарные архивы, проверен по Git blob SHA-1 до тестирования. Обычный локальный clone не использовался: у Git CLI отсутствовала авторизация. История и исходники не переносились в публичные репозитории; `main` и рабочие сайты не изменялись.

## Полное сравнение деревьев

| Проверка | Результат |
|---|---:|
| Файлов исходной интеграционной ветки | 193 |
| Файлов кандидата release-ci | 189 |
| Совпадающих файлов | 134 |
| Общих путей с отличающимися байтами | 25 |
| Дополнительных файлов кандидата | 30 |
| Собственных файлов интеграционной ветки, сохранённых | 34 |
| Перенесено изменённых/недостающих файлов | 55 |

Перенос охватывает всё дерево кандидата, а не выборку. Cloud `src/` уже совпадал; недостающая защита Native, тесты, workflow, документация и исторический regression ZIP перенесены. Различия поверх кандидата теперь документированы: исправления leases/recovery, тестовых fixtures, build ID, UTC builder, контракт 1.3, обновление с 2.1.14 и этот отчёт. Полные списки путей: `reports/work-2026-10-12/reconciliation.json`.

## Среда

Ubuntu 24.04; PHP **8.3.6** с PDO SQLite, PDO MySQL, mysqli, sodium, curl, XML и mbstring. MySQL **8.0.46** — основной MySQL-прогон; дополнительно выполнен прогон на MariaDB **10.11.14**. WordPress **7.0.6** и **7.1.3**, WP-CLI **2.12.0**. PHP **8.2.29** и **8.4.1** собраны отдельно из release tarballs для проверки синтаксиса.

Системные ограничения Work препятствовали обычной установке служб. PHP и серверы БД запускались из локально распакованных пакетов; БД слушала только 127.0.0.1, без UNIX socket, и останавливалась после каждого прогона. Все WordPress сайты и базы созданы для этой проверки. WordPress HTTP к внешним ресурсам, cron и auto-updater заблокированы; нужные дистрибутивы загружены отдельно. Ни один тест не устанавливал сборки на wpaiadmin.ru или okean.market.

## Результаты PASS/FAIL/SKIP

Числа assertions ниже — сообщения соответствующих исполняемых тестов; они не включают подготовительные команды установки и не означают тестирование всех возможных сценариев продукта.

| Группа | SQLite | MySQL 8.0 | Дополнительное свидетельство |
|---|---|---|---|
| Core / MCP / quota process concurrency | PASS, 125 | PASS, 125 | 10 процессов / 3 слота |
| Legacy Core compatibility | PASS, 76 | PASS, 76 | Сохранены исходные 11 таблиц |
| OAuth PKCE / audience / consent / refresh replay | PASS, 57 | PASS, 57 | Frontend-session: 16 тестов PASS |
| Multi-site / общий план | PASS, 33 | PASS, 33 | Новые сайты не расширяют старые grants |
| Token Lifecycle | PASS, 14 | PASS, 14 | TTL, revoke, disconnect, site grants |
| Tenant Isolation | PASS, 40 | PASS, 40 | Отказы для чужого owner/site и старых grants |
| Quota & Idempotency | PASS, 35 | PASS, 35 | Failed=0, replay без нового write, uncertain reconciliation |
| Schema Integrity | PASS, 4 | PASS, 5 | Реальное повреждение схемы WP: PASS |
| Gateway Protocol | PASS, 17 | PASS, 17 | JSON-RPC negative cases |
| Foundation proof | PASS, 19 | PASS, 19 | Привязка и integrity |
| Release boundaries | PASS, 136 | PASS, 138 | Suspension/revoke, nested transactions, size guards |
| Reconnect identity | PASS, 7 | PASS, 7 | Сохранение active registration / запрет старого revoked grant |
| Конкурентное подключение сайта | — | PASS | Один владелец-победитель, 11 отказов |
| Suspension vs token/consent/exchange/in-flight call | — | PASS, все 4 группы | Нет восстановления отозванного доступа; учёт завершённого вызова сохранён |
| Native batch mutex / OAuth schema upgrade | — | PASS, обе группы | Один contender отклонён; client retention |

Дополнительно **PASS**: Native bootstrap guard, signed entitlements, Elementor single-source edits, option normalization, Woo integer stock, 42 browser-pairing fixture assertions, Cloud version contract, public-client 10 тестов и legacy prototype Core 107 assertions. Все 19 MySQL-групп также прошли на MariaDB; это отдельный дополнительный результат, не замена MySQL 8.0.

| WordPress-проверка | 7.0.6 | 7.1.3 |
|---|---|---|
| Fresh Native ZIP install + lifecycle negatives | PASS | PASS |
| MCP onboarding / auth demotion / admin navigation | PASS | PASS |
| Настоящий WordPress browser-pairing handler | PASS, 33 | PASS, 33 |
| Cleanup / quota retention | PASS | PASS |
| Lease stale-read / recovery interleavings | PASS, обе регрессии | PASS, обе регрессии |
| Deactivate → Reactivate | PASS | PASS |
| Native 2.1.13 → 2.1.15 ZIP upgrade | PASS | PASS |
| Cloud fresh ZIP install + explicit enable | PASS | PASS |
| Cloud current-version schema damage | PASS | PASS |
| Cloud 0.3.6 → 0.3.7 ZIP upgrade | PASS | PASS |

**Исходный Native 2.1.14 → 2.1.15:** отдельный PASS на WP 7.0.6. Сохранены site/installation IDs, MCP/OAuth credentials, license и pending operation evidence; autonomous modes сброшены. В CI добавлен этот baseline с SHA-256 исторического ZIP, сохранив прежний 2.1.13 regression.

Fresh Native: **116 tools**, self-test **77/77**, security fuzz **220/220**. Дополнительная среда WP 7.0.6: WooCommerce **11.1.2**, Elementor **4.3.3**, Yoast **28.6** активированы; пять фаз option lifecycle, включая Yoast cold verify, **PASS**; self-test администратора **78/78**. Woodmart MCP **1.0.1**: plan/apply/rollback/verify **PASS** с минимальной theme API fixture. Лицензированная полная тема Woodmart этим прогоном не квалифицирована.

Синтаксис: **153 PHP-файла PASS на PHP 8.2, 8.3 и 8.4**. Синтаксис build/upgrade shell и browser JS также проверен.

| Незапущенный дополнительный гейт | Статус | Причина |
|---|---|---|
| Настоящий Chromium cross-origin / native consent navigation | SKIP | Chrome runtime получен, но AF_UNIX `socket()` запрещён в Work: EPERM и abort до тестов страниц. PHP handler/session-проверки не подменяют браузерный гейт. |
| Полная runtime-матрица БД/WordPress на PHP 8.2 и 8.4 | SKIP | Здесь эти версии использованы для syntax; полный runtime-прогон выполнен на PHP 8.3.6. |
| External ChatGPT / два owner / два production сайта | SKIP | Вне изолированной задачи; production операции прямо запрещены. |

GitHub Actions green status не заявляется: результаты получены в Work, без ожидания hosted runner. Public enrollment, billing, commercial entitlement enforcement и batch receipt accounting по-прежнему не считаются завершёнными.

## Обнаруженные ошибки и исправления

| Ошибка до исправления | Исправление | Повторная проверка |
|---|---|---|
| Старый читатель lease удалял новый lease другого запроса | SQL compare-and-delete по точному бинарному значению; сброс option cache; повторное чтение при конфликте | Новый real-WP тест сначала FAIL, после исправления PASS на обеих WP версиях |
| Два запроса одновременно восстанавливали abandoned Apply/Rollback | Lease на recovery, свежая строка операции, атомарные terminal claims, release в finally | Новый real-WP тест сначала FAIL, после исправления PASS; существующие lifecycle tests PASS |
| Quota fixture не импортировал Security и использовал истёкший токен для следующего месяца | Импорт, отдельный expired-token denial, свежий token для November charge | 35 assertions PASS на SQLite/MySQL/MariaDB |
| Schema-damage fixture считал пустой DB password утечкой | Проверять только непустые маркеры | Реальное удаление столбца: fail-closed и sanitized health PASS на обеих WP версиях |

Новые регрессии включены в release-sandbox workflow. Native build изменён на `2.1.15-release-reconciled-20261012`; версии кандидатов остались 2.1.15 / 0.3.7, опубликованные production версии не переиспользовались.

В отчётном коммите также исправлен archive publication workflow: автоматические push на candidate-ветку не перезаписывают проверенный ZIP; publication job доступен только при отдельном manual dispatch на main, использует canonical builder и отказывается заменять уже существующую версию другими байтами. Сам main не изменялся и этот job не запускался.

## ZIP и воспроизводимость

Источник: `64bf281411163a4b1f9de1bee0722c8b89308b07`. `SOURCE_DATE_EPOCH=1791806408`, TZ нормализован builder в UTC. Две независимые сборки, включая запуск с TZ=Pacific/Honolulu, дали побайтно одинаковые Native/Cloud ZIP и PROVENANCE.

| Артефакт | Файлов | SHA-256 |
|---|---:|---|
| wp-ai-admin-2.1.15.zip | 85 | `b3ca981439827f90ed206433acad86e5fe4c5589beca359b7d107fa8880b1f77` |
| wp-ai-admin-cloud-0.3.7.zip | 19 | `04b5060cbce9234b8d6b4bb5ac8531446df90606b252d378bf258e765e520705` |

PASS: ZIP CRC/integrity; отсутствие tests/.git/cache; соответствие header/version/build signatures; byte-for-byte соответствие файлов окончательных ZIP файлам проверенных fresh WordPress установок. Сканирование payload на private-key PEM и распространённые credential prefixes не нашло совпадений; это ограниченная проверка шаблонов, не гарантия отсутствия любого возможного секрета. Базы, реальные credentials и локальные runtime-пакеты в ZIP/коммиты не включались.

Файлы сохранены в приватной ветке: `releases/2.1.15/wp-ai-admin-2.1.15.zip`, `releases/wp-ai-cloud-0.3.7/wp-ai-admin-cloud-0.3.7.zip`, `releases/2.1.15/PROVENANCE.txt`, `releases/2.1.15/SHA256SUMS.txt`. Машиночитаемые результаты: `reports/work-2026-10-12/results.json`.

Для повторной сборки checkout указанного source commit и выполнить:

```bash
GITHUB_SHA=64bf281411163a4b1f9de1bee0722c8b89308b07 \
SOURCE_DATE_EPOCH=1791806408 \
bash scripts/build-release-candidate.sh
```

Команды исполняемых тестов находятся в `.github/workflows/validate.yml`, `wp-ai-cloud.yml`, `release-sandbox.yml`; для MySQL-групп передать TEST_MYSQL_DSN/USER/PASSWORD из одноразовой локальной БД, для WP eval-file использовать только свежие изолированные установки. `wordpress-cloud-schema-damage.php` запускать последним: он намеренно повреждает тестовую схему.

## Следующий гейт

Запустить Chromium navigation на изолированном runner, разрешающем необходимые локальные сокеты, и полную runtime-матрицу PHP 8.2/8.4. Затем отдельно согласовать external ChatGPT/two-owner live acceptance. До этих шагов PR остаётся draft, production установка и публичный релиз не выполняются.
