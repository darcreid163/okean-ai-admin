<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_Plans {
    /**
     * Legacy monthly catalogue. Retained verbatim so accounts that were created
     * before tariffs_v2 keep their original limits. Never remove or rewrite
     * these rows during an upgrade.
     */
    public static function defaults() {
        return array(
            'free'   => array('name' => 'Free',   'version' => 'FREE_V1',   'monthly_actions' => 100,   'max_sites' => 1),
            'pro'    => array('name' => 'Pro',    'version' => 'PRO_V1',    'monthly_actions' => 500,   'max_sites' => 3),
            'power'  => array('name' => 'Power',  'version' => 'POWER_V1',  'monthly_actions' => 2000,  'max_sites' => 5),
            'agency' => array('name' => 'Agency', 'version' => 'AGENCY_V1', 'monthly_actions' => 5000,  'max_sites' => 15),
            'scale'  => array('name' => 'Scale',  'version' => 'SCALE_V1',  'monthly_actions' => 10000, 'max_sites' => 50),
        );
    }

    /**
     * Target catalogue from the single shipped source. Paid quotas stay null
     * until the owner approves a number; they are never invented here.
     */
    public static function catalogue() {
        $file = dirname(__DIR__) . '/config/plans.json';
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return null;
        $policy = json_decode($raw, true);
        if (!is_array($policy)) return null;
        $scheme = $policy['schemes']['tariffs_v3'] ?? null;
        return is_array($scheme) ? $scheme : null;
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

        $catalogue = self::catalogue();
        if (!$catalogue) return;
        $suffix = (string) ($catalogue['version_key_suffix'] ?? 'V3');
        $status = (array) ($catalogue['quota_status'] ?? array());
        foreach ((array) ($catalogue['plans'] ?? array()) as $slug => $limit) {
            $plan_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $plans_table WHERE slug=%s LIMIT 1", $slug));
            if (!$plan_id) {
                $wpdb->insert($plans_table, array(
                    'slug' => $slug,
                    'name' => (string) ($catalogue['names'][$slug] ?? ucfirst($slug)),
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
                $plan_id = (int) $wpdb->insert_id;
            }
            $version_key = strtoupper($slug) . '_' . $suffix;
            $version_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $versions_table WHERE version_key=%s LIMIT 1", $version_key));
            if (!$version_id) {
                $wpdb->insert($versions_table, array(
                    'plan_id' => $plan_id,
                    'version_key' => $version_key,
                    'scheme' => 'tariffs_v3',
                    'quota_status' => (string) ($status[$slug] ?? 'requires_owner_approval'),
                    'monthly_actions' => (int) ($limit ?? 0),
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
