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
        echo '<p style="margin-top:18px">REST status: <code>' . esc_html(rest_url(WPAI_CLOUD_REST_NS . '/status')) . '</code></p>';
        echo '<p>Plans API: <code>' . esc_html(rest_url(WPAI_CLOUD_REST_NS . '/plans')) . '</code></p>';
        \WPAICloudGateway\WordPress::statusPage();
        echo '</div>';
    }
}
