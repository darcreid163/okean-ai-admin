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
        $batch=(($policy['batch']??'')===\WPAICloudGateway\BatchAccounting::POLICY);
        $plans=array();
        foreach ($policy['plans'] as $slug=>$limit) {
            $period=$policy['periods'][$slug];
            $plans[]=array('slug'=>$slug,'name'=>\WPAICloudGateway\QuotaPolicy::planLabel($policy,$slug,\WPAICloudGateway\QuotaPolicy::CURRENT),'active'=>1,'version_key'=>$policy['version'],
                'actions_limit'=>$limit,'quota_period'=>$period,'timezone'=>'UTC',
                'monthly_actions'=>$period==='calendar_month_utc'?$limit:null,
                'daily_actions'=>$period==='calendar_day_utc'?$limit:null,
                'batch_objects_per_action'=>$batch?1:null,
                'batch_policy'=>$batch?'successful_object_v1':'disabled_until_receipt_accounting_contract',
                'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0);
        }
        $target=null;
        $scheme=$policy['schemes']['tariffs_v3']??null;
        if (is_array($scheme)) {
            $items=array();
            foreach ($scheme['plans'] as $slug=>$limit) {
                $period=(string)($scheme['periods'][$slug]??'');
                $items[]=array('slug'=>$slug,'name'=>(string)($scheme['names'][$slug]??$slug),
                    'actions_limit'=>$limit,
                    'quota_status'=>(string)($scheme['quota_status'][$slug]??''),
                    'quota_period'=>$period,
                    'timezone'=>'UTC',
                    'monthly_actions'=>$period==='calendar_month_utc'?$limit:null,
                    'daily_actions'=>$period==='calendar_day_utc'?$limit:null,
                    'price_rub_month'=>$scheme['prices_rub_month'][$slug]??null,
                    'price_rub_year'=>$scheme['prices_rub_year'][$slug]??null,
                    'all_tools'=>true,
                    'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0);
            }
            $paidStatuses=array();
            foreach ($scheme['plans'] as $slug=>$unused) {
                if ($slug==='free') continue;
                $paidStatuses[]=(string)($scheme['quota_status'][$slug]??'');
            }
            $target=array('items'=>$items,'quota_scheme'=>'tariffs_v3','all_tools'=>true,
                'paid_quotas_approved'=>$paidStatuses!==[] && !in_array('requires_owner_approval',$paidStatuses,true),
                'prices_approved'=>(bool)($scheme['prices_approved']??false),
                'automatic_money_debits'=>false,
                'offers'=>(array)($policy['commerce']['products']??[]),
                'currency'=>(string)($policy['commerce']['currency']??'RUB'),
                'auto_renew'=>(bool)($policy['commerce']['auto_renew']??false));
        }
        return self::response(array('items'=>$plans,'quota_scheme'=>(string)($policy['default_scheme']??'tariffs_v3'),'payments_enabled'=>false,
            'automatic_money_debits'=>false,'manual_refills'=>'deferred',
            'prices_approved'=>(bool)($policy['prices_approved']??false),'target'=>$target));
    }
}
