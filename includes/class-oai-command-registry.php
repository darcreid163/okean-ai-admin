<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Command_Registry {
 public static function all(){return array(
  'core version'=>array('family'=>'core','handler'=>'core','read'=>true),
  'plugin list'=>array('family'=>'plugin','handler'=>'plugin_list','read'=>true),
  'plugin activate'=>array('family'=>'plugin','handler'=>'plugin_activate','read'=>false,'approval'=>true),
  'plugin deactivate'=>array('family'=>'plugin','handler'=>'plugin_deactivate','read'=>false,'approval'=>true),
  'plugin update'=>array('family'=>'plugin','handler'=>'plugin_update','read'=>false,'approval'=>true),
  'theme list'=>array('family'=>'theme','handler'=>'theme_list','read'=>true),
  'theme activate'=>array('family'=>'theme','handler'=>'theme_activate','read'=>false,'approval'=>true),
  'post list'=>array('family'=>'post','handler'=>'post_list','read'=>true),
  'post get'=>array('family'=>'post','handler'=>'post_get','read'=>true),
  'post create'=>array('family'=>'post','handler'=>'post_create','read'=>false,'approval'=>true),
  'post update'=>array('family'=>'post','handler'=>'post_update','read'=>false,'approval'=>true),
  'option get'=>array('family'=>'option','handler'=>'option_get','read'=>true),
  'option update'=>array('family'=>'option','handler'=>'option_update','read'=>false,'approval'=>true),
  'user list'=>array('family'=>'user','handler'=>'user_list','read'=>true),
  'user get'=>array('family'=>'user','handler'=>'user_get','read'=>true),
  'term list'=>array('family'=>'term','handler'=>'term_list','read'=>true),
  'menu list'=>array('family'=>'menu','handler'=>'menu_list','read'=>true),
  'media list'=>array('family'=>'media','handler'=>'media_list','read'=>true),
  'cron event list'=>array('family'=>'cron','handler'=>'cron_list','read'=>true),
  'cache purge'=>array('family'=>'cache','handler'=>'cache_purge','read'=>false,'approval'=>true),
  'db query'=>array('family'=>'db','handler'=>'db_query','read'=>true),
 );}
 public static function resolve($command){$n=OAI_Command_Normalizer::normalize($command); if(is_wp_error($n)) return $n; $key=$n['family'].' '.$n['verb']; if(!empty($n['subverb']))$key.=' '.$n['subverb']; $all=self::all(); if(isset($all[$key])) return array_merge($n,$all[$key],array('canonical'=>$key)); return new WP_Error('oai_cli_not_allowed','Command is not allowed by the single CommandRegistry',array('status'=>403,'command'=>$n));}
 public static function handler_audit(){
  $all=self::all(); $implemented=array();
  $ref=class_exists('OAI_WP_CLI_Execution')?new ReflectionClass('OAI_WP_CLI_Execution'):null;
  $source=$ref?$ref->getFileName():null; $text=($source&&is_readable($source))?file_get_contents($source):'';
  foreach($all as $key=>$entry){$h=(string)($entry['handler']??''); $implemented[$h]=($h!==''&&$text!==''&&strpos($text,"case '".$h."'")!==false);}
  $missing=array(); foreach($implemented as $h=>$ok)if(!$ok)$missing[]=$h;
  return array('registered'=>count($all),'handlers'=>count(array_unique(array_keys($implemented))),'missing_handlers'=>$missing,'ok'=>empty($missing));
 }
 public static function catalog(){return self::all();}
}
