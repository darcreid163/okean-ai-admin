<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_Plans {
    public static function defaults() {
        return array(
            'free'   => array('name' => 'Free',   'version' => 'FREE_V1',   'monthly_actions' => 100,   'max_sites' => 1),
            'pro'    => array('name' => 'Pro',    'version' => 'PRO_V1',    'monthly_actions' => 500,   'max_sites' => 3),
            'power'  => array('name' => 'Power',  'version' => 'POWER_V1',  'monthly_actions' => 2000,  'max_sites' => 5),
            'agency' => array('name' => 'Agency', 'version' => 'AGENCY_V1', 'monthly_actions' => 5000,  'max_sites' => 15),
            'scale'  => array('name' => 'Scale',  'version' => 'SCALE_V1',  'monthly_actions' => 10000, 'max_sites' => 50),
        );
    }

    public static function seed() {
        global $wpdb;
        $plans_table = WPAI_Cloud_DB::table('plans');
        $versions_table = WPAI_Cloud_DB::table('plan_versions');
        $now = current_time('mysql', true);

        foreach (self::defaults() as $slug => $plan) {
            $plan_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $plans_table WHERE slug=%s LIMIT 1", $slug));
            if (!$plan_id) {
                $wpdb->insert($plans_table, array(
                    'slug' => $slug,
                    'name' => $plan['name'],
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
                $plan_id = (int) $wpdb->insert_id;
            }

            $version_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $versions_table WHERE version_key=%s LIMIT 1", $plan['version']));
            if (!$version_id) {
                $wpdb->insert($versions_table, array(
                    'plan_id' => $plan_id,
                    'version_key' => $plan['version'],
                    'monthly_actions' => (int) $plan['monthly_actions'],
                    'batch_objects_per_action' => 5,
                    'failed_actions_billable' => 0,
                    'rollback_billable' => 0,
                    'verify_billable' => 0,
                    'active' => 1,
                    'effective_from' => $now,
                    'created_at' => $now,
                ));
            }
        }
    }
}
