<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Command_Registry {
 public static function all(){return array(
  'core version'=>array('family'=>'core','handler'=>'core','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'plugin list'=>array('family'=>'plugin','handler'=>'plugin_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'plugin activate'=>array('family'=>'plugin','handler'=>'plugin_activate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'plugin deactivate'=>array('family'=>'plugin','handler'=>'plugin_deactivate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'plugin update'=>array('family'=>'plugin','handler'=>'plugin_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'theme list'=>array('family'=>'theme','handler'=>'theme_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'theme activate'=>array('family'=>'theme','handler'=>'theme_activate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'post list'=>array('family'=>'post','handler'=>'post_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('post_type','post_status','per_page','number','format')),
  'post get'=>array('family'=>'post','handler'=>'post_get','read'=>true,'execution_mode'=>'read_only'),
  'post create'=>array('family'=>'post','handler'=>'post_create','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'post update'=>array('family'=>'post','handler'=>'post_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'option get'=>array('family'=>'option','handler'=>'option_get','read'=>true,'execution_mode'=>'read_only'),
  'option update'=>array('family'=>'option','handler'=>'option_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'user list'=>array('family'=>'user','handler'=>'user_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('number','format')),
  'user get'=>array('family'=>'user','handler'=>'user_get','read'=>true,'execution_mode'=>'read_only'),
  'term list'=>array('family'=>'term','handler'=>'term_list','read'=>true,'execution_mode'=>'read_only'),
  'menu list'=>array('family'=>'menu','handler'=>'menu_list','read'=>true,'execution_mode'=>'read_only'),
  'media list'=>array('family'=>'media','handler'=>'media_list','read'=>true,'execution_mode'=>'read_only'),
  'cron event list'=>array('family'=>'cron','handler'=>'cron_list','read'=>true,'execution_mode'=>'read_only'),
  'cache purge'=>array('family'=>'cache','handler'=>'cache_purge','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'db query'=>array('family'=>'db','handler'=>'db_query','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('limit')),
 );}
 public static function resolve($command){$n=OAI_Command_Normalizer::normalize($command); if(is_wp_error($n)) return $n; $key=$n['family'].' '.$n['verb']; if(!empty($n['subverb']))$key.=' '.$n['subverb']; $all=self::all(); if(isset($all[$key])) { $entry=$all[$key]; $allowed=isset($entry['allowed_flags'])?(array)$entry['allowed_flags']:array(); foreach(array_keys($n['flags']) as $flag){if(!in_array($flag,$allowed,true))return new WP_Error('oai_cli_unknown_flag','Flag is not allowed for this command',array('status'=>400,'command'=>$key,'flag'=>$flag,'allowed_flags'=>$allowed));} if(!empty($entry['read'])){ $range=array('post get'=>array(1,1),'option get'=>array(1,1),'user get'=>array(1,1),'term list'=>array(0,1),'db query'=>array(1,128))[$key]??array(0,0); $argc=count($n['args']); if($argc<$range[0]||$argc>$range[1])return new WP_Error('oai_cli_args','Unexpected number of positional arguments',array('status'=>400,'command'=>$key,'count'=>$argc,'min'=>$range[0],'max'=>$range[1])); } return array_merge($n,$entry,array('canonical'=>$key)); } return new WP_Error('oai_cli_not_allowed','Command is not allowed by the single CommandRegistry',array('status'=>403,'command'=>$n));}
 public static function handler_audit(){
  $all=self::all(); $implemented=array();
  $ref=class_exists('OAI_WP_CLI_Execution')?new ReflectionClass('OAI_WP_CLI_Execution'):null;
  $source=$ref?$ref->getFileName():null; $text=($source&&is_readable($source))?file_get_contents($source):'';
  foreach($all as $key=>$entry){$h=(string)($entry['handler']??''); if(!empty($entry['read']))$implemented[$h]=($h!==''&&$text!==''&&strpos($text,"case '".$h."'")!==false);}
  $missing=array(); foreach($implemented as $h=>$ok)if(!$ok)$missing[]=$h;
  $planned=array(); foreach($all as $key=>$entry)if(empty($entry['read']))$planned[]=$key;
  return array('registered'=>count($all),'handlers'=>count(array_unique(array_keys($implemented))),'missing_handlers'=>$missing,'planned_write_commands'=>$planned,'write_commands_require_sandbox_lifecycle'=>true,'ok'=>empty($missing));
 }
 public static function catalog(){return self::all();}
}

