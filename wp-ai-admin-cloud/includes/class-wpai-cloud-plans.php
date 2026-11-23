<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_Plans {
    /**
     * Approved final daily catalogue from the single shipped source. Monthly
     * legacy plans were retired by the owner on 16.11.2026; historical rows may
     * remain in the database for audit but are never seeded or offered again.
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

        $catalogue = self::catalogue();
        if (!$catalogue) return;
        $suffix = (string) ($catalogue['version_key_suffix'] ?? 'V5');
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
