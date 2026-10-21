<?php
/**
 * Closed Tier A skill library.
 *
 * These instructions are the delivery format of the hosted gateway only. They are
 * never shipped in the distributable WordPress plugin, so progressive skill text
 * stays server-side while the plugin keeps only its REST/MCP executor surface.
 *
 * Shape per entry:
 *   id, title, summary, when, tools[] (site tool names this skill drives),
 *   steps[] (ordered, max 40), guardrails[] (max 20).
 */
declare(strict_types=1);

return array(
    'content-edit' => array(
        'title' => 'Правка контента и публикация',
        'summary' => 'Найти материал, изменить текст через sandbox и провести его через подтверждение.',
        'when' => 'Пользователь просит изменить текст, заголовок или статус существующей записи или страницы.',
        'tools' => array('wp_read_post', 'content_patch', 'operation_apply', 'operation_verify', 'operation_rollback', 'operation_get_receipt'),
        'steps' => array(
            'Найди целевой объект: используй wp_list_posts или wp_read_content и верни ID, тип, статус и автора.',
            'Прочитай актуальное содержимое через wp_read_post и сохрани его хэш из ответа.',
            'Создай изменение через content_patch с точным old_content и new_content; не переписывай файл целиком.',
            'Покажи пользователю diff и запроси подтверждение. Не вызывай operation_apply до явного согласия.',
            'После подтверждения вызови operation_apply, затем operation_verify и сохрани operation_get_receipt.',
            'Если verify не подтвердил результат, не повторяй apply: сообщи о конфликте и предложи operation_rollback.',
        ),
        'guardrails' => array(
            'Никогда не публикуй черновик без отдельного подтверждения.',
            'Не редактируй записи другого автора без явного разрешения.',
            'Один idempotency_key на одно логическое действие; не повторяй его с новым ключом.',
        ),
    ),
    'menu-management' => array(
        'title' => 'Меню и навигация',
        'summary' => 'Добавить, скрыть или переставить пункт меню через sandbox.',
        'when' => 'Нужно изменить навигацию сайта, добавить страницу в меню или скрыть пункт.',
        'tools' => array('wp_list_menus', 'menu_add_item_sandbox', 'menu_remove_item_sandbox', 'operation_apply', 'operation_verify'),
        'steps' => array(
            'Прочитай меню через wp_list_menus и определи menu_id и локацию.',
            'Создай пункт через menu_add_item_sandbox или скрытие через menu_remove_item_sandbox.',
            'Проверь, что новый пункт не дублирует существующий: сравни URL и label.',
            'Запроси подтверждение с указанием меню, позиции и видимости.',
            'После apply выполни verify и покажи итоговое дерево меню.',
        ),
        'guardrails' => array(
            'Не меняй локацию меню без подтверждения: это влияет на весь сайт.',
            'Не удаляй пункт физически, если доступно скрытие.',
        ),
    ),
    'woocommerce-product' => array(
        'title' => 'Товар WooCommerce',
        'summary' => 'Изменить цену, остаток или описание товара с проверкой складской логики.',
        'when' => 'Пользователь просит обновить товар, цену, скидку или остаток.',
        'tools' => array('woocommerce_search_products', 'woocommerce_get_product_full', 'woocommerce_update_product_sandbox', 'operation_apply', 'operation_verify', 'operation_rollback'),
        'steps' => array(
            'Найди товар через woocommerce_search_products по SKU или названию.',
            'Прочитай полные данные через woocommerce_get_product_full: цена, остаток, статус склада, вариации.',
            'Проверь бизнес-правила: цена не отрицательная, sale_price не выше regular_price, остаток целый.',
            'Создай изменение через woocommerce_update_product_sandbox и покажи пользователю таблицу до/после.',
            'После подтверждения вызови operation_apply, затем operation_verify; зафиксируй receipt.',
        ),
        'guardrails' => array(
            'Не меняй цены массово без поштучного подтверждения списка.',
            'Не отключай управление остатками, если пользователь этого не просил.',
        ),
    ),
    'elementor-page' => array(
        'title' => 'Страница Elementor',
        'summary' => 'Точечно изменить виджет или фрагмент без перезаписи дерева.',
        'when' => 'Нужно поправить текст, изображение или HTML внутри страницы Elementor.',
        'tools' => array('elementor_get_structure', 'elementor_get_widget', 'elementor_patch_fragment', 'elementor_patch_widget', 'operation_apply', 'operation_verify'),
        'steps' => array(
            'Прочитай структуру через elementor_get_structure и найди widget_id.',
            'Прочитай виджет через elementor_get_widget view=outline и сохрани его хэш.',
            'Для текста или картинки используй elementor_patch_fragment с точным old_fragment и expected_sha256.',
            'Для настроек используй elementor_patch_widget с изменением одного ключа.',
            'Покажи diff, дождись подтверждения, затем apply и verify.',
        ),
        'guardrails' => array(
            'Не заменяй весь _elementor_data вручную.',
            'Не трогай глобальные шаблоны и тему без отдельного подтверждения.',
        ),
    ),
    'theme-file' => array(
        'title' => 'Файлы темы',
        'summary' => 'Прочитать и изменить файл дочерней темы в ограниченном scope.',
        'when' => 'Пользователь просит поправить CSS, шаблон или functions.php дочерней темы.',
        'tools' => array('wp_list_files', 'file_read', 'wp_file_outline', 'file_diff', 'operation_apply', 'operation_verify', 'operation_rollback'),
        'steps' => array(
            'Перечисли файлы через wp_list_files в scope child_theme и найди нужный путь.',
            'Прочитай файл через file_read с start_line/end_line, если он большой.',
            'Проверь, что путь не выходит за пределы разрешённого scope и расширение в allowlist.',
            'Создай sandbox-изменение и покажи diff через file_diff.',
            'После подтверждения выполни apply, verify и сохрани receipt.',
        ),
        'guardrails' => array(
            'Не редактируй родительскую тему и ядро WordPress.',
            'Для PHP-файлов обязателен lint до применения; при ошибке синтаксиса откажи.',
        ),
    ),
    'media' => array(
        'title' => 'Медиа',
        'summary' => 'Импортировать изображение по URL или найти его и подключить к материалу.',
        'when' => 'Нужна картинка для записи, товара или баннера.',
        'tools' => array('media_import_url_sandbox', 'media_set_featured_sandbox', 'media_update_alt_sandbox', 'operation_apply', 'operation_verify'),
        'steps' => array(
            'Получи от пользователя HTTPS-URL изображения или найди его через cloud_media_search_images.',
            'Создай медиа через media_import_url_sandbox: сервер проверит URL, размер и реальный MIME.',
            'Заполни alt через media_update_alt_sandbox: alt обязателен для доступности.',
            'Для записи или товара подключи изображение через media_set_featured_sandbox.',
            'После подтверждения выполни apply и verify; при откате файл удаляется только если создан этой операцией.',
        ),
        'guardrails' => array(
            'Не импортируй URL с приватных адресов, localhost или нестандартных портов.',
            'Для найденных через Unsplash изображений сохраняй атрибуцию автора в ответе пользователю.',
        ),
    ),
);
