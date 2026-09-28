# WP AI Admin 2.0.56 — кандидат для проверки

Дата: 28.09.2026. Установленная версия 2.0.56 подтверждена manifest build `2.0.56-atomic-batch-mutex-20260928`.

Кандидат ZIP: `wp-ai-admin-2.0.56-atomic-batch-mutex.zip`, SHA-256 `c9802cf9c22e3af41e7b9bc6abc80a413ae982c74be28bbbe745f94935ade636`; 85 записей, 57 PHP-файлов плагина, CRC PASS.

## Изменение

Все мутации batch job (`create`, `resume`, `pause`, `retry`, `cancel`, `rollback`) выполняются под одним именованным MySQL lock, привязанным к базе и префиксу таблиц WordPress. Конкурирующий запрос возвращает 409, если lock занят; при недоступности блокировки возвращает 503. Lock освобождается в `finally` и также освобождается сервером при закрытии соединения. Прежняя 60-секундная lease остаётся в checkpoint для наблюдения за job.

Batch jobs хранятся в общем WordPress option, поэтому блокировка охватывает мутации разных jobs и исключает потерю изменений при двух одновременных read/modify/write. Цена — последовательное выполнение batch-мутаций на одной установке.

## Ворота проверки

- **PASS**: PHP lint 58 файлов (57 файлов плагина и один тест) на PHP 8.2, 8.3 и 8.4.
- **PASS**: ZIP integrity.
- **PASS**: два независимых PHP-процесса против одного MySQL: первый удерживает lock, второй получает `oai_batch_busy`, следующий после release входит. GitHub CI run `36469920817`.
- **PASS на сайте**: catalog 90/90, self-test 49/49, Security Fuzz 200/200.
- **PASS на сайте**: Regression Matrix `mx_20260928_191818_7808d1e1`, 90 PASS / 0 FAIL / 0 SKIP, две итерации по 8 lifecycle-сценариев, `baseline_restored=true`.
- **PASS через MCP**: batch `batch_4596a461da314beea85d0da33873079a` из 50 read-шагов. Два одновременно отправленных resume с `expected_version=1`: первый выполнил 20 шагов, второй получил 409 version conflict; ровно 20 шагов имели по одной попытке. Затем 20+10, job completed 50/50, lease inactive, version/checkpoint 7/7. Вызовы могли быть последовательно обработаны подключением.
- **PASS на сайте**: batch receipt и прямой proof имеют одинаковый payload hash. Тестовый черновик №35419 сохранил пустой excerpt, товар №35127 — stock 0 и пустую цену, pending operations 0.

CI проверяет реальную конкуренцию за MySQL lock, но не заменяет полный сетевой тест двух WordPress/PHP запросов на хостинге: через MCP второй вызов увидел уже обновлённую версию, а не занятый lock. Прямые live writes по-прежнему запрещены; запись batch создаёт только sandbox child-operation.
