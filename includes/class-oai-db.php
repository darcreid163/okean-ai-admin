<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_DB {
 public static function table(){global $wpdb; return $wpdb->prefix.'oai_operations';}
 public static function audit_table(){global $wpdb; return $wpdb->prefix.'oai_audit_log';}
 public static function quota_table(){global $wpdb; return $wpdb->prefix.'oai_quota_usage';}
 public static function quota_events_table(){global $wpdb; return $wpdb->prefix.'oai_quota_events';}
 public static function install(){global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $t=self::table(); $charset=$wpdb->get_charset_collate(); $sql="CREATE TABLE $t (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 operation_id varchar(64) NOT NULL,
 status varchar(20) NOT NULL,
 type varchar(64) NOT NULL,
 target text NOT NULL,
 before_data longtext NULL,
 after_data longtext NULL,
 applied_data longtext NULL,
 meta_data longtext NULL,
 diff_data longtext NULL,
 risk varchar(20) NOT NULL DEFAULT 'low',
 receipt longtext NULL,
 proof longtext NULL,
 created_at datetime NOT NULL,
 approved_at datetime NULL,
 rejected_at datetime NULL,
 applied_at datetime NULL,
 rolled_back_at datetime NULL,
 phase varchar(32) NULL,
 workspace_id varchar(64) NULL,
 rollback_data longtext NULL,
 expires_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY operation_id (operation_id),
 KEY status (status),
 KEY created_at (created_at)
) $charset;"; dbDelta($sql);
 $a=self::audit_table();
 $q=self::quota_table();
 $qe=self::quota_events_table();
 $qesql="CREATE TABLE $qe (
 request_key char(64) NOT NULL,
 channel varchar(32) NOT NULL,
 units bigint(20) unsigned NOT NULL DEFAULT 1,
 state varchar(20) NOT NULL DEFAULT 'succeeded',
 reserved_units bigint(20) unsigned NOT NULL DEFAULT 0,
 plan varchar(32) NOT NULL DEFAULT '',
 period_key varchar(32) NOT NULL DEFAULT '',
 fingerprint char(64) NOT NULL DEFAULT '',
 evidence_sha256 char(64) NOT NULL DEFAULT '',
 created_at datetime NOT NULL,
 updated_at datetime NULL,
 PRIMARY KEY  (request_key),
 KEY created_at (created_at),
 KEY channel (channel)
 ) ENGINE=InnoDB $charset;";
 dbDelta($qesql);
 $qsql="CREATE TABLE $q (
 plan varchar(32) NOT NULL,
 period_key varchar(32) NOT NULL,
 used bigint(20) unsigned NOT NULL DEFAULT 0,
 reserved bigint(20) unsigned NOT NULL DEFAULT 0,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (plan,period_key)
 ) ENGINE=InnoDB $charset;";
 dbDelta($qsql);
 $asql="CREATE TABLE $a (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 operation_id varchar(64) NOT NULL,
 operation_db_id bigint(20) unsigned NULL,
 event varchar(32) NOT NULL,
 status varchar(20) NULL,
 actor bigint(20) unsigned NULL,
 target text NULL,
 details longtext NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY operation_id (operation_id),
 KEY event (event),
 KEY created_at (created_at)
 ) $charset;";
 dbDelta($asql); update_option('oai_db_version',OAI_DB_VERSION); if(class_exists('OAI_MCP_OAuth')) OAI_MCP_OAuth::install(); self::migrate_token(); }
 public static function maybe_upgrade(){
  if((int)get_option('oai_db_version',0)<OAI_DB_VERSION)self::install();
  elseif(class_exists('OAI_MCP_OAuth')&&(int)get_option('oai_oauth_schema_version',0)<OAI_MCP_OAuth::SCHEMA_VERSION)OAI_MCP_OAuth::install();
  self::migrate_token();self::prune_quota_events();
 }
 public static function prune_quota_events(){
  // Cleanup is throttled to at most once per day; do not run a DELETE on every request.
  $lock=(int)get_option('oai_quota_events_pruned_at',0);
  if($lock && (time()-$lock)<DAY_IN_SECONDS) return;
  update_option('oai_quota_events_pruned_at',time(),false);
  global $wpdb; $table=self::quota_events_table();
  // Never erase unresolved reservations: time alone cannot prove the outcome.
  $wpdb->query("DELETE FROM $table WHERE state IN ('succeeded','failed') AND created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY");
 }
 public static function migrate_token(){ $old=get_option('oai_api_token',''); $new=get_option('oai_bearer_token',''); if(!$new && $old) update_option('oai_bearer_token',$old,false); }
 public static function insert($data){global $wpdb; $t=self::table(); $ok=$wpdb->insert($t,$data); if(!$ok) return new WP_Error('oai_db','Could not create operation',array('status'=>500,'db_error'=>$wpdb->last_error)); return (int)$wpdb->insert_id;}
 public static function get($id){global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A);}
 public static function update($id,$data){global $wpdb; return $wpdb->update(self::table(),$data,array('id'=>$id));}
 public static function transition($id,$from,$status,$phase=null){
  global $wpdb;
  $from=array_values(array_filter(array_map('sanitize_key',(array)$from)));
  $status=sanitize_key((string)$status);
  if(!$from||$status==='')return false;
  $placeholders=implode(',',array_fill(0,count($from),'%s'));
  $sql='UPDATE '.self::table().' SET status=%s, phase=%s WHERE id=%d AND status IN ('.$placeholders.')';
  $args=array_merge(array($status,sanitize_key((string)($phase??$status)),absint($id)),$from);
  return $wpdb->query($wpdb->prepare($sql,$args));
 }
 private static function operation_lease_key($id){return 'oai_operation_lease_'.absint($id);}
 private static function delete_operation_lease_if_current($key,$expected){
  global $wpdb;
  // Expiry/release must not delete a new holder that replaced the value after
  // this request read it. The options table provides a single atomic CAS.
  $deleted=$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name=%s AND BINARY option_value=BINARY %s',$key,maybe_serialize($expected)));
  wp_cache_delete($key,'options');
  wp_cache_delete('notoptions','options');
  return $deleted===1;
 }
 public static function operation_lease($id){
  $key=self::operation_lease_key($id);
  for($attempt=0;$attempt<2;$attempt++){
   $lease=get_option($key,null);
   if(!is_array($lease)||empty($lease['token_hash']))return null;
   $expires=absint($lease['expires_at']??0);
   if($expires>time())return array('action'=>sanitize_key((string)($lease['action']??'')),'acquired_at'=>absint($lease['acquired_at']??0),'expires_at'=>$expires);
   if(self::delete_operation_lease_if_current($key,$lease))return null;
  }
  // Repeated contention or a database error must block recovery, not make an
  // uncertain lease look absent. This conservative result contains no token.
  return array('action'=>'busy','acquired_at'=>0,'expires_at'=>time()+30);
 }
 public static function acquire_operation_lease($id,$action,$ttl=300){
  $id=absint($id);$action=sanitize_key((string)$action);$ttl=max(30,min(900,absint($ttl)));
  if(!$id||$action==='')return new WP_Error('oai_operation_lease_invalid','Invalid operation execution lease request',array('status'=>500));
  $key=self::operation_lease_key($id);
  for($attempt=0;$attempt<2;$attempt++){
   $existing=get_option($key,null);
   if(is_array($existing)&&!empty($existing['token_hash'])&&absint($existing['expires_at']??0)>time()){
    return new WP_Error('oai_operation_busy','Operation is already executing',array('status'=>409,'action'=>sanitize_key((string)($existing['action']??'')),'lease_expires_at'=>absint($existing['expires_at']??0)));
   }
   if($existing!==null&&!self::delete_operation_lease_if_current($key,$existing))continue;
   $token=wp_generate_uuid4();
   $value=array('token_hash'=>hash('sha256',$token),'action'=>$action,'acquired_at'=>time(),'expires_at'=>time()+$ttl);
   if(add_option($key,$value,'',false))return $token;
  }
  $active=self::operation_lease($id);
  return new WP_Error('oai_operation_busy','Operation execution lease could not be acquired',array('status'=>409,'action'=>$active['action']??null,'lease_expires_at'=>$active['expires_at']??null));
 }
 public static function release_operation_lease($id,$token){
  $key=self::operation_lease_key($id);$lease=get_option($key,null);
  if(!is_array($lease)||empty($lease['token_hash']))return false;
  $hash=hash('sha256',(string)$token);
  if(!hash_equals((string)$lease['token_hash'],$hash))return false;
  return self::delete_operation_lease_if_current($key,$lease);
 }
 public static function clear_operation_leases(){
  global $wpdb;
  $pattern=$wpdb->esc_like('oai_operation_lease_').'%';
  $keys=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",$pattern));
  $deleted=0;
  foreach((array)$keys as $key)if(is_string($key)&&str_starts_with($key,'oai_operation_lease_')&&delete_option($key))$deleted++;
  return $deleted;
 }
 public static function all($limit=200){global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);}
 public static function count_all(){global $wpdb; return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table());}
 public static function page($limit=50,$offset=0){global $wpdb;$limit=max(1,min(200,(int)$limit));$offset=max(0,(int)$offset);return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' ORDER BY id DESC LIMIT %d OFFSET %d',$limit,$offset),ARRAY_A);}
 public static function by_status($statuses,$limit=50,$offset=0){
  global $wpdb;$statuses=array_values(array_filter(array_map('sanitize_key',(array)$statuses)));if(!$statuses)return array();$limit=max(1,min(200,(int)$limit));$offset=max(0,(int)$offset);
  $placeholders=implode(',',array_fill(0,count($statuses),'%s'));$sql='SELECT * FROM '.self::table().' WHERE status IN ('.$placeholders.') ORDER BY id DESC LIMIT %d OFFSET %d';
  return $wpdb->get_results($wpdb->prepare($sql,array_merge($statuses,array($limit,$offset))),ARRAY_A);
 }
 public static function count_by_status($statuses){global $wpdb;$statuses=array_values(array_filter(array_map('sanitize_key',(array)$statuses)));if(!$statuses)return 0;$placeholders=implode(',',array_fill(0,count($statuses),'%s'));return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE status IN ('.$placeholders.')',$statuses));}
 public static function audit_insert($data){global $wpdb; return $wpdb->insert(self::audit_table(),$data);}
 public static function audit_all($limit=200){global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::audit_table().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);}
}

