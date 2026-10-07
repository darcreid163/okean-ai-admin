<?php
/** Browser-only onboarding for the WP AI Admin central gateway. */
if(!defined('ABSPATH'))exit;
class OAI_Gateway_Pairing {
 const GATEWAY='https://wpaiadmin.ru';
 const CALLBACK='https://wpaiadmin.ru/wpai-cloud/pair/callback';
 const CREDENTIAL_PREFIX='oai_pair_credential_';
 public static function boot(){
  add_action('admin_post_oai_gateway_pair',array(__CLASS__,'authorize'));
  add_action('admin_post_nopriv_oai_gateway_pair',array(__CLASS__,'authorize'));
  add_action('rest_api_init',array(__CLASS__,'routes'));
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('oai_gateway_pair_cleanup',array(__CLASS__,'cleanup'),10,1);
 }
 public static function routes(){
  register_rest_route('okean-ai/v1','/pairing/capabilities',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>function(){return new WP_REST_Response(array('protocol'=>'wpai-browser-pairing-v1','plugin_version'=>OAI_VERSION,'site_url'=>untrailingslashit(home_url('/'))),200,array('Cache-Control'=>'no-store'));}));
  register_rest_route('okean-ai/v1','/pairing/exchange',array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'exchange_route')));
 }
 private static function fail($code,$status=400){return new WP_Error($code,'Не удалось завершить подключение. Начните подключение заново в кабинете WP AI Admin.',array('status'=>$status));}
 private static function valid($value){return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{43}$/D',$value);}
 private static function b64($bytes){return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
 public static function request($state,$now){
  if(!self::valid($state))return self::fail('oai_pair_state');
  $response=wp_remote_get(self::GATEWAY.'/wp-json/wpai-cloud/v1/pairing/request?state='.rawurlencode($state),array('timeout'=>10,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>8192,'headers'=>array('Accept'=>'application/json')));
  if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return self::fail('oai_pair_request_unavailable',502);
  $r=json_decode(wp_remote_retrieve_body($response),true);
  if(!is_array($r)||($r['protocol']??'')!=='wpai-browser-pairing-v1'||($r['state']??'')!==$state||($r['site_url']??'')!==untrailingslashit(home_url('/'))||($r['gateway']??'')!==self::GATEWAY||($r['callback']??'')!==self::CALLBACK||!self::valid($r['challenge']??null)||!is_string($r['account_id']??null)||!preg_match('/^[1-9][0-9]{0,19}$/D',$r['account_id'])||!is_string($r['account_label']??null)||strlen($r['account_label'])>400||!is_int($r['expires']??null)||$r['expires']<=$now||$r['expires']>$now+600)return self::fail('oai_pair_request_invalid');
  return $r;
 }
 public static function issue_code($r,$now){
  if(!is_ssl()||!current_user_can('manage_options'))return self::fail('oai_pair_admin_required',403);
  if(!is_array($r)||($r['expires']??0)<=$now||($r['callback']??'')!==self::CALLBACK||!self::valid($r['state']??null)||!self::valid($r['challenge']??null))return self::fail('oai_pair_request_invalid');
  // First-time free local access is initialized only after explicit administrator consent.
  // Existing subscription/paused/expired license records are never overwritten.
  if(!get_option('oai_license',array())){
   $license=OAI_License::activate_local_owner(get_current_user_id());if(is_wp_error($license))return $license;
  }
  $license=OAI_License::can('mcp');if(is_wp_error($license))return $license;
  $code=self::b64(random_bytes(32));$key='oai_pair_code_'.hash('sha256',$code);
  $record=array('user_id'=>get_current_user_id(),'state'=>$r['state'],'challenge'=>$r['challenge'],'account_id'=>$r['account_id'],'account_label'=>$r['account_label'],'expires'=>min($r['expires'],$now+120));
  if(!add_option($key,$record,'',false))return self::fail('oai_pair_conflict',409);
  wp_schedule_single_event($record['expires'],'oai_gateway_pair_cleanup',array($key));
  update_option('oai_mcp_enabled',true,false);
  return $code;
 }
 public static function exchange($p,$now){
  if(!is_ssl())return self::fail('oai_pair_https_required',403);
  if(!is_array($p)||array_diff(array_keys($p),array('code','state','verifier','callback'))||!self::valid($p['code']??null)||!self::valid($p['state']??null)||!self::valid($p['verifier']??null)||($p['callback']??'')!==self::CALLBACK)return self::fail('oai_pair_exchange_invalid');
  $key='oai_pair_code_'.hash('sha256',$p['code']);$r=get_option($key,null);
  if(!is_array($r)||($r['expires']??0)<=$now||!hash_equals($r['state'],$p['state'])||!hash_equals($r['challenge'],self::b64(hash('sha256',$p['verifier'],true))))return self::fail('oai_pair_code_invalid',401);
  $user=get_user_by('id',$r['user_id']);
  if(!$user||!user_can($user,'manage_options'))return self::fail('oai_pair_admin_revoked',403);
  if(!delete_option($key))return self::fail('oai_pair_code_used',409);
  $token='oai_pair_'.self::b64(random_bytes(32));$hash=hash('sha256',$token);
  $record=array('user_id'=>$r['user_id'],'account_id'=>$r['account_id'],'account_label'=>$r['account_label'],'created_at'=>$now);
  if(!add_option(self::CREDENTIAL_PREFIX.$hash,$record,'',false))return self::fail('oai_pair_conflict',409);
  return array('token'=>$token,'site_url'=>untrailingslashit(home_url('/')));
 }
 public static function exchange_route($req){
  if(strlen($req->get_body())>2048)return self::fail('oai_pair_body_too_large',413);
  if(stripos((string)$req->get_header('content-type'),'application/json')!==0)return self::fail('oai_pair_json_required',415);
  $value=self::exchange($req->get_json_params(),time());
  if(is_wp_error($value))return $value;
  return new WP_REST_Response($value,200,array('Cache-Control'=>'no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff'));
 }
 public static function authenticate($token,$now=null){
  if(!is_string($token)||!preg_match('/^oai_pair_[A-Za-z0-9_-]{43}$/D',$token))return false;
  $now=$now===null?time():$now;$hash=hash('sha256',$token);$key=self::CREDENTIAL_PREFIX.$hash;$r=get_option($key,null);
  if(!is_array($r))return false;
  $user=get_user_by('id',$r['user_id']??0);if(!$user||!user_can($user,'manage_options'))return false;
  return array('user'=>$user,'client_id'=>'gateway_'.substr($hash,0,32),'scope'=>'mcp:read mcp:write');
 }
 public static function revoke($hash){
  if(!current_user_can('manage_options')||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::fail('oai_pair_revoke_denied',403);
  $key=self::CREDENTIAL_PREFIX.$hash;$r=get_option($key,null);
  if(!is_array($r)||(int)$r['user_id']!==get_current_user_id())return self::fail('oai_pair_revoke_denied',403);
  delete_option($key);return true;
 }
 public static function cleanup($key){
  if(!is_string($key)||!preg_match('/^oai_pair_code_[a-f0-9]{64}$/D',$key))return;
  $r=get_option($key,null);if(!is_array($r))return;
  if(($r['expires']??0)<=time())delete_option($key);
 }
 public static function authorize(){
  if(!is_ssl()){wp_die('Для подключения нужен HTTPS.','',array('response'=>403));return;}
  $state=wp_unslash($_REQUEST['state']??'');if(!self::valid($state)){wp_die('Ссылка подключения недействительна.','',array('response'=>400));return;}
  if(!is_user_logged_in()){wp_safe_redirect(wp_login_url(add_query_arg(array('action'=>'oai_gateway_pair','state'=>$state),admin_url('admin-post.php')),false));exit;}
  if(!current_user_can('manage_options')){wp_die('Войдите как администратор этого сайта.','',array('response'=>403));return;}
  $r=self::request($state,time());if(is_wp_error($r)){wp_die(esc_html($r->get_error_message()),'',array('response'=>400));return;}
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   check_admin_referer('oai_gateway_pair_'.$state,'oai_pair_nonce');
   $decision=$_POST['decision']??'';
   if($decision==='deny')$query=array('state'=>$state,'error'=>'access_denied');
   elseif($decision==='allow'){$code=self::issue_code($r,time());if(is_wp_error($code)){wp_die(esc_html($code->get_error_message()),'',array('response'=>400));return;}$query=array('state'=>$state,'code'=>$code);}
   else{wp_die('Подтвердите или отмените подключение.','',array('response'=>400));return;}
   // Only the fixed trusted central callback receives a code, never a credential.
   wp_redirect(add_query_arg($query,self::CALLBACK),303,'WP AI Admin');exit;
  }
  nocache_headers();header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
  echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подключение к WP AI Admin</title><style>body{font:16px system-ui;max-width:640px;margin:50px auto;padding:24px;background:#f5f7fc;color:#14234a}main{padding:28px;background:white;border-radius:18px}button{padding:12px;margin:8px;font:inherit}</style><main><h1>Подключить этот сайт?</h1><p><strong>'.esc_html(home_url('/')).'</strong></p><p>Сайт будет доступен в кабинете <strong>'.esc_html($r['account_label']).'</strong> на <strong>wpaiadmin.ru</strong>.</p><p>ИИ сможет читать сайт и готовить изменения. Применение изменений сохраняет проверки и подтверждение WP AI Admin. Доступ можно отозвать в разделе «Подключения к gateway».</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="oai_gateway_pair"><input type="hidden" name="state" value="'.esc_attr($state).'">';wp_nonce_field('oai_gateway_pair_'.$state,'oai_pair_nonce');
  echo '<button name="decision" value="allow">Подключить сайт</button><button name="decision" value="deny">Отмена</button></form></main></html>';exit;
 }
 public static function menu(){add_submenu_page('okean-ai-admin','Подключения к gateway','Подключения к gateway','manage_options','oai-gateway-connections',array(__CLASS__,'page'));}
 public static function page(){
  if(!current_user_can('manage_options'))return;
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   $hash=wp_unslash($_POST['credential_hash']??'');if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return;
   check_admin_referer('oai_gateway_revoke_'.$hash,'oai_pair_nonce');$result=self::revoke($hash);
   echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Доступ отозван.').'</p></div>';
  }
  echo '<div class="wrap"><h1>Подключения к gateway</h1><p>Начните подключение в <a href="https://wpaiadmin.ru/wpai-cloud/connect">кабинете WP AI Admin</a>.</p>';
  global $wpdb;$keys=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",$wpdb->esc_like(self::CREDENTIAL_PREFIX).'%'));
  $count=0;foreach($keys as $key){$r=get_option($key,null);if(!is_array($r)||(int)$r['user_id']!==get_current_user_id())continue;$hash=substr($key,strlen(self::CREDENTIAL_PREFIX));$count++;
   echo '<p>Кабинет: <strong>'.esc_html($r['account_label']).'</strong> · '.esc_html(gmdate('Y-m-d H:i',$r['created_at'])).' UTC</p><form method="post">';wp_nonce_field('oai_gateway_revoke_'.$hash,'oai_pair_nonce');echo '<input type="hidden" name="credential_hash" value="'.esc_attr($hash).'"><button class="button" type="submit">Отозвать доступ</button></form>';
  }if(!$count)echo '<p>Подключений пока нет.</p>';echo '</div>';
 }
}
