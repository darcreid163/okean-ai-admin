<?php
/**
 * HPOS datastore assertion for disposable WooCommerce acceptance only.
 * The full order lifecycle test runs immediately after this guard.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run HPOS acceptance test outside disposable WordPress');
}
$orderUtil = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
if (!class_exists($orderUtil)) {
    throw new RuntimeException('WooCommerce OrderUtil is unavailable');
}
if (!$orderUtil::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('HPOS is not the authoritative order datastore');
}
if (!function_exists('wc_get_order') || !function_exists('wc_get_orders')) {
    throw new RuntimeException('WooCommerce public order CRUD/query APIs are unavailable');
}
echo "PASS: HPOS is authoritative; WooCommerce order CRUD APIs are available\\n";
