<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Abilities {
 /**
  * Opt-in, read-only discovery. An Ability is never made executable merely
  * because it is registered. The upstream permission callback is not a
  * substitute for MCP tenant, site and human approval boundaries.
  */
 public static function discover($limit=100){
  if(!function_exists('wp_get_abilities') || !current_user_can('manage_options'))return array();
  $abilities=wp_get_abilities();
  if(!is_array($abilities))return array();
  $rows=array();
  foreach($abilities as $key=>$ability){
   if(!is_object($ability) || !method_exists($ability,'get_name') || !method_exists($ability,'get_meta'))continue;
   $meta=$ability->get_meta();
   if(!is_array($meta) || (empty($meta['public']) && empty($meta['mcp']['public'])))continue;
   $name=$ability->get_name();
   if(!is_string($name) || !preg_match('#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#D',$name))continue;
   $rows[$name]=array(
    'name'=>$name,
    'label'=>method_exists($ability,'get_label')?substr((string)$ability->get_label(),0,160):$name,
    'description'=>method_exists($ability,'get_description')?substr((string)$ability->get_description(),0,500):'',
    'execution'=>'disabled_until_explicit_policy',
   );
  }
  ksort($rows,SORT_STRING);
  return array_slice(array_values($rows),0,max(1,min(100,(int)$limit)));
 }
 public static function info($name){
  if(!is_string($name) || !preg_match('#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#D',$name))return new WP_Error('oai_ability_invalid','Invalid ability name');
  foreach(self::discover(100) as $item)if($item['name']===$name)return $item;
  return new WP_Error('oai_ability_unavailable','Ability is not exposed or is not authorized');
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $defs=array(
   array('name'=>'okean-ai/get-site-info','label'=>'Okean AI: Site info','description'=>'Read basic site information.','callback'=>function(){return OAI_REST::site();},'permission_callback'=>function(){return current_user_can('manage_options');}),
   array('name'=>'okean-ai/list-operations','label'=>'Okean AI: List operations','description'=>'List sandbox operations.','callback'=>function(){return OAI_REST::sandbox_list();},'permission_callback'=>function(){return current_user_can('manage_options');})
  );
  foreach($defs as $d){try{wp_register_ability($d['name'],$d);}catch(Throwable $e){}}
 }
}

