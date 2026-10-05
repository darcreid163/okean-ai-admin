# WP AI Admin — публичный клиент беты 0.3.2

Основной MCP URL: **https://wpaiadmin.ru/mcp** (Streamable HTTP). Для Qwen 3.8 Max Responses API: **https://wpaiadmin.ru/sse** (legacy SSE).

ChatGPT и Qwen3.8-Max — первые клиенты. Claude (под «cloud» понимаем Claude) и DeepSeek предусмотрены в providers.json как следующие интеграции. Сервер предоставляет инструменты WordPress; он не запускает и не оплачивает модели ИИ. Модель выбирается в клиенте. Разные ИИ могут работать одновременно: отдельные OAuth-сессии, общий аккаунт/реестр сайтов/лимит и журнал.

## Фактическое состояние на 5 октября

На wpaiadmin.ru установлен и активен Cloud Core 0.3.1. Проверка 5 октября подтвердила рабочее прямое подключение WP AI Admin 2.1.9, но публичный gateway возвращает configured=false и oauth=false. Новая сборка 0.3.2 переносит MCP и SSE на основной домен. Она сохраняет выбор сайтов, профили ИИ и OAuth. Сборка подготовлена для установки; живой вход ChatGPT/Qwen через новые адреса ещё не проверен.

## Как работает пользовательское подключение

1. Пользователь имеет собственную учётную запись WordPress на wpaiadmin.ru. Для беты администратор добавляет ID пользователя в список допуска; администраторам доступ разрешён сразу.
2. В https://wpaiadmin.ru/wpai-cloud/connect пользователь подключает свой WordPress-сайт его администраторским MCP-токеном. Сервер проверяет владение через system_manifest и хранит токен в зашифрованном виде. Автоматическое pairing и выдача коммерческих V2 лицензий — последующие этапы.
3. Пользователь добавляет https://wpaiadmin.ru/mcp в ChatGPT или Qwen Code. Ответ 401 содержит ссылку на OAuth protected-resource metadata.
4. Клиент регистрируется как публичный OAuth-клиент, выполняет Authorization Code + S256 PKCE и открывает вход WordPress. Пользователь явно выбирает сайты и разрешает права mcp:read / mcp:write / offline_access. Чужие сайты не могут попасть в согласие.
5. Код действует 5 минут и используется один раз. Access token действует час; refresh token — до 30 дней, ротируется при каждом обновлении. Повтор старого refresh отзывает только эту OAuth-сессию, не сессии другого ИИ.
6. ИИ получает список разрешённых сайтов, затем каталог инструментов выбранного сайта и вызывает инструмент с явным site_id. Gateway проверяет пользователя, токен, права, сайт и общий месячный лимит; локальный WP AI Admin выполняет sandbox → approve → apply → verify → rollback.

Для двух ИИ один аккаунт означает один лимит. Совпадающий idempotency_key одного логического вызова возвращает ту же квитанцию и не повторяет выполнение/списание. У каждой другой операции нужен собственный ключ. У двух разных аккаунтов данные и лимиты изолированы.

## Настроить хостинг и сервер

1. Используем существующий основной домен wpaiadmin.ru с HTTPS. Отдельный поддомен, новая DNS-запись и второй WordPress не нужны. Пути /mcp, /sse и /.well-known/oauth-protected-resource/mcp обрабатывает Cloud Core; хостинг должен передавать их в WordPress и сохранять Authorization.
2. Установить wp-ai-admin-cloud-0.3.2.zip вместо установленного WP AI Admin Cloud Core. Клиентский WP AI Admin 2.1.9 оставить активным.
3. В админке WP AI Admin Cloud нажать «Включить тестовый gateway», допустить пользователей беты и подключить wpaiadmin.ru как тестовый сайт. Ключи создаются автоматически и не выводятся.
4. После настройки проверить https://wpaiadmin.ru/.well-known/oauth-protected-resource/mcp: resource должен точно равняться https://wpaiadmin.ru/mcp.
5. Центральный issuer: https://wpaiadmin.ru/wpai-cloud. Discovery: https://wpaiadmin.ru/.well-known/oauth-authorization-server/wpai-cloud. Это отдельный issuer: существующий локальный OAuth WP AI Admin на https://wpaiadmin.ru не переписывается.
6. Незаполненный/неверный Bearer на /mcp должен дать 401 с WWW-Authenticate и resource_metadata. POST initialize с действующим токеном — 200; initialized notification — пустой 202; GET с токеном — 405 (сервер без постоянного SSE потока).

## ChatGPT

В настройках ChatGPT включить Developer mode, создать личное MCP-подключение с URL https://wpaiadmin.ru/mcp и OAuth. Название подключения можно указать тем же URL. Пройти вход и выбор сайтов. Этот этап — тестирование личного подключения; публикация в общем каталоге ещё требует отдельного review. ChatGPT Business не является обязательной зависимостью нашей архитектуры.

Первые запросы: «Покажи доступные сайты и остаток лимита», «Покажи каталог инструментов wpaiadmin.ru». Затем провести один полный цикл с тестовой страницей и откатом. Нельзя объявлять публикацию готовой только по HTTP 200 или успешному tools/list.

## Qwen3.8-Max

Основной путь: Qwen Code с доступом к модели qwen3.8-max. Добавить mcpServers из qwen-code.settings.json в свою конфигурацию, не заменяя весь существующий settings.json. httpUrl использует Streamable HTTP; OAuth обнаруживается автоматически. redirectUri установлен на http://127.0.0.1:7777/oauth/callback для локального компьютера. В терминале Qwen Code открыть /mcp и пройти вход. Доступ к модели и её API-авторизацию настраивать отдельно от OAuth нашего сервера.

Альтернатива: Python-клиент с адаптером MCP → Function Calling. Python 3.10+, дополнительных библиотек нет. Он использует **qwen3.8-max** через совместимый /chat/completions. API base берётся из собственной учётной записи Alibaba/Model Studio с учётом региона и workspace — не подставлять адрес другого региона.

Настроить переменные окружения локально: WPAI_GATEWAY_TOKEN (токен нашего сервера), DASHSCOPE_API_KEY (ключ Qwen) и WPAI_QWEN_API_BASE (совместимый base URL из Model Studio). В файлы/чат их не записывать. Gateway-токен для первых администраторских проверок можно получить на час в админке.

```
python wpai_client.py smoke
python wpai_client.py qwen --prompt "Покажи мои сайты и остаток лимита"
python wpai_client.py qwen --interactive-confirm --prompt "Проведи тест на черновике с откатом"
```

Без --interactive-confirm клиент исполняет только gateway-список сайтов/каталог/usage; native tool calls требуют отдельного подтверждения. Стабильный provider call ID привязан к исходным аргументам. Сетевой неизвестный исход или серверный pending_reconciliation останавливает сессию; автоматического повторного выполнения записи нет.

Официальный Qwen Responses API принимает native MCP только по SSE. В 0.3.2 для этого используется отдельный https://wpaiadmin.ru/sse. Qwen Code может по-прежнему подключаться к https://wpaiadmin.ru/mcp по Streamable HTTP. В Cloud Core для Qwen, ChatGPT, Claude и DeepSeek можно выбрать активный подключённый сайт и выдать одночасовой токен, ограниченный этим сайтом.

## Следующие интеграции

Claude: native remote MCP/OAuth; клиентский профиль зарезервирован, живое подключение не проведено. DeepSeek: тот же gateway через function/tool-calling адаптер; профиль предусмотрен, модель и параметры выберем на этапе подключения. Не доверять названию провайдера как доказательству прав: полномочия всегда определяются токеном и аккаунтом.

## Проверки и границы беты

Локально: 125 core/security/concurrency assertions, 61 compatibility/HTTP assertions, 50 OAuth assertions с десятью конкурентными обменами одного кода (ровно один успешен), 10 offline тестов публичного клиента. MySQL/PHP matrix проверяется CI отдельно.

Эта бета ещё не реализует коммерческое enforcement V2, платежи, коммерческие лицензии, учёт одного intent без двойного sandbox/apply списания, batch ceil(успешных объектов/5), автоматическое pairing и восстановление dispatching после падения процесса. Проверки/квитанции/откат при нулевом лимите бесплатны, но локальный клиент 2.1.9 всё ещё имеет собственные ограничения. Отдельная публикация приложения для всех — после реальных тестов, закрытой беты и review.

Официальные источники (проверены 5 октября 2026):
- https://developers.openai.com/plugins/build/auth
- https://qwenlm.github.io/qwen-code-docs/en/developers/tools/mcp-server/
- https://www.alibabacloud.com/help/en/model-studio/mcp
- https://www.alibabacloud.com/help/en/model-studio/qwen-function-calling
- https://platform.claude.com/docs/en/agents-and-tools/mcp-connector
- https://api-docs.deepseek.com/guides/tool_calls/


## Qwen Responses API — прямой тест 0.3.2

1. В WP AI Admin Cloud Core выберите **Qwen 3.8 Max → нужный сайт**.
2. Нажмите **«Получить токен на 1 час для Qwen 3.8 Max»**. Токен показывается один раз и передаётся Qwen в заголовке Authorization: Bearer.
3. В Alibaba Model Studio Responses API задайте MCP tool: server_protocol = sse, server_url = https://wpaiadmin.ru/sse.
4. Первый безопасный prompt: «Покажи доступные сайты и остаток лимита». Затем запросите каталог инструментов выбранного сайта.
5. Запись тестируйте только на черновике и с полным sandbox → approval → apply → verify → rollback циклом.

Для SSE важно, чтобы прокси/хостинг не буферизовал text/event-stream, не удалял Authorization и позволял одновременно держать GET /sse и POST /sse/messages. На shared hosting это обязательная живая проверка.

## Переход с адреса поддомена

После установки 0.3.2 заново создайте OAuth-подключение с https://wpaiadmin.ru/mcp: прежний токен, выданный на другой resource URL, не должен переноситься между ресурсами. Сайты, аккаунты, лимиты и ключи не сбрасываются. Если в wp-config.php вручную задан WP_AI_CLOUD_PUBLIC_MCP_URL или WP_AI_CLOUD_PUBLIC_SSE_URL, обновите эти явные переопределения на основной домен.
