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
  case 'core_check_update':$transient=get_site_transient('update_core');$offers=array();if(is_object($transient)&&!empty($transient->updates))foreach((array)$transient->updates as $u)$offers[]=array('version'=>(string)($u->current??''),'locale'=>(string)($u->locale??''),'php_version'=>(string)($u->php_version??''));return array('current'=>get_bloginfo('version'),'offers'=>$offers,'offers_count'=>count($offers),'source'=>'update_core_transient','network_call'=>false);
  case 'plugin_get':require_once ABSPATH.'wp-admin/includes/plugin.php';$file=(string)$a[0];$plugins=get_plugins();$path=null;foreach($plugins as $candidate=>$data){if($candidate===$file||$data['Name']===$file){$path=$candidate;break;}}if($path===null)return new WP_Error('oai_plugin_not_found','Plugin not found',array('status'=>404));$data=$plugins[$path];return array('plugin'=>$path,'name'=>$data['Name'],'version'=>$data['Version'],'active'=>is_plugin_active($path),'requires_php'=>$data['RequiresPHP']??'','requires_wp'=>$data['RequiresWP']??'');
  case 'theme_mod_get':$name=sanitize_key((string)$a[0]);$exists=null!==get_theme_mod($name,null);$value=get_theme_mod($name,null);return array('name'=>$name,'exists'=>$exists,'type'=>gettype($value),'redacted'=>false,'value'=>self::bounded_value($value),'value_sha256'=>$exists?hash('sha256',wp_json_encode($value)):null);
  case 'theme_mod_list':$mods=get_theme_mods();$mods=is_array($mods)?$mods:array();$rows=array();foreach(array_slice($mods,0,200,true) as $k=>$v)$rows[]=array('name'=>(string)$k,'type'=>gettype($v),'size'=>is_string($v)?strlen($v):(is_array($v)?count($v):0));return array('stylesheet'=>get_option('stylesheet'),'mods'=>$rows,'count'=>count($mods),'truncated'=>count($mods)>200);
  case 'post_meta_get':$id=absint($a[0]??0);$key=(string)($a[1]??'');if(!get_post($id))return new WP_Error('oai_post_not_found','Post not found',array('status'=>404));if($key==='')return new WP_Error('oai_meta_key','A meta key is required',array('status'=>400));$exists=metadata_exists('post',$id,$key);$value=$exists?get_post_meta($id,$key,true):null;$redacted=self::redact_meta_key($key);return array('post_id'=>$id,'key'=>$key,'exists'=>$exists,'redacted'=>$redacted,'value'=>$redacted?null:self::bounded_value($value),'value_sha256'=>$exists?hash('sha256',wp_json_encode($value)):null);
  case 'post_meta_list':$id=absint($a[0]??0);if(!get_post($id))return new WP_Error('oai_post_not_found','Post not found',array('status'=>404));$all=get_post_meta($id);$rows=array();foreach(array_slice(array_keys($all),0,300) as $k)$rows[]=array('key'=>(string)$k,'redacted'=>self::redact_meta_key($k));return array('post_id'=>$id,'keys'=>$rows,'count'=>count($all),'values_returned'=>false);
  case 'post_type_list':$rows=array();foreach(get_post_types(array(),'objects') as $name=>$o)$rows[]=array('name'=>$name,'label'=>(string)($o->labels->name??$name),'public'=>!empty($o->public),'hierarchical'=>!empty($o->hierarchical),'has_archive'=>$o->has_archive??false);return $rows;
  case 'taxonomy_list':$rows=array();foreach(get_taxonomies(array(),'objects') as $name=>$o)$rows[]=array('name'=>$name,'label'=>(string)($o->labels->name??$name),'public'=>!empty($o->public),'hierarchical'=>!empty($o->hierarchical),'objects'=>array_values((array)($o->object_type??array())));return $rows;
  case 'term_get':$tax=sanitize_key((string)($a[0]??''));$tid=absint($a[1]??0);$term=get_term($tid,$tax);if(!$term||is_wp_error($term))return new WP_Error('oai_term_not_found','Term not found',array('status'=>404));return array('term_id'=>(int)$term->term_id,'taxonomy'=>$term->taxonomy,'name'=>$term->name,'slug'=>$term->slug,'parent'=>(int)$term->parent,'count'=>(int)$term->count);
  case 'user_meta_get':$id=absint($a[0]??0);$key=(string)($a[1]??'');if(!get_user_by('id',$id))return new WP_Error('oai_user_not_found','User not found',array('status'=>404));$exists=metadata_exists('user',$id,$key);$redacted=self::redact_meta_key($key);$value=$exists&&!$redacted?get_user_meta($id,$key,true):null;return array('user_id'=>$id,'key'=>$key,'exists'=>$exists,'redacted'=>$redacted,'value'=>$redacted?null:self::bounded_value($value));
  case 'user_meta_list':$id=absint($a[0]??0);if(!get_user_by('id',$id))return new WP_Error('oai_user_not_found','User not found',array('status'=>404));$all=get_user_meta($id);$rows=array();foreach(array_slice(array_keys($all),0,300) as $k)$rows[]=array('key'=>(string)$k,'redacted'=>self::redact_meta_key($k));return array('user_id'=>$id,'keys'=>$rows,'count'=>count($all),'values_returned'=>false);
  case 'comment_list':$args=array('number'=>isset($f['number'])?max(1,min(100,(int)$f['number'])):50,'status'=>isset($f['status'])?sanitize_key((string)$f['status']):'all','orderby'=>'comment_date_gmt','order'=>'DESC');if(isset($f['post_id']))$args['post_id']=absint($f['post_id']);$rows=array();foreach(get_comments($args) as $c)$rows[]=array('id'=>(int)$c->comment_ID,'post_id'=>(int)$c->comment_post_ID,'author'=>(string)$c->comment_author,'status'=>(string)$c->comment_approved,'date_gmt'=>(string)$c->comment_date_gmt,'content_length'=>strlen((string)$c->comment_content));return $rows;
  case 'comment_count':$post=isset($f['post_id'])?absint($f['post_id']):0;$counts=(array)wp_count_comments($post);return array('post_id'=>$post,'counts'=>$counts,'total'=>(int)($counts['total_comments']??0),'pii_returned'=>false);
  case 'role_list':$rows=array();foreach(wp_roles()->roles as $name=>$role)$rows[]=array('role'=>$name,'name'=>(string)($role['name']??$name),'cap_count'=>count((array)($role['capabilities']??array())));return $rows;
  case 'cap_list':$roles=wp_roles()->roles;$target=isset($a[0])?sanitize_key((string)$a[0]):'';if($target!==''){if(!isset($roles[$target]))return new WP_Error('oai_role_not_found','Role not found',array('status'=>404));$caps=array_keys(array_filter((array)$roles[$target]['capabilities']));sort($caps);return array('role'=>$target,'capabilities'=>array_slice($caps,0,300),'count'=>count($caps));}$rows=array();foreach($roles as $name=>$role)$rows[]=array('role'=>$name,'cap_count'=>count((array)($role['capabilities']??array())));return array('roles'=>$rows,'hint'=>'Pass a role name to list its capabilities');
  case 'sidebar_list':$sidebars=wp_get_sidebars_widgets();$rows=array();foreach((array)$sidebars as $id=>$widgets)$rows[]=array('sidebar'=>(string)$id,'widget_count'=>is_array($widgets)?count($widgets):0);return $rows;
  case 'widget_list':$sidebars=(array)wp_get_sidebars_widgets();$target=isset($a[0])?sanitize_key((string)$a[0]):'';if($target!==''){if(!isset($sidebars[$target]))return new WP_Error('oai_sidebar_not_found','Sidebar not found',array('status'=>404));$ids=is_array($sidebars[$target])?$sidebars[$target]:array();return array('sidebar'=>$target,'widgets'=>array_values(array_map('strval',$ids)),'count'=>count($ids));}$rows=array();foreach($sidebars as $id=>$widgets)$rows[]=array('sidebar'=>(string)$id,'widget_count'=>is_array($widgets)?count($widgets):0);return $rows;
  case 'menu_item_list':$menu=absint($a[0]??0);if($menu<=0){$obj=wp_get_nav_menu_object((string)($a[0]??''));if(is_object($obj))$menu=(int)$obj->term_id;}if($menu<=0)return new WP_Error('oai_menu_not_found','Menu not found',array('status'=>404));$items=wp_get_nav_menu_items($menu);if($items===false)return new WP_Error('oai_menu_not_found','Menu not found',array('status'=>404));$rows=array();foreach($items as $i)$rows[]=array('id'=>(int)$i->ID,'title'=>(string)$i->title,'type'=>(string)$i->type,'object'=>(string)$i->object,'parent'=>(int)$i->menu_item_parent,'position'=>(int)$i->menu_order,'url'=>(string)$i->url);return array('menu_id'=>$menu,'items'=>$rows,'count'=>count($rows));
  case 'db_tables':global $wpdb;$tables=$wpdb->get_col('SHOW TABLES');$tables=is_array($tables)?$tables:array();return array('count'=>count($tables),'tables'=>array_slice(array_map('strval',$tables),0,300),'rows_returned'=>false);
  case 'db_prefix':global $wpdb;return array('prefix'=>(string)$wpdb->prefix,'base_prefix'=>(string)$wpdb->base_prefix,'blog_id'=>(int)($wpdb->blogid??0));
  default:return new WP_Error('oai_cli_not_implemented','Command is registered but its execution handler is not implemented',array('status'=>501,'handler'=>$h,'canonical'=>$resolved['canonical']??null));}}
 /** Keys that may carry credentials or session material are never returned by value. */
 private static function redact_meta_key($key){
  return (bool)preg_match('/(pass|pwd|secret|token|salt|nonce|api[_-]?key|private|credential|session|cookie)/i',(string)$key);
 }
 /** Bound a value so one option or meta row cannot flood the response. */
 private static function bounded_value($value,$max=2000){
  if(is_string($value)){
   if(strlen($value)<=$max)return $value;
   return array('truncated'=>true,'length'=>strlen($value),'sha256'=>hash('sha256',$value),'sample'=>substr($value,0,200));
  }
  if(is_array($value)){
   if(count($value)>100)return array('truncated'=>true,'count'=>count($value),'sha256'=>hash('sha256',wp_json_encode($value)));
   return $value;
  }
  return $value;
 }
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

