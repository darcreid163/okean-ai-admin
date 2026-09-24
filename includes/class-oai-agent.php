<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Restricted remote agent channel for Okean AI Admin.
 *
 * This is deliberately separate from the normal Bearer API. The agent key is
 * stored only as a hash and every request is signed with HMAC + timestamp +
 * nonce. The execution surface is an explicit allow-list; arbitrary REST
 * routes, PHP, SQL and filesystem paths are never accepted here.
 */
class OAI_Agent {
 public static function key_hash(){ return (string)get_option('oai_agent_key_hash',''); }
 public static function enabled(){ return (bool)get_option('oai_agent_enabled',false) && self::key_hash()!==''; }
 public static function scopes(){
  $v=get_option('oai_agent_scopes',array('read','sandbox','preview','verify'));
  return is_array($v)?array_values(array_unique(array_map('sanitize_key',$v))):array('read');
 }
 public static function generate_key(){
  $key=wp_generate_password(64,false,false);
  update_option('oai_agent_key_hash',hash('sha256',$key),false);
  update_option('oai_agent_enabled',true,false);
  update_option('oai_agent_created_at',gmdate('c'),false);
  return $key;
 }
 public static function disable(){update_option('oai_agent_enabled',false,false);}
 public static function set_scopes($scopes){
  $allowed=array('read','sandbox','preview','verify','approve','apply','rollback');
  $scopes=is_array($scopes)?$scopes:array();
  $scopes=array_values(array_intersect($allowed,array_unique(array_map('sanitize_key',$scopes))));
  update_option('oai_agent_scopes',$scopes,false);
  return $scopes;
 }
 private static function body_raw($req){
  $raw=file_get_contents('php://input');
  if($raw===false || $raw===''){
   $json=$req->get_json_params();
   if(is_array($json)) return wp_json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   return '';
  }
  return $raw;
 }
 public static function perm($req){
  if(!self::enabled()) return false;
  $license=OAI_License::can('agent');
  if(is_wp_error($license)) return false;
  $key=(string)$req->get_header('x-oai-agent-key');
  $sig=(string)$req->get_header('x-oai-agent-signature');
  $ts=(string)$req->get_header('x-oai-agent-timestamp');
  $nonce=(string)$req->get_header('x-oai-agent-nonce');
  if($key==='' || $sig==='' || $ts==='' || $nonce==='') return false;
  if(!ctype_digit($ts) || abs(time()-(int)$ts)>300) return false;
  if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/',$nonce)) return false;
  if(!hash_equals(self::key_hash(),hash('sha256',$key))) return false;
  $raw=self::body_raw($req);
  $expected=hash_hmac('sha256',$ts.'|'.$nonce.'|'.$raw,$key);
  if(!hash_equals($expected,$sig)) return false;
  $nonce_key='oai_agent_nonce_'.hash('sha256',$key.'|'.$nonce);
  if(get_transient($nonce_key)) return false;
  set_transient($nonce_key,1,5*MINUTE_IN_SECONDS);
  return true;
 }
 private static function has_scope($scope){ return in_array($scope,self::scopes(),true); }
 private static function feature_for_action($action){
  if($action==='elementor_create_page') return 'elementor';
  if($action==='woocommerce_patch') return 'woocommerce';
  return '';
 }
 private static function scope_for($action){
  if(in_array($action,array('status','diagnostics','foundation_capabilities','intent_normalize','agent_plan','operation_catalog','read_discover','error_contract','operation_receipt','workspace_status','workspace_files','workspace_read','workspace_surfaces','surfaces_catalog','search_files','operation','proof','mcp_status','license_status'),true) || strpos($action,'read_')===0) return 'read';
  if($action==='sandbox_create' || $action==='sandbox_list' || $action==='elementor_create_page' || $action==='content_patch' || $action==='woocommerce_patch' || $action==='workspace_create' || $action==='workspace_write') return 'sandbox';
  if($action==='preview_link' || $action==='preview_page') return 'preview';
  if($action==='verify') return 'verify';
  if($action==='approve') return 'approve';
  if($action==='apply') return 'apply';
  if($action==='rollback') return 'rollback';
  return '';
 }
 private static function allowlist(){
  return array(
   'status'=>array('GET','/okean-ai/v1/status'),
   'diagnostics'=>array('GET','/okean-ai/v1/diagnostics'),
   'read_site'=>array('GET','/okean-ai/v1/read/site'),
   'read_plugins'=>array('GET','/okean-ai/v1/read/plugins'),
   'read_capabilities'=>array('GET','/okean-ai/v1/read/capabilities'),
   'read_themes'=>array('GET','/okean-ai/v1/read/themes'),
   'read_content'=>array('GET','/okean-ai/v1/read/content'),
   'read_content_detail'=>array('GET','/okean-ai/v1/read/content/{id}'),
   'read_content_meta'=>array('GET','/okean-ai/v1/read/content/{id}/meta'),
   'read_elementor'=>array('GET','/okean-ai/v1/read/content/{id}/elementor'),
   'read_elementor_tree'=>array('GET','/okean-ai/v1/read/content/{id}/elementor-tree'),
   'read_elementor_node'=>array('GET','/okean-ai/v1/read/content/{id}/elementor/{widget_id}'),
   'read_elementor_schema'=>array('GET','/okean-ai/v1/read/elementor/schema/{widget_type}'),
   'read_elementor_globals'=>array('GET','/okean-ai/v1/read/elementor/globals'),
   'read_products'=>array('GET','/okean-ai/v1/read/products'),
   'read_media'=>array('GET','/okean-ai/v1/read/media'),
   'read_menus'=>array('GET','/okean-ai/v1/read/menus'),
   'read_taxonomies'=>array('GET','/okean-ai/v1/read/taxonomies'),
   'read_post_types'=>array('GET','/okean-ai/v1/read/post-types'),
   'read_options'=>array('GET','/okean-ai/v1/read/options'),
   'read_widgets'=>array('GET','/okean-ai/v1/read/widgets'),
   'read_health'=>array('GET','/okean-ai/v1/read/health'),
   'read_gutenberg'=>array('GET','/okean-ai/v1/read/gutenberg/{id}'),
   'read_woocommerce'=>array('GET','/okean-ai/v1/read/woocommerce/{id}'),
   'operation_receipt'=>array('GET','/okean-ai/v1/operations/{id}/receipt'),
   'operation'=>array('GET','/okean-ai/v1/operations/{id}'),
   'proof'=>array('GET','/okean-ai/v1/proof/{id}'),
   'foundation_capabilities'=>array('GET','/okean-ai/v1/capabilities/foundation'),
   'content_patch'=>array('POST','/okean-ai/v1/content/patch'),
   'woocommerce_patch'=>array('POST','/okean-ai/v1/woocommerce/patch'),
   'mcp_status'=>array('GET','/okean-ai/v1/mcp/status'),
   'license_status'=>array('GET','/okean-ai/v1/license/status'),
   'intent_normalize'=>array('POST','/okean-ai/v1/agent/intent'),
   'agent_plan'=>array('POST','/okean-ai/v1/agent/plan'),
   'operation_catalog'=>array('GET','/okean-ai/v1/operations/catalog'),
   'read_discover'=>array('POST','/okean-ai/v1/read/discover'),
   'error_contract'=>array('GET','/okean-ai/v1/errors/contract'),
   'read_files_list'=>array('GET','/okean-ai/v1/files/list'),
   'workspace_status'=>array('GET','/okean-ai/v1/workspace'),
   'workspace_create'=>array('POST','/okean-ai/v1/workspace/create'),
   'workspace_files'=>array('GET','/okean-ai/v1/workspace/files'),
   'workspace_read'=>array('GET','/okean-ai/v1/workspace/read'),
   'workspace_write'=>array('POST','/okean-ai/v1/workspace/write'),
   'workspace_surfaces'=>array('GET','/okean-ai/v1/workspace/surfaces'),
   'surfaces_catalog'=>array('GET','/okean-ai/v1/surfaces'),
   'read_files'=>array('GET','/okean-ai/v1/files/read'),
   'search_files'=>array('GET','/okean-ai/v1/files/search'),
   'sandbox_list'=>array('GET','/okean-ai/v1/sandbox'),
   'sandbox_create'=>array('POST','/okean-ai/v1/sandbox'),
   'elementor_create_page'=>array('POST','/okean-ai/v1/elementor/create-page'),
   'preview_link'=>array('GET','/okean-ai/v1/preview-link/{id}'),
   'preview_page'=>array('GET','/okean-ai/v1/preview-page/{id}'),
   'verify'=>array('GET','/okean-ai/v1/verify/{id}'),
   'approve'=>array('POST','/okean-ai/v1/sandbox/{id}/approve'),
   'apply'=>array('POST','/okean-ai/v1/sandbox/{id}/apply'),
   'rollback'=>array('POST','/okean-ai/v1/sandbox/{id}/rollback'),
  );
 }
 private static function route($action,$params){
  if(in_array($action,array('approve','apply','rollback'),true)) return new WP_Error('oai_agent_human_approval','Approve, apply and rollback are restricted to wp-admin human confirmation',array('status'=>403));
  $map=self::allowlist();
  if(!isset($map[$action])) return new WP_Error('oai_agent_action','Agent action is not allowed',array('status'=>403));
  $scope=self::scope_for($action);
  if($scope==='' || !self::has_scope($scope)) return new WP_Error('oai_agent_scope','Agent scope is not granted',array('status'=>403,'scope'=>$scope));
  $feature=self::feature_for_action($action);
  if($feature!==''){
   $license=OAI_License::can($feature);
   if(is_wp_error($license)) return $license;
  }

  $method=$map[$action][0]; $path=$map[$action][1];
  $route_names=array();
  if(preg_match_all('/\\{([A-Za-z0-9_]+)\\}/',$path,$m)) $route_names=$m[1];
  foreach($route_names as $name){
   if(!isset($params[$name]) || $params[$name]==='') return new WP_Error('oai_agent_param','Missing route parameter: '.$name,array('status'=>400));
  }

  // Agent authentication is already completed by perm(). Do not redispatch
  // through WP's REST router: that can fail to match custom OAI routes when
  // invoked internally and previously produced rest_no_route (404).
  // Instead call the already-registered OAI callback directly through a
  // controlled allow-list. No arbitrary route/callback names are accepted.
  $callbacks=array(
   'status'=>array('OAI_REST','status'),
   'diagnostics'=>array('OAI_REST','diagnostics'),
   'read_site'=>array('OAI_REST','site'),
   'read_plugins'=>array('OAI_REST','plugins'),
   'read_capabilities'=>array('OAI_REST','capabilities'),
   'read_themes'=>array('OAI_REST','themes'),
   'read_content'=>array('OAI_REST','content'),
   'read_content_detail'=>array('OAI_REST','content_detail'),
   'read_content_meta'=>array('OAI_REST','content_meta'),
   'read_elementor'=>array('OAI_REST','elementor_find'),
   'read_elementor_tree'=>array('OAI_REST','elementor_tree'),
   'read_elementor_node'=>array('OAI_REST','elementor_node'),
   'read_elementor_schema'=>array('OAI_REST','elementor_schema'),
   'read_elementor_globals'=>array('OAI_REST','elementor_globals'),
   'read_products'=>array('OAI_REST','products'),
   'read_media'=>array('OAI_REST','media'),
   'read_menus'=>array('OAI_REST','menus'),
   'read_taxonomies'=>array('OAI_REST','taxonomies'),
   'read_post_types'=>array('OAI_REST','post_types'),
   'read_options'=>array('OAI_REST','options'),
   'read_widgets'=>array('OAI_REST','widgets'),
   'read_health'=>array('OAI_REST','health'),
   'read_gutenberg'=>array('OAI_REST','gutenberg'),
   'read_woocommerce'=>array('OAI_REST','woocommerce'),
   'operation_receipt'=>array('OAI_REST','receipt'),
   'operation'=>array('OAI_REST','operation'),
   'proof'=>array('OAI_REST','proof'),
   'foundation_capabilities'=>array('OAI_REST','foundation_capabilities'),
   'content_patch'=>array('OAI_REST','content_patch'),
   'woocommerce_patch'=>array('OAI_REST','woocommerce_patch'),
   'mcp_status'=>array('OAI_MCP','info'),
   'license_status'=>array('OAI_REST','license_status'),
   'intent_normalize'=>array('OAI_REST','intent_normalize'),
   'agent_plan'=>array('OAI_REST','agent_plan'),
   'operation_catalog'=>array('OAI_REST','operation_catalog'),
   'read_discover'=>array('OAI_REST','read_discover'),
   'error_contract'=>array('OAI_REST','error_contract'),
   'read_files_list'=>array('OAI_REST','files_list'),
   'workspace_status'=>array('OAI_REST','workspace_status'),
   'workspace_create'=>array('OAI_REST','workspace_create'),
   'workspace_files'=>array('OAI_REST','workspace_files'),
   'workspace_read'=>array('OAI_REST','workspace_read'),
   'workspace_write'=>array('OAI_REST','workspace_write'),
   'workspace_surfaces'=>array('OAI_REST','workspace_surfaces'),
   'surfaces_catalog'=>array('OAI_REST','surfaces_catalog'),
   'read_files'=>array('OAI_REST','files_read'),
   'search_files'=>array('OAI_REST','files_search'),
   'sandbox_list'=>array('OAI_REST','sandbox_list'),
   'sandbox_create'=>array('OAI_REST','sandbox_create'),
   'elementor_create_page'=>array('OAI_REST','elementor_create_page'),
   'preview_link'=>array('OAI_REST','preview_link'),
   'preview_page'=>array('OAI_REST','preview_page'),
   'verify'=>array('OAI_REST','verify'),
   'approve'=>array('OAI_REST','sandbox_action'),
   'apply'=>array('OAI_REST','sandbox_action'),
   'rollback'=>array('OAI_REST','sandbox_action')
  );
  if(!isset($callbacks[$action])) return new WP_Error('oai_agent_action','Agent action is not implemented',array('status'=>500));

  $r=new WP_REST_Request($method,$path);
  foreach($params as $k=>$v){
   if($k==='action' || $k==='query' || $k==='body') continue;
   if(is_scalar($v) || is_array($v)) $r->set_param($k,$v);
  }
  $query=isset($params['query'])&&is_array($params['query'])?$params['query']:array();
  $body=isset($params['body'])&&is_array($params['body'])?$params['body']:array();
  foreach($query as $k=>$v) if(is_scalar($v)) $r->set_param(sanitize_key($k),$v);
  foreach($body as $k=>$v) $r->set_param(sanitize_key($k),$v);

  // sandbox action callback derives approve/apply/rollback from the route.
  if(in_array($action,array('approve','apply','rollback'),true)){
   $r->set_route('/okean-ai/v1/sandbox/'.absint($params['id']).'/'.$action);
  }

  $cb=$callbacks[$action];
  if(!is_callable($cb)) return new WP_Error('oai_agent_callback','Agent callback is unavailable',array('status'=>500));
  $data=call_user_func($cb,$r);
  if($data instanceof WP_Error) return $data;
  return array('http_status'=>200,'data'=>$data);
 }
 public static function manifest(){
  return array(
   'ok'=>true,'channel'=>'okean_ai_agent','version'=>'1','enabled'=>self::enabled(),
   'authentication'=>array('scheme'=>'HMAC-SHA256','headers'=>array('X-OAI-Agent-Key','X-OAI-Agent-Timestamp','X-OAI-Agent-Nonce','X-OAI-Agent-Signature'),'timestamp_window_seconds'=>300,'replay_protection'=>true),
   'scopes'=>self::scopes(),
   'actions'=>array_keys(self::allowlist()),
   'security'=>array('arbitrary_routes'=>false,'arbitrary_php'=>false,'arbitrary_sql'=>false,'direct_live_writes'=>false,'approval_required_for_apply'=>true)
  );
 }
 public static function execute($req){
  $params=$req->get_json_params(); if(!is_array($params)) $params=array();
  $action=sanitize_key((string)($params['action']??''));
  if($action==='') return new WP_Error('oai_agent_action','action is required',array('status'=>400));
  // Reject unsupported actions, missing scopes and remote approval attempts
  // before consuming quota. A denied request must not burn a user-visible unit.
  $map=self::allowlist();
  if(!isset($map[$action])) return new WP_Error('oai_agent_action','Agent action is not allowed',array('status'=>403));
  $scope=self::scope_for($action);
  if($scope==='' || !self::has_scope($scope)) return new WP_Error('oai_agent_scope','Agent scope is not granted',array('status'=>403,'scope'=>$scope));
  $feature=self::feature_for_action($action);
  if($feature!==''){
   $license=OAI_License::can($feature);
   if(is_wp_error($license)) return $license;
  }
  if(in_array($action,array('approve','apply','rollback'),true)) return new WP_Error('oai_agent_human_approval','Approve, apply and rollback are restricted to wp-admin human confirmation',array('status'=>403));
  if(class_exists('OAI_Quota')){
   $fingerprint=wp_json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   $request_id=(string)($req->get_header('x-oai-agent-request-id')?:'');
   if($request_id==='') $request_id=hash('sha256',$fingerprint);
   $request_key=OAI_Quota::request_key('agent',$request_id,$fingerprint);
   $quota=OAI_Quota::consume_request($request_key,'agent',1);
   if(is_wp_error($quota)) return $quota;
  }
  $result=self::route($action,$params);
  if(is_wp_error($result)) return $result;
  return array('ok'=>true,'action'=>$action,'result'=>$result);
 }
}
