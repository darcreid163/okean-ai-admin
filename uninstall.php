<?php
/**
 * Remove WP AI Admin data only after an explicit per-site administrator opt-in.
 *
 * This file is intentionally separate from deactivation and upgrade paths.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpai_admin_uninstall_delete_option_prefix')) {
    function wpai_admin_uninstall_delete_option_prefix($prefix) {
        global $wpdb;
        // LEFT() avoids LIKE wildcard/escape-mode differences for underscores in prefixes.
        $pattern = $wpdb->esc_like($prefix) . '%';
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $pattern
        ));
        if ($wpdb->last_error !== '') {
            return;
        }
        foreach ((array) $keys as $key) {
            if (is_string($key) && strpos($key, $prefix) === 0) {
                delete_option($key);
            }
        }
    }

    function wpai_admin_uninstall_delete_transient_prefix($prefix) {
        global $wpdb;
        $base = '_transient_' . $prefix;
        $timeout = '_transient_timeout_' . $prefix;
        $base_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($base) . '%'
        ));
        if ($wpdb->last_error !== '') {
            return;
        }
        $timeout_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($timeout) . '%'
        ));
        if ($wpdb->last_error !== '') {
            return;
        }
        foreach (array_unique(array_merge((array) $base_keys, (array) $timeout_keys)) as $option_name) {
            if (!is_string($option_name)) {
                continue;
            }
            if (strpos($option_name, $timeout) === 0) {
                $transient = substr($option_name, strlen('_transient_timeout_'));
            } elseif (strpos($option_name, $base) === 0) {
                $transient = substr($option_name, strlen('_transient_'));
            } else {
                continue;
            }
            if ($transient !== '') {
                delete_transient($transient);
            }
        }
    }

    function wpai_admin_uninstall_site_data() {
        if (get_option('oai_delete_data_on_uninstall', '0') !== '1') {
            return;
        }

        global $wpdb;

        // Exact plugin-owned tables only. Never infer table names from a broad prefix.
        $table_suffixes = array(
            'oai_operations',
            'oai_audit_log',
            'oai_quota_usage',
            'oai_quota_events',
            'oai_oauth_clients',
            'oai_oauth_codes',
            'oai_oauth_tokens',
            'oai_oauth_registry',
            'oai_rate_limits', // Present on Native DB schema 25+.
        );
        $quote = chr(96);
        foreach ($table_suffixes as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $quoted = $quote . str_replace($quote, $quote . $quote, $table) . $quote;
            $wpdb->query("DROP TABLE IF EXISTS {$quoted}");
        }

        // Explicit allowlist of static plugin options.
        $options = array(
            'oai_runtime_build_id',
            'oai_bearer_token',
            'oai_api_token',
            'oai_bearer_token_hash',
            'oai_bearer_user_id',
            'oai_mcp_enabled',
            'oai_mcp_token_hash',
            'oai_mcp_token_user_id',
            'oai_agent_key_hash',
            'oai_agent_enabled',
            'oai_agent_scopes',
            'oai_agent_created_at',
            'oai_ai_lifecycle_control',
            'oai_batch_jobs_v1',
            'oai_db_version',
            'oai_quota_events_pruned_at',
            'oai_rate_limits_pruned_at',
            'oai_oauth_schema_version',
            'oai_oauth_pruned_at',
            'oai_gateway_paired',
            'oai_license',
            'oai_site_id',
            'oai_installation_id',
            'oai_control_plane_url',
            'oai_control_plane_registration_id',
            'oai_control_plane_public_key',
            'oai_active_workspace',
            'oai_plan',
            'oai_qa_managed_fixtures',
            'oai_qa_fixture_provision_lock',
            'oai_regression_history_v2',
            'oai_test_automation',
            'oai_test_automation_last_regression',
            'oai_test_automation_last_matrix',
            'oai_delete_data_on_uninstall',
        );
        foreach ($options as $option) {
            delete_option($option);
        }

        // Dynamic options with reviewed, plugin-specific prefixes.
        $option_prefixes = array(
            'oai_cloud_usage_',
            'oai_media_creation_',
            'oai_operation_lease_',
            'oai_workspace_creation_',
            'oai_menu_creation_',
            'oai_menu_item_creation_',
            'oai_product_creation_',
            'oai_post_creation_',
            'oai_elementor_page_creation_',
            'oai_pair_code_',
            'oai_pair_credential_',
        );
        foreach ($option_prefixes as $prefix) {
            wpai_admin_uninstall_delete_option_prefix($prefix);
        }

        // Short-lived transients only; these are caches/nonces, not user content.
        $transient_prefixes = array(
            'oai_rl_',
            'oai_cimd_',
            'oai_oauth_login_',
            'oai_agent_nonce_',
            'oai_vqa_',
            'oai_vqar_',
        );
        foreach ($transient_prefixes as $prefix) {
            wpai_admin_uninstall_delete_transient_prefix($prefix);
        }

        // Remove every scheduled instance, including events carrying arguments.
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook('oai_gateway_pair_cleanup');
        }
    }
}

// In Multisite each blog is checked independently. Only blogs with their own
// explicit opt-in are cleaned; all other sites retain their data.
if (is_multisite()) {
    $offset = 0;
    $page_size = 100;
    do {
        $site_ids = get_sites(array(
            'fields' => 'ids',
            'number' => $page_size,
            'offset' => $offset,
        ));
        foreach ((array) $site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                wpai_admin_uninstall_site_data();
            } finally {
                restore_current_blog();
            }
        }
        $offset += count((array) $site_ids);
    } while (count((array) $site_ids) === $page_size);
} else {
    wpai_admin_uninstall_site_data();
}
