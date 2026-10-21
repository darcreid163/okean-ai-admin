<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Command_Registry {
 public static function all(){return array(
  'ability list'=>array('family'=>'ability','handler'=>'ability_list','read'=>true,'execution_mode'=>'read_only'),
  'ability get'=>array('family'=>'ability','handler'=>'ability_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'core version'=>array('family'=>'core','handler'=>'core','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'core check-update'=>array('family'=>'core','handler'=>'core_check_update','read'=>true,'execution_mode'=>'read_only'),
  'plugin list'=>array('family'=>'plugin','handler'=>'plugin_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'plugin get'=>array('family'=>'plugin','handler'=>'plugin_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'plugin activate'=>array('family'=>'plugin','handler'=>'plugin_activate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'plugin deactivate'=>array('family'=>'plugin','handler'=>'plugin_deactivate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'plugin update'=>array('family'=>'plugin','handler'=>'plugin_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'theme list'=>array('family'=>'theme','handler'=>'theme_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('format')),
  'theme mod get'=>array('family'=>'theme','handler'=>'theme_mod_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'theme mod list'=>array('family'=>'theme','handler'=>'theme_mod_list','read'=>true,'execution_mode'=>'read_only'),
  'theme activate'=>array('family'=>'theme','handler'=>'theme_activate','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'post list'=>array('family'=>'post','handler'=>'post_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('post_type','post_status','per_page','number','format')),
  'post get'=>array('family'=>'post','handler'=>'post_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'post meta get'=>array('family'=>'post','handler'=>'post_meta_get','read'=>true,'execution_mode'=>'read_only','args'=>array(2,2)),
  'post meta list'=>array('family'=>'post','handler'=>'post_meta_list','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'post create'=>array('family'=>'post','handler'=>'post_create','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'post update'=>array('family'=>'post','handler'=>'post_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'post-type list'=>array('family'=>'post-type','handler'=>'post_type_list','read'=>true,'execution_mode'=>'read_only'),
  'taxonomy list'=>array('family'=>'taxonomy','handler'=>'taxonomy_list','read'=>true,'execution_mode'=>'read_only'),
  'option get'=>array('family'=>'option','handler'=>'option_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'option update'=>array('family'=>'option','handler'=>'option_update','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'user list'=>array('family'=>'user','handler'=>'user_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('number','format')),
  'user get'=>array('family'=>'user','handler'=>'user_get','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'user meta get'=>array('family'=>'user','handler'=>'user_meta_get','read'=>true,'execution_mode'=>'read_only','args'=>array(2,2)),
  'user meta list'=>array('family'=>'user','handler'=>'user_meta_list','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'term list'=>array('family'=>'term','handler'=>'term_list','read'=>true,'execution_mode'=>'read_only','args'=>array(0,1)),
  'term get'=>array('family'=>'term','handler'=>'term_get','read'=>true,'execution_mode'=>'read_only','args'=>array(2,2)),
  'menu list'=>array('family'=>'menu','handler'=>'menu_list','read'=>true,'execution_mode'=>'read_only'),
  'menu item list'=>array('family'=>'menu','handler'=>'menu_item_list','read'=>true,'execution_mode'=>'read_only','args'=>array(1,1)),
  'media list'=>array('family'=>'media','handler'=>'media_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('number','format')),
  'comment list'=>array('family'=>'comment','handler'=>'comment_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('post_id','number','status')),
  'comment count'=>array('family'=>'comment','handler'=>'comment_count','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('post_id','status')),
  'role list'=>array('family'=>'role','handler'=>'role_list','read'=>true,'execution_mode'=>'read_only'),
  'cap list'=>array('family'=>'cap','handler'=>'cap_list','read'=>true,'execution_mode'=>'read_only','args'=>array(0,1)),
  'sidebar list'=>array('family'=>'sidebar','handler'=>'sidebar_list','read'=>true,'execution_mode'=>'read_only'),
  'widget list'=>array('family'=>'widget','handler'=>'widget_list','read'=>true,'execution_mode'=>'read_only','args'=>array(0,1)),
  'cron event list'=>array('family'=>'cron','handler'=>'cron_list','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('number')),
  'cache purge'=>array('family'=>'cache','handler'=>'cache_purge','read'=>false,'approval'=>true,'execution_mode'=>'plan_only'),
  'db query'=>array('family'=>'db','handler'=>'db_query','read'=>true,'execution_mode'=>'read_only','allowed_flags'=>array('limit'),'args'=>array(1,128)),
  'db tables'=>array('family'=>'db','handler'=>'db_tables','read'=>true,'execution_mode'=>'read_only'),
  'db prefix'=>array('family'=>'db','handler'=>'db_prefix','read'=>true,'execution_mode'=>'read_only'),
 );}
 /**
  * Resolve a command against the registry.
  *
  * A command may have two tokens (`post get 42`) or three (`post meta get 42 key`).
  * The three-token form is preferred when it exists, so `post meta get` is not
  * confused with a `post meta` family; positional arguments are sliced after the
  * matched key, never after a fixed offset.
  */
 public static function resolve($command){
  $n=OAI_Command_Normalizer::normalize($command); if(is_wp_error($n))return $n;
  $all=self::all(); $tokens=isset($n['tokens'])&&is_array($n['tokens'])?$n['tokens']:array();
  $keys=array();
  $third=isset($tokens[2])&&is_string($tokens[2])?sanitize_key($tokens[2]):'';
  if($third!==''&&strncmp((string)$tokens[2],'-',1)!==0)$keys[]=$n['family'].' '.$n['verb'].' '.$third;
  $base=$n['family'].' '.$n['verb'].(!empty($n['subverb'])?' '.$n['subverb']:'');
  if(!in_array($base,$keys,true))$keys[]=$base;
  foreach($keys as $key){
   if(!isset($all[$key]))continue;
   $entry=$all[$key];
   $depth=count(explode(' ',$key));
   $args=array_slice($tokens,$depth);
   $flags=isset($n['flags'])&&is_array($n['flags'])?$n['flags']:array();
   $allowed=isset($entry['allowed_flags'])?(array)$entry['allowed_flags']:array();
   foreach(array_keys($flags) as $flag){if(!in_array($flag,$allowed,true))return new WP_Error('oai_cli_unknown_flag','Flag is not allowed for this command',array('status'=>400,'command'=>$key,'flag'=>$flag,'allowed_flags'=>$allowed));}
   if(isset($entry['args']))$range=(array)$entry['args']; elseif(!empty($entry['read']))$range=array(0,0); else $range=null;
   if($range!==null){
    $argc=count($args);
    if($argc<$range[0]||$argc>$range[1])return new WP_Error('oai_cli_args','Unexpected number of positional arguments',array('status'=>400,'command'=>$key,'count'=>$argc,'min'=>$range[0],'max'=>$range[1]));
   }
   return array_merge($n,$entry,array('args'=>$args,'flags'=>$flags,'canonical'=>$key));
  }
  return new WP_Error('oai_cli_not_allowed','Command is not allowed by the single CommandRegistry',array('status'=>403,'command'=>$n));
 }
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
