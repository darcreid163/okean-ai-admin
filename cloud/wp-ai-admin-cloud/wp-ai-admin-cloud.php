<?php
/**
 * Plugin Name: WP AI Admin Cloud
 * Description: Central Control Plane foundation for WP AI Admin accounts, workspaces, site pairing and revocation.
 * Version: 0.1.0
 * Author: Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

define('WPAIC_VERSION','0.1.0');
define('WPAIC_DB_VERSION',1);
define('WPAIC_DIR',plugin_dir_path(__FILE__));

require_once WPAIC_DIR.'includes/class-wpaic-db.php';
require_once WPAIC_DIR.'includes/class-wpaic-rest.php';

register_activation_hook(__FILE__,array('WPAIC_DB','install'));
add_action('plugins_loaded',array('WPAIC_DB','maybe_upgrade'));
add_action('rest_api_init',array('WPAIC_REST','register_routes'));
