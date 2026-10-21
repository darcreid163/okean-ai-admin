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
        try {
            if (!class_exists('WPAICloudGateway\\Settings')) throw new RuntimeException('Gateway PHP requirements unavailable');
            $policy=\WPAICloudGateway\Settings::catalogPolicy();
        } catch (Throwable $e) {
            return new WP_REST_Response(array('success'=>false,'data'=>null,'error'=>'plan_catalog_unavailable'),503);
        }
        $batch=in_array(get_option('wpai_gateway_batch_object_v1',false),array(true,1,'1'),true);
        $names=array('free'=>'Free','pro'=>'Pro','biznes'=>'Biznes','biznespro'=>'BiznesPro');
        $plans=array();
        foreach ($policy['plans'] as $slug=>$limit) {
            $period=$policy['periods'][$slug];
            $plans[]=array('slug'=>$slug,'name'=>$names[$slug],'active'=>1,'version_key'=>$policy['version'],
                'actions_limit'=>$limit,'quota_period'=>$period,'timezone'=>'UTC',
                'monthly_actions'=>$period==='calendar_month_utc'?$limit:null,
                'daily_actions'=>$period==='calendar_day_utc'?$limit:null,
                'batch_objects_per_action'=>$batch?1:null,
                'batch_policy'=>$batch?'successful_object_v1':'disabled_until_receipt_accounting_contract',
                'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0);
        }
        return self::response(array('items'=>$plans,'quota_scheme'=>'tariffs_v2','payments_enabled'=>false,
            'automatic_money_debits'=>false,'manual_refills'=>'deferred','prices_approved'=>false));
    }
}
