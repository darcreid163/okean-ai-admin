<?php
/**
 * Plugin Name: AI Admin
 * Description: Universal AI Admin for WordPress with sandbox, diff, preview, approval, verification, rollback, Elementor, WooCommerce, Gutenberg, files and MCP.
 * Version: 2.0.13
 * Author: Андриянов Н.Г.
 * Copyright: Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
define('OAI_VERSION','2.0.13');
define('OAI_BUILD_ID','2.0.13-wpcli-gutenberg-hardening-20260922');
define('OAI_DB_VERSION',21);
define('OAI_MCP_VERSION','2025-06-18');
define('OAI_COPYRIGHT','Авторские права Андриянов Н.Г.');
define('OAI_DIR',plugin_dir_path(__FILE__));
require_once OAI_DIR.'includes/class-oai-db.php';
require_once OAI_DIR.'includes/class-oai-errors.php';
require_once OAI_DIR.'includes/class-oai-operation-schema.php';
require_once OAI_DIR.'includes/class-oai-intent.php';
require_once OAI_DIR.'includes/class-oai-read-engine.php';
require_once OAI_DIR.'includes/class-oai-workspace.php';
require_once OAI_DIR.'includes/class-oai-lifecycle.php';
require_once OAI_DIR.'includes/class-oai-surfaces.php';
require_once OAI_DIR.'includes/class-oai-security.php';
require_once OAI_DIR.'includes/class-oai-audit.php';
require_once OAI_DIR.'includes/class-oai-files.php';
require_once OAI_DIR.'includes/class-oai-abilities.php';
require_once OAI_DIR.'includes/class-oai-read.php';
require_once OAI_DIR.'includes/class-oai-diagnostics.php';
require_once OAI_DIR.'includes/class-oai-capabilities.php';
require_once OAI_DIR.'includes/class-oai-adapter-catalog.php';
require_once OAI_DIR.'includes/class-oai-planner.php';
require_once OAI_DIR.'includes/class-oai-elementor.php';
require_once OAI_DIR.'includes/class-oai-builder.php';
require_once OAI_DIR.'includes/class-oai-elementor-adapter.php';
require_once OAI_DIR.'includes/class-oai-rate-limit.php';
require_once OAI_DIR.'includes/class-oai-license.php';
require_once OAI_DIR.'includes/class-oai-mcp-oauth.php';
require_once OAI_DIR.'includes/class-oai-mcp.php';
require_once OAI_DIR.'includes/class-oai-rest.php';
require_once OAI_DIR.'includes/class-oai-agent.php';

require_once OAI_DIR.'includes/class-oai-operations.php';
require_once OAI_DIR.'includes/class-oai-receipts.php';
require_once OAI_DIR.'includes/class-oai-proof.php';
require_once OAI_DIR.'includes/class-oai-authoritative.php';
require_once OAI_DIR.'includes/class-oai-verify.php';
require_once OAI_DIR.'includes/class-oai-content.php';
require_once OAI_DIR.'includes/class-oai-woocommerce.php';
require_once OAI_DIR.'includes/class-oai-gutenberg.php';
require_once OAI_DIR.'includes/class-oai-wp.php';
require_once OAI_DIR.'includes/class-oai-command-registry.php';
require_once OAI_DIR.'includes/class-oai-command-normalizer.php';
require_once OAI_DIR.'includes/class-oai-argument-parser.php';
require_once OAI_DIR.'includes/class-oai-permission-resolver.php';
require_once OAI_DIR.'includes/class-oai-wp-post-resolver.php';
require_once OAI_DIR.'includes/class-oai-gutenberg-resolver.php';
require_once OAI_DIR.'includes/class-oai-wp-cli-execution.php';
require_once OAI_DIR.'includes/class-oai-execution-dispatcher.php';
require_once OAI_DIR.'includes/class-oai-admin.php';
register_activation_hook(__FILE__, array('OAI_DB','install'));
add_action('plugins_loaded', array('OAI_DB','maybe_upgrade'));
add_action('plugins_loaded', function(){ OAI_Builder_Manager::register(new OAI_Elementor_Adapter()); });
add_action('wp_abilities_api_init', array('OAI_Abilities','register'));
add_action('rest_api_init', array('OAI_REST','register_routes'));
add_action('rest_api_init', array('OAI_MCP_OAuth','register_routes'), 19);
add_action('rest_api_init', array('OAI_MCP','register_routes'), 20);
add_action('init', array('OAI_MCP_OAuth','well_known_hooks'));
add_action('admin_menu', array('OAI_Admin','menu'));
add_action('admin_init', array('OAI_Admin','ensure_token'));

/** Initialize WordPress Filesystem for safe theme/file operations. */
function oai_fs(){
 global $wp_filesystem;
 if(!empty($wp_filesystem)) return $wp_filesystem;
 if(!function_exists('WP_Filesystem')) require_once ABSPATH.'wp-admin/includes/file.php';
 if(!WP_Filesystem()) return false;
 return $wp_filesystem;
}

/** Validate PHP syntax without executing the submitted source. */
function oai_check_php_syntax($source,$label=''){
 try{ @token_get_all($source,TOKEN_PARSE); }
 catch(Throwable $e){ return new WP_Error('oai_php_syntax',sprintf('Syntax error in %s: %s',$label?:'file',$e->getMessage()),array('status'=>422)); }
 return true;
}

