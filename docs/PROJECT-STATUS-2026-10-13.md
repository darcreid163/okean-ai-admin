# WP AI Admin — единый статус проекта, QA38 и история QA37

Дата: 13 октября 2026. Владелец проекта: Николай Андриянов.

**Исторический итог QA37:** проверки и сборка выполнены в среде ChatGPT Work без ожидания GitHub Actions. Подготовлены отдельные установочные QA-кандидаты Native 2.1.15 и Cloud Core 0.3.7. Публичный коммерческий релиз пока не готов: остаются живая приёмка, часть интеграций и коммерческие процессы.

Сводка объединяет доступные сведения из прошлых чатов, текущие исходники, PR/Issues и результаты этой сессии. Это не утверждение о полном просмотре каждого сообщения всех чатов. Исторические результаты отделены от повторно выполненных проверок. Исходники и текущие наблюдения имеют приоритет перед прежними описаниями.

## Текущее продолжение: QA38 — 13 октября 2026

**Готов отдельный Native QA38:** `2.1.15-qa38-20261013`, source commit `617ae304fb95ed0832c32a9d5f250fe2d8001c99`, tree `2bde9cfaa70b58267154118fa25be083eaa018cc`. Код сохранён в PR #37. Слияния и установки на рабочие сайты не выполнялись. Далее разделы 1–7 сохраняют историю QA37; этот раздел и обновлённый трекер имеют приоритет для текущей сборки.

### Что изменено в QA38

| Изменение | Результат |
|---|---|
| Автоматическая подготовка объектов при включении QA администратором | Gutenberg и Elementor-черновики; скрытые simple/variable продукты, две вариации; ссылки на объекты в панели |
| Elementor-фикстура | Один H1, отдельный HTML-виджет и контейнер; регрессия запускается без ручного ввода IDs |
| Сохранение ручных правок | Изменённые черновики и атрибуты вариаций сохраняются; новая сессия получает отдельную фикстуру |
| Scope и владение | Только явно выбранные/управляемые draft/private объекты; название TEST не даёт доступа; чужой Gutenberg не присваивается; опубликованный товар исключается |
| Подготовка и очистка | Compare-and-delete lease; потеря lease не включает разрешения и не удаляет нового владельца блокировки; ошибка save listener не оставляет новый orphan |
| Woodmart | Null/false/callable choices помечаются read-only; некорректная схема/санитайзер возвращают структурированную ошибку без записи и создания операции |
| Идентификаторы операций | Устранена наблюдавшаяся коллизия короткого случайного ID; 128 бит случайности, прежние IDs сохраняются, миграция БД не нужна |
| Workspace | Создаётся каталог изолированной темы и при работе без клонирования; существующие ограничения путей сохранены |

Управляемые здоровые черновики намеренно остаются для повторного использования. Это не случайные тестовые остатки. Временные объекты lifecycle-сценариев удаляются откатом; вручную изменённые объекты сохраняются и не зачисляются автоматически в следующую сессию.

### Повторно выполненные проверки QA38

Среда выполнения: PHP 8.3.6, MariaDB 10.11.14, WordPress 7.0.6, WooCommerce 11.1.2, Elementor 4.3.3, Yoast 28.6. Woodmart положительные мутации проверялись с настоящими классами MCP 1.0.1 и минимальной theme API fixture; полный коммерческий Header Builder пока не проверен.

| Проверка | Итог |
|---|---|
| PHP lint | 159 файлов × PHP 8.2.29 / 8.3.6 / 8.4.1; 0 ошибок |
| Native unit-гейты | 7 групп PASS: bootstrap, entitlement, options, Elementor, Woo stock, pairing, IDs |
| Управляемые фикстуры | 84 assertions PASS; scope/client/session/TTL/владение/ручные правки/ошибка подготовки/lease |
| Zero-ID runner | 16 PASS / 0 FAIL / 0 SKIP; исходное состояние восстановлено |
| Матрица | 112 PASS / 0 FAIL / 4 SKIP / 0 BLOCKED; 116 инструментов |
| ID concurrency | 1 024 уникальных IDs, 8 процессов даже при фиксированном wp_rand; старый алгоритм на этом тесте воспроизводимо выдаёт дубликаты |
| Все пять путей создания операций | 20 настоящих ledger-записей: option/content/Elementor/P0/workspace; уникальные IDs; завершаются reject без применения контента |
| Existing WordPress gates | Explicit-ID QA, admin navigation, native auth boundaries, execution/recovery lease races, operations cleanup — PASS |
| Woodmart | Structured negative cases и Plan/Apply/Rollback/Verify — PASS |
| Деактивация/реактивация | IDs/credential/license сохранены; leases очищены; autonomous modes OFF |
| Готовый ZIP: чистая установка | 116 tools; self-test 77/77; fuzz 220/220 |
| Готовый ZIP: обновления | 2.1.14 → QA38, прежний 2.1.15 → QA38, QA37 → QA38 — PASS; site/installation IDs, OAuth, license и pending operation evidence сохранены; automation OFF |
| Готовый ZIP: происхождение | Все включённые файлы совпали с Git-исходниками; две сборки побайтно идентичны; 86 файлов |

Core не менялся: его файлы побайтно совпали с QA37 Core 0.3.7. Исторические 22 SQLite/offline и 19 MariaDB/Core групп из QA37 не выдаются за новый прогон QA38. Повторная установка Core для этой Native-сборки не требуется.

### Проверка существующей установки wpaiadmin.ru

По MCP подтверждены WordPress 7.1.3 / PHP 8.4.24; Native `2.1.15-release-reconciled-20261012`, Cloud Core 0.3.7. QA37/QA38 на сайте пока не установлены. Self-test текущего Native: 79/79. Центральный grant этого подключения показывает один доступный сайт — wpaiadmin.ru; это не доказывает отсутствие других регистраций или двух владельцев.

У `default_header` текущая схема Woodmart содержит `options=null` и ошибочно объявляет запись доступной. Исправление QA38 проверено локально; на сайте настройка не менялась.

Настоящий браузерный probe для существующего draft #75 прошёл desktop 1440, tablet 768, mobile 390: Visual PASS, найденных overflow/clipped/small-targets/small-text/overlap/offscreen — 0. Общий Page QA — WARN из-за отсутствующего H1 в прежнем черновике. Контент и статус записи не менялись. Это наблюдение существующей сборки, не приёмка QA38 на production и не замена четырёх SKIP локальной матрицы.

### Текущий установочный архив

`wp-ai-admin-2.1.15-qa38-617ae30.zip` — 250 587 байт, 86 файлов.  
SHA-256: `9e16f889d2b78f0f2b7156243c4f11fb6242e980a7c83c5aa25861e492274fb5`.

Исторические QA37 ZIP ниже сохранены, но для нового тестирования Native нужно использовать QA38. Архив `WP_AI_Admin_QA38_Evidence.zip` содержит результаты, snapshots, проверки установки/обновления и provenance, без долговечных credentials и подписанных browser URLs.

## 1. Репозитории, версии и сборки

| Объект | Подтверждённое состояние |
|---|---|
| Основной репозиторий | `darcreid163/okean-ai-admin`, PRIVATE |
| Интеграционная ветка | `codex/private-release-candidate-reconciliation` |
| Основной интеграционный PR | [#35](https://github.com/darcreid163/okean-ai-admin/pull/35), draft |
| QA-исправления | [PR #37](https://github.com/darcreid163/okean-ai-admin/pull/37), ветка `fix/qa-regression-options-20261013`, draft |
| Исходники итоговой QA-сборки | `3ae9e4f7c749f7eb447772fcd131a1c0ca67cc36` |
| Git tree исходников | `893e75784b113f9b44f7328d7f88e002f7bc48ee` |
| Native QA | 2.1.15, Build ID `2.1.15-qa37-20261013` |
| Cloud Core QA | 0.3.7 |
| `main` на момент чтения | Native 2.1.14 / Cloud Core 0.3.6; в этой сессии не изменён |
| Дополнительный CI-репозиторий | `darcreid163/wp-ai-admin-release-ci`: текущая видимость PRIVATE, проверена через GitHub |
| Связанные задачи | [#36](https://github.com/darcreid163/okean-ai-admin/issues/36), [#32](https://github.com/darcreid163/okean-ai-admin/issues/32), историческая [#20](https://github.com/darcreid163/okean-ai-admin/issues/20) |

Старые ZIP в `releases/2.1.15` и `releases/wp-ai-cloud-0.3.7` не заменены новыми байтами. QA-архивы имеют отдельные имена и SHA-256. Номер 2.1.15 сохраняется для тестового кандидата; отдельный Build ID обеспечивает сброс разрешений прежней автономной сессии при его установке. Проверено обновление как с 2.1.14, так и с прежнего кандидата 2.1.15.

## 2. Назначение и принятая архитектура

WP AI Admin связывает ИИ с WordPress через MCP. Native-плагин работает на каждом управляемом сайте. Cloud Core на `wpaiadmin.ru` хранит центральный аккаунт, регистрации сайтов, доступы и общий баланс. Один аккаунт обслуживает несколько сайтов; отдельная подписка для каждого сайта не предполагается.

| Компонент | Роль и договорённость |
|---|---|
| Native | Чтение WordPress, Elementor, Gutenberg, WooCommerce, медиа, меню и файлов; безопасное создание и исполнение операций |
| Cloud Core | Центральный MCP, OAuth, регистрация сайтов, owner/site isolation, общий тариф и квота |
| Центральный endpoint | `https://wpaiadmin.ru/mcp` |
| Сохранённый site-level endpoint | `/wp-json/okean-ai/mcp` на каждом сайте |
| Кабинет подключения | `https://wpaiadmin.ru/wpai-cloud/connect` |
| REST Native | `/wp-json/okean-ai/v1/` |
| Транспорт | Streamable HTTP; SSE-профиль предусмотрен для соответствующих клиентов |
| Выбор сайта | Явный `site_id` и проверка grant на каждом вызове; глобальный изменяемый «текущий сайт» не используется |
| Вместимость аккаунта | 50 сайтов в действующем контракте; добавление сайта не расширяет ранее выданные OAuth grants |
| Публикация | Основной путь — публичное приложение через OpenAI Apps SDK / App Directory; обязательная зависимость от ChatGPT Business не закладывается |

Существуют два независимых согласия: администратор подключает сайт к центральному аккаунту; пользователь разрешает ИИ доступ к выбранным сайтам и scope. Долговечный credential сайта не выдаётся ИИ. Сохраняются PKCE S256, одноразовые коды, refresh rotation, revoke, persistent site/installation IDs и владелец регистрации.

## 3. Что уже реализовано

| Область | Текущее содержание | Ограничение вывода |
|---|---|---|
| Безопасные изменения | Sandbox → approval → apply → verify → receipt/proof → rollback; diff, конфликтные проверки, ledger, execution/recovery leases | Прямые live writes выключены |
| Автономные режимы | AI Lifecycle Control и Test Automation с разрешением администратора, привязкой к клиенту/сессии/build и сроком действия | Подключение сайта само их не включает |
| Elementor | Структура, виджеты, HTML/фрагменты, настройки, контейнеры, layout, создание черновика, серия точечных правок | Сохраняется требование EDIT ≠ APPEND; visual QA — отдельный гейт |
| Gutenberg | Разбор/outline/поиск блоков, точечные правки, managed test fixture | Некорректную сериализацию нельзя считать успешной правкой |
| WooCommerce | Каталог, simple/variable products, stock/price, SKU, атрибуты/вариации и draft creation через lifecycle | Дальнейшая массовая коммерческая тарификация требует отдельного контракта |
| Медиа | Upload, alt, title, featured через sandbox и rollback | Импорт по внешнему URL в этой матрице пропущен |
| Меню | Create/add/move/remove через sandbox и rollback | Назначение реальных production locations автоматической регрессией не проверялось |
| Файлы/REST/WP-CLI | Ограниченные scope, чтение/планирование, контроль путей и защищённых REST-направлений | Эта QA-регрессия не включала live file mutation |
| Woodmart | Базовые theme settings, transactional write, конфликтная защита, invalidate CSS и rollback | Полная коммерческая тема и Header Builder не квалифицированы |
| Native batch | Durable job, зависимости, pause/resume/cancel/retry, версии/checkpoints, mutex, receipts/proofs | Центральное batch billing пока заблокировано |
| Core security | Tenant isolation, scope/audience/site grants, revoke/suspend, idempotency, quota reservation/reconciliation | Реальные два чужих аккаунта ChatGPT ещё нужны |

## 4. Принятые коммерческие правила

Все инструменты предполагаются доступными на Free. Основное отличие тарифов — объём успешных действий; один тариф и баланс общие для сайтов аккаунта. Неуспешные вызовы не расходуют квоту. Назначенные verify/receipt/rollback-вызовы остаются бесплатными по текущей политике.

| Тариф | Предварительный месячный объём |
|---|---:|
| Free | 100 |
| Pro | 500 |
| Power | 2 000 |
| Agency | 5 000 |
| Scale | 10 000 |

Текущая серверная конфигурация: `pilot-2026-10-02`, календарный месяц UTC, `status=preliminary`, тарификация успешного одиночного tool call. Согласованная будущая batch-формула — 1 action за каждые 5 успешно обработанных объектов, без учёта неудачных, с версионированием серверной политики. Это план; центральное исполнение batch сейчас блокируется до реализации receipt accounting.

Оплаты, публичная самостоятельная регистрация, автоматическая выдача платных entitlements, докупаемые пакеты и партнёрская программа не считаются готовыми только потому, что для них есть таблицы или предварительный план.

## 5. Изменения из других чатов и этой сессии

12 октября в интеграционную ветку были сведены 55 отличавшихся/недостающих файлов кандидата; собственные файлы интеграционной ветки сохранены. Исправлены гонки удаления чужого нового lease и одновременного восстановления abandoned Apply/Rollback. Подробное историческое свидетельство находится в `docs/WORK-QUALIFICATION-2026-10-12.md`; оно относится к прежнему source commit и не подменяет текущую проверку.

13 октября живая проверка старого кандидата на `wpaiadmin.ru` выявила проблемы, записанные в #36: reserved QA option key, ложные ошибки короткой пагинации, неполный rollback-отчёт, недоступный allowlist для части fixtures, отсутствие heading fixture и незавершённый Woodmart Header Builder. До PR #37 матрица на сайте сообщала 95 PASS / 13 FAIL / 8 SKIP. Это историческое состояние старой сборки, а не итог QA37.

| Исправление QA37 | Проверка |
|---|---|
| `oai_test_matrix_*` заменён безопасным `wp_ai_admin_test_matrix_*` | Настоящий option lifecycle PASS; служебная защита не ослаблена |
| Пустая вторая страница короткой коллекции не считается ошибкой сама по себе | Media/users/comments pagination в матрице PASS |
| Ошибка до создания операции различается с невосстановленным состоянием; orphan draft не объявляется очищенным | Положительные lifecycle и stop-on-uncertain-state контракт сохранены; все возможные injected failures не заявляются проверенными |
| Runner создаёт отдельные hidden draft simple/variable Woo products | Оба create → apply → verify → rollback сценария PASS |
| Batch retry использует фактический baseline excerpt fixture | Denial при unresolved child, reconciliation после reject и последующий retry/cancel PASS |
| Supports типа записи читаются через WordPress registry | Матрица проходит без предупреждения об отсутствующем `WP_Post_Type::$supports` |
| dbDelta получает каждый индекс на отдельной строке | Два install подряд, наличие индексов и Reactivation PASS без прежних SQL-ошибок |
| У QA37 отдельный Build ID | Same-version upgrade 2.1.15 → QA37 сохраняет IDs/credentials и отключает старые autonomous modes |
| Добавлен `tests/wordpress-qa-regression.php` | Явный disposable guard; реальная WordPress регрессия, матрица, snapshots и residue checks |

**Историческое ограничение QA37, закрыто в QA38:** автоподготовка всех fixtures в пользовательском интерфейсе Test Automation тогда требовала завершения: managed Gutenberg уже есть, simple/variable создаются внутри runner, но Elementor heading/html/container и enrolment всех managed fixtures не следует объявлять полноценным законченным UI-процессом.

## 6. Исторические результаты локального QA37

Среда: Ubuntu 24.04, runtime PHP 8.3.6, SQLite, MariaDB 10.11.14, WordPress 7.0.6, WooCommerce 11.1.2, Elementor 4.3.3, Yoast 28.6. PHP 8.2.29 и 8.4.1 собраны отдельно для lint. WordPress и БД одноразовые; серверы слушали loopback и остановлены после проверок. Ни одна установка не выполнялась на `wpaiadmin.ru` или `okean.market`.

| Проверка | Итог |
|---|---|
| Получение исходного PR | 222 текстовых + 12 бинарных Git blobs сверены по SHA-1; дальнейшие изменения сохранены отдельными Git commits |
| PHP lint | 154 исходных PHP-файла × PHP 8.2/8.3/8.4: 0 ошибок |
| Native/Core/client/session offline + SQLite | 22 группы PASS |
| MariaDB server / concurrency / isolation | 19 групп PASS |
| Fresh Native ZIP install | 116 tools; self-test 77/77; fuzz 220/220 на свежей среде |
| Self-test с активными дополнительными plugins и Woodmart fixture | 79 PASS / 0 FAIL |
| Reversible QA runner | 16 PASS / 0 FAIL / 0 SKIP |
| Tool matrix | 112 PASS / 0 FAIL / 4 SKIP; 116 entries |
| Media/Menu/Reject | Все соответствующие runner stages PASS |
| Независимая сверка fixtures | Значения, типы, Elementor HTML/структура восстановлены; новых post artifacts нет; меню/locations/QA-опции сохранены |
| Elementor raw JSON | Экранирование `/` нормализуется: raw storage bytes могут различаться; decoded values/types и HTML совпадают |
| Native upgrade | 2.1.14 → QA37 и прежняя 2.1.15 → QA37: PASS; IDs/OAuth/license/pending evidence сохранены; automation reset |
| Cloud upgrade | 0.3.6 → 0.3.7: PASS; keys/account/site/bearer/OAuth сохранены |
| Woodmart MCP 1.0.1 | Plan/Apply/Rollback/Verify PASS с минимальной theme API fixture |
| ZIP | Integrity, каждый включённый файл соответствует исходнику, две независимые сборки побайтно совпали |

Числа групп, assertions, сценариев и entries обозначают разные уровни проверки; складывать их в одно число «всех тестов» некорректно. Часть matrix PASS подтверждает контракт/планирование, а не отдельную живую мутацию каждого инструмента.

Разрешённые SKIP матрицы: внешний visual probe, media URL import, назначение production menu locations и Woodmart mutation внутри automatic QA. Базовая Woodmart mutation проверена отдельно только с минимальной fixture.

Первичные сбои подготовки стенда устранены: AF_UNIX/сетевые namespace ограничения потребовали совместного запуска DB и клиентов; PHP получил Phar; тестовый wp-config получил сильные AUTH keys; повторный upgrade fixture очищал собственную ранее созданную синтетическую token row. Эти ошибки стенда не представлены как исправления продукта. Первое raw JSON сравнение уточнено до семантики хранения, с сохранённой отдельной отметкой о нормализации байтов.

GitHub Actions зелёным не объявляется. Для прежнего SHA `d97ae7c...` Native и Core runs завершились failure; logs retrieval вернул BlobNotFound. Причина hosted failure по полным журналам не подтверждена. Local qualification обходится без запуска или ожидания hosted CI.

## 7. Архивы и контрольные суммы

| Архив | SHA-256 |
|---|---|
| `wp-ai-admin-2.1.15-qa37-3ae9e4f.zip` | `9dbb1e2d7d4a108350231f561dadef76a37d4e7a93e26baea70f1ddcea16cf48` |
| `wp-ai-admin-cloud-0.3.7-qa37-3ae9e4f.zip` | `24c801c36907ea1067ebe9221b8f18922d4bd1ecad7044e74a8edd0884133275` |

Это установочные QA-архивы плагинов, не ZIP всего репозитория. Native содержит 85 файлов, Core — 19; tests и исторические releases в установочные ZIP не включены. У старого Windows-скрипта pinned SHA `d97ae7c...`: он преднамеренно остановится на новом head и не является сборщиком этих финальных архивов.

## 8. Единый трекер продолжения

| Приоритет | Работа | Статус / критерий завершения |
|---|---|---|
| P0 | Локальная проверка QA38 + сборка | Выполнено; QA37 сохранён как история |
| P0 | Живая регрессия QA38 на wpaiadmin.ru | Ожидает установки проверенного QA-кандидата; self-test, matrix, receipts, rollback, independent snapshots и residue |
| P0 | Fixture provisioning Test Automation UI | Выполнено в QA38; ожидает проверки установленной сборки на сайте |
| P0 | Два реальных владельца | Account A не читает/calls B, не подменяет site_id, не получает чужие operations/receipts/logs; revoked/old token и scope denied |
| P0 | ChatGPT OAuth / clean-user onboarding | Реальное браузерное согласие обоих видов, два сайта, allow/deny, refresh/revoke и сохранение доступа после обновления |
| P0 | Полный Woodmart и Header Builder | Structured invalid default_header error исправлен локально в QA38; далее отдельный reversible header adapter; не заменять production header тестовой фикстурой |
| P0 | Остальные четыре SKIP | Отдельно закрыть или явно исключить из согласованного release scope; не скрывать в общей статистике |
| P0 | Release engineering | PR #37 остаётся draft; затем сверка/слияние в integration и квалификация #35. Не выпускать в main автоматически |
| P0 | Реальная коммерческая готовность | Public registration, billing/entitlements и тексты о реально доступных функциях |
| P0 | OpenAI submission | Privacy, Terms, data retention, SECURITY, demo/reviewer access и честный release checklist #32 |
| P1 | Дополнительная runtime matrix | В этой сессии PHP 8.2/8.4 проверены только lint; MySQL 8 и WP 7.1.3 — исторические проверки, не повторены на финальном QA38 |
| P1 | Qwen/SSE и другие клиенты | Реальная provider acceptance; Claude/DeepSeek не объявлять готовыми только по наличию профиля |
| P1 | Batch receipt accounting | Утвердить versioned формулу успешных объектов, retries/refunds и reconciliation до включения central batch |
| Будущее | Пакеты ресурсов, fleet jobs, safe updater, session rollback, richer Woo/Elementor adapters | После или параллельно закрытию P0 без ослабления безопасности |
| Отдельный проект | `universal-mcp-platform` | Своя ветка/PR и универсальный каталог MCP; в этой сессии не изменён и не слит с WP AI Admin |

Следующий практический этап — установить QA38 Native на wpaiadmin.ru, подтвердить Build ID и снова включить QA администратором в панели. Новый build отключает прежние автономные разрешения. Затем проверить подготовленные fixtures, self-test, matrix, Apply/Verify/Rollback, независимые snapshots и отсутствие неожиданных остатков. Core уже 0.3.7. Отдельный двухаккаунтный isolation gate обязателен перед подключением чужих клиентов. Локальный PASS не подтверждает внешний ChatGPT/browser/hosting acceptance; GitHub Actions не объявляется зелёным.
