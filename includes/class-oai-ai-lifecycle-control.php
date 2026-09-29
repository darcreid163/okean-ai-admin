<?php
/**
 * Optional AI-controlled sandbox lifecycle.
 *
 * Disabled by default. When an administrator explicitly enables this mode,
 * one authenticated administrator MCP client may approve, reject, apply and
 * rollback normal sandbox operations. All normal lifecycle conflict, expiry,
 * verification and rollback guards remain enforced by OAI_REST::sandbox_action.
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
   'bound_client_id_hash'=>'',
   'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'',
  ));
  return self::status();
 }

 public static function disable($actor_user_id=0){
  $cfg=self::raw();
  $cfg['enabled']=false;
  $cfg['disabled_at']=time();
  $cfg['disabled_by']=absint($actor_user_id);
  self::save($cfg);
  self::$request_context=null;
  return self::status();
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
   'client_bound'=>!empty($cfg['bound_client_id_hash']),
   'rules'=>array(
    'administrator_enable_required'=>true,
    'administrator_mcp_identity_required'=>true,
    'single_mcp_client_binding'=>true,
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
   && !empty(self::$request_context['authorized']);
 }

 private static function authorize($id,$action,$client_id,$user_id){
  if(!self::is_active())return new WP_Error('oai_ai_lifecycle_inactive','AI Lifecycle Control is not active',array('status'=>403));
  if(!in_array($action,array('approve','reject','apply','rollback'),true))return new WP_Error('oai_ai_lifecycle_action','Unsupported lifecycle action',array('status'=>400));
  $user_id=absint($user_id);
  if(!$user_id || !user_can($user_id,'manage_options'))return new WP_Error('oai_ai_lifecycle_admin','Administrator MCP identity required',array('status'=>403));
  $cfg=self::raw();
  if($user_id!==absint($cfg['owner_user_id']??0))return new WP_Error('oai_ai_lifecycle_owner','AI Lifecycle Control belongs to a different administrator',array('status'=>403));
  $client_hash=hash('sha256',(string)$client_id);
  if(empty($cfg['bound_client_id_hash'])){
   $cfg['bound_client_id_hash']=$client_hash;
   self::save($cfg);
  }elseif(!hash_equals((string)$cfg['bound_client_id_hash'],$client_hash)){
   return new WP_Error('oai_ai_lifecycle_client','AI Lifecycle Control is bound to a different MCP client',array('status'=>403));
  }
  $row=OAI_DB::get(absint($id));
  if(!$row)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  return $row;
 }

 public static function perform($id,$action,$client_id,$user_id){
  $auth=self::authorize($id,$action,$client_id,$user_id);
  if(is_wp_error($auth))return $auth;
  self::$request_context=array('id'=>absint($id),'action'=>(string)$action,'authorized'=>true);
  try{
   $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.absint($id).'/'.$action);
   $req->set_url_params(array('id'=>absint($id),'action'=>$action));
   $result=OAI_REST::sandbox_action($req);
   if(!is_wp_error($result)){
    $row=OAI_DB::get(absint($id));
    OAI_Audit::log('ai_lifecycle_'.$action,$row,array('client_id_hash'=>hash('sha256',(string)$client_id),'user_id'=>absint($user_id)));
   }
   return $result;
  }finally{
   self::$request_context=null;
  }
 }
}
