<?php
/**
 * Okean AI Admin plan entitlements.
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Entitlements {
 public static function plan(){ return OAI_Quota::current_plan(); }

 public static function features(){
  if(OAI_Quota::is_engineering_access()){
   return array('read','plan','sandbox','preview','verify','proof','rollback','elementor','gutenberg','woocommerce','wp_cli','mcp','mcp_apply','mcp_rollback','agent');
  }

  $map=array(
   'free'=>array('read','plan','sandbox','preview','verify'),
   'daily'=>array('read','plan','sandbox','preview','verify'),
   'pro'=>array('read','plan','sandbox','preview','verify','elementor','gutenberg','woocommerce','wp_cli'),
   'business'=>array('read','plan','sandbox','preview','verify','elementor','gutenberg','woocommerce','wp_cli','mcp','agent'),
  );
  return $map[self::plan()]??$map['free'];
 }

 public static function can($feature){ return in_array(sanitize_key($feature),self::features(),true); }
}
