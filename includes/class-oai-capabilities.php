<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Capabilities {
 public static function map() {
  return array(
   'read'=>array(
    'site','foundation_capabilities','operation','proof','plugins','themes','content','elementor','elementor_schema','elementor_globals','builder_adapters','woocommerce','media','menus',
    'taxonomies','options','users','comments','widgets','files','diagnostics'
   ),
   'sandbox'=>array(
    'option_update','post_title','post_excerpt','post_content',
    'elementor_widget_html','elementor_widget_settings','elementor_page_create','woo_product_price','woo_product_stock','file_write'
   ),
   'approval'=>array('approve','reject','apply','rollback'),
   'builders'=>array('elementor'=>'builder_owned_save_pipeline','future'=>array('gutenberg','woocommerce','bricks','breakdance','beaver','divi','seedprod')),
   'mcp'=>array('transport'=>'streamable_http_jsonrpc','endpoint'=>'/wp-json/okean-ai/mcp','oauth_ready'=>true,'bearer_token'=>true,'subscription_gate'=>true,'default_enabled'=>false,'writes'=>'sandbox_only'),
   'security'=>array(
    'agent_channel'=>true,
    'agent_auth'=>'hmac_sha256',
    'agent_replay_protection'=>true,
    'direct_live_writes'=>false,
    'sandbox_artifacts'=>array('elementor_page_create'=>'private_draft_preview_only'),
    'bearer_or_wp_admin'=>true,
    'file_scopes'=>array('child_theme','theme')
   )
  );
 }
 public static function available() {
  $m=self::map();
  $m['runtime']=array(
   'abilities_api'=>function_exists('wp_register_ability'),
   'woocommerce'=>class_exists('WooCommerce'),
   'elementor'=>defined('ELEMENTOR_VERSION')
  );
  return $m;
 }
}
