<?php
if (!defined('ABSPATH')) exit;

class WPAI_Cloud_DB {
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wpai_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $tables = array();

        $tables[] = "CREATE TABLE " . self::table('accounts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wp_user_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY status (status)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('plans') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            name varchar(128) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('plan_versions') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            version_key varchar(64) NOT NULL,
            monthly_actions int unsigned NOT NULL DEFAULT 0,
            batch_objects_per_action int unsigned NOT NULL DEFAULT 5,
            failed_actions_billable tinyint(1) NOT NULL DEFAULT 0,
            rollback_billable tinyint(1) NOT NULL DEFAULT 0,
            verify_billable tinyint(1) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            effective_from datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY version_key (version_key),
            KEY plan_id (plan_id),
            KEY active (active)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('licenses') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            plan_version_id bigint(20) unsigned NOT NULL,
            license_hash char(64) NOT NULL,
            license_prefix varchar(24) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            max_sites int unsigned NOT NULL DEFAULT 1,
            issued_at datetime NOT NULL,
            expires_at datetime NULL,
            grace_until datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY license_hash (license_hash),
            KEY account_id (account_id),
            KEY status (status),
            KEY plan_version_id (plan_version_id)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('sites') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            license_id bigint(20) unsigned NOT NULL,
            site_uuid char(36) NOT NULL,
            canonical_domain varchar(255) NOT NULL,
            site_url text NOT NULL,
            environment varchar(24) NOT NULL DEFAULT 'production',
            plugin_version varchar(32) NULL,
            wp_version varchar(32) NULL,
            php_version varchar(32) NULL,
            status varchar(32) NOT NULL DEFAULT 'active',
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY site_uuid (site_uuid),
            KEY account_id (account_id),
            KEY license_id (license_id),
            KEY canonical_domain (canonical_domain(191)),
            KEY status (status)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('site_tokens') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            refresh_hash char(64) NULL,
            status varchar(32) NOT NULL DEFAULT 'active',
            issued_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            refresh_expires_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY site_id (site_id),
            KEY refresh_hash (refresh_hash),
            KEY status (status)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('intents') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            intent_uuid char(36) NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            site_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            closed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY intent_uuid (intent_uuid),
            KEY account_id (account_id),
            KEY site_id (site_id),
            KEY status (status)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('usage_events') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            license_id bigint(20) unsigned NOT NULL,
            site_id bigint(20) unsigned NOT NULL,
            intent_id bigint(20) unsigned NULL,
            operation_id varchar(128) NULL,
            tool_name varchar(191) NOT NULL,
            action_class varchar(64) NOT NULL,
            objects_attempted int unsigned NOT NULL DEFAULT 1,
            objects_successful int unsigned NOT NULL DEFAULT 0,
            billable_actions int unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL,
            plan_version_key varchar(64) NOT NULL,
            idempotency_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_uuid (event_uuid),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY account_id (account_id),
            KEY site_id (site_id),
            KEY created_at (created_at),
            KEY tool_name (tool_name)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('usage_balances') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            license_id bigint(20) unsigned NOT NULL,
            period_start datetime NOT NULL,
            period_end datetime NOT NULL,
            base_limit int unsigned NOT NULL DEFAULT 0,
            addon_actions int unsigned NOT NULL DEFAULT 0,
            used_actions int unsigned NOT NULL DEFAULT 0,
            reserved_actions int unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY license_period (license_id, period_start),
            KEY account_id (account_id),
            KEY period_end (period_end)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('usage_reservations') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_uuid char(36) NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            license_id bigint(20) unsigned NOT NULL,
            site_id bigint(20) unsigned NOT NULL,
            intent_id bigint(20) unsigned NULL,
            actions_reserved int unsigned NOT NULL,
            actions_committed int unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'active',
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY reservation_uuid (reservation_uuid),
            KEY license_id (license_id),
            KEY site_id (site_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset;";

        $tables[] = "CREATE TABLE " . self::table('audit_log') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id varchar(128) NULL,
            account_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NULL,
            site_id bigint(20) unsigned NULL,
            event varchar(128) NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY site_id (site_id),
            KEY event (event),
            KEY created_at (created_at)
        ) $charset;";

        foreach ($tables as $sql) dbDelta($sql);

        update_option('wpai_cloud_db_version', WPAI_CLOUD_DB_VERSION, false);
        WPAI_Cloud_Plans::seed();
    }

    public static function maybe_upgrade() {
        if ((string) get_option('wpai_cloud_db_version', '') !== (string) WPAI_CLOUD_DB_VERSION) {
            self::install();
        }
    }
}
