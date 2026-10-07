<?php
/**
 * Okean AI Admin plan entitlements.
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Entitlements {
 private const ALL_FEATURES=array(
  'read','plan','sandbox','preview','verify','proof','rollback',
  'elementor','gutenberg','woocommerce','wp_cli',
  'mcp','mcp_apply','mcp_rollback','agent'
 );

 public static function plan(){ return OAI_Quota::current_plan(); }

 public static function features(){
  // All public plans have the complete feature set. The only plan
  // differentiation is quota; feature gating must never hide tools.
  return self::ALL_FEATURES;
 }

 public static function can($feature){
  return in_array(sanitize_key($feature),self::ALL_FEATURES,true);
 }
}

