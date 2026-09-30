<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * OAI_Read
 *
 * Read-only discovery layer. It is intentionally separate from the write engine:
 * AI can inspect the site broadly without receiving permission to mutate anything.
 */
class OAI_Read {
 private static function sensitive_option($key){
  $key=strtolower((string)$key);
  $deny=array('oai_api_token','oai_bearer_token','oai_agent_secret','oai_client_secret','oai_license_signature','oai_agent_key_hash','oai_license');
  if(in_array($key,$deny,true))return true;
  return (bool)preg_match('/(^|[_\-])(token|secret|password|passwd|api[_\-]?key|private[_\-]?key|auth[_\-]?key|cookie|nonce|signature|credential|hash)([_\-]|$)/i',$key);
 }
 private static function redact_nested($value,&$redacted,$depth=0){
  if($depth>12){$redacted=true;return '[REDACTED]';}
  if(!is_array($value) && !is_object($value))return $value;
  $out=array();
  foreach((array)$value as $key=>$item){
   if(self::sensitive_option($key)){$out[$key]='[REDACTED]';$redacted=true;}
   else $out[$key]=self::redact_nested($item,$redacted,$depth+1);
  }
  return $out;
 }
 public static function option_value($key,$value){
  if(self::sensitive_option($key))return array('redacted'=>true,'value'=>'[REDACTED]');
  $redacted=false;
  $safe=self::redact_nested($value,$redacted);
  return array('redacted'=>$redacted,'value'=>$safe);
 }
 public static function post_types() {
  $types=get_post_types(array('show_ui'=>true),'objects');
  $out=array();
  foreach($types as $name=>$obj){
   $out[]=array(
    'name'=>$name,'label'=>$obj->label,'public'=>(bool)$obj->public,
    'hierarchical'=>(bool)$obj->hierarchical,
    'supports'=>array_values((array)$obj->supports),
    'rest_base'=>isset($obj->rest_base)?$obj->rest_base:null
   );
  }
  return $out;
 }

 public static function taxonomies($req) {
  $pt=sanitize_key((string)$req->get_param('post_type'));
  $args=array('public'=>true);
  if($pt!=='') $args['object_type']=array($pt);
  $taxes=get_taxonomies($args,'objects');
  $out=array();
  foreach($taxes as $name=>$tax){
   $terms=get_terms(array('taxonomy'=>$name,'hide_empty'=>false,'number'=>min(200,max(1,absint($req->get_param('limit')?:200)))));
   $rows=array();
   if(!is_wp_error($terms)) foreach($terms as $t) $rows[]=array(
    'id'=>(int)$t->term_id,'name'=>$t->name,'slug'=>$t->slug,
    'count'=>(int)$t->count,'parent'=>(int)$t->parent
   );
   $out[]=array('name'=>$name,'label'=>$tax->label,'hierarchical'=>(bool)$tax->hierarchical,'terms'=>$rows);
  }
  return array('post_type'=>$pt?:null,'count'=>count($out),'items'=>$out);
 }

 public static function media($req) {
  $limit=min(100,max(1,absint($req->get_param('limit')?:20)));
  $page=min(10000,max(1,absint($req->get_param('page')?:1)));
  $search=sanitize_text_field((string)$req->get_param('search'));
  $args=array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>$limit,'paged'=>$page,'orderby'=>'date','order'=>'DESC');
  if($search!=='') $args['s']=$search;
  $q=new WP_Query($args); $items=array();
  foreach($q->posts as $p){
   $items[]=array(
    'id'=>$p->ID,'title'=>get_the_title($p),'mime'=>$p->post_mime_type,
    'url'=>wp_get_attachment_url($p->ID),
    'thumbnail'=>wp_get_attachment_image_url($p->ID,'thumbnail'),
    'alt'=>get_post_meta($p->ID,'_wp_attachment_image_alt',true),
    'caption'=>$p->post_excerpt,'description'=>$p->post_content,
    'modified'=>$p->post_modified
   );
  }
  return array('count'=>count($items),'page'=>$page,'limit'=>$limit,'total'=>(int)$q->found_posts,'total_pages'=>(int)$q->max_num_pages,'has_more'=>$page<$q->max_num_pages,'items'=>$items);
 }

 public static function menus() {
  $menus=wp_get_nav_menus(); $out=array();
  foreach($menus as $menu){
   $items=wp_get_nav_menu_items($menu->term_id);
   $rows=array();
   if($items) foreach($items as $i) $rows[]=array(
    'id'=>(int)$i->ID,'title'=>$i->title,'url'=>$i->url,
    'object_id'=>(int)$i->object_id,'object'=>$i->object,
    'type'=>$i->type,'parent'=>(int)$i->menu_item_parent,
    'order'=>(int)$i->menu_order,'classes'=>array_values((array)$i->classes)
   );
   $out[]=array('id'=>(int)$menu->term_id,'name'=>$menu->name,'slug'=>$menu->slug,'items'=>$rows);
  }
  return array('count'=>count($out),'items'=>$out);
 }

 public static function themes() {
  $themes=wp_get_themes(); $active=wp_get_theme(); $out=array();
  foreach($themes as $stylesheet=>$theme) $out[]=array(
   'stylesheet'=>$stylesheet,'name'=>$theme->get('Name'),'version'=>$theme->get('Version'),
   'active'=>$stylesheet===$active->get_stylesheet(),'template'=>$theme->get_template(),
   'parent'=>$theme->parent()? $theme->parent()->get_stylesheet():null
  );
  return $out;
 }

 public static function options($req) {
  $prefix=sanitize_text_field((string)$req->get_param('prefix'));
  $keys=$req->get_param('keys');
  if(is_string($keys) && $keys!=='') $keys=array_filter(array_map('trim',explode(',',$keys)));
  if(!is_array($keys)) $keys=array();
  $out=array();
  if($keys){
   foreach($keys as $key){
    $key=sanitize_text_field($key);
    if($key==='') continue;
    $v=get_option($key,null);
    $safe=self::option_value($key,$v); $out[$key]=array('exists'=>($v!==null),'redacted'=>$safe['redacted'],'value'=>$safe['value']);
   }
  } elseif($prefix!==''){
   global $wpdb;
   $like=$wpdb->esc_like($prefix).'%';
   $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100",$like),ARRAY_A);
   foreach($rows as $row){
    $safe=self::option_value($row['option_name'],maybe_unserialize($row['option_value']));
    $out[$row['option_name']]=array('exists'=>true,'autoload'=>$row['autoload'],'redacted'=>$safe['redacted'],'value'=>$safe['value']);
   }
  } else return new WP_Error('oai_options_query','Specify prefix or keys',array('status'=>400));
  return array('count'=>count($out),'items'=>$out);
 }

 public static function users($req) {
  if(!current_user_can('list_users')) return new WP_Error('oai_forbidden','User list permission required',array('status'=>403));
  $limit=min(100,max(1,absint($req->get_param('limit')?:50)));
  $page=min(10000,max(1,absint($req->get_param('page')?:1)));
  $query=new WP_User_Query(array('number'=>$limit,'offset'=>($page-1)*$limit,'orderby'=>'ID','order'=>'DESC','count_total'=>true));
  $users=$query->get_results();
  $out=array();
  foreach($users as $u) $out[]=array(
   'id'=>$u->ID,'display_name'=>$u->display_name,
   'roles'=>array_values($u->roles),'registered'=>$u->user_registered
  );
  $total=(int)$query->get_total();$pages=(int)ceil($total/$limit);
  return array('count'=>count($out),'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$pages,'has_more'=>$page<$pages,'items'=>$out);
 }

 public static function comments($req) {
  $limit=min(100,max(1,absint($req->get_param('limit')?:50)));
  $page=min(10000,max(1,absint($req->get_param('page')?:1)));
  $args=array('number'=>$limit,'offset'=>($page-1)*$limit,'status'=>'all','orderby'=>'comment_date_gmt','order'=>'DESC');
  $comments=get_comments($args);
  $out=array();
  foreach($comments as $c) $out[]=array(
   'id'=>$c->comment_ID,'post_id'=>$c->comment_post_ID,'author'=>$c->comment_author,
   'approved'=>$c->comment_approved,'date'=>$c->comment_date_gmt,'content'=>$c->comment_content
  );
  $total=(int)get_comments(array('count'=>true,'status'=>'all'));$pages=(int)ceil($total/$limit);
  return array('count'=>count($out),'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$pages,'has_more'=>$page<$pages,'items'=>$out);
 }

 public static function widgets() {
  global $wp_registered_sidebars,$wp_registered_widgets;
  $sidebars=array();
  foreach((array)$wp_registered_sidebars as $id=>$sidebar) $sidebars[]=array(
   'id'=>$id,'name'=>$sidebar['name'],'description'=>$sidebar['description']??''
  );
  $widgets=array();
  foreach((array)$wp_registered_widgets as $id=>$w) $widgets[]=array(
   'id'=>$id,'name'=>$w['name'],'callback'=>is_array($w['callback'])?'method':gettype($w['callback']),
   'params'=>$w['params']??array()
  );
  return array('sidebars'=>$sidebars,'widgets'=>$widgets);
 }

 public static function health() {
  global $wpdb;
  $checks=array();
  $checks['database']=array('ok'=>(bool)$wpdb->get_var("SELECT 1"),'error'=>$wpdb->last_error?:null);
  $checks['uploads']=array('ok'=>wp_is_writable(wp_upload_dir()['basedir']));
  $checks['wp_debug']=array('enabled'=>defined('WP_DEBUG')?(bool)WP_DEBUG:false);
  $checks['memory_limit']=ini_get('memory_limit');
  $checks['max_execution_time']=ini_get('max_execution_time');
  $checks['max_input_vars']=ini_get('max_input_vars');
  $checks['https']=array('is_ssl'=>is_ssl(),'home_url'=>home_url('/'));
  $checks['cron']=array('disabled'=>defined('DISABLE_WP_CRON')?(bool)DISABLE_WP_CRON:false);
  return $checks;
 }
}
