<?php
/**
 * Okean AI Admin OAuth 2.1 / PKCE authorization server.
 */
if (!defined('ABSPATH')) exit;

class OAI_MCP_OAuth {
 public static function clients_table(){global $wpdb; return $wpdb->prefix.'oai_oauth_clients';}
 public static function codes_table(){global $wpdb; return $wpdb->prefix.'oai_oauth_codes';}
 public static function tokens_table(){global $wpdb; return $wpdb->prefix.'oai_oauth_tokens';}

 public static function install(){
  global $wpdb;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $charset=$wpdb->get_charset_collate();

  $sql1="CREATE TABLE ".self::clients_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   client_id varchar(128) NOT NULL,
   client_name varchar(190) NULL,
   redirect_uris longtext NOT NULL,
   created_at datetime NOT NULL,
   PRIMARY KEY (id),
   UNIQUE KEY client_id (client_id)
  ) $charset;";
  $sql2="CREATE TABLE ".self::codes_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   code_hash char(64) NOT NULL,
   client_id varchar(128) NOT NULL,
   user_id bigint(20) unsigned NOT NULL,
   redirect_uri text NOT NULL,
   scope varchar(255) NOT NULL,
   resource text NULL,
   code_challenge varchar(255) NOT NULL,
   created_at datetime NOT NULL,
   expires_at datetime NOT NULL,
   used tinyint(1) NOT NULL DEFAULT 0,
   PRIMARY KEY (id),
   UNIQUE KEY code_hash (code_hash),
   KEY client_id (client_id),
   KEY expires_at (expires_at)
  ) $charset;";
  $sql3="CREATE TABLE ".self::tokens_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   access_hash char(64) NOT NULL,
   refresh_hash char(64) NULL,
   client_id varchar(128) NOT NULL,
   user_id bigint(20) unsigned NOT NULL,
   scope varchar(255) NOT NULL,
   resource text NULL,
   created_at datetime NOT NULL,
   expires_at datetime NOT NULL,
   refresh_expires_at datetime NULL,
   revoked tinyint(1) NOT NULL DEFAULT 0,
   PRIMARY KEY (id),
   UNIQUE KEY access_hash (access_hash),
   KEY refresh_hash (refresh_hash),
   KEY client_id (client_id),
   KEY user_id (user_id),
   KEY expires_at (expires_at)
  ) $charset;";
  dbDelta($sql1);
  dbDelta($sql2);
  dbDelta($sql3);
 }

 public static function register_routes(){
  register_rest_route('okean-ai/v1','/oauth/register',array(
   'methods'=>'POST',
   'callback'=>array(__CLASS__,'register_client'),
   'permission_callback'=>'__return_true'
  ));
  register_rest_route('okean-ai/v1','/oauth/authorize',array(
   'methods'=>'GET',
   'callback'=>array(__CLASS__,'authorize'),
   'permission_callback'=>'__return_true'
  ));
  register_rest_route('okean-ai/v1','/oauth/authorize',array(
   'methods'=>'POST',
   'callback'=>array(__CLASS__,'authorize'),
   'permission_callback'=>'__return_true'
  ));
  register_rest_route('okean-ai/v1','/oauth/login',array(
   'methods'=>'GET',
   'callback'=>array(__CLASS__,'login_bridge'),
   'permission_callback'=>'__return_true'
  ));
  register_rest_route('okean-ai/v1','/oauth/token',array(
   'methods'=>'POST',
   'callback'=>array(__CLASS__,'token'),
   'permission_callback'=>'__return_true'
  ));
 }

 public static function init_hooks(){
  add_action('template_redirect',array(__CLASS__,'well_known'));
  add_filter('login_redirect',array(__CLASS__,'login_redirect'),9999,3);
  add_action('admin_post_oai_oauth_authorize',array(__CLASS__,'admin_post_authorize'));
  add_action('admin_post_nopriv_oai_oauth_authorize',array(__CLASS__,'admin_post_authorize'));
 }

 public static function well_known(){
  $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  if($path!=='/.well-known/oauth-protected-resource' && $path!=='/.well-known/oauth-authorization-server') return;
  status_header(200);
  nocache_headers();
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  if($path==='/.well-known/oauth-protected-resource'){
   echo wp_json_encode(array(
    'resource'=>rest_url('okean-ai/mcp'),
    'authorization_servers'=>array(home_url('/')),
    'scopes_supported'=>array('mcp:read','mcp:write'),
    'bearer_methods_supported'=>array('header')
   ),JSON_UNESCAPED_SLASHES);
   exit;
  }
  echo wp_json_encode(array(
   'issuer'=>home_url('/'),
   'authorization_endpoint'=>rest_url('okean-ai/v1/oauth/authorize'),
   'token_endpoint'=>rest_url('okean-ai/v1/oauth/token'),
   'registration_endpoint'=>rest_url('okean-ai/v1/oauth/register'),
   'response_types_supported'=>array('code'),
   'grant_types_supported'=>array('authorization_code','refresh_token'),
   'code_challenge_methods_supported'=>array('S256'),
   'token_endpoint_auth_methods_supported'=>array('none'),
   'scopes_supported'=>array('mcp:read','mcp:write')
  ),JSON_UNESCAPED_SLASHES);
  exit;
 }

 private static function json_error($code,$message,$status=400){
  return new WP_REST_Response(array('error'=>$code,'error_description'=>$message),$status);
 }

 private static function client($client_id){
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::clients_table().' WHERE client_id=%s',$client_id),ARRAY_A);
  if(!$row) return null;
  $row['redirect_uris']=json_decode((string)$row['redirect_uris'],true);
  return $row;
 }

 private static function exact_redirect($client,$redirect_uri){
  return $client && is_array($client['redirect_uris']) && in_array($redirect_uri,$client['redirect_uris'],true);
 }

 private static function browser_user(){
  $user=wp_get_current_user();
  if($user instanceof WP_User && $user->exists()) return $user;

  $cookies=array();
  if(defined('LOGGED_IN_COOKIE')) $cookies[]='logged_in';
  if(defined('SECURE_AUTH_COOKIE')) $cookies[]='secure_auth';
  if(defined('AUTH_COOKIE')) $cookies[]='auth';

  foreach($cookies as $scheme){
   $constant=$scheme==='logged_in'?'LOGGED_IN_COOKIE':($scheme==='secure_auth'?'SECURE_AUTH_COOKIE':'AUTH_COOKIE');
   if(!defined($constant) || empty($_COOKIE[constant($constant)])) continue;
   $uid=wp_validate_auth_cookie(wp_unslash($_COOKIE[constant($constant)]),$scheme);
   if($uid){
    wp_set_current_user($uid);
    $user=wp_get_current_user();
    if($user instanceof WP_User && $user->exists()) return $user;
   }
  }
  return null;
 }

 private static function login_continuation_key($continuation){
  return 'oai_oauth_login_'.hash('sha256',(string)$continuation);
 }

 private static function login_continuation_valid($continuation){
  return is_string($continuation)
   && preg_match('/^[A-Za-z0-9_-]{32,128}$/',$continuation)
   && is_array(get_transient(self::login_continuation_key($continuation)));
 }

 private static function store_login_continuation($params){
  $continuation='oai_continue_'.wp_generate_password(48,false,false);
  if(!set_transient(self::login_continuation_key($continuation),$params,10*MINUTE_IN_SECONDS)){
   return false;
  }
  return $continuation;
 }

 public static function login_redirect($redirect_to,$requested_redirect_to,$user){
  if(!is_string($requested_redirect_to)) return $redirect_to;
  $path=parse_url($requested_redirect_to,PHP_URL_PATH);
  $bridge_path=parse_url(rest_url('okean-ai/v1/oauth/login'),PHP_URL_PATH);
  $host=strtolower((string)parse_url($requested_redirect_to,PHP_URL_HOST));
  $site_host=strtolower((string)parse_url(home_url('/'),PHP_URL_HOST));
  if(!$path || $path!==$bridge_path || !$host || $host!==$site_host) return $redirect_to;
  parse_str((string)parse_url($requested_redirect_to,PHP_URL_QUERY),$query);
  $continuation=(string)($query['continuation']??'');
  if(!self::login_continuation_valid($continuation)) return $redirect_to;
  return $requested_redirect_to;
 }

 public static function register_client($req){
  $data=$req->get_json_params();
  if(!is_array($data)) return self::json_error('invalid_client_metadata','JSON object required');
  $name=sanitize_text_field((string)($data['client_name']??'MCP client'));
  $uris=isset($data['redirect_uris']) && is_array($data['redirect_uris'])?$data['redirect_uris']:array();
  if(empty($uris)) return self::json_error('invalid_redirect_uri','redirect_uris is required');
  $clean=array();
  foreach($uris as $uri){
   $uri=esc_url_raw((string)$uri);
   if(!$uri) return self::json_error('invalid_redirect_uri','Every redirect URI must be an absolute URI');
   $parts=wp_parse_url($uri);
   if(!$parts || empty($parts['scheme']) || empty($parts['host'])) return self::json_error('invalid_redirect_uri','Every redirect URI must include scheme and host');
   if($parts['scheme']==='http' && !in_array(strtolower((string)$parts['host']),array('localhost','127.0.0.1','[::1]'),true)) return self::json_error('invalid_redirect_uri','HTTP redirect URIs are allowed only for localhost');
   $clean[]=$uri;
  }
  $client_id='oai_client_'.wp_generate_uuid4();
  global $wpdb;
  $ok=$wpdb->insert(self::clients_table(),array(
   'client_id'=>$client_id,
   'client_name'=>$name?:'MCP client',
   'redirect_uris'=>wp_json_encode(array_values(array_unique($clean)),JSON_UNESCAPED_SLASHES),
   'created_at'=>current_time('mysql',true)
  ));
  if(!$ok) return self::json_error('server_error','Could not register client',500);
  return new WP_REST_Response(array(
   'client_id'=>$client_id,
   'client_name'=>$name?:'MCP client',
   'redirect_uris'=>array_values(array_unique($clean)),
   'grant_types'=>array('authorization_code','refresh_token'),
   'response_types'=>array('code'),
   'token_endpoint_auth_method'=>'none'
  ),201);
 }

 public static function admin_post_authorize(){
  $request=new WP_REST_Request('POST','/okean-ai/v1/oauth/authorize');
  foreach(array('client_id','redirect_uri','response_type','scope','state','code_challenge','code_challenge_method','resource','oauth_action','decision','_wpnonce') as $key){
   if(isset($_POST[$key])) $request->set_param($key,wp_unslash($_POST[$key]));
  }
  $request->set_param('action','approve');
  return self::authorize($request);
 }

 public static function login_bridge($req){
  $continuation=sanitize_text_field((string)$req->get_param('continuation'));
  if(!self::login_continuation_valid($continuation)){
   return self::json_error('invalid_request','OAuth login continuation is invalid or expired',400);
  }

  $params=get_transient(self::login_continuation_key($continuation));
  if(!is_array($params)){
   return self::json_error('invalid_request','OAuth login continuation is invalid or expired',400);
  }

  $user=self::browser_user();
  if(!$user){
   nocache_headers();
   $bridge=add_query_arg(array('continuation'=>$continuation),rest_url('okean-ai/v1/oauth/login'));
   wp_safe_redirect(wp_login_url($bridge));
   exit;
  }

  delete_transient(self::login_continuation_key($continuation));
  $url=add_query_arg($params,rest_url('okean-ai/v1/oauth/authorize'));
  nocache_headers();
  wp_safe_redirect($url);
  exit;
 }

 public static function authorize($req){
  $p=array();
  foreach(array('client_id','redirect_uri','response_type','scope','state','code_challenge','code_challenge_method') as $k) $p[$k]=(string)$req->get_param($k);
  $client=self::client($p['client_id']);
  if(!$client || !self::exact_redirect($client,$p['redirect_uri'])) return self::json_error('invalid_request','Unknown client or redirect_uri',400);
  if($p['response_type']!=='code') return self::json_error('unsupported_response_type','Only response_type=code is supported');
  if($p['code_challenge']==='' || $p['code_challenge_method']!=='S256') return self::json_error('invalid_request','PKCE S256 code_challenge is required');

  $scope_tokens=preg_split('/\s+/',trim($p['scope']?:'mcp:read'));
  $allowed=array('mcp:read','mcp:write');
  foreach($scope_tokens as $s) if($s!=='' && !in_array($s,$allowed,true)) return self::json_error('invalid_scope','Unsupported scope');
  $scope=implode(' ',array_values(array_unique(array_filter($scope_tokens))));
  $resource=esc_url_raw((string)$req->get_param('resource'));
  if($resource!=='' && !hash_equals(rest_url('okean-ai/mcp'),$resource)) return self::json_error('invalid_target','Authorization resource must be this MCP server');

  $user=self::browser_user();
  if(!$user){
   $continuation=self::store_login_continuation(array(
    'client_id'=>$p['client_id'],
    'redirect_uri'=>$p['redirect_uri'],
    'response_type'=>$p['response_type'],
    'scope'=>$p['scope'],
    'state'=>$p['state'],
    'code_challenge'=>$p['code_challenge'],
    'code_challenge_method'=>$p['code_challenge_method'],
    'resource'=>$resource
   ));
   if(!$continuation) return self::json_error('server_error','Could not create OAuth login continuation',500);
   $bridge=add_query_arg(array('continuation'=>$continuation),rest_url('okean-ai/v1/oauth/login'));
   nocache_headers();
   wp_safe_redirect(wp_login_url($bridge));
   exit;
  }

  if(!$user || !$user->exists() || !user_can($user,'manage_options')){
   return self::json_error('access_denied','A WordPress administrator must approve this connection',403);
  }
  if($_SERVER['REQUEST_METHOD']==='GET'){
   $nonce=wp_create_nonce('oai_oauth_authorize');
   ob_start(); ?>
   <!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Подключение AI Admin</title>
   <style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:40px}.box{max-width:620px;margin:auto;background:#fff;border:1px solid #d9dee8;border-radius:12px;padding:30px;box-shadow:0 8px 30px rgba(0,0,0,.06)}h1{margin-top:0}.muted{color:#687386}.actions{display:flex;gap:12px;margin-top:24px}.btn{padding:11px 18px;border-radius:8px;border:0;cursor:pointer;font-size:15px}.allow{background:#0b57d0;color:#fff}.deny{background:#fff;border:1px solid #c7cedb}</style></head>
   <body><div class="box"><h1>Подключение AI к Okean AI Admin</h1>
   <p class="muted">Приложение <strong><?php echo esc_html($client['client_name']);?></strong> запрашивает доступ к сайту.</p>
   <p>Доступ будет выполняться от имени WordPress-пользователя <strong><?php echo esc_html($user->display_name?:$user->user_login);?></strong>.</p>
   <p class="muted">Изменения сайта по-прежнему проходят через sandbox, diff, preview и подтверждение.</p>
   <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="oai_oauth_authorize"><input type="hidden" name="oauth_action" value="approve"><?php
   foreach($p as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
   echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
   ?><div class="actions"><button class="btn deny" name="decision" value="deny" type="submit">Отклонить</button><button class="btn allow" name="decision" value="approve" type="submit">Разрешить</button></div></form>
   </div></body></html><?php
   $html=ob_get_clean();
   // REST endpoints normally serialize strings as JSON. For the OAuth consent screen
   // we explicitly serve a real HTML document and stop REST serialization.
   status_header(200);
   nocache_headers();
   header('Content-Type: text/html; charset=utf-8');
   header('X-Robots-Tag: noindex, nofollow');
   echo $html;
   exit;
  }

  $oauth_action=(string)$req->get_param('oauth_action');
  if($oauth_action==='' && $req->get_param('action')==='approve') $oauth_action='approve';
  if($oauth_action!=='approve' || !wp_verify_nonce((string)$req->get_param('_wpnonce'),'oai_oauth_authorize')) return self::json_error('invalid_request','Invalid authorization confirmation',400);
  $decision=(string)$req->get_param('decision');
  if($decision!=='approve'){
   return self::redirect_error($p['redirect_uri'],$p['state'],'access_denied','User denied access');
  }

  $code='oai_code_'.wp_generate_password(64,false,false);
  global $wpdb;
  $ok=$wpdb->insert(self::codes_table(),array(
   'code_hash'=>hash('sha256',$code),
   'client_id'=>$p['client_id'],
   'user_id'=>$user->ID,
   'redirect_uri'=>$p['redirect_uri'],
   'scope'=>$scope,
   'resource'=>rest_url('okean-ai/mcp'),
   'code_challenge'=>$p['code_challenge'],
   'created_at'=>current_time('mysql',true),
   'expires_at'=>gmdate('Y-m-d H:i:s',time()+300),
   'used'=>0
  ));
  if(!$ok) return self::json_error('server_error','Could not create authorization code',500);
  return self::redirect_success($p['redirect_uri'],$p['state'],$code);
 }

 private static function redirect_success($uri,$state,$code){
  $params=array('code'=>$code);
  if($state!=='')$params['state']=$state;
  wp_redirect(add_query_arg($params,$uri),302); exit;
 }
 private static function redirect_error($uri,$state,$error,$description=''){
  $params=array('error'=>$error);
  if($description!=='')$params['error_description']=$description;
  if($state!=='')$params['state']=$state;
  wp_redirect(add_query_arg($params,$uri),302); exit;
 }

 public static function token($req){
  $grant=sanitize_key((string)$req->get_param('grant_type'));
  if($grant==='authorization_code') return self::exchange_code($req);
  if($grant==='refresh_token') return self::refresh($req);
  return self::json_error('unsupported_grant_type','Only authorization_code and refresh_token are supported');
 }

 private static function verify_pkce($verifier,$challenge){
  return $verifier!=='' && hash_equals($challenge,rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='));
 }

 private static function exchange_code($req){
  $resource=esc_url_raw((string)$req->get_param('resource'));
  $code=(string)$req->get_param('code'); $client_id=sanitize_text_field((string)$req->get_param('client_id')); $redirect=(string)$req->get_param('redirect_uri'); $verifier=(string)$req->get_param('code_verifier');
  if($code===''||$client_id===''||$redirect===''||$verifier==='') return self::json_error('invalid_request','code, client_id, redirect_uri and code_verifier are required');
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::codes_table().' WHERE code_hash=%s LIMIT 1',hash('sha256',$code)),ARRAY_A);
  if(!$row) return self::json_error('invalid_grant','Authorization code is invalid');
  if((int)$row['used']===1 || strtotime($row['expires_at'])<time()) return self::json_error('invalid_grant','Authorization code is expired or already used');
  if($row['client_id']!==$client_id || $row['redirect_uri']!==$redirect || !self::verify_pkce($verifier,$row['code_challenge'])) return self::json_error('invalid_grant','Authorization code validation failed');
  $expected_resource=rest_url('okean-ai/mcp');
  if($resource!=='' && !hash_equals($expected_resource,$resource)) return self::json_error('invalid_target','Authorization request resource is not this MCP server');
  $wpdb->update(self::codes_table(),array('used'=>1),array('id'=>(int)$row['id']));

  return self::issue_tokens((int)$row['user_id'],$client_id,$row['scope'],(string)($row['resource']??rest_url('okean-ai/mcp')));
 }

 private static function issue_tokens($user_id,$client_id,$scope,$resource=''){
  $access='oai_access_'.wp_generate_password(72,false,false);
  $refresh='oai_refresh_'.wp_generate_password(72,false,false);
  global $wpdb;
  $ok=$wpdb->insert(self::tokens_table(),array(
   'access_hash'=>hash('sha256',$access),
   'refresh_hash'=>hash('sha256',$refresh),
   'client_id'=>$client_id,
   'user_id'=>absint($user_id),
   'scope'=>$scope,
   'resource'=>$resource?:rest_url('okean-ai/mcp'),
   'created_at'=>current_time('mysql',true),
   'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
   'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS),
   'revoked'=>0
  ));
  if(!$ok) return self::json_error('server_error','Could not issue access token',500);
  return new WP_REST_Response(array(
   'access_token'=>$access,
   'token_type'=>'Bearer',
   'expires_in'=>3600,
   'refresh_token'=>$refresh,
   'scope'=>$scope,
   'resource'=>$resource?:rest_url('okean-ai/mcp')
  ),200);
 }

 private static function refresh($req){
  $refresh=(string)$req->get_param('refresh_token'); $client_id=sanitize_text_field((string)$req->get_param('client_id'));
  if($refresh===''||$client_id==='') return self::json_error('invalid_request','refresh_token and client_id are required');
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::tokens_table().' WHERE refresh_hash=%s AND client_id=%s LIMIT 1',hash('sha256',$refresh),$client_id),ARRAY_A);
  if(!$row || (int)$row['revoked']===1 || empty($row['refresh_expires_at']) || strtotime($row['refresh_expires_at'])<time()) return self::json_error('invalid_grant','Refresh token is invalid or expired');
  $wpdb->update(self::tokens_table(),array('revoked'=>1),array('id'=>(int)$row['id']));
  return self::issue_tokens((int)$row['user_id'],$client_id,$row['scope'],(string)($row['resource']??rest_url('okean-ai/mcp')));
 }

 public static function authenticate_access_token($token){
  global $wpdb;
  if($token==='')return false;
  $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::tokens_table().' WHERE access_hash=%s AND revoked=0 LIMIT 1',hash('sha256',$token)),ARRAY_A);
  if(!$row)return false;
  if(strtotime($row['expires_at'])<time()){
   $wpdb->update(self::tokens_table(),array('revoked'=>1),array('id'=>(int)$row['id']));
   return false;
  }
  $user=get_user_by('id',(int)$row['user_id']);
  if(!$user || !user_can($user,'manage_options'))return false;
  return array('user'=>$user,'client_id'=>$row['client_id'],'scope'=>$row['scope']);
 }
}
