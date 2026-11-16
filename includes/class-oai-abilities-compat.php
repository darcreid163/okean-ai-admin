<?php
/**
 * WordPress Abilities discovery compatibility.
 *
 * Copyright (C) 2026 Андриянов Н.Г.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Original WP AI Admin implementation informed by the public WordPress
 * Abilities API and WPVibe's public behavior; no WPVibe source is copied.
 * The gateway remains responsible for authorization, approvals and billing.
 */
if (!defined('ABSPATH')) exit;

class OAI_Abilities_Compat {
 const MAX_PER_PAGE = 100;
 const MAX_SCHEMA_DEPTH = 24;
 const MAX_SCHEMA_ITEMS = 500;

 private static function mcp_public($meta) {
  if (!is_array($meta)) return false;
  if (!array_key_exists('mcp', $meta)) return isset($meta['public']) && true === $meta['public'];
  if (!is_array($meta['mcp'])) return false;
  return isset($meta['mcp']['public']) && true === $meta['mcp']['public'];
 }

 private static function rest_public($meta) {
  return is_array($meta) && !empty($meta['show_in_rest']);
 }

 private static function is_exposed($meta) {
  return self::rest_public($meta) || self::mcp_public($meta);
 }

 /** Strip arbitrary ability metadata; only documented non-sensitive fields are projected. */
 private static function public_meta($meta) {
  if (!is_array($meta)) return array();
  $out = array();
  foreach (array('show_in_rest') as $key) if (array_key_exists($key,$meta)) $out[$key]=(bool)$meta[$key];
  if (isset($meta['mcp']) && is_array($meta['mcp']) && isset($meta['mcp']['public'])) {
   $out['mcp']=array('public'=>true === $meta['mcp']['public']);
  } elseif (isset($meta['public'])) {
   $out['public']=true === $meta['public'];
  }
  if (isset($meta['annotations']) && is_array($meta['annotations'])) {
   $annotations=array();
   foreach (array('readonly','destructive','idempotent','sensitive') as $key) {
    if (array_key_exists($key,$meta['annotations']) && is_bool($meta['annotations'][$key])) $annotations[$key]=$meta['annotations'][$key];
   }
   if ($annotations) $out['annotations']=$annotations;
  }
  return $out;
 }

 private static function clean_schema_value($value,$depth=0,&$budget=null) {
  if ($budget===null) $budget=self::MAX_SCHEMA_ITEMS;
  if ($depth>self::MAX_SCHEMA_DEPTH || --$budget<0) return null;
  if (is_null($value)||is_bool($value)||is_int($value)||is_float($value)) return $value;
  if (is_string($value)) return substr($value,0,4000);
  if (!is_array($value)) return null;
  $out=array();
  foreach ($value as $key=>$item) {
   if ($budget<=0) break;
   if (is_string($key)) $safe_key=substr($key,0,120);
   elseif (is_int($key)) $safe_key=$key;
   else continue;
   $clean=self::clean_schema_value($item,$depth+1,$budget);
   if ($clean!==null || $item===null) $out[$safe_key]=$clean;
  }
  return $out;
 }

 private static function schema_for_client($schema) {
  if (!is_array($schema)) return array();
  if (function_exists('wp_prepare_json_schema_for_client')) $schema=wp_prepare_json_schema_for_client($schema);
  $budget=self::MAX_SCHEMA_ITEMS;
  $clean=self::clean_schema_value($schema,0,$budget);
  return is_array($clean)?$clean:array();
 }

 private static function item($ability) {
  $meta=$ability->get_meta();
  return array(
   'name'=>(string)$ability->get_name(),
   'label'=>substr((string)$ability->get_label(),0,160),
   'description'=>substr((string)$ability->get_description(),0,1000),
   'category'=>substr((string)$ability->get_category(),0,120),
   'input_schema'=>self::schema_for_client($ability->get_input_schema()),
   'output_schema'=>self::schema_for_client($ability->get_output_schema()),
   'meta'=>self::public_meta($meta),
   'execution_policy'=>'plan_required',
  );
 }

 private static function unavailable() {
  return new WP_Error('oai_abilities_unavailable','WordPress Abilities API is not available on this WordPress version',array('status'=>501));
 }

 public static function list_public($category='', $page=1, $per_page=50) {
  if (!function_exists('wp_get_abilities')) return self::unavailable();
  if (!current_user_can('read')) return new WP_Error('oai_abilities_forbidden','The current user cannot discover WordPress Abilities',array('status'=>403));
  $category=sanitize_key((string)$category);
  $page=max(1,(int)$page);
  $per_page=max(1,min(self::MAX_PER_PAGE,(int)$per_page));
  $all=array();
  foreach ((array)wp_get_abilities() as $ability) {
   if (!is_object($ability) || !method_exists($ability,'get_meta') || !method_exists($ability,'get_name')) continue;
   $meta=$ability->get_meta();
   if (!self::is_exposed($meta)) continue;
   $name=(string)$ability->get_name();
   if ($name==='' || strlen($name)>191) continue;
   if ($category!=='' && (!method_exists($ability,'get_category') || $category!==(string)$ability->get_category())) continue;
   $all[]=$ability;
  }
  usort($all,static function($a,$b){return strcmp((string)$a->get_name(),(string)$b->get_name());});
  $total=count($all);
  $items=array();
  foreach (array_slice($all,($page-1)*$per_page,$per_page) as $ability) $items[]=self::item($ability);
  return array(
   'items'=>$items,'total'=>$total,'page'=>$page,'per_page'=>$per_page,
   'total_pages'=>(int)ceil($total/$per_page),
   'execution'=>'metadata_only; invoke through WP AI Admin planner',
  );
 }

 public static function get_public($name) {
  $name=is_string($name)?trim($name):'';
  if ($name===''||strlen($name)>191||!function_exists('wp_has_ability')||!function_exists('wp_get_ability')) {
   if (!function_exists('wp_has_ability') || !function_exists('wp_get_ability')) return self::unavailable();
   return new WP_Error('oai_ability_not_found','Ability not found or not exposed',array('status'=>404));
  }
  if (!wp_has_ability($name)) return new WP_Error('oai_ability_not_found','Ability not found or not exposed',array('status'=>404));
  $ability=wp_get_ability($name);
  if (!$ability||!method_exists($ability,'get_meta')||!self::is_exposed($ability->get_meta())) {
   return new WP_Error('oai_ability_not_found','Ability not found or not exposed',array('status'=>404));
  }
  return self::item($ability);
 }

 /** Direct generic execution is forbidden; mapped operations must be explicitly implemented first. */
 public static function run($name,$input=array()) {
  $definition=self::get_public($name);
  if (is_wp_error($definition)) return $definition;
  if (!is_array($input)) return new WP_Error('oai_ability_input','Ability input must be an object',array('status'=>400));
  return new WP_Error('oai_ability_requires_plan','Ability execution is not exposed directly. Create a mapped WP AI Admin sandbox operation and require explicit approval.',array('status'=>409,'ability'=>$name,'execution_policy'=>'plan_required'));
 }
}
