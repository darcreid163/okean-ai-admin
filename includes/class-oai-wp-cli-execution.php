<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_WP_CLI_Execution {
 public static function execute($resolved){if(empty($resolved['read']))return new WP_Error('oai_cli_write_requires_lifecycle','Write WP-CLI commands must go through sandbox → approval → apply lifecycle',array('status'=>409,'execution_model'=>'sandbox_then_approval','handler'=>$resolved['handler']??null,'canonical'=>$resolved['canonical']??null));$h=$resolved['handler'];$a=$resolved['args'];$f=$resolved['flags'];switch($h){
  case 'core':return array('version'=>get_bloginfo('version'),'command'=>'core version');
  case 'plugin_list':require_once ABSPATH.'wp-admin/includes/plugin.php';$all=get_plugins();$rows=array();foreach($all as $file=>$data)$rows[]=array('plugin'=>$file,'name'=>$data['Name'],'version'=>$data['Version'],'active'=>is_plugin_active($file));return self::format($rows,$f);
  case 'theme_list':$rows=array();foreach(wp_get_themes() as $stylesheet=>$t)$rows[]=array('stylesheet'=>$stylesheet,'name'=>$t->get('Name'),'version'=>$t->get('Version'),'active'=>$stylesheet===get_option('stylesheet'));return self::format($rows,$f);
  case 'post_list':$args=array('post_type'=>$f['post_type']??'any','post_status'=>$f['post_status']??'any','posts_per_page'=>isset($f['number'])?max(1,min(100,(int)$f['number'])):(isset($f['per_page'])?max(1,min(100,(int)$f['per_page'])):20),'paged'=>1);$ps=get_posts($args);$rows=array();foreach($ps as $p)$rows[]=array('id'=>$p->ID,'title'=>$p->post_title,'type'=>$p->post_type,'status'=>$p->post_status,'slug'=>$p->post_name);return self::format($rows,$f);
  case 'post_get':$p=get_post(absint($a[0]??0));if(!$p)return new WP_Error('oai_post_not_found','Post not found',array('status'=>404));return array('id'=>$p->ID,'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'type'=>$p->post_type,'status'=>$p->post_status,'meta_keys'=>array_keys(get_post_meta($p->ID)));
  case 'option_get':$name=(string)$a[0];$v=get_option($name,null);$safe=OAI_Read::option_value($name,$v);return array('name'=>$name,'exists'=>$v!==null,'redacted'=>$safe['redacted'],'value'=>$safe['value']);
  case 'user_list':$users=get_users(array('number'=>isset($f['number'])?max(1,min(100,$f['number'])):20));$rows=array();foreach($users as $u)$rows[]=array('id'=>$u->ID,'login'=>$u->user_login,'name'=>$u->display_name,'roles'=>$u->roles);return self::format($rows,$f);
  case 'user_get':$u=get_user_by('id',absint($a[0]??0));if(!$u)return new WP_Error('oai_user_not_found','User not found',array('status'=>404));return array('id'=>$u->ID,'login'=>$u->user_login,'name'=>$u->display_name,'email'=>$u->user_email,'roles'=>$u->roles);
  case 'term_list':$tax=sanitize_key((string)($a[0]??'category'));return array('taxonomy'=>$tax,'terms'=>get_terms(array('taxonomy'=>$tax,'hide_empty'=>false,'number'=>100,'fields'=>'all')));
  case 'menu_list':return array('menus'=>wp_get_nav_menus());
  case 'media_list':$ps=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>isset($f['number'])?max(1,min(100,(int)$f['number'])):50));$rows=array();foreach($ps as $p)$rows[]=array('id'=>$p->ID,'title'=>$p->post_title,'mime'=>get_post_mime_type($p->ID),'url'=>wp_get_attachment_url($p->ID));return self::format($rows,$f);
  case 'cron_list':return self::cron_list($f);
  case 'db_query':return self::db_query($a,$f);
  case 'cache_purge':if(function_exists('wp_cache_flush'))wp_cache_flush();if(function_exists('rocket_clean_domain'))rocket_clean_domain();return array('purged'=>true);
  default:return new WP_Error('oai_cli_not_implemented','Command is registered but its execution handler is not implemented',array('status'=>501,'handler'=>$h,'canonical'=>$resolved['canonical']??null));}}
 private static function cron_list($f){
  $cron=function_exists('_get_cron_array')?_get_cron_array():array();
  $events=array();
  foreach((array)$cron as $timestamp=>$hooks){
   foreach((array)$hooks as $hook=>$instances){
    foreach((array)$instances as $sig=>$event){
     $args=isset($event['args'])&&is_array($event['args'])?$event['args']:array();
     $events[]=array('timestamp'=>(int)$timestamp,'hook'=>(string)$hook,'sig'=>(string)$sig,'args_count'=>count($args),'schedule'=>$event['schedule']??false,'interval'=>isset($event['interval'])?(int)$event['interval']:0);
    }
   }
  }
  usort($events,function($a,$b){return $a['timestamp']<=>$b['timestamp'];});
  $limit=isset($f['number'])?max(1,min(100,(int)$f['number'])):100;
  return self::format(array_slice($events,0,$limit),$f);
 }
 private static function db_query($a,$f){
  $sql=trim(implode(' ',array_map('strval',$a)));
  if(!preg_match('/^SELECT\s+COUNT\s*\(\s*\*\s*\)\s*(?:AS\s+([A-Za-z_][A-Za-z0-9_]*))?\s+FROM\s+([A-Za-z0-9_]+)$/i',$sql,$m))return new WP_Error('oai_db_scope','Only COUNT(*) on approved WordPress tables is allowed',array('status'=>403));
  global $wpdb;
  $allowed=array($wpdb->posts,$wpdb->postmeta,$wpdb->options,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->comments,$wpdb->commentmeta,$wpdb->users,$wpdb->usermeta);
  $table=$m[2];
  if(!in_array($table,$allowed,true))return new WP_Error('oai_db_scope','Table is not approved for diagnostics',array('status'=>403));
  $alias=$m[1]??'count';
  $count=$wpdb->get_var('SELECT COUNT(*) FROM '.$table);
  if($wpdb->last_error)return new WP_Error('oai_db_query','Database count failed',array('status'=>400));
  return array('rows'=>array(array($alias=>(int)$count)),'count'=>1,'limited_to'=>1,'sql_hash'=>hash('sha256',$sql),'scope'=>'approved_table_count');
 }
 public static function readiness(){return array('handlers'=>OAI_Command_Registry::handler_audit(),'direct_write_execution'=>false,'db_query_read_only'=>true,'db_query_scope'=>'approved_table_count','db_query_max_rows'=>1);}
 private static function format($rows,$f){if(isset($f['format'])&&$f['format']==='ids'){return array_values(array_map(function($x){return (int)$x['id'];},$rows));}if(isset($f['format'])&&$f['format']==='count')return count($rows);return $rows;}
 public static function plan($resolved){$perm=OAI_Permission_Resolver::resolve($resolved);$write=empty($resolved['read']);$implemented=false;if(isset($resolved['handler'])){$ref=new ReflectionClass(__CLASS__);$m=$ref->getMethod('execute');$src=$m->getFileName();if($src&&is_readable($src)){$text=file_get_contents($src);$implemented=strpos($text,"case '".$resolved['handler']."'")!==false;}}return array('canonical'=>$resolved['canonical'],'handler'=>$resolved['handler'],'args'=>$resolved['args'],'flags'=>$resolved['flags'],'permission'=>$perm,'execution_model'=>$write?'sandbox_then_approval':'read_only','executable_now'=>!$write,'handler_implemented'=>$implemented,'next_step'=>$write?'create_operation_and_require_approval':'execute_read');}
}

