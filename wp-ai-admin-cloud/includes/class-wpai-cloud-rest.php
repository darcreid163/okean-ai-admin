<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_REST {
    public static function register_routes() {
        register_rest_route(WPAI_CLOUD_REST_NS, '/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(WPAI_CLOUD_REST_NS, '/plans', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'plans'),
            'permission_callback' => '__return_true',
        ));
    }

    private static function response($data, $status = 200) {
        return new WP_REST_Response(array(
            'success' => true,
            'request_id' => 'req_' . wp_generate_uuid4(),
            'data' => $data,
            'error' => null,
        ), $status);
    }

    public static function status() {
        return self::response(array(
            'service' => 'WP AI Admin Cloud Core',
            'version' => WPAI_CLOUD_VERSION,
            'db_version' => WPAI_CLOUD_DB_VERSION,
            'environment' => wp_get_environment_type(),
            'site_url' => home_url('/'),
            'time_utc' => gmdate('c'),
        ));
    }

    public static function plans() {
        global $wpdb;
        $plans = $wpdb->get_results(
            "SELECT p.slug,p.name,p.active,v.version_key,v.monthly_actions,v.batch_objects_per_action,v.failed_actions_billable,v.rollback_billable,v.verify_billable
             FROM " . WPAI_Cloud_DB::table('plans') . " p
             INNER JOIN " . WPAI_Cloud_DB::table('plan_versions') . " v ON v.plan_id=p.id
             WHERE p.active=1 AND v.active=1
             ORDER BY p.id ASC",
            ARRAY_A
        );
        return self::response(array('items' => $plans));
    }
}
