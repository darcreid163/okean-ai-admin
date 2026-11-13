<?php
/**
 * Optional AI-controlled sandbox lifecycle.
 *
 * AI lifecycle authorization is bound to the durable local administrator,
 * site context, operation creator and the immutable approved snapshot.
 * MCP client/session identifiers are audit metadata only: reconnecting or
 * rotating a pairing credential must not transfer or revoke operation ownership.
 *
 * Manual WP Admin controls remain available in parallel.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_AI_Lifecycle_Control {
 const OPTION='oai_ai_lifecycle_control';
 private static $request_context=null;

 private static function raw(){
  $cfg=get_option(self::OPTION,array());
  return is_array($cfg)?$cfg:array();
 }
 private static function save($cfg){update_option(self::OPTION,$cfg,false);}

 public static function enable($owner_user_id){
  $owner=absint($owner_user_id);
  if(!$owner || !user_can($owner,'manage_options'))return new WP_Error('oai_ai_lifecycle_admin','Administrator capability required',array('status'=>403));
  self::save(array(
   'enabled'=>true,
   'owner_user_id'=>$owner,
   'enabled_at'=>time(),
   // Retained as an empty legacy field for backwards-compatible status reads.
   // It is deliberately no longer an authorization boundary.
   'bound_client_id_hash'=>'',
   'principal_version'=>2,
   'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'',
  ));
  return self::status();
 }

 public static function disable($actor_user_id=0,$reason='manual'){
  self::save(array(
   'enabled'=>false,
   'owner_user_id'=>0,
   'enabled_at'=>0,
   'bound_client_id_hash'=>'',
   'principal_version'=>2,
   'build_id'=>defined('OAI_BUILD_ID')?(string)OAI_BUILD_ID:'',
   'disabled_at'=>time(),
   'disabled_by'=>absint($actor_user_id),
   'disabled_reason'=>sanitize_key((string)$reason)?:'manual',
  ));
  self::$request_context=null;
  return self::status();
 }

 public static function reset_for_build_change(){
  if(!defined('OAI_BUILD_ID'))return false;
  self::save(array(
   'enabled'=>false,
   'owner_user_id'=>0,
   'enabled_at'=>0,
   'bound_client_id_hash'=>'',
   'principal_version'=>2,
   'build_id'=>(string)OAI_BUILD_ID,
   'disabled_at'=>time(),
   'disabled_by'=>0,
   'disabled_reason'=>'build_changed',
  ));
  self::$request_context=null;
  return true;
 }

 public static function reset_stale_build(){
  $cfg=self::raw();
  if(!defined('OAI_BUILD_ID'))return false;
  $stored=(string)($cfg['build_id']??'');
  if($stored!==''&&hash_equals($stored,(string)OAI_BUILD_ID))return false;
  return self::reset_for_build_change();
 }

 public static function is_active(){
  $cfg=self::raw();
  if(empty($cfg['enabled']))return false;
  if(empty($cfg['owner_user_id']) || !user_can(absint($cfg['owner_user_id']),'manage_options'))return false;
  if(!empty($cfg['build_id']) && defined('OAI_BUILD_ID') && !hash_equals((string)$cfg['build_id'],(string)OAI_BUILD_ID))return false;
  return true;
 }

 public static function status(){
  $cfg=self::raw();
  return array(
   'supported'=>true,
   'active'=>self::is_active(),
   'mode'=>self::is_active()?'ai_and_human_lifecycle':'human_lifecycle_only',
   'owner_user_id'=>absint($cfg['owner_user_id']??0),
   'enabled_at'=>absint($cfg['enabled_at']??0),
   'client_bound'=>false,
   'rules'=>array(
    'administrator_enable_required'=>true,
    'administrator_mcp_identity_required'=>true,
    'single_mcp_client_binding'=>false,
    'durable_principal_binding'=>true,
    'operation_creator_required'=>true,
    'approved_snapshot_hash_required'=>true,
    'manual_controls_preserved'=>true,
    'sandbox_required'=>true,
    'conflict_guards_preserved'=>true,
    'expiry_guards_preserved'=>true,
    'verify_guards_preserved'=>true,
    'rollback_guards_preserved'=>true,
   ),
  );
 }

 public static function request_authorized($id,$action){
  if(!is_array(self::$request_context))return false;
  return absint(self::$request_context['id']??0)===absint($id)
   && (string)(self::$request_context['action']??'')===(string)$action
   && !empty(self::$request_context['principal_user_id'])
   && !empty(self::$request_context['site_fingerprint'])
   && !empty(self::$request_context['authorized']);
 }

 private static function site_fingerprint(){
  $identity=array(
   'home'=>untrailingslashit(home_url('/')),
   'site'=>untrailingslashit(site_url('/')),
   'blog_id'=>function_exists('get_current_blog_id')?absint(get_current_blog_id()):1,
   'auth_salt_hash'=>hash('sha256',(string)wp_salt('auth')),
  );
  return hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }

 private static function operation_snapshot_hash($row,$principal_user_id,$site_fingerprint){
  $snapshot=array(
   'operation_id'=>(string)($row['operation_id']??''),
   'type'=>(string)($row['type']??''),
   'target'=>(string)($row['target']??''),
   'before_data'=>(string)($row['before_data']??''),
   'after_data'=>(string)($row['after_data']??''),
   'diff_data'=>(string)($row['diff_data']??''),
   'rollback_data'=>(string)($row['rollback_data']??''),
   'workspace_id'=>(string)($row['workspace_id']??''),
   'expires_at'=>(string)($row['expires_at']??''),
   'principal_user_id'=>absint($principal_user_id),
   'site_fingerprint'=>(string)$site_fingerprint,
  );
  return hash('sha256',wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }

 /**
  * The original "created" audit event is the authoritative owner record for
  * legacy operations. If meta_data also carries created_by, both must agree.
  * Missing or ambiguous creator evidence fails closed.
  */
 private static function operation_creator($row){
  global $wpdb;
  $operation_id=(string)($row['operation_id']??'');
  if($operation_id==='' || !isset($wpdb) || !method_exists($wpdb,'get_col'))return 0;
  $table=OAI_DB::audit_table();
  $actors=$wpdb->get_col($wpdb->prepare(
   "SELECT DISTINCT actor FROM {$table} WHERE operation_id=%s AND event=%s AND actor IS NOT NULL",
   $operation_id,'created'
  ));
  $actors=array_values(array_unique(array_filter(array_map('absint',(array)$actors))));
  if(count($actors)!==1)return 0;
  $audit_creator=$actors[0];
  $meta=json_decode((string)($row['meta_data']??''),true);
  if(is_array($meta)&&isset($meta['created_by'])&&absint($meta['created_by'])!==$audit_creator)return 0;
  return $audit_creator;
 }

 /**
  * @return bool|null true when the latest AI approval matches, false when it
  *                   exists but does not match, null when the operation was
  *                   approved outside AI Lifecycle Control.
  */
 private static function approved_snapshot_matches($row,$principal_user_id,$site_fingerprint,$snapshot_hash){
  global $wpdb;
  if(!isset($wpdb) || !method_exists($wpdb,'get_row'))return null;
  $table=OAI_DB::audit_table();
  $entry=$wpdb->get_row($wpdb->prepare(
   "SELECT actor,details FROM {$table} WHERE operation_id=%s AND event=%s ORDER BY id DESC LIMIT 1",
   (string)$row['operation_id'],'ai_lifecycle_approve'
  ),ARRAY_A);
  if(!is_array($entry))return null;
  if(absint($entry['actor']??0)!==absint($principal_user_id))return false;
  $details=json_decode((string)($entry['details']??''),true);
  return is_array($details)
   && absint($details['principal_user_id']??0)===absint($principal_user_id)
   && isset($details['site_fingerprint']) && hash_equals((string)$details['site_fingerprint'],(string)$site_fingerprint)
   && isset($details['snapshot_sha256']) && hash_equals((string)$details['snapshot_sha256'],(string)$snapshot_hash);
 }

 private static function authorize($id,$action,$client_id,$user_id){
  if(!self::is_active())return new WP_Error('oai_ai_lifecycle_inactive','AI Lifecycle Control is not active',array('status'=>403));
  if(!in_array($action,array('approve','reject','apply','rollback'),true))return new WP_Error('oai_ai_lifecycle_action','Unsupported lifecycle action',array('status'=>400));
  $user_id=absint($user_id);
  if(!$user_id || !user_can($user_id,'manage_options'))return new WP_Error('oai_ai_lifecycle_admin','Administrator MCP identity required',array('status'=>403));
  $cfg=self::raw();
  if($user_id!==absint($cfg['owner_user_id']??0))return new WP_Error('oai_ai_lifecycle_owner','AI Lifecycle Control belongs to a different administrator',array('status'=>403));
  if(!is_string($client_id) || $client_id==='')return new WP_Error('oai_ai_lifecycle_client_identity','MCP client identity is required for audit',array('status'=>403));
  $row=OAI_DB::get(absint($id));
  if(!$row)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  if($row['type']==='option_update'&&($action==='approve'||($action==='apply'&&($row['status']??'')!=='applying'))){
   $policy=OAI_REST::validate_option_write_target($row['target']);if(is_wp_error($policy))return $policy;
  }
  // Only the forward mutations require verifiable ownership: approve and apply
  // push after_data into live state. Reject and rollback are recovery paths for
  // rows that may predate creator audit evidence (pre-upgrade/legacy data), so
  // they keep the administrator + site checks without the creator gate.
  if($action==='approve'||$action==='apply'){
   $creator=self::operation_creator($row);
   if(!$creator || $creator!==$user_id)return new WP_Error('oai_ai_lifecycle_operation_owner','Operation creator could not be verified for this administrator',array('status'=>403));
  }
  $site_fingerprint=self::site_fingerprint();
  $snapshot_hash=self::operation_snapshot_hash($row,$user_id,$site_fingerprint);
  $status=(string)($row['status']??'');
  // Let OAI_REST::sandbox_action keep its public state errors (oai_state) for
  // operations that are not actionable. The snapshot gate applies only when
  // the lifecycle call would actually write.
  $snapshot_gate=($action==='apply' && $status==='approved')
   || ($action==='rollback' && $status==='applied');
  if($snapshot_gate){
   $approval=self::approved_snapshot_matches($row,$user_id,$site_fingerprint,$snapshot_hash);
   // Apply requires an AI-approved snapshot. Rollback stays available for
   // operations approved by a human before AI Lifecycle Control existed, but
   // must fail closed when an AI approval exists and no longer matches.
   if($approval===false || ($action==='apply' && $approval===null)){
    return new WP_Error('oai_ai_lifecycle_approval_mismatch','Approved operation snapshot or administrator context has changed; create and approve a fresh operation',array('status'=>409));
   }
  }
  return array('row'=>$row,'snapshot_sha256'=>$snapshot_hash,'site_fingerprint'=>$site_fingerprint,'principal_user_id'=>$user_id);
 }

 public static function perform($id,$action,$client_id,$user_id){
  $auth=self::authorize($id,$action,$client_id,$user_id);
  if(is_wp_error($auth))return $auth;
  $row=$auth['row'];
  self::$request_context=array(
   'id'=>absint($id),
   'action'=>(string)$action,
   'principal_user_id'=>absint($auth['principal_user_id']),
   'site_fingerprint'=>(string)$auth['site_fingerprint'],
   'authorized'=>true,
  );
  try{
   $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.absint($id).'/'.$action);
   $req->set_url_params(array('id'=>absint($id),'action'=>$action));
   $result=OAI_REST::sandbox_action($req);
   if(!is_wp_error($result)){
    $updated=OAI_DB::get(absint($id));
    OAI_Audit::log('ai_lifecycle_'.$action,$updated?:$row,array(
     'client_id_hash'=>hash('sha256',(string)$client_id),
     'principal_user_id'=>absint($user_id),
     'site_fingerprint'=>(string)$auth['site_fingerprint'],
     'snapshot_sha256'=>(string)$auth['snapshot_sha256'],
    ));
   }
   return $result;
  }finally{
   self::$request_context=null;
  }
 }
}
