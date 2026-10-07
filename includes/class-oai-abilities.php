<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Abilities {
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $defs=array(
   array('name'=>'okean-ai/get-site-info','label'=>'Okean AI: Site info','description'=>'Read basic site information.','callback'=>function(){return OAI_REST::site();},'permission_callback'=>function(){return current_user_can('manage_options');}),
   array('name'=>'okean-ai/list-operations','label'=>'Okean AI: List operations','description'=>'List sandbox operations.','callback'=>function(){return OAI_REST::sandbox_list();},'permission_callback'=>function(){return current_user_can('manage_options');})
  );
  foreach($defs as $d){try{wp_register_ability($d['name'],$d);}catch(Throwable $e){}}
 }
}

