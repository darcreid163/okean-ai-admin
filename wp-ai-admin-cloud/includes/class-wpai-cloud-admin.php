<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_Admin {
    public static function register_menu() {
        add_menu_page(
            'WP AI Admin Cloud',
            'WP AI Admin Cloud',
            'manage_options',
            'wpai-cloud',
            array(__CLASS__, 'page'),
            'dashicons-cloud',
            57
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $counts = array();
        foreach (array('accounts','licenses','sites','usage_events','usage_reservations') as $name) {
            $table = WPAI_Cloud_DB::table($name);
            $counts[$name] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        }
        echo '<div class="wrap"><h1>WP AI Admin Cloud Core</h1>';
        echo '<p>Версия <strong>' . esc_html(WPAI_CLOUD_VERSION) . '</strong>. Центральный control plane для лицензий, сайтов и usage.</p>';
        echo '<table class="widefat striped" style="max-width:800px"><tbody>';
        foreach ($counts as $key => $count) echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . (int) $count . '</td></tr>';
        echo '</tbody></table>';
        echo '<h2 style="margin-top:24px">Каталог тарифов</h2>';
        try {
            $policy = \WPAICloudGateway\Settings::policy();
            $rows = array();
            foreach ((array) ($policy['plans'] ?? array()) as $slug => $limit) $rows[] = array('tariffs_v2', $slug, (string) ($policy['periods'][$slug] ?? ''), $limit, 'approved');
            foreach ((array) ($policy['legacy_plans'] ?? array()) as $slug => $limit) $rows[] = array('legacy_monthly_v1', $slug, 'calendar_month_utc', $limit, 'approved');
            $v3 = $policy['schemes']['tariffs_v3'] ?? null;
            if (is_array($v3)) foreach ($v3['plans'] as $slug => $limit) $rows[] = array('tariffs_v3', $slug, (string) ($v3['periods'][$slug] ?? ''), $limit, (string) ($v3['quota_status'][$slug] ?? ''));
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Схема</th><th>План</th><th>Период</th><th>Лимит действий</th><th>Квота</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td><code>' . esc_html($row[0]) . '</code></td><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td><td>'
                    . ($row[3] === null ? '<strong>не утверждён</strong>' : (int) $row[3]) . '</td><td>' . esc_html($row[4]) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p>Платные квоты Business/Pro/BusinessPro утверждает владелец. До утверждения billable-вызовы отклоняются, а операции чтения, проверки, квитанции и откат остаются доступны.</p>';
        } catch (Throwable $e) {
            echo '<p><strong>Каталог недоступен:</strong> <code>' . esc_html($e->getMessage()) . '</code></p>';
        }
        echo '<p style="margin-top:18px">REST status: <code>' . esc_html(rest_url(WPAI_CLOUD_REST_NS . '/status')) . '</code></p>';
        echo '<p>Plans API: <code>' . esc_html(rest_url(WPAI_CLOUD_REST_NS . '/plans')) . '</code></p>';
        if (class_exists('WPAICloudGateway\\WordPress')) \WPAICloudGateway\WordPress::statusPage();
        echo '</div>';
    }
}
