<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Administrator-only read-only reconciliation screen. */
final class ReconciliationAdmin {
    public static function boot(): void {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_wpai_cloud_reconcile',[self::class,'handle']);
        add_action('admin_post_wpai_cloud_repair_paid_order',[self::class,'repairPaidOrder']);
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
        echo '<p>Проверка только читает заказы WooCommerce и реестры Cloud Core. Исправление доступно отдельно, только для оплаченного заказа без записи Cloud, после проверки операции в кабинете Robokassa и явного подтверждения администратора.</p>';
        self::repairNotice();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('wpai_cloud_reconcile');
        echo '<input type="hidden" name="action" value="wpai_cloud_reconcile">';
        echo '<p><label>Последние заказы для проверки: <select name="limit"><option value="100">100</option><option value="250">250</option><option value="500" selected>500</option></select></label></p>';
        submit_button('Запустить сверку (только чтение)');
        echo '</form></div>';
    }

    private static function repairForm(int $orderId): void {
        $phrase='ACTIVATE ORDER #'.$orderId;
        echo '<div style="margin-top:10px;padding:10px;border:1px solid #dba617;background:#fff">';
        echo '<strong>Ручное восстановление выдачи лицензии</strong>';
        echo '<p>Сначала убедитесь в кабинете Robokassa, что операция по этому заказу действительно завершена успешно. Затем введите точную фразу <code>'.esc_html($phrase).'</code>.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('wpai_cloud_repair_paid_order_'.$orderId);
        echo '<input type="hidden" name="action" value="wpai_cloud_repair_paid_order">';
        echo '<input type="hidden" name="order_id" value="'.$orderId.'">';
        echo '<input type="text" name="confirmation" required autocomplete="off" placeholder="'.esc_attr($phrase).'" style="min-width:240px">';
        echo ' <button type="submit" class="button button-secondary">Подтвердить повторную выдачу</button>';
        echo '</form></div>';
    }

    private static function repairNotice(): void {
        $result=isset($_GET['repair_result'])?sanitize_key(wp_unslash($_GET['repair_result'])):'';
        if ($result==='repaired') {
            echo '<div class="notice notice-success"><p>Платёж повторно обработан. Запись Cloud и лицензия проверены.</p></div>';
        } elseif ($result==='already_settled') {
            echo '<div class="notice notice-info"><p>Платёж уже обработан. Повторная выдача не выполнялась.</p></div>';
        } elseif ($result!=='') {
            echo '<div class="notice notice-error"><p>Исправление остановлено. Код: <code>'.esc_html($result).'</code>. Проверьте заказ и платёж вручную.</p></div>';
        }
    }

    public static function repairPaidOrder(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.','',['response'=>403]);
        $orderId=isset($_POST['order_id'])?absint($_POST['order_id']):0;
        if ($orderId<=0) wp_die('Некорректный номер заказа.','',['response'=>400]);
        check_admin_referer('wpai_cloud_repair_paid_order_'.$orderId);
        $confirmation=isset($_POST['confirmation'])?sanitize_text_field(wp_unslash($_POST['confirmation'])):'';
        $expected='ACTIVATE ORDER #'.$orderId;
        if (!hash_equals($expected,$confirmation)) wp_die('Подтверждение не совпадает.','',['response'=>400]);
        try {
            $result=WordPress::repairPaidWooOrder($orderId);
            $state=!empty($result['repaired'])?'repaired':'already_settled';
        } catch (Fault $e) {
            $state=sanitize_key($e->reason);
        } catch (\Throwable $e) {
            $state='repair_unavailable';
        }
        $url=add_query_arg(['page'=>'wpai-cloud-reconciliation','repair_result'=>$state],admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.','',['response'=>403]);
        check_admin_referer('wpai_cloud_reconcile');
        $limit=isset($_POST['limit'])?(int)$_POST['limit']:500;
        if (!in_array($limit,[100,250,500],true)) $limit=500;
        try {
            $report=WordPress::reconciliationReport($limit);
        } catch (Fault $e) {
            $report=['error'=>$e->reason];
        } catch (\Throwable $e) {
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
                    echo '<tr><td><code>'.esc_html($issue['code']).'</code></td><td><a href="'.esc_url(admin_url('post.php?post='.(int)$issue['order_id'].'&action=edit')).'">#'.esc_html($issue['order_id']).'</a></td><td>'.esc_html($issue['message']);
                    if (!empty($issue['repairable'])) self::repairForm((int)$issue['order_id']);
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="notice notice-success"><p>В проверенной выборке расхождений не обнаружено.</p></div>';
            }
            if (!empty($report['truncated'])) echo '<p>Показаны первые 100 расхождений. Сводные счётчики включают все найденные.</p>';
            echo '<p><strong>Важно:</strong> WooCommerce-заказы и Cloud-платежи проверяются в ограниченных выборках. Для каждой попавшей в отчёт записи Cloud выполняется отдельный поиск заказа по ID; старый заказ не считается отсутствующим только из-за того, что он вне списка последних заказов.</p>';
        }
        echo '</div>';
    }
}
