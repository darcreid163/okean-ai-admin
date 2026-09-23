<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_MCP {
 private static $auth_context=array('scope'=>'');
 public static function register_routes(){
  register_rest_route('okean-ai','/mcp',array('methods'=>'POST','callback'=>array(__CLASS__,'handle'),'permission_callback'=>array(__CLASS__,'permission')));
  register_rest_route('okean-ai','/mcp',array('methods'=>'GET','callback'=>array(__CLASS__,'info'),'permission_callback'=>array(__CLASS__,'permission')));
 }
 public static function info(){
  return array(
   'name'=>'Okean AI Admin MCP',
   'version'=>OAI_VERSION,
   'protocol'=>'MCP Streamable HTTP / JSON-RPC 2.0',
   'enabled'=>(bool)get_option('oai_mcp_enabled',false),
   'license'=>OAI_License::user_status(),
   'auth'=>'Bearer access token',
   'write_model'=>'sandbox + human approval'
  );
 }
 private static function token_hash(){return (string)get_option('oai_mcp_token_hash','');}
 public static function generate_token($user_id=0){ $token='oai_mcp_'.wp_generate_password(56,false,false); update_option('oai_mcp_token_hash',hash('sha256',$token),false); if($user_id) update_option('oai_mcp_token_user_id',absint($user_id),false); else delete_option('oai_mcp_token_user_id'); update_option('oai_mcp_enabled',true,false); return $token; }
 public static function admin_generate_token(){
  if(!current_user_can('manage_options')) return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  $token=self::generate_token(get_current_user_id());
  return array('ok'=>true,'token'=>$token,'enabled'=>true,'message'=>'MCP token generated. Store it securely; it will not be shown again.');
 }
 private static function auth(){
  if(!get_option('oai_mcp_enabled',false))return new WP_Error('oai_mcp_disabled','MCP is disabled',array('status'=>403));
  $license=OAI_License::can('mcp'); if(is_wp_error($license))return $license;
  $auth='';
  if(isset($_SERVER['HTTP_AUTHORIZATION'])) $auth=trim((string)wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
  if(!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth=trim((string)wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
  if(!$auth)return new WP_Error('oai_mcp_auth_required','Bearer token or WordPress Application Password required',array('status'=>401));
  if(stripos($auth,'basic ')===0 && function_exists('wp_authenticate_application_password')){
   $raw=base64_decode(trim(substr($auth,6)),true);
   if($raw!==false && strpos($raw,':')!==false){
    list($username,$password)=explode(':',$raw,2);
    $user=wp_authenticate_application_password(null,$username,$password);
    if(!is_wp_error($user) && $user instanceof WP_User && user_can($user,'manage_options')){wp_set_current_user($user->ID);return OAI_Rate_Limit::check('mcp',120,60);}
   }
   return new WP_Error('oai_mcp_auth','Invalid WordPress Application Password',array('status'=>401));
  }
  $parts=preg_split('/\s+/',trim($auth)); if(count($parts)!==2||strtolower($parts[0])!=='bearer')return new WP_Error('oai_mcp_auth','Bearer token or WordPress Application Password required',array('status'=>401));
  $oauth=class_exists('OAI_MCP_OAuth')?OAI_MCP_OAuth::authenticate_access_token($parts[1]):false;
  if($oauth){ self::$auth_context=array('user_id'=>(int)$oauth['user']->ID,'client_id'=>(string)$oauth['client_id'],'scope'=>(string)$oauth['scope']); wp_set_current_user((int)$oauth['user']->ID); return OAI_Rate_Limit::check('mcp',120,60); }
  if(!self::token_hash()||!hash_equals(self::token_hash(),hash('sha256',$parts[1])))return new WP_Error('oai_mcp_auth','Invalid MCP token',array('status'=>401));
  $uid=absint(get_option('oai_mcp_token_user_id',0)); $user=$uid?get_user_by('id',$uid):false;
  if(!$user || !user_can($user,'manage_options')) return new WP_Error('oai_mcp_auth','MCP token owner is not a valid administrator',array('status'=>403));
  self::$auth_context=array('user_id'=>(int)$user->ID,'client_id'=>'legacy_token','scope'=>'mcp:read mcp:write');
  wp_set_current_user($user->ID);
  return OAI_Rate_Limit::check('mcp',120,60);
 }
 private static function unauthorized($id,$err){ $message=is_wp_error($err)?$err->get_error_message():'Authorization required'; $r=self::response($id,null,array('code'=>-32001,'message'=>$message)); $r->set_status(401); $r->header('WWW-Authenticate','Bearer resource_metadata="'.esc_url_raw(home_url('/.well-known/oauth-protected-resource')).'", scope="mcp:read mcp:write"'); return $r; }
 private static function has_scope($scope){$scope_tokens=preg_split('/\s+/',trim((string)(self::$auth_context['scope']??'')));return in_array($scope,$scope_tokens,true);}
 private static function scope_error($id,$scope){return self::response($id,null,array('code'=>-32010,'message'=>'OAuth scope required: '.$scope));}
 private static function tool_requires_write($name,$args){if(in_array($name,array('gutenberg_patch','content_patch','woocommerce_patch','elementor_create_draft','operation_approve','operation_apply','operation_rollback'),true))return true;if($name==='wordpress.execute')return sanitize_key((string)($args['mode']??'read'))!=='read';return false;}
 private static function response($id,$result=null,$error=null){$r=array('jsonrpc'=>'2.0','id'=>$id);if($error!==null)$r['error']=$error;else$r['result']=$result;return new WP_REST_Response($r,200,array('Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store'));}

 public static function handle($req){
  $auth=self::auth();if(is_wp_error($auth))return self::unauthorized(($req->get_json_params()['id']??null),$auth);
  $body=$req->get_json_params();if(!is_array($body))return self::response(null,null,array('code'=>-32600,'message'=>'Invalid Request'));
  $id=$body['id']??null;$method=(string)($body['method']??'');$params=is_array($body['params']??null)?$body['params']:array();
  if($method==='initialize')return self::response($id,array('protocolVersion'=>OAI_MCP_VERSION,'capabilities'=>array('tools'=>array('listChanged'=>false),'resources'=>array('subscribe'=>false,'listChanged'=>false),'prompts'=>array('listChanged'=>false)),'serverInfo'=>array('name'=>'okean-ai-admin','version'=>OAI_VERSION,'vendor'=>'Андриянов Н.Г.')));
  if($method==='notifications/initialized'||$method==='ping')return self::response($id,array());
  if($method==='tools/list')return self::response($id,array('tools'=>self::tools()));
  if($method==='resources/list')return self::response($id,array('resources'=>self::resources()));
  if($method==='resources/read'){if(!self::has_scope('mcp:read'))return self::scope_error($id,'mcp:read');return self::resource_read($id,$params);}
  if($method==='prompts/list')return self::response($id,array('prompts'=>self::prompts()));
  if($method==='tools/call'){
   $tool_name=sanitize_key(str_replace('.','_',strtolower((string)($params['name']??''))));
   $write=self::tool_requires_write($tool_name,is_array($params['arguments']??null)?$params['arguments']:array());
   if($write&&!self::has_scope('mcp:write'))return self::scope_error($id,'mcp:write');
   if(!$write&&!self::has_scope('mcp:read'))return self::scope_error($id,'mcp:read');
   if(class_exists('OAI_Quota')){
    $quota=OAI_Quota::consume(1);
    if(is_wp_error($quota))return self::response($id,null,array('code'=>-32029,'message'=>$quota->get_error_message(),'data'=>$quota->get_error_data()));
   }
   return self::call_tool($id,$params);
  }
  return self::response($id,null,array('code'=>-32601,'message'=>'Method not found'));
 }

// The remainder of the MCP implementation is intentionally unchanged.