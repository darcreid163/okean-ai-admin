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
 public static function info(){return array('name'=>'Okean AI Admin MCP','version'=>OAI_VERSION,'protocol'=>'MCP Streamable HTTP / JSON-RPC 2.0','enabled'=>(bool)get_option('oai_mcp_enabled',false),'license'=>OAI_License::user_status(),'auth'=>'Bearer access token','write_model'=>'sandbox + human approval; optional short-lived scoped test automation');}
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
    if(!is_wp_error($user) && $user instanceof WP_User && user_can($user,'manage_options')){
     self::$auth_context=array('user_id'=>(int)$user->ID,'client_id'=>'application_password','scope'=>'mcp:read mcp:write');
     wp_set_current_user($user->ID);
     return OAI_Rate_Limit::check('mcp',120,60);
    }
   }
   return new WP_Error('oai_mcp_auth','Invalid WordPress Application Password',array('status'=>401));
  }
  $parts=preg_split('/\s+/',trim($auth)); if(count($parts)!==2||strtolower($parts[0])!=='bearer')return new WP_Error('oai_mcp_auth','Bearer token or WordPress Application Password required',array('status'=>401));
  $oauth=class_exists('OAI_MCP_OAuth')?OAI_MCP_OAuth::authenticate_access_token($parts[1]):false;
  if($oauth){ self::$auth_context=array('user_id'=>(int)$oauth['user']->ID,'client_id'=>(string)$oauth['client_id'],'scope'=>(string)$oauth['scope']); wp_set_current_user((int)$oauth['user']->ID); return OAI_Rate_Limit::check('mcp',120,60); }
  if(!self::token_hash()||!hash_equals(self::token_hash(),hash('sha256',$parts[1])))return new WP_Error('oai_mcp_auth','Invalid MCP token',array('status'=>401));
  $uid=absint(get_option('oai_mcp_token_user_id',0));
  $user=$uid?get_user_by('id',$uid):false;
  if(!$user || !user_can($user,'manage_options')) return new WP_Error('oai_mcp_auth','MCP token owner is not a valid administrator',array('status'=>403));
  self::$auth_context=array('user_id'=>(int)$user->ID,'client_id'=>'legacy_token','scope'=>'mcp:read mcp:write');
  wp_set_current_user($user->ID);
  return OAI_Rate_Limit::check('mcp',120,60);
 }
 public static function permission($req){ return true; }
 private static function unauthorized($id,$err){ $message=is_wp_error($err)?$err->get_error_message():'Authorization required'; $r=self::response($id,null,array('code'=>-32001,'message'=>$message)); $r->set_status(401); $r->header('WWW-Authenticate','Bearer resource_metadata="'.esc_url_raw(home_url('/.well-known/oauth-protected-resource')).'", scope="mcp:read mcp:write"'); return $r; }
 private static function has_scope($scope){
  $scope_tokens=preg_split('/\s+/',trim((string)(self::$auth_context['scope']??'')));
  return in_array($scope,$scope_tokens,true);
 }
 private static function scope_error($id,$scope){
  $meta=array('mcp/www_authenticate'=>'Bearer resource_metadata="'.esc_url_raw(home_url('/.well-known/oauth-protected-resource')).'", scope="'.esc_attr($scope).'"');
  return self::response($id,null,array('code'=>-32010,'message'=>'OAuth scope required: '.$scope,'_meta'=>$meta));
 }
 private static function tool_requires_write($name,$args){
  if(in_array($name,array('system_regression_run','system_regression_matrix','batch_create','batch_resume','batch_pause','batch_retry','batch_cancel','batch_rollback','gutenberg_patch','content_patch','woocommerce_patch','woocommerce_update_product_sandbox','elementor_create_draft','elementor_patch_widget','elementor_patch_fragment','elementor_patch_settings','file_write_sandbox','operation_reject','operation_approve','operation_apply','operation_rollback'),true)) return true;
  if($name==='wordpress.execute') return sanitize_key((string)($args['mode']??'read'))!=='read';
  return false;
 }

 private static function response($id,$result=null,$error=null){$r=array('jsonrpc'=>'2.0','id'=>$id);if($error!==null)$r['error']=$error;else$r['result']=$result;return new WP_REST_Response($r,200,array('Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store'));}
 public static function handle($req){
  $auth=self::auth();if(is_wp_error($auth))return self::unauthorized(($req->get_json_params()['id']??null),$auth);
  $body=$req->get_json_params();if(!is_array($body))return self::response(null,null,array('code'=>-32600,'message'=>'Invalid Request'));
  $id=$body['id']??null;$method=(string)($body['method']??'');$params=is_array($body['params']??null)?$body['params']:array();
  if($method==='initialize')return self::response($id,array('protocolVersion'=>OAI_MCP_VERSION,'capabilities'=>array('tools'=>array('listChanged'=>false),'resources'=>array('subscribe'=>false,'listChanged'=>false),'prompts'=>array('listChanged'=>false)),'serverInfo'=>array('name'=>'okean-ai-admin','version'=>OAI_VERSION,'vendor'=>'Андриянов Н.Г.')));
  if($method==='notifications/initialized'||$method==='ping')return self::response($id,array());
  if($method==='tools/list')return self::response($id,array('tools'=>self::tools()));
  if($method==='resources/list')return self::response($id,array('resources'=>self::resources()));
  if($method==='resources/read'){ if(!self::has_scope('mcp:read')) return self::scope_error($id,'mcp:read'); return self::resource_read($id,$params); }
  if($method==='prompts/list')return self::response($id,array('prompts'=>self::prompts()));
  if($method==='tools/call'){
   $tool_name=sanitize_key(str_replace('.','_',strtolower((string)($params['name']??''))));
   $write=self::tool_requires_write($tool_name,is_array($params['arguments']??null)?$params['arguments']:array());
   if($write && !self::has_scope('mcp:write')) return self::scope_error($id,'mcp:write');
   if($write){
    $feature='mcp_apply';
    if($tool_name==='operation_rollback') $feature='mcp_rollback';
    $license=OAI_License::can($feature);
    if(is_wp_error($license)) return self::response($id,null,array('code'=>-32030,'message'=>$license->get_error_message(),'data'=>$license->get_error_data()));
   }
   if(!$write && !self::has_scope('mcp:read')) return self::scope_error($id,'mcp:read');
   // One quota unit represents one user-visible MCP tool request. Internal
   // resolver steps are not charged separately.
   if(class_exists('OAI_Quota')){
    $arguments=is_array($params['arguments']??null)?$params['arguments']:array();
    $fingerprint=wp_json_encode(array('method'=>'tools/call','tool'=>$tool_name,'arguments'=>$arguments),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $request_id=(string)($id??'');
    if($request_id==='') $request_id=hash('sha256',$fingerprint);
    $request_key=OAI_Quota::request_key('mcp',$request_id,$fingerprint);
    $quota=OAI_Quota::consume_request($request_key,'mcp',1);
    if(is_wp_error($quota)) return self::response($id,null,array('code'=>-32029,'message'=>$quota->get_error_message(),'data'=>$quota->get_error_data()));
   }
   return self::call_tool($id,$params);
  }
  return self::response($id,null,array('code'=>-32601,'message'=>'Method not found'));
 }
 private static function resources(){
  return array(
   array('uri'=>'okean://site/status','name'=>'Okean site status','description'=>'Current WordPress/site diagnostics snapshot','mimeType'=>'application/json'),
   array('uri'=>'okean://capabilities','name'=>'Okean capabilities','description'=>'Enabled Okean AI capabilities and security policy','mimeType'=>'application/json'),
   array('uri'=>'okean://operations','name'=>'Okean operations','description'=>'Sandbox operation ledger','mimeType'=>'application/json')
  );
 }
 private static function resource_read($id,$p){
  $uri=(string)($p['uri']??'');
  if($uri==='okean://site/status')$value=OAI_REST::site();
  elseif($uri==='okean://capabilities')$value=OAI_Capabilities::available();
  elseif($uri==='okean://operations')$value=OAI_REST::sandbox_list();
  else return self::response($id,null,array('code'=>-32002,'message'=>'Unknown resource'));
  if(is_wp_error($value))return self::response($id,null,array('code'=>-32000,'message'=>$value->get_error_message(),'data'=>$value->get_error_data()));
  return self::response($id,array('contents'=>array(array('uri'=>$uri,'mimeType'=>'application/json','text'=>wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))));
 }
 private static function prompts(){
  return array(
   array('name'=>'safe-change','description'=>'Plan a WordPress change using sandbox, diff, preview, approval, apply and verification.'),
   array('name'=>'diagnostics','description'=>'Run a read-only site diagnostic before proposing changes.')
  );
 }
 private static function tool($name,$description,$schema=array('type'=>'object'),$annotations=array()){
  $tool=array('name'=>$name,'description'=>$description,'inputSchema'=>$schema);
  // Advertise the same OAuth policy that the runtime enforces. ChatGPT uses
  // securitySchemes to know which tools require linking and which scope is needed.
  $normalized=sanitize_key(str_replace('.','_',strtolower((string)$name)));
  $write=self::tool_requires_write($normalized,array());
  $scopes=$normalized==='wordpress_execute'?array('mcp:read','mcp:write'):array($write?'mcp:write':'mcp:read');
  $tool['securitySchemes']=array(array('type'=>'oauth2','scopes'=>array_values($scopes)));
  // Mirror securitySchemes in _meta for clients that still inspect the legacy MCP Apps field.
  $tool['_meta']=array('securitySchemes'=>$tool['securitySchemes']);
  // Keep MCP annotations explicit for ChatGPT/Codex safety and confirmation UX.
  // Okean tools operate only inside the authenticated WordPress workspace and
  // do not access arbitrary public internet resources.
  if(!array_key_exists('openWorldHint',$annotations)) $annotations['openWorldHint']=false;
  if(!empty($annotations))$tool['annotations']=$annotations;
  return $tool;
 }
 public static function tool_definitions(){return self::tools();}
 private static function tools(){
  $schemas=array(
   'wp.inspect_html'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('url'=>array('type'=>'string'),'post_id'=>array('type'=>'integer','minimum'=>1),'selector'=>array('type'=>'string'),'mode'=>array('type'=>'string','enum'=>array('inspect','outline')),'max_results'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'max_chars'=>array('type'=>'integer','minimum'=>100,'maximum'=>10000),'include_attributes'=>array('type'=>'boolean'),'include_text'=>array('type'=>'boolean'),'include_html'=>array('type'=>'boolean'),'max_length'=>array('type'=>'integer','minimum'=>1000,'maximum'=>30000))),
   'wp.get_theme_mods'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('name'=>array('type'=>'string'))),
   'wp.list_media'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000),'search'=>array('type'=>'string'))),
   'wp.list_users'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000))),
   'wp.list_comments'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000))),
   'wp.list_operations'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('page'=>array('type'=>'integer','minimum'=>1),'per_page'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'status'=>array('type'=>'string'))),
   'wp.list_options'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('prefix'=>array('type'=>'string'),'keys'=>array('type'=>array('array','string'),'items'=>array('type'=>'string')))),
   'elementor.get_structure'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500),'include_html'=>array('type'=>'boolean'))),
   'elementor.get_widget'=>array('type'=>'object','required'=>array('post_id','widget_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'view'=>array('type'=>'string','enum'=>array('full','outline','html_range')),'start_line'=>array('type'=>'integer','minimum'=>1),'end_line'=>array('type'=>'integer','minimum'=>1),'max_chars'=>array('type'=>'integer','minimum'=>100,'maximum'=>20000),'expected_sha256'=>array('type'=>'string'))),
   'elementor.get_schema'=>array('type'=>'object','required'=>array('widget_type'),'additionalProperties'=>false,'properties'=>array('widget_type'=>array('type'=>'string','minLength'=>1))),
   'elementor.get_globals'=>array('type'=>'object','additionalProperties'=>false),
   'woocommerce.get_product'=>array('type'=>'object','required'=>array('product_id'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1))),
   'operation.get_receipt'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.get'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.verify'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.proof'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.preview_link'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'wordpress.execute'=>array('type'=>'object','required'=>array('command'),'additionalProperties'=>false,'properties'=>array('command'=>array('type'=>'string','minLength'=>1),'mode'=>array('type'=>'string','enum'=>array('read','plan')))),
   'gutenberg.get_blocks'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'view'=>array('type'=>'string','enum'=>array('outline','full')))),
   'gutenberg.get_outline'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1))),
   'gutenberg.locate_block'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'block_name'=>array('type'=>'string'),'path'=>array('type'=>'string'),'text'=>array('type'=>'string'),'html_contains'=>array('type'=>'string'),'view'=>array('type'=>'string','enum'=>array('outline','full')))),
   'gutenberg.patch'=>array('type'=>'object','required'=>array('post_id','selector'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'selector'=>array('type'=>'object'),'attrs'=>array('type'=>'object'),'html'=>array('type'=>'string'))),
   'content.patch'=>array('type'=>'object','required'=>array('post_id','field','old_content','new_content'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'field'=>array('type'=>'string'),'old_content'=>array('type'=>'string'),'new_content'=>array('type'=>'string'),'replace_all'=>array('type'=>'boolean'))),
   'woocommerce.patch'=>array('type'=>'object','required'=>array('product_id','changes'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'changes'=>array('type'=>'object'))),
   'elementor.create_draft'=>array('type'=>'object','required'=>array('title'),'additionalProperties'=>false,'properties'=>array('title'=>array('type'=>'string'),'data'=>array('type'=>'array'),'source_post_id'=>array('type'=>'integer','minimum'=>1),'page_template'=>array('type'=>'string'))),
   'elementor.patch_widget'=>array('type'=>'object','required'=>array('post_id','widget_id','old_html','new_html'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'old_html'=>array('type'=>'string'),'new_html'=>array('type'=>'string'))),
   'elementor.patch_fragment'=>array('type'=>'object','required'=>array('post_id','widget_id','expected_sha256','old_fragment','new_fragment'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'expected_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),'old_fragment'=>array('type'=>'string','minLength'=>1),'new_fragment'=>array('type'=>'string'))),
   'builder.detect'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1))),
   'wp.list_files'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string'))),
   'wp.search_files'=>array('type'=>'object','required'=>array('q'),'additionalProperties'=>false,'properties'=>array('scope'=>array('type'=>'string'),'q'=>array('type'=>'string','minLength'=>1),'path'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100))),
   'operation.approve'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.apply'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.rollback'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)))
   ,'wp.read_content'=>array('type'=>'object','properties'=>array('post_type'=>array('type'=>'string'),'search'=>array('type'=>'string'),'page'=>array('type'=>'integer','minimum'=>1),'per_page'=>array('type'=>'integer','minimum'=>1,'maximum'=>100)),'additionalProperties'=>false)
   ,'wp.read_post'=>array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'include_content'=>array('type'=>'boolean'),'include_elementor'=>array('type'=>'boolean'),'include_css'=>array('type'=>'boolean')),'additionalProperties'=>false)
   ,'wp.read_meta'=>array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'wp.read_option'=>array('type'=>'object','required'=>array('keys'),'properties'=>array('keys'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'wp.rest_routes'=>array('type'=>'object','properties'=>array('namespace'=>array('type'=>'string'),'search'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>200)),'additionalProperties'=>false)
   ,'wp.rest_read'=>array('type'=>'object','required'=>array('route'),'properties'=>array('route'=>array('type'=>'string','minLength'=>1),'params'=>array('type'=>'object'),'fields'=>array('type'=>'string'),'max_items'=>array('type'=>'integer','minimum'=>1,'maximum'=>100)),'additionalProperties'=>false)
   ,'file.read'=>array('type'=>'object','required'=>array('scope','path'),'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string'),'start_line'=>array('type'=>'integer','minimum'=>1),'end_line'=>array('type'=>'integer','minimum'=>1),'max_chars'=>array('type'=>'integer','minimum'=>100,'maximum'=>20000),'expected_sha256'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'wp.file.outline'=>array('type'=>'object','required'=>array('scope','path'),'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>200)),'additionalProperties'=>false)
   ,'woocommerce.search_products'=>array('type'=>'object','properties'=>array('query'=>array('type'=>'string'),'sku'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000),'fields'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'woocommerce.get_variations'=>array('type'=>'object','required'=>array('product_id'),'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100)),'additionalProperties'=>false)
   ,'woocommerce.search_orders'=>array('type'=>'object','properties'=>array('page'=>array('type'=>'integer','minimum'=>1,'maximum'=>10000),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),'status'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'woocommerce.update_product_sandbox'=>array('type'=>'object','required'=>array('product_id','changes'),'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'changes'=>array('type'=>'object','properties'=>array('regular_price'=>array('type'=>array('string','number')),'sale_price'=>array('type'=>array('string','number')),'stock_quantity'=>array('type'=>array('integer','number','null')),'stock_status'=>array('type'=>'string','enum'=>array('instock','outofstock','onbackorder')),'manage_stock'=>array('type'=>'boolean')),'additionalProperties'=>false)),'additionalProperties'=>false)
   ,'operation.status'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'operation.diff'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'preview.get'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'verify.operation'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'proof.operation'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'elementor.inspect_page'=>array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500),'include_html'=>array('type'=>'boolean')),'additionalProperties'=>false)
   ,'elementor.inspect_widget'=>array('type'=>'object','required'=>array('post_id','widget_id'),'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'view'=>array('type'=>'string','enum'=>array('full','outline','html_range')),'start_line'=>array('type'=>'integer','minimum'=>1),'end_line'=>array('type'=>'integer','minimum'=>1),'max_chars'=>array('type'=>'integer','minimum'=>100,'maximum'=>20000),'expected_sha256'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'system.manifest'=>array('type'=>'object','additionalProperties'=>false)
   ,'system.tool_catalog'=>array('type'=>'object','additionalProperties'=>false)
   ,'system.self_test'=>array('type'=>'object','additionalProperties'=>false)
   ,'system.security_status'=>array('type'=>'object','additionalProperties'=>false)
   ,'system.test_automation_status'=>array('type'=>'object','additionalProperties'=>false)
   ,'system.regression_run'=>array(
     'type'=>'object',
     'required'=>array('content_post_id','elementor_post_id','elementor_widget_id','product_id'),
     'properties'=>array(
      'content_post_id'=>array('type'=>'integer','minimum'=>1),
      'elementor_post_id'=>array('type'=>'integer','minimum'=>1),
      'elementor_widget_id'=>array('type'=>'string','minLength'=>1),
      'product_id'=>array('type'=>'integer','minimum'=>1),
      'gutenberg_post_id'=>array('type'=>'integer','minimum'=>1),
      'include_file'=>array('type'=>'boolean'),
      'include_reject'=>array('type'=>'boolean'),
      'include_elementor_settings'=>array('type'=>'boolean'),
      'include_elementor_create'=>array('type'=>'boolean')
     ),
     'additionalProperties'=>false
    )
   ,'system.regression_matrix'=>array(
     'type'=>'object',
     'required'=>array('content_post_id','elementor_post_id','elementor_widget_id','product_id'),
     'properties'=>array(
      'content_post_id'=>array('type'=>'integer','minimum'=>1),
      'elementor_post_id'=>array('type'=>'integer','minimum'=>1),
      'elementor_widget_id'=>array('type'=>'string','minLength'=>1),
      'product_id'=>array('type'=>'integer','minimum'=>1),
      'iterations'=>array('type'=>'integer','minimum'=>1,'maximum'=>3)
     ),
     'additionalProperties'=>false
    )
   ,'system.regression_history'=>array('type'=>'object','properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>50),'kind'=>array('type'=>'string','enum'=>array('all','regression_run','regression_matrix')),'include_tools'=>array('type'=>'boolean')),'additionalProperties'=>false)
   ,'system.coverage_report'=>array('type'=>'object','properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>50)),'additionalProperties'=>false)
   ,'system.security_fuzz'=>array('type'=>'object','properties'=>array('iterations'=>array('type'=>'integer','minimum'=>1,'maximum'=>10)),'additionalProperties'=>false)
   ,'batch.plan'=>array('type'=>'object','required'=>array('items'),'properties'=>array('items'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>array('type'=>'object','required'=>array('tool'),'properties'=>array('tool'=>array('type'=>'string','minLength'=>1),'arguments'=>array('type'=>'object')),'additionalProperties'=>false))),'additionalProperties'=>false)
   ,'batch.preflight'=>array('type'=>'object','required'=>array('items'),'properties'=>array('items'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>array('type'=>'object','required'=>array('tool'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'tool'=>array('type'=>'string','minLength'=>1),'arguments'=>array('type'=>'object'),'depends_on'=>array('type'=>'array','items'=>array('type'=>'string'))),'additionalProperties'=>false))),'additionalProperties'=>false)
   ,'batch.dry_run'=>array('type'=>'object','required'=>array('items'),'properties'=>array('items'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>array('type'=>'object','required'=>array('tool'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'tool'=>array('type'=>'string','minLength'=>1),'arguments'=>array('type'=>'object'),'depends_on'=>array('type'=>'array','items'=>array('type'=>'string'))),'additionalProperties'=>false))),'additionalProperties'=>false)
   ,'batch.create'=>array('type'=>'object','required'=>array('items'),'properties'=>array('label'=>array('type'=>'string'),'items'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>array('type'=>'object','required'=>array('tool'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'tool'=>array('type'=>'string','minLength'=>1),'arguments'=>array('type'=>'object'),'depends_on'=>array('type'=>'array','items'=>array('type'=>'string'))),'additionalProperties'=>false))),'additionalProperties'=>false)
   ,'batch.list'=>array('type'=>'object','properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>50)),'additionalProperties'=>false)
   ,'batch.status'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'include_arguments'=>array('type'=>'boolean')),'additionalProperties'=>false)
   ,'batch.resume'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'max_steps'=>array('type'=>'integer','minimum'=>1,'maximum'=>20),'expected_version'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'batch.pause'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'reason'=>array('type'=>'string'),'expected_version'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'batch.retry'=>array('type'=>'object','required'=>array('id','step_id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'step_id'=>array('type'=>'string','minLength'=>1),'expected_version'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'batch.cancel'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'reason'=>array('type'=>'string'),'expected_version'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'batch.rollback'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1),'expected_version'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
   ,'batch.receipt'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1)),'additionalProperties'=>false)
   ,'batch.proof'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'string','minLength'=>1)),'additionalProperties'=>false)
   ,'elementor.patch_settings'=>array('type'=>'object','required'=>array('post_id','widget_id','changes'),'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'changes'=>array('type'=>'object')),'additionalProperties'=>false)
   ,'file.write_sandbox'=>array('type'=>'object','required'=>array('scope','path','content'),'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string','minLength'=>1),'content'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'file.diff'=>array('type'=>'object','required'=>array('scope','path','proposed_content'),'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string','minLength'=>1),'proposed_content'=>array('type'=>'string')),'additionalProperties'=>false)
   ,'operation.reject'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'additionalProperties'=>false)
  );
  $read=array(
   'wp.get_site','wp.inspect_html','wp.get_theme_mods','wp.list_plugins','wp.list_themes','wp.list_post_types','wp.list_taxonomies','wp.list_media','wp.list_menus','wp.list_options','wp.list_users','wp.list_comments','wp.list_widgets','wp.health','wp.diagnostics','wp.list_operations','operation.get','operation.verify','operation.proof','operation.preview_link',
   'wp.list_files','wp.search_files','wp.execution_readiness','elementor.get_structure','elementor.get_widget','elementor.get_schema','elementor.get_globals','woocommerce.get_product','operation.get_receipt',
   'gutenberg.get_blocks','gutenberg.get_outline','gutenberg.locate_block','builder.catalog','builder.detect','system.capabilities',
   'wp.read_content','wp.read_post','wp.read_meta','wp.read_option','wp.rest_routes','wp.rest_read','file.read','wp.file.outline','woocommerce.search_products','woocommerce.get_variations','woocommerce.search_orders','operation.status','operation.diff','preview.get','verify.operation','proof.operation','elementor.inspect_page','elementor.inspect_widget','system.manifest','batch.plan','batch.preflight','batch.dry_run','batch.list','batch.status','batch.receipt','batch.proof','file.diff','system.tool_catalog','system.self_test','system.security_status','system.test_automation_status','system.regression_history','system.coverage_report','system.security_fuzz'
  );
  $tools=array();
  $descriptions=array(
   'wp.inspect_html'=>'Inspect public HTML. Use mode=outline first, then a bounded selector; JavaScript is not executed.',
   'wp.file.outline'=>'List file landmarks and line numbers without returning the full file; use file.read with a line range next.',
   'file.read'=>'Read a scoped theme file; start_line and end_line return a bounded excerpt with snapshot hash.',
   'wp.get_theme_mods'=>'Read one or up to 100 saved settings of the active WordPress theme; values retain their types.',
   'wp.rest_routes'=>'Discover registered same-site REST GET routes without invoking them.',
   'wp.rest_read'=>'Call a registered same-site WordPress REST GET route through a redacted, bounded resolver; external URLs, WordPress user endpoints and sensitive/internal routes are blocked.',
   'woocommerce.get_variations'=>'Read paginated WooCommerce product variations without customer data.',
   'woocommerce.search_orders'=>'Read paginated WooCommerce order summaries without customer PII.',
   'batch.preflight'=>'Validate a dependency-aware batch graph and execution order without executing any child operation.',
   'batch.dry_run'=>'Validate executor support, dependency depth, payload size and expected sandbox pause points without creating a job.',
   'batch.list'=>'List resumable batch jobs created by this MCP client.',
   'batch.status'=>'Read a resumable batch job, checkpoint version and child-step states.',
   'batch.receipt'=>'Read a compact batch-level execution receipt with child operation states and bounded checkpoint events.',
   'batch.proof'=>'Generate a SHA-256 batch proof bound to the site, installation, receipt payload and build.'
  );
  foreach($read as $name){
   if($name==='wp.file.outline'&&!OAI_Platform::content_navigation_allowed())continue;
   $description=$descriptions[$name]??('WP AI Admin read tool: '.$name);
   $tools[]=self::tool($name,$description,$schemas[$name]??array('type'=>'object'),array('readOnlyHint'=>true,'destructiveHint'=>false));
  }
  $tools[]=self::tool(
   'system.regression_run',
   'Run a strictly sequential reversible regression suite inside an active scoped Test Automation session. Each mutation uses the normal sandbox lifecycle and must be rolled back and verified before the next scenario starts.',
   $schemas['system.regression_run'],
   array('readOnlyHint'=>false,'destructiveHint'=>true)
  );
  $tools[]=self::tool(
   'system.regression_matrix',
   'Run the extended bounded regression matrix: safe reads, negative security checks, schema/alias coverage, previews, and sequential reversible mutation scenarios. Requires active scoped Test Automation Mode.',
   $schemas['system.regression_matrix'],
   array('readOnlyHint'=>false,'destructiveHint'=>true)
  );
  $tools[]=self::tool('batch.create','Create a durable resumable batch job after dependency preflight. This writes only plugin job state; it does not change live site content.',$schemas['batch.create'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('batch.resume','Resume a leased/versioned batch checkpoint. Read steps execute; write steps create normal sandbox child operations and pause for the existing approval/apply lifecycle.',$schemas['batch.resume'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('batch.pause','Manually pause future batch scheduling without cancelling steps or altering existing child operations.',$schemas['batch.pause'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('batch.retry','Reset one failed/rolled-back/cancelled batch step for a bounded retry. Retry is blocked while any child operation anywhere in the job is unresolved.',$schemas['batch.retry'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('batch.cancel','Cancel remaining pending batch steps without silently rejecting or applying child operations.',$schemas['batch.cancel'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('batch.rollback','Reconcile applied batch child operations in reverse order. Outside Test Automation, live rollback still requires wp-admin human confirmation.',$schemas['batch.rollback'],array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('wordpress.execute','Universal WordPress command resolver. Reads execute directly; writes remain plan-only.',array('type'=>'object','required'=>array('command'),'additionalProperties'=>false,'properties'=>array('command'=>array('type'=>'string','minLength'=>1),'mode'=>array('type'=>'string','enum'=>array('read','plan')))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('gutenberg.patch','Create a sandbox Gutenberg patch; live content is not written.',array('type'=>'object','required'=>array('post_id','selector'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'selector'=>array('type'=>'object'),'attrs'=>array('type'=>'object'),'html'=>array('type'=>'string'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('content.patch','Create a sandbox content patch; live content is not written.',array('type'=>'object','required'=>array('post_id','field','old_content','new_content'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'field'=>array('type'=>'string'),'old_content'=>array('type'=>'string'),'new_content'=>array('type'=>'string'),'replace_all'=>array('type'=>'boolean'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('woocommerce.patch','Create a sandbox WooCommerce product patch; live data is not written.',array('type'=>'object','required'=>array('product_id','changes'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'changes'=>array('type'=>'object'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('woocommerce.update_product_sandbox','Modernized WooCommerce sandbox update. Strict product fields only; live data is not written until human approval.', $schemas['woocommerce.update_product_sandbox'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('elementor.create_draft','Create an Elementor draft from data, or clone layout and widget tree from source_post_id when data is omitted. Newly created pages use Elementor as the sole content source.', $schemas['elementor.create_draft'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('elementor.patch_widget','Create a sandbox patch for an existing Elementor HTML widget. Requires an exact old_html snapshot; live content is not written until human approval and apply.',array('type'=>'object','required'=>array('post_id','widget_id','old_html','new_html'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1),'old_html'=>array('type'=>'string'),'new_html'=>array('type'=>'string'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('elementor.patch_fragment','Replace one exact text, image tag or HTML block within an existing Elementor HTML widget. Supply the current SHA-256 from elementor.get_widget; rejects stale or ambiguous fragments. Creates a sandbox operation.', $schemas['elementor.patch_fragment'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('elementor.patch_settings','Create a sandbox patch for Elementor widget settings; live data is not written until human approval.', $schemas['elementor.patch_settings'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('file.write_sandbox','Create a sandbox file write using existing file scopes and rollback protection.', $schemas['file.write_sandbox'],array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('operation.reject','Reject requires human wp-admin confirmation by default; scoped Test Automation Mode can act only on session-tagged test operations.', $schemas['operation.reject'],array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('operation.approve','Approval requires human wp-admin confirmation by default; scoped Test Automation Mode can act only on session-tagged test operations.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('operation.apply','Apply requires human wp-admin confirmation by default; scoped Test Automation Mode can act only on session-tagged test operations and still runs conflict/verify guards.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('operation.rollback','Rollback requires human wp-admin confirmation by default; scoped Test Automation Mode can act only on session-tagged test operations and still runs rollback conflict/verify guards.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  return $tools;
 }
 private static function call_tool($id,$p){
  $raw_name=(string)($p['name']??''); $name=sanitize_key(str_replace('.','_',strtolower($raw_name))); $a=$p['arguments']??array(); if(!is_array($a))$a=array();
  $r=null;
  switch($name){
   case 'system_manifest':$out=OAI_Platform::manifest();break;
   case 'system_tool_catalog':$out=OAI_Platform::tool_catalog();break;
   case 'system_self_test':$out=OAI_Platform::self_test();break;
   case 'system_security_status':$out=OAI_Platform::security_status();break;
   case 'system_test_automation_status':$out=OAI_Test_Automation::status();break;
   case 'system_regression_history':$out=OAI_Regression_History::history(absint($a['limit']??20),(string)($a['kind']??'all'),!empty($a['include_tools']));break;
   case 'system_coverage_report':$out=OAI_Regression_History::coverage(absint($a['limit']??50));break;
   case 'system_security_fuzz':$out=OAI_Security_Fuzz::run(absint($a['iterations']??1));break;
   case 'system_regression_run':
    if(!class_exists('OAI_Regression_Runner')){$out=new WP_Error('oai_regression_unavailable','Regression runner is unavailable',array('status'=>503));break;}
    $out=OAI_Regression_Runner::run($a,(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));
    break;
   case 'system_regression_matrix':
    $out=OAI_Regression_Matrix::run($a,(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));
    break;
   case 'batch_plan':$out=OAI_Platform::batch_plan($a['items']??array());break;
   case 'batch_preflight':$out=OAI_Platform::batch_preflight($a['items']??array());break;
   case 'batch_dry_run':$out=OAI_Batch_Executor::dry_run($a['items']??array());break;
   case 'batch_create':$out=OAI_Batch_Executor::create($a['items']??array(),(string)($a['label']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;
   case 'batch_list':$out=OAI_Batch_Executor::list_jobs(absint($a['limit']??20),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;
   case 'batch_status':$out=OAI_Batch_Executor::get((string)($a['id']??''),!empty($a['include_arguments']),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;
   case 'batch_resume':$out=OAI_Batch_Executor::resume((string)($a['id']??''),absint($a['max_steps']??10),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0),absint($a['expected_version']??0));break;
   case 'batch_pause':$out=OAI_Batch_Executor::pause((string)($a['id']??''),(string)($a['reason']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0),absint($a['expected_version']??0));break;
   case 'batch_retry':$out=OAI_Batch_Executor::retry((string)($a['id']??''),(string)($a['step_id']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0),absint($a['expected_version']??0));break;
   case 'batch_cancel':$out=OAI_Batch_Executor::cancel((string)($a['id']??''),(string)($a['reason']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0),absint($a['expected_version']??0));break;
   case 'batch_rollback':$out=OAI_Batch_Executor::rollback((string)($a['id']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0),absint($a['expected_version']??0));break;
   case 'batch_receipt':$out=OAI_Batch_Executor::receipt((string)($a['id']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;
   case 'batch_proof':$out=OAI_Batch_Executor::proof((string)($a['id']??''),(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;
   case 'wp_read_content':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content');foreach($a as $k=>$v)$r->set_param($k,$v);if(isset($a['post_type']))$r->set_param('type',$a['post_type']);if(isset($a['per_page']))$r->set_param('limit',$a['per_page']);if(isset($a['page']))$r->set_param('page',$a['page']);$out=OAI_REST::content($r);break;
   case 'wp_read_post':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.absint($a['post_id']??0));$r->set_url_params(array('id'=>absint($a['post_id']??0)));foreach(array('include_content','include_elementor','include_css') as $k)if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);$out=OAI_REST::content_detail($r);break;
   case 'wp_read_meta':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.absint($a['post_id']??0).'/meta');$r->set_url_params(array('id'=>absint($a['post_id']??0)));$out=OAI_REST::content_meta($r);break;
   case 'wp_read_option':$r=new WP_REST_Request('GET','/okean-ai/v1/read/options');$r->set_param('keys',$a['keys']??'');$out=OAI_REST::options($r);break;
   case 'wp_rest_routes':$out=OAI_REST_Resolver::discover((string)($a['namespace']??''),(string)($a['search']??''),absint($a['limit']??100));break;
   case 'wp_rest_read':$out=OAI_REST_Resolver::read((string)($a['route']??''),is_array($a['params']??null)?$a['params']:array(),(string)($a['fields']??'summary'),absint($a['max_items']??50));break;
   case 'file_read':$r=new WP_REST_Request('GET','/okean-ai/v1/files/read');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::files_read($r);break;
   case 'wp_file_outline':$r=new WP_REST_Request('GET','/okean-ai/v1/files/outline');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::files_outline($r);break;
   case 'woocommerce_search_products':$out=OAI_WooCommerce::search((string)($a['query']??''),(string)($a['sku']??''),absint($a['limit']??20),absint($a['page']??1),(string)($a['fields']??'all'));break;
   case 'woocommerce_get_variations':$out=OAI_WooCommerce::variations(absint($a['product_id']??0),absint($a['page']??1),absint($a['limit']??20));break;
   case 'woocommerce_search_orders':$out=OAI_WooCommerce::orders(absint($a['page']??1),absint($a['limit']??20),(string)($a['status']??'any'));break;
   case 'operation_status':
   case 'operation_diff':$oid=absint($a['id']??0);$rr=new WP_REST_Request('GET','/okean-ai/v1/operations/'.$oid);$rr->set_url_params(array('id'=>$oid));$out=OAI_REST::operation($rr);break;
   case 'preview_get':$oid=absint($a['id']??0);$rr=new WP_REST_Request('GET','/okean-ai/v1/preview-link/'.$oid);$rr->set_url_params(array('id'=>$oid));$out=OAI_REST::preview_link($rr);break;
   case 'verify_operation':$oid=absint($a['id']??0);$rr=new WP_REST_Request('GET','/okean-ai/v1/verify/'.$oid);$rr->set_url_params(array('id'=>$oid));$out=OAI_REST::verify($rr);break;
   case 'proof_operation':$oid=absint($a['id']??0);$rr=new WP_REST_Request('GET','/okean-ai/v1/proof/'.$oid);$rr->set_url_params(array('id'=>$oid));$out=OAI_REST::proof($rr);break;
   case 'elementor_inspect_page':$post_id=absint($a['post_id']??0);$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.$post_id.'/elementor-tree');$r->set_url_params(array('id'=>$post_id));foreach(array('widget','limit','include_html') as $k){if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);}$out=OAI_REST::elementor_tree($r);break;
   case 'elementor_inspect_widget':$post_id=absint($a['post_id']??0);$widget_id=sanitize_text_field((string)($a['widget_id']??''));$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.$post_id.'/elementor/'.$widget_id);$r->set_url_params(array('id'=>$post_id,'widget_id'=>$widget_id));foreach(array('view','start_line','end_line','max_chars','expected_sha256') as $k)if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);$out=OAI_REST::elementor_node($r);break;
   case 'file_diff':$cur=OAI_Files::read((string)($a['scope']??''),(string)($a['path']??''));if(is_wp_error($cur)){$out=$cur;}else{$before=(string)($cur['content']??'');$after=(string)($a['proposed_content']??'');$out=array('scope'=>$a['scope']??'','path'=>$a['path']??'','diff'=>OAI_Operations::diff($before,$after),'before_sha256'=>hash('sha256',$before),'after_sha256'=>hash('sha256',$after));}break;
   case 'wp_inspect_html':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/html');foreach(array('url','post_id','selector','mode','max_results','max_chars','include_attributes','include_text','include_html','max_length') as $k){if(array_key_exists($k,$a))$rr->set_param($k,$a[$k]);}$out=OAI_HTML_Inspector::inspect($rr);break;
   case 'wp_get_theme_mods':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/theme-mods');if(array_key_exists('name',$a))$rr->set_param('name',$a['name']);$out=OAI_Theme_Mods::get($rr);break;
   case 'wp_get_site':$out=OAI_REST::site();break;
   case 'wp_list_plugins':$out=OAI_REST::plugins();break;
   case 'wp_list_themes':$out=OAI_REST::themes();break;
   case 'wp_list_post_types':$out=OAI_REST::post_types();break;
   case 'wp_list_taxonomies':$out=OAI_REST::taxonomies(new WP_REST_Request('GET','/okean-ai/v1/read/taxonomies'));break;
   case 'wp_list_media':$r=new WP_REST_Request('GET','/okean-ai/v1/read/media');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::media($r);break;
   case 'wp_list_menus':$out=OAI_REST::menus();break;
   case 'wp_list_options':$r=new WP_REST_Request('GET','/okean-ai/v1/read/options');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::options($r);break;
   case 'wp_list_users':$r=new WP_REST_Request('GET','/okean-ai/v1/read/users');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::users($r);break;
   case 'wp_list_comments':$r=new WP_REST_Request('GET','/okean-ai/v1/read/comments');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::comments($r);break;
   case 'wp_list_widgets':$out=OAI_REST::widgets();break;
   case 'wp_health':$out=OAI_REST::health();break;
   case 'wp_diagnostics':$out=OAI_REST::diagnostics();break;
   case 'wp_list_operations':$r=new WP_REST_Request('GET','/okean-ai/v1/sandbox');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::sandbox_list($r);break;
   case 'wp_list_files':$r=new WP_REST_Request('GET','/okean-ai/v1/files/list');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::files_list($r);break;
   case 'wp_search_files':$r=new WP_REST_Request('GET','/okean-ai/v1/files/search');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::files_search($r);break;
   case 'elementor_get_structure':
    $post_id=absint($a['post_id']??0);
    $r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.$post_id.'/elementor-tree');
    $r->set_url_params(array('id'=>$post_id));
    foreach(array('widget','limit','include_html') as $k){if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);}
    $out=OAI_REST::elementor_tree($r);
    break;
   case 'elementor_get_widget':
    $post_id=absint($a['post_id']??0); $widget_id=sanitize_text_field((string)($a['widget_id']??''));
    $r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.$post_id.'/elementor/'.$widget_id);
    $r->set_url_params(array('id'=>$post_id,'widget_id'=>$widget_id));
    foreach(array('view','start_line','end_line','max_chars','expected_sha256') as $k)if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);
    $out=OAI_REST::elementor_node($r);
    break;
   case 'elementor_get_schema':$out=OAI_Elementor::widget_schema((string)($a['widget_type']??''));break;
   case 'elementor_get_globals':$out=OAI_Elementor::globals();break;
   case 'woocommerce_get_product':
    $pid=absint($a['product_id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/read/woocommerce/'.$pid);
    $rr->set_url_params(array('id'=>$pid));
    $out=OAI_REST::woocommerce($rr);
    break;
   case 'operation_get_receipt':
    $oid=absint($a['id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/operations/'.$oid.'/receipt');
    $rr->set_url_params(array('id'=>$oid));
    $out=OAI_REST::receipt($rr);
    break;
   case 'operation_get':
    $oid=absint($a['id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/operations/'.$oid);
    $rr->set_url_params(array('id'=>$oid));
    $out=OAI_REST::operation($rr);
    break;
   case 'operation_verify':
    $oid=absint($a['id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/verify/'.$oid);
    $rr->set_url_params(array('id'=>$oid));
    $out=OAI_REST::verify($rr);
    break;
   case 'operation_proof':
    $oid=absint($a['id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/proof/'.$oid);
    $rr->set_url_params(array('id'=>$oid));
    $out=OAI_REST::proof($rr);
    break;
   case 'operation_preview_link':
    $oid=absint($a['id']??0);
    $rr=new WP_REST_Request('GET','/okean-ai/v1/preview-link/'.$oid);
    $rr->set_url_params(array('id'=>$oid));
    $out=OAI_REST::preview_link($rr);
    break;
   case 'wp_execution_readiness':$out=OAI_REST::execution_readiness();break;
   case 'builder_catalog':$out=OAI_Adapter_Catalog::catalog();break;
   case 'builder_detect':$out=OAI_Adapter_Catalog::detect(absint($a['post_id']??0));break;
   case 'system_capabilities':$out=array('capabilities'=>OAI_Capabilities::available(),'adapters'=>OAI_Adapter_Catalog::catalog(),'execution'=>OAI_REST::execution_readiness());break;
   case 'wordpress_execute':$cmd=$a['command']??null;$mode=sanitize_key((string)($a['mode']??'read'));$out=OAI_Execution_Dispatcher::dispatch($cmd,$mode);break;
   case 'gutenberg_get_blocks':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0));$rr->set_url_params(array('id'=>absint($a['post_id']??0)));if(isset($a['view']))$rr->set_param('view',$a['view']);$out=OAI_REST::gutenberg_resolved($rr);break;
   case 'gutenberg_get_outline':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0));$rr->set_url_params(array('id'=>absint($a['post_id']??0)));$rr->set_param('view','outline');$out=OAI_REST::gutenberg_resolved($rr);break;
   case 'gutenberg_locate_block':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0).'/locate');$rr->set_url_params(array('id'=>absint($a['post_id']??0)));foreach(array('block_name','path','text','html_contains','view') as $k){if(array_key_exists($k,$a))$rr->set_param($k,$a[$k]);}$out=OAI_REST::gutenberg_locate($rr);break;
   case 'gutenberg_patch':$rr=new WP_REST_Request('POST','/okean-ai/v1/gutenberg/patch');foreach($a as $k=>$v)$rr->set_param($k,$v);$out=OAI_REST::gutenberg_patch($rr);break;
   case 'content_patch':$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::content_patch($r);break;
   case 'elementor_patch_settings':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch');$r->set_param('target',absint($a['post_id']??0).':'.sanitize_text_field((string)($a['widget_id']??'')));$r->set_param('changes',$a['changes']??array());$out=OAI_REST::elementor_patch($r);break;
   case 'file_write_sandbox':$r=new WP_REST_Request('POST','/okean-ai/v1/files/write');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::files_write($r);break;
   case 'operation_reject':
    if(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()){$out=OAI_Test_Automation::perform(absint($a['id']??0),'reject',(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;}
    return self::response($id,null,array('code'=>-32003,'message'=>'Human rejection is required in wp-admin unless scoped Test Automation Mode is active for this operation'));
   case 'woocommerce_update_product_sandbox':
   case 'woocommerce_patch':$r=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::woocommerce_patch($r);break;
   case 'elementor_create_draft':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::elementor_create_page($r);break;
   case 'elementor_patch_widget':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-widget');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::elementor_patch_widget($r);break;
   case 'elementor_patch_fragment':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-fragment');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::elementor_patch_fragment($r);break;
   case 'operation_approve':
    if(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()){$out=OAI_Test_Automation::perform(absint($a['id']??0),'approve',(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;}
    return self::response($id,null,array('code'=>-32003,'message'=>'Human approval is required in wp-admin unless scoped Test Automation Mode is active for this operation'));
   case 'operation_apply':
    $license=OAI_License::can('mcp_apply');if(is_wp_error($license))return self::response($id,null,array('code'=>-32004,'message'=>$license->get_error_message()));
    if(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()){$out=OAI_Test_Automation::perform(absint($a['id']??0),'apply',(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;}
    return self::response($id,null,array('code'=>-32003,'message'=>'Human approval required in wp-admin before apply'));
   case 'operation_rollback':
    $license=OAI_License::can('mcp_rollback');if(is_wp_error($license))return self::response($id,null,array('code'=>-32004,'message'=>$license->get_error_message()));
    if(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()){$out=OAI_Test_Automation::perform(absint($a['id']??0),'rollback',(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));break;}
    return self::response($id,null,array('code'=>-32003,'message'=>'Rollback must be confirmed in wp-admin'));
   default:return self::response($id,null,array('code'=>-32602,'message'=>'Unknown tool'));
  }
  $sandbox_tools=array('gutenberg_patch','content_patch','woocommerce_patch','woocommerce_update_product_sandbox','elementor_create_draft','elementor_patch_widget','elementor_patch_fragment','elementor_patch_settings','file_write_sandbox');
  if(!is_wp_error($out) && in_array($name,$sandbox_tools,true) && is_array($out) && !empty($out['id']) && class_exists('OAI_Test_Automation')){
   $tag=OAI_Test_Automation::tag_operation(absint($out['id']),$raw_name,(string)(self::$auth_context['client_id']??''),absint(self::$auth_context['user_id']??0));
   if(is_array($tag))$out['test_automation']=$tag;
  }
  if(is_wp_error($out))return self::response($id,null,array('code'=>-32000,'message'=>$out->get_error_message(),'data'=>$out->get_error_data()));
  return self::response($id,array('content'=>array(array('type'=>'text','text'=>wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))));
 }
}
