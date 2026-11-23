<?php
/**
 * Plugin Name: WP AI Admin Cloud Core
 * Description: Central cloud control plane for WP AI Admin: plans, licenses, sites, usage and API foundation.
 * Version: 0.3.13-dev
 * Requires at least: 6.4
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * Author: Андриянов Н.Г.
 */

if (!defined('ABSPATH')) exit;

// Cloud Core consumes WooCommerce order objects for Robokassa settlement and
// refund lifecycle processing. Declare HPOS compatibility before WooCommerce
// initializes its feature compatibility checks; all order access uses WC APIs.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

define('WPAI_CLOUD_VERSION', '0.3.13-dev');
define('WPAI_CLOUD_DB_VERSION', '2');
define('WPAI_CLOUD_DIR', plugin_dir_path(__FILE__));
define('WPAI_CLOUD_REST_NS', 'wpai-cloud/v1');

require_once WPAI_CLOUD_DIR . 'includes/class-wpai-cloud-db.php';
require_once WPAI_CLOUD_DIR . 'includes/class-wpai-cloud-plans.php';
require_once WPAI_CLOUD_DIR . 'includes/class-wpai-cloud-rest.php';
require_once WPAI_CLOUD_DIR . 'includes/class-wpai-cloud-admin.php';

register_activation_hook(__FILE__, array('WPAI_Cloud_DB', 'install'));
add_action('plugins_loaded', array('WPAI_Cloud_DB', 'maybe_upgrade'));
add_action('rest_api_init', array('WPAI_Cloud_REST', 'register_routes'));
add_action('admin_menu', array('WPAI_Cloud_Admin', 'register_menu'));

if (PHP_VERSION_ID < 80200) {
    add_action('admin_notices', static function () { echo '<div class="notice notice-error"><p>Gateway требует PHP 8.2 или новее. Cloud Core продолжает работать.</p></div>'; });
    return;
}

// Gateway storage is separate from the preserved Cloud Core v1 schema.
foreach (['Security','Transport','Store','Licenses','QuotaPolicy','BatchAccounting','AbilitiesPolicy','Core','Skills','Unsplash','MediaPolicy','Gateway','Settings','OAuth','Pairing','Sse','Surface','Admin','WooCommercePayments','Reconciliation','ReconciliationRepair','ReconciliationAdmin','WordPress'] as $component) require_once WPAI_CLOUD_DIR . 'src/' . $component . '.php';
\WPAICloudGateway\WordPress::boot(__FILE__);
