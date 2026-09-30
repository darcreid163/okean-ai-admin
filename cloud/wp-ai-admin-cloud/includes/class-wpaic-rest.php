<?php
if (!defined('ABSPATH')) exit;

class WPAIC_REST {
 const NS='wp-ai-admin-cloud/v1';
 const PAIR_TTL=600;

 public static function register_routes(){
  register_rest_route(self::NS,'/health',array(
   'methods'=>'GET','callback'=>array(__CLASS__,'health'),'permission_callback'=>'__return_true'
  ));
  register_rest_route(self::NS,'/pairings/start',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'pairing_start'),'permission_callback'=>'__return_true'
  ));
  register_rest_route(self::NS,'/pairings/claim',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'pairing_claim'),'permission_callback'=>function(){return is_user_logged_in();}
  ));
  register_rest_route(self::NS,'/pairings/exchange',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'pairing_exchange'),'permission_callback'=>'__return_true'
  ));
  register_rest_route(self::NS,'/sites',array(
   'methods'=>'GET','callback'=>array(__CLASS__,'sites_list'),'permission_callback'=>function(){return is_user_logged_in();}
  ));
  register_rest_route(self::NS,'/sites/ping',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'site_ping'),'permission_callback'=>'__return_true'
  ));
  register_rest_route(self::NS,'/sites/self-revoke',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'site_self_revoke'),'permission_callback'=>'__return_true'
  ));
  register_rest_route(self::NS,'/sites/(?P<site_id>[A-Za-z0-9_-]{8,64})/revoke',array(
   'methods'=>'POST','callback'=>array(__CLASS__,'site_revoke'),'permission_callback'=>function(){return is_user_logged_in();}
  ));
 }

 private static function now(){return current_time('mysql',true);}
 private static function random_id($prefix,$bytes=18){
  try{$raw=random_bytes($bytes);}catch(Throwable $e){$raw=wp_generate_password($bytes*2,true,true);}
  if(is_string($raw) && strlen($raw)===$bytes)$token=rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
  else $token=rtrim(strtr(base64_encode(hash('sha256',(string)$raw,true)),'+/','-_'),'=');
  return $prefix.$token;
 }
 private static function secret_hash($value){return hash_hmac('sha256',(string)$value,wp_salt('auth'));}
 private static function json_error($code,$message,$status=400){
  return new WP_REST_Response(array('ok'=>false,'error'=>$code,'message'=>$message),$status);
 }
 private static function clean_site_identity($data){
  $site_id=sanitize_text_field((string)($data['site_id']??''));
  $installation_id=sanitize_text_field((string)($data['installation_id']??''));
  $domain=strtolower(sanitize_text_field((string)($data['domain']??'')));
  $plugin_version=sanitize_text_field((string)($data['plugin_version']??''));
  $catalog=sanitize_text_field((string)($data['catalog_sha256']??''));
  if(!preg_match('/^[A-Za-z0-9_-]{8,64}$/',$site_id))return new WP_Error('invalid_site_id','Invalid site_id');
  if(!preg_match('/^[A-Za-z0-9_-]{8,128}$/',$installation_id))return new WP_Error('invalid_installation_id','Invalid installation_id');
  if($domain===''||strlen($domain)>190||!preg_match('/^[A-Za-z0-9.-]+$/',$domain))return new WP_Error('invalid_domain','Invalid domain');
  if($catalog!==''&&!preg_match('/^[a-f0-9]{64}$/i',$catalog))return new WP_Error('invalid_catalog','Invalid catalog hash');
  return array('site_id'=>$site_id,'installation_id'=>$installation_id,'domain'=>$domain,'plugin_version'=>$plugin_version,'catalog_sha256'=>strtolower($catalog));
 }
 private static function rate_limit($bucket,$limit=20,$window=60){
  $ip=sanitize_text_field((string)($_SERVER['REMOTE_ADDR']??'unknown'));
  $key='wpaic_rl_'.hash('sha256',$bucket.'|'.$ip);
  $state=get_transient($key);if(!is_array($state))$state=array('count'=>0);
  $state['count']=(int)($state['count']??0)+1;
  set_transient($key,$state,$window);
  return $state['count']<=$limit;
 }
 private static function pairing_row($pairing_id){
  global $wpdb;
  return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::pairings_table().' WHERE pairing_id=%s',$pairing_id),ARRAY_A);
 }
 private static function workspace_for_user($user_id,$workspace_id=''){
  global $wpdb;
  $user_id=absint($user_id);
  if($workspace_id!==''){
   $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::workspaces_table().' WHERE workspace_id=%s AND owner_user_id=%d AND status=%s',$workspace_id,$user_id,'active'),ARRAY_A);
   if($row)return $row;
   return null;
  }
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::workspaces_table().' WHERE owner_user_id=%d AND status=%s ORDER BY id ASC LIMIT 1',$user_id,'active'),ARRAY_A);
  if($row)return $row;
  $id=self::random_id('ws_',16);$now=self::now();
  $ok=$wpdb->insert(WPAIC_DB::workspaces_table(),array(
   'workspace_id'=>$id,'owner_user_id'=>$user_id,'name'=>'My Sites','status'=>'active','created_at'=>$now,'updated_at'=>$now
  ));
  if(!$ok)return null;
  return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::workspaces_table().' WHERE workspace_id=%s',$id),ARRAY_A);
 }

 public static function health(){
  return array('ok'=>true,'service'=>'wp-ai-admin-cloud','version'=>WPAIC_VERSION,'contract'=>'control-plane-foundation-v1');
 }

 public static function pairing_start($req){
  if(!self::rate_limit('pairing_start',15,60))return self::json_error('rate_limited','Too many pairing attempts',429);
  $data=$req->get_json_params();if(!is_array($data))return self::json_error('invalid_json','JSON object required');
  $site=self::clean_site_identity($data);if(is_wp_error($site))return self::json_error($site->get_error_code(),$site->get_error_message(),400);
  global $wpdb;
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::sites_table().' WHERE site_id=%s OR installation_id=%s',$site['site_id'],$site['installation_id']),ARRAY_A);
  if($existing && (
   !hash_equals((string)$existing['site_id'],$site['site_id'])
   || !hash_equals((string)$existing['installation_id'],$site['installation_id'])
  ))return self::json_error('identity_conflict','Site or installation identity is already bound differently',409);
  if($existing && in_array((string)$existing['status'],array('active','frozen'),true))return self::json_error('already_registered','Site is already registered',409);

  $pairing_id=self::random_id('pair_',18);
  $exchange_secret=self::random_id('ex_',32);
  $claim_code=strtoupper(substr(hash('sha256',self::random_id('claim_',24)),0,12));
  $registration_id=$existing?(string)$existing['registration_id']:self::random_id('reg_',18);
  $now=self::now();$expires=gmdate('Y-m-d H:i:s',time()+self::PAIR_TTL);

  if(!$existing){
   $wpdb->insert(WPAIC_DB::sites_table(),array(
    'site_id'=>$site['site_id'],'installation_id'=>$site['installation_id'],'registration_id'=>$registration_id,
    'workspace_id'=>null,'domain'=>$site['domain'],'display_name'=>$site['domain'],'plugin_version'=>$site['plugin_version'],
    'catalog_sha256'=>$site['catalog_sha256']?:null,'status'=>'unclaimed','credential_hash'=>null,
    'created_at'=>$now,'updated_at'=>$now,'last_seen_at'=>null,'revoked_at'=>null
   ));
  }else{
   $wpdb->update(WPAIC_DB::sites_table(),array(
    'domain'=>$site['domain'],'plugin_version'=>$site['plugin_version'],'catalog_sha256'=>$site['catalog_sha256']?:null,
    'status'=>'unclaimed','updated_at'=>$now,'revoked_at'=>null
   ),array('id'=>(int)$existing['id']));
  }

  $ok=$wpdb->insert(WPAIC_DB::pairings_table(),array(
   'pairing_id'=>$pairing_id,'site_id'=>$site['site_id'],'installation_id'=>$site['installation_id'],
   'exchange_hash'=>self::secret_hash($exchange_secret),'claim_code_hash'=>self::secret_hash($claim_code),
   'workspace_id'=>null,'status'=>'pending','created_at'=>$now,'expires_at'=>$expires,
   'claimed_at'=>null,'claimed_by_user_id'=>null,'exchanged_at'=>null
  ));
  if(!$ok)return self::json_error('pairing_create_failed','Could not create pairing',500);

  return new WP_REST_Response(array(
   'ok'=>true,'pairing_id'=>$pairing_id,'claim_code'=>$claim_code,'exchange_secret'=>$exchange_secret,
   'expires_at'=>$expires,'claim_path'=>'/wp-admin/admin.php?page=wp-ai-admin-cloud-pair&pairing_id='.rawurlencode($pairing_id)
  ),201);
 }

 public static function pairing_claim($req){
  $user_id=get_current_user_id();if(!$user_id)return self::json_error('login_required','Login required',401);
  $data=$req->get_json_params();if(!is_array($data))return self::json_error('invalid_json','JSON object required');
  $pairing_id=sanitize_text_field((string)($data['pairing_id']??''));
  $claim_code=strtoupper(sanitize_text_field((string)($data['claim_code']??'')));
  $workspace_id=sanitize_text_field((string)($data['workspace_id']??''));
  $row=self::pairing_row($pairing_id);
  if(!$row)return self::json_error('pairing_not_found','Pairing not found',404);
  if((string)$row['status']!=='pending')return self::json_error('pairing_state','Pairing is not pending',409);
  if(strtotime((string)$row['expires_at'])<=time())return self::json_error('pairing_expired','Pairing expired',410);
  if(!hash_equals((string)$row['claim_code_hash'],self::secret_hash($claim_code)))return self::json_error('claim_code','Invalid claim code',403);

  $workspace=self::workspace_for_user($user_id,$workspace_id);
  if(!$workspace)return self::json_error('workspace','Workspace not found or not owned by current user',403);
  global $wpdb;$now=self::now();
  $wpdb->update(WPAIC_DB::pairings_table(),array(
   'workspace_id'=>$workspace['workspace_id'],'status'=>'claimed','claimed_at'=>$now,'claimed_by_user_id'=>$user_id
  ),array('id'=>(int)$row['id']));
  $wpdb->update(WPAIC_DB::sites_table(),array(
   'workspace_id'=>$workspace['workspace_id'],'status'=>'claiming','updated_at'=>$now
  ),array('site_id'=>$row['site_id'],'installation_id'=>$row['installation_id']));

  return array('ok'=>true,'pairing_id'=>$pairing_id,'workspace_id'=>$workspace['workspace_id'],'status'=>'claimed');
 }

 public static function pairing_exchange($req){
  if(!self::rate_limit('pairing_exchange',30,60))return self::json_error('rate_limited','Too many exchange attempts',429);
  $data=$req->get_json_params();if(!is_array($data))return self::json_error('invalid_json','JSON object required');
  $pairing_id=sanitize_text_field((string)($data['pairing_id']??''));
  $exchange_secret=sanitize_text_field((string)($data['exchange_secret']??''));
  $row=self::pairing_row($pairing_id);
  if(!$row)return self::json_error('pairing_not_found','Pairing not found',404);
  if((string)$row['status']!=='claimed')return self::json_error('pairing_state','Pairing is not ready for exchange',409);
  if(strtotime((string)$row['expires_at'])<=time())return self::json_error('pairing_expired','Pairing expired',410);
  if(!hash_equals((string)$row['exchange_hash'],self::secret_hash($exchange_secret)))return self::json_error('exchange_secret','Invalid exchange secret',403);

  $site_token=self::random_id('site_',32);$now=self::now();global $wpdb;
  $site=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::sites_table().' WHERE site_id=%s AND installation_id=%s',$row['site_id'],$row['installation_id']),ARRAY_A);
  if(!$site)return self::json_error('site_not_found','Site registration not found',404);
  $wpdb->update(WPAIC_DB::sites_table(),array(
   'workspace_id'=>$row['workspace_id'],'status'=>'active','credential_hash'=>self::secret_hash($site_token),
   'updated_at'=>$now,'last_seen_at'=>$now,'revoked_at'=>null
  ),array('id'=>(int)$site['id']));
  $wpdb->update(WPAIC_DB::pairings_table(),array('status'=>'exchanged','exchanged_at'=>$now),array('id'=>(int)$row['id']));

  return array(
   'ok'=>true,'site_id'=>$row['site_id'],'installation_id'=>$row['installation_id'],
   'registration_id'=>$site['registration_id'],'workspace_id'=>$row['workspace_id'],
   'site_token'=>$site_token,'status'=>'active'
  );
 }

 private static function bearer_token($req){
  $header=(string)$req->get_header('authorization');
  if(!preg_match('/^Bearer\s+([^\s]+)$/i',$header,$m))return '';
  return trim((string)$m[1]);
 }

 public static function site_ping($req){
  if(!self::rate_limit('site_ping',120,60))return self::json_error('rate_limited','Too many site requests',429);
  $data=$req->get_json_params();if(!is_array($data))return self::json_error('invalid_json','JSON object required');
  $site_id=sanitize_text_field((string)($data['site_id']??''));
  $installation_id=sanitize_text_field((string)($data['installation_id']??''));
  $token=self::bearer_token($req);
  if($site_id===''||$installation_id===''||$token==='')return self::json_error('authentication','Site identity and bearer token required',401);

  global $wpdb;
  $site=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::sites_table().' WHERE site_id=%s AND installation_id=%s',$site_id,$installation_id),ARRAY_A);
  if(!$site||empty($site['credential_hash']))return self::json_error('authentication','Unknown site credential',401);
  if(!hash_equals((string)$site['credential_hash'],self::secret_hash($token)))return self::json_error('authentication','Invalid site credential',401);
  if((string)$site['status']==='revoked')return self::json_error('revoked','Site is revoked',403);
  if((string)$site['status']==='frozen')return self::json_error('frozen','Site is frozen',423);
  if((string)$site['status']!=='active')return self::json_error('site_state','Site is not active',409);

  $plugin_version=sanitize_text_field((string)($data['plugin_version']??''));
  $catalog=sanitize_text_field((string)($data['catalog_sha256']??''));
  if($catalog!==''&&!preg_match('/^[a-f0-9]{64}$/i',$catalog))return self::json_error('invalid_catalog','Invalid catalog hash',400);
  $now=self::now();
  $wpdb->update(WPAIC_DB::sites_table(),array(
   'plugin_version'=>$plugin_version?:$site['plugin_version'],
   'catalog_sha256'=>$catalog!==''?strtolower($catalog):$site['catalog_sha256'],
   'last_seen_at'=>$now,'updated_at'=>$now
  ),array('id'=>(int)$site['id']));

  return array(
   'ok'=>true,'site_id'=>$site_id,'registration_id'=>$site['registration_id'],
   'workspace_id'=>$site['workspace_id'],'status'=>'active','server_time'=>$now,
   'writes_frozen'=>false
  );
 }

 public static function site_self_revoke($req){
  if(!self::rate_limit('site_self_revoke',30,60))return self::json_error('rate_limited','Too many site requests',429);
  $data=$req->get_json_params();if(!is_array($data))return self::json_error('invalid_json','JSON object required');
  $site_id=sanitize_text_field((string)($data['site_id']??''));
  $installation_id=sanitize_text_field((string)($data['installation_id']??''));
  $token=self::bearer_token($req);
  if($site_id===''||$installation_id===''||$token==='')return self::json_error('authentication','Site identity and bearer token required',401);

  global $wpdb;
  $site=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPAIC_DB::sites_table().' WHERE site_id=%s AND installation_id=%s',$site_id,$installation_id),ARRAY_A);
  if(!$site||empty($site['credential_hash']))return self::json_error('authentication','Unknown site credential',401);
  if(!hash_equals((string)$site['credential_hash'],self::secret_hash($token)))return self::json_error('authentication','Invalid site credential',401);

  $now=self::now();
  $wpdb->update(WPAIC_DB::sites_table(),array(
   'status'=>'revoked','credential_hash'=>null,'revoked_at'=>$now,'updated_at'=>$now
  ),array('id'=>(int)$site['id']));
  return array('ok'=>true,'site_id'=>$site_id,'status'=>'revoked');
 }

 public static function sites_list(){
  $user_id=get_current_user_id();global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare(
   'SELECT s.site_id,s.registration_id,s.domain,s.display_name,s.plugin_version,s.catalog_sha256,s.status,s.last_seen_at,s.updated_at,w.workspace_id,w.name AS workspace_name
    FROM '.WPAIC_DB::sites_table().' s
    INNER JOIN '.WPAIC_DB::workspaces_table().' w ON w.workspace_id=s.workspace_id
    WHERE w.owner_user_id=%d AND w.status=%s
    ORDER BY s.domain ASC',
   $user_id,'active'
  ),ARRAY_A);
  return array('ok'=>true,'count'=>count($rows),'sites'=>$rows);
 }

 public static function site_revoke($req){
  $site_id=sanitize_text_field((string)$req['site_id']);$user_id=get_current_user_id();global $wpdb;
  $site=$wpdb->get_row($wpdb->prepare(
   'SELECT s.* FROM '.WPAIC_DB::sites_table().' s INNER JOIN '.WPAIC_DB::workspaces_table().' w ON w.workspace_id=s.workspace_id WHERE s.site_id=%s AND w.owner_user_id=%d',
   $site_id,$user_id
  ),ARRAY_A);
  if(!$site)return self::json_error('site_not_found','Site not found',404);
  $now=self::now();
  $wpdb->update(WPAIC_DB::sites_table(),array(
   'status'=>'revoked','credential_hash'=>null,'revoked_at'=>$now,'updated_at'=>$now
  ),array('id'=>(int)$site['id']));
  return array('ok'=>true,'site_id'=>$site_id,'status'=>'revoked');
 }
}
