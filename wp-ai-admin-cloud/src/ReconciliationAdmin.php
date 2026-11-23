<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Administrator-only read-only reconciliation screen. */
final class ReconciliationAdmin {
    public static function boot(): void {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_wpai_cloud_reconcile',[self::class,'handle']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wpai-cloud',
            'Сверка платежей',
            'Сверка платежей',
            'manage_options',
            'wpai-cloud-reconciliation',
            [self::class,'page']
        );
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Сверка платежей и лицензий</h1>';
        echo '<p>Проверка только читает заказы WooCommerce и реестры Cloud Core. Она не выдаёт, не отзывает и не изменяет лицензии, заказы или платежи.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('wpai_cloud_reconcile');
        echo '<input type="hidden" name="action" value="wpai_cloud_reconcile">';
        echo '<p><label>Последние заказы для проверки: <select name="limit"><option value="100">100</option><option value="250">250</option><option value="500" selected>500</option></select></label></p>';
        submit_button('Запустить сверку (только чтение)');
        echo '</form></div>';
    }

    public static function handle(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.',403);
        check_admin_referer('wpai_cloud_reconcile');
        $limit=isset($_POST['limit'])?(int)$_POST['limit']:500;
        if (!in_array($limit,[100,250,500],true)) $limit=500;
        try {
            $report=WordPress::reconciliationReport($limit);
        } catch (Fault $e) {
            $report=['error'=>$e->reason];
        } catch (\\Throwable $e) {
            $report=['error'=>'reconciliation_unavailable'];
        }
        nocache_headers();
        echo '<div class="wrap"><h1>Результат сверки платежей</h1>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=wpai-cloud-reconciliation')).'">Вернуться</a></p>';
        if (isset($report['error'])) {
            echo '<div class="notice notice-error"><p>'.esc_html($report['error']).'</p></div>';
        } else {
            echo '<p><strong>Режим:</strong> только чтение · <strong>Время:</strong> '.esc_html($report['generated_at']).' UTC</p>';
            echo '<ul>';
            echo '<li>Заказов Robokassa просмотрено: '.(int)$report['orders_scanned'].'</li>';
            echo '<li>Оплаченных заказов: '.(int)$report['paid_robokassa_orders'].'</li>';
            echo '<li>Записей Cloud: '.(int)$report['cloud_payments_scanned'].'</li>';
            echo '<li>Расхождений: '.(int)$report['issue_count'].'</li>';
            echo '<li>Изменений данных: 0</li>';
            echo '</ul>';
            if (!empty($report['issues'])) {
                echo '<table class="widefat striped"><thead><tr><th>Код</th><th>Заказ</th><th>Описание</th></tr></thead><tbody>';
                foreach ($report['issues'] as $issue) {
                    echo '<tr><td><code>'.esc_html($issue['code']).'</code></td><td><a href="'.esc_url(admin_url('post.php?post='.(int)$issue['order_id'].'&action=edit')).'">#'.esc_html($issue['order_id']).'</a></td><td>'.esc_html($issue['message']).'</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="notice notice-success"><p>В проверенной выборке расхождений не обнаружено.</p></div>';
            }
            if (!empty($report['truncated'])) echo '<p>Показаны первые 100 расхождений. Сводные счётчики включают все найденные.</p>';
            echo '<p><strong>Важно:</strong> записи Cloud, для которых заказ не попал в выбранные последние '.$limit.' заказов, помечаются как требующие проверки, а не как доказанное отсутствие заказа.</p>';
        }
        echo '</div>';
    }
}
