<?php
/**
 * WP AI Admin site-side client for the central Control Plane.
 * No cloud credential is exposed through status()/manifest output.
 */
if (!defined('ABSPATH')) exit;

class OAI_Cloud_Client {
 const URL_OPTION='oai_control_plane_url';
 const TOKEN_OPTION='oai_control_plane_site_token';
 const REGISTRATION_OPTION='oai_control_plane_registration_id';
 const WORKSPACE_OPTION='oai_control_plane_workspace_id';
 const PAIRING_OPTION='oai_control_plane_pairing';
 const LAST_SEEN_OPTION='oai_control_plane_last_seen';

 public static function base_url(){
  $url=defined('OAI_CLOUD_URL')?(string)OAI_CLOUD_URL:(string)get_option(self::URL_OPTION,'');
  $url=(string)apply_filters('oai_cloud_url',$url);
  $url=untrailingslashit(esc_url_raw($url));
  if($url==='')return '';
  $parts=wp_parse_url($url);
  if(!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))return '';
  return $url;
 }

 private static function endpoint($path){
  $base=self::base_url();
  return $base===''?'':$base.'/wp-json/wp-ai-admin-cloud/v1/'.ltrim((string)$path,'/');
 }

 private static function decode_response($response){
  if(is_wp_error($response))return $response;
  $status=(int)wp_remote_retrieve_response_code($response);
  $body=(string)wp_remote_retrieve_body($response);
  $data=json_decode($body,true);
  if(!is_array($data))return new WP_Error('oai_cloud_response','Invalid Control Plane response',array('status'=>502));
  if($status<200||$status>=300||empty($data['ok'])){
   return new WP_Error(
    sanitize_key((string)($data['error']??'oai_cloud_error')),
    sanitize_text_field((string)($data['message']??'Control Plane request failed')),
    array('status'=>$status?:502)
   );
  }
  return $data;
 }

 private static function post($path,$body,$token=''){
  $url=self::endpoint($path);
  if($url==='')return new WP_Error('oai_cloud_unconfigured','WP AI Admin Cloud endpoint is not configured',array('status'=>503));
  $headers=array('Accept'=>'application/json','Content-Type'=>'application/json');
  if($token!=='')$headers['Authorization']='Bearer '.$token;
  $response=wp_safe_remote_post($url,array(
   'timeout'=>8,'redirection'=>0,'headers'=>$headers,
   'body'=>wp_json_encode($body,JSON_UNESCAPED_SLASHES)
  ));
  return self::decode_response($response);
 }

 public static function status(){
  $pairing=get_option(self::PAIRING_OPTION,array());
  if(!is_array($pairing))$pairing=array();
  $registration=(string)get_option(self::REGISTRATION_OPTION,'');
  $workspace=(string)get_option(self::WORKSPACE_OPTION,'');
  $token=(string)get_option(self::TOKEN_OPTION,'');
  $expires=(string)($pairing['expires_at']??'');
  $pending=!empty($pairing['pairing_id'])&&!empty($pairing['exchange_secret'])&&($expires===''||strtotime($expires)>time());
  return array(
   'supported'=>true,
   'configured'=>self::base_url()!=='',
   'connected'=>$registration!==''&&$workspace!==''&&$token!=='',
   'registration_id'=>$registration,
   'workspace_id'=>$workspace,
   'pairing_pending'=>$pending,
   'pairing_id'=>$pending?(string)$pairing['pairing_id']:'',
   'claim_code'=>$pending?(string)($pairing['claim_code']??''):'',
   'claim_url'=>$pending?(string)($pairing['claim_url']??''):'',
   'pairing_expires_at'=>$pending?$expires:'',
   'last_seen_at'=>(string)get_option(self::LAST_SEEN_OPTION,''),
   'site_id'=>OAI_Platform::site_id(),
   'installation_id'=>OAI_Platform::installation_id(),
  );
 }

 public static function start_pairing($actor_user_id=0){
  $actor_user_id=absint($actor_user_id?:get_current_user_id());
  if(!$actor_user_id||!user_can($actor_user_id,'manage_options'))return new WP_Error('oai_cloud_admin','Administrator capability required',array('status'=>403));
  if(self::base_url()==='')return new WP_Error('oai_cloud_unconfigured','WP AI Admin Cloud endpoint is not configured',array('status'=>503));
  $catalog=OAI_Platform::tool_catalog();
  $data=self::post('pairings/start',array(
   'site_id'=>OAI_Platform::site_id(),
   'installation_id'=>OAI_Platform::installation_id(),
   'domain'=>strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)),
   'plugin_version'=>defined('OAI_VERSION')?OAI_VERSION:'',
   'catalog_sha256'=>(string)($catalog['catalog_sha256']??''),
  ));
  if(is_wp_error($data))return $data;
  foreach(array('pairing_id','claim_code','exchange_secret','expires_at') as $field){
   if(empty($data[$field]))return new WP_Error('oai_cloud_pairing_shape','Incomplete pairing response',array('status'=>502));
  }
  $claim_path=(string)($data['claim_path']??'');
  $claim_url=$claim_path!==''?self::base_url().'/'.ltrim($claim_path,'/'):'';
  $pairing=array(
   'pairing_id'=>sanitize_text_field((string)$data['pairing_id']),
   'claim_code'=>sanitize_text_field((string)$data['claim_code']),
   'exchange_secret'=>sanitize_text_field((string)$data['exchange_secret']),
   'expires_at'=>sanitize_text_field((string)$data['expires_at']),
   'claim_url'=>esc_url_raw($claim_url),
   'created_at'=>time()
  );
  update_option(self::PAIRING_OPTION,$pairing,false);
  return self::status();
 }

 public static function complete_pairing($actor_user_id=0){
  $actor_user_id=absint($actor_user_id?:get_current_user_id());
  if(!$actor_user_id||!user_can($actor_user_id,'manage_options'))return new WP_Error('oai_cloud_admin','Administrator capability required',array('status'=>403));
  $pairing=get_option(self::PAIRING_OPTION,array());
  if(!is_array($pairing)||empty($pairing['pairing_id'])||empty($pairing['exchange_secret']))return new WP_Error('oai_cloud_pairing','No pending pairing',array('status'=>409));
  if(!empty($pairing['expires_at'])&&strtotime((string)$pairing['expires_at'])<=time())return new WP_Error('oai_cloud_pairing_expired','Pairing expired; start again',array('status'=>410));

  $data=self::post('pairings/exchange',array(
   'pairing_id'=>(string)$pairing['pairing_id'],
   'exchange_secret'=>(string)$pairing['exchange_secret']
  ));
  if(is_wp_error($data))return $data;
  foreach(array('site_token','registration_id','workspace_id') as $field){
   if(empty($data[$field]))return new WP_Error('oai_cloud_exchange_shape','Incomplete exchange response',array('status'=>502));
  }
  update_option(self::TOKEN_OPTION,(string)$data['site_token'],false);
  update_option(self::REGISTRATION_OPTION,sanitize_text_field((string)$data['registration_id']),false);
  update_option(self::WORKSPACE_OPTION,sanitize_text_field((string)$data['workspace_id']),false);
  delete_option(self::PAIRING_OPTION);
  $ping=self::ping();
  return is_wp_error($ping)?self::status():$ping;
 }

 public static function ping(){
  $token=(string)get_option(self::TOKEN_OPTION,'');
  if($token==='')return new WP_Error('oai_cloud_disconnected','Site is not connected',array('status'=>409));
  $catalog=OAI_Platform::tool_catalog();
  $data=self::post('sites/ping',array(
   'site_id'=>OAI_Platform::site_id(),
   'installation_id'=>OAI_Platform::installation_id(),
   'plugin_version'=>defined('OAI_VERSION')?OAI_VERSION:'',
   'catalog_sha256'=>(string)($catalog['catalog_sha256']??''),
  ),$token);
  if(is_wp_error($data))return $data;
  update_option(self::LAST_SEEN_OPTION,current_time('mysql',true),false);
  return self::status();
 }

 public static function disconnect($actor_user_id=0){
  $actor_user_id=absint($actor_user_id?:get_current_user_id());
  if(!$actor_user_id||!user_can($actor_user_id,'manage_options'))return new WP_Error('oai_cloud_admin','Administrator capability required',array('status'=>403));
  $token=(string)get_option(self::TOKEN_OPTION,'');
  if($token!==''){
   $data=self::post('sites/self-revoke',array(
    'site_id'=>OAI_Platform::site_id(),
    'installation_id'=>OAI_Platform::installation_id(),
   ),$token);
   if(is_wp_error($data))return $data;
  }
  delete_option(self::TOKEN_OPTION);
  delete_option(self::REGISTRATION_OPTION);
  delete_option(self::WORKSPACE_OPTION);
  delete_option(self::PAIRING_OPTION);
  delete_option(self::LAST_SEEN_OPTION);
  return self::status();
 }
}
