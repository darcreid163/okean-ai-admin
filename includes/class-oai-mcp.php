<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_MCP {
 public static function register_routes(){
  register_rest_route('okean-ai','/mcp',array('methods'=>'POST','callback'=>array(__CLASS__,'handle'),'permission_callback'=>array(__CLASS__,'permission')));
  register_rest_route('okean-ai','/mcp',array('methods'=>'GET','callback'=>array(__CLASS__,'info'),'permission_callback'=>array(__CLASS__,'permission')));
 }
 public static function info(){return array('name'=>'Okean AI Admin MCP','version'=>OAI_VERSION,'protocol'=>'MCP Streamable HTTP / JSON-RPC 2.0','enabled'=>(bool)get_option('oai_mcp_enabled',false),'license'=>OAI_License::status(),'auth'=>'Bearer access token','write_model'=>'sandbox + human approval');}
 private static function token_hash(){return (string)get_option('oai_mcp_token_hash','');}
 public static function generate_token(){ $token='oai_mcp_'.wp_generate_password(56,false,false); update_option('oai_mcp_token_hash',hash('sha256',$token),false); update_option('oai_mcp_enabled',true,false); return $token; }
 public static function admin_generate_token(){
  if(!current_user_can('manage_options')) return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  $token=self::generate_token();
  return array('ok'=>true,'token'=>$token,'enabled'=>true,'message'=>'MCP token generated. Store it securely; it will not be shown again.');
 }
 private static function auth(){
  if(!get_option('oai_mcp_enabled',false))return new WP_Error('oai_mcp_disabled','MCP is disabled',array('status'=>403));
  $license=OAI_License::can('mcp'); if(is_wp_error($license))return $license;
  $auth='';
  if(isset($_SERVER['HTTP_AUTHORIZATION'])) $auth=trim((string)wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
  if(!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth=trim((string)wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
  if(!$auth)return new WP_Error('oai_mcp_auth','Bearer token or WordPress Application Password required',array('status'=>401));
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
  if(!self::token_hash()||!hash_equals(self::token_hash(),hash('sha256',$parts[1])))return new WP_Error('oai_mcp_auth','Invalid MCP token',array('status'=>401));
  return OAI_Rate_Limit::check('mcp',120,60);
 }
 public static function permission($req){ $x=self::auth($req); return is_wp_error($x)?$x:true; }
 private static function response($id,$result=null,$error=null){$r=array('jsonrpc'=>'2.0','id'=>$id);if($error!==null)$r['error']=$error;else$r['result']=$result;return new WP_REST_Response($r,200,array('Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store'));}
 public static function handle($req){
  $auth=self::auth();if(is_wp_error($auth))return $auth;
  $body=$req->get_json_params();if(!is_array($body))return self::response(null,null,array('code'=>-32600,'message'=>'Invalid Request'));
  $id=$body['id']??null;$method=(string)($body['method']??'');$params=is_array($body['params']??null)?$body['params']:array();
  if($method==='initialize')return self::response($id,array('protocolVersion'=>OAI_MCP_VERSION,'capabilities'=>array('tools'=>array('listChanged'=>false),'resources'=>array('subscribe'=>false,'listChanged'=>false),'prompts'=>array('listChanged'=>false)),'serverInfo'=>array('name'=>'okean-ai-admin','version'=>OAI_VERSION,'vendor'=>'Андриянов Н.Г.')));
  if($method==='notifications/initialized'||$method==='ping')return self::response($id,array());
  if($method==='tools/list')return self::response($id,array('tools'=>self::tools()));
  if($method==='resources/list')return self::response($id,array('resources'=>self::resources()));
  if($method==='resources/read')return self::resource_read($id,$params);
  if($method==='prompts/list')return self::response($id,array('prompts'=>self::prompts()));
  if($method==='tools/call')return self::call_tool($id,$params);
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
  if(!empty($annotations))$tool['annotations']=$annotations;
  return $tool;
 }
 private static function tools(){
  $schemas=array(
   'elementor.get_structure'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500),'include_html'=>array('type'=>'boolean'))),
   'elementor.get_widget'=>array('type'=>'object','required'=>array('post_id','widget_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'widget_id'=>array('type'=>'string','minLength'=>1))),
   'elementor.get_schema'=>array('type'=>'object','required'=>array('widget_type'),'additionalProperties'=>false,'properties'=>array('widget_type'=>array('type'=>'string','minLength'=>1))),
   'elementor.get_globals'=>array('type'=>'object','additionalProperties'=>false),
   'woocommerce.get_product'=>array('type'=>'object','required'=>array('product_id'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1))),
   'operation.get_receipt'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'wordpress.execute'=>array('type'=>'object','required'=>array('command'),'additionalProperties'=>false,'properties'=>array('command'=>array('type'=>'string','minLength'=>1),'mode'=>array('type'=>'string','enum'=>array('read','plan')))),
   'gutenberg.get_blocks'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1))),
   'gutenberg.locate_block'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'block_name'=>array('type'=>'string'),'path'=>array('type'=>'string'),'text'=>array('type'=>'string'),'html_contains'=>array('type'=>'string'))),
   'gutenberg.patch'=>array('type'=>'object','required'=>array('post_id','selector'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'selector'=>array('type'=>'object'),'attrs'=>array('type'=>'object'),'html'=>array('type'=>'string'))),
   'content.patch'=>array('type'=>'object','required'=>array('post_id','field','old_content','new_content'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'field'=>array('type'=>'string'),'old_content'=>array('type'=>'string'),'new_content'=>array('type'=>'string'),'replace_all'=>array('type'=>'boolean'))),
   'woocommerce.patch'=>array('type'=>'object','required'=>array('product_id','changes'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'changes'=>array('type'=>'object'))),
   'elementor.create_draft'=>array('type'=>'object','required'=>array('title','data'),'additionalProperties'=>false,'properties'=>array('title'=>array('type'=>'string'),'data'=>array('type'=>'array'),'page_template'=>array('type'=>'string'))),
   'builder.detect'=>array('type'=>'object','required'=>array('post_id'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1))),
   'wp.list_files'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('scope'=>array('type'=>'string'),'path'=>array('type'=>'string'))),
   'wp.search_files'=>array('type'=>'object','required'=>array('q'),'additionalProperties'=>false,'properties'=>array('scope'=>array('type'=>'string'),'q'=>array('type'=>'string','minLength'=>1),'path'=>array('type'=>'string'),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100))),
   'operation.approve'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.apply'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),
   'operation.rollback'=>array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)))
  );
  $read=array(
   'wp.get_site','wp.list_plugins','wp.list_themes','wp.list_post_types','wp.list_taxonomies','wp.list_media','wp.list_menus','wp.list_options','wp.list_users','wp.list_comments','wp.list_widgets','wp.health','wp.diagnostics','wp.list_operations',
   'wp.list_files','wp.search_files','wp.execution_readiness','elementor.get_structure','elementor.get_widget','elementor.get_schema','elementor.get_globals','woocommerce.get_product','operation.get_receipt',
   'gutenberg.get_blocks','gutenberg.locate_block','builder.catalog','builder.detect','system.capabilities'
  );
  $tools=array();
  foreach($read as $name){
   $tools[]=self::tool($name,'Okean AI read tool: '.$name,$schemas[$name]??array('type'=>'object'),array('readOnlyHint'=>true,'destructiveHint'=>false));
  }
  $tools[]=self::tool('wordpress.execute','Universal WordPress command resolver. Reads execute directly; writes remain plan-only.',array('type'=>'object','required'=>array('command'),'additionalProperties'=>false,'properties'=>array('command'=>array('type'=>'string','minLength'=>1),'mode'=>array('type'=>'string','enum'=>array('read','plan')))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('gutenberg.patch','Create a sandbox Gutenberg patch; live content is not written.',array('type'=>'object','required'=>array('post_id','selector'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'selector'=>array('type'=>'object'),'attrs'=>array('type'=>'object'),'html'=>array('type'=>'string'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('content.patch','Create a sandbox content patch; live content is not written.',array('type'=>'object','required'=>array('post_id','field','old_content','new_content'),'additionalProperties'=>false,'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1),'field'=>array('type'=>'string'),'old_content'=>array('type'=>'string'),'new_content'=>array('type'=>'string'),'replace_all'=>array('type'=>'boolean'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('woocommerce.patch','Create a sandbox WooCommerce product patch; live data is not written.',array('type'=>'object','required'=>array('product_id','changes'),'additionalProperties'=>false,'properties'=>array('product_id'=>array('type'=>'integer','minimum'=>1),'changes'=>array('type'=>'object'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('elementor.create_draft','Create and verify an Elementor draft preview through the builder-owned pipeline.',array('type'=>'object','required'=>array('title','data'),'additionalProperties'=>false,'properties'=>array('title'=>array('type'=>'string'),'data'=>array('type'=>'array'),'page_template'=>array('type'=>'string'))),array('readOnlyHint'=>false,'destructiveHint'=>false));
  $tools[]=self::tool('operation.approve','Approval endpoint reserved for the human wp-admin confirmation flow.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('operation.apply','Apply endpoint reserved for the human wp-admin confirmation flow.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  $tools[]=self::tool('operation.rollback','Rollback endpoint reserved for the human wp-admin confirmation flow.',array('type'=>'object','required'=>array('id'),'additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1))),array('readOnlyHint'=>false,'destructiveHint'=>true));
  return $tools;
 }
 private static function call_tool($id,$p){
  $raw_name=(string)($p['name']??''); $name=sanitize_key(str_replace('.','_',strtolower($raw_name))); $a=$p['arguments']??array(); if(!is_array($a))$a=array();
  $r=null;
  switch($name){
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
   case 'wp_list_operations':$out=OAI_REST::sandbox_list();break;
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
    $out=OAI_REST::elementor_node($r);
    break;
   case 'elementor_get_schema':$out=OAI_Elementor::widget_schema((string)($a['widget_type']??''));break;
   case 'elementor_get_globals':$out=OAI_Elementor::globals();break;
   case 'woocommerce_get_product':$out=OAI_REST::woocommerce((new WP_REST_Request('GET','/okean-ai/v1/read/woocommerce/'.absint($a['product_id']??0))));break;
   case 'operation_get_receipt':$out=OAI_REST::receipt((new WP_REST_Request('GET','/okean-ai/v1/operations/'.absint($a['id']??0).'/receipt')));break;
   case 'wp_execution_readiness':$out=OAI_REST::execution_readiness();break;
   case 'builder_catalog':$out=OAI_Adapter_Catalog::catalog();break;
   case 'builder_detect':$out=OAI_Adapter_Catalog::detect(absint($a['post_id']??0));break;
   case 'system_capabilities':$out=array('capabilities'=>OAI_Capabilities::available(),'adapters'=>OAI_Adapter_Catalog::catalog(),'execution'=>OAI_REST::execution_readiness());break;
   case 'wordpress_execute':$cmd=$a['command']??null;$mode=sanitize_key((string)($a['mode']??'read'));$out=OAI_Execution_Dispatcher::dispatch($cmd,$mode);break;
   case 'gutenberg_get_blocks':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0));$rr->set_url_params(array('id'=>absint($a['post_id']??0)));$out=OAI_REST::gutenberg_resolved($rr);break;
   case 'gutenberg_locate_block':$rr=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0).'/locate');$rr->set_url_params(array('id'=>absint($a['post_id']??0)));foreach(array('block_name','path','text','html_contains') as $k){if(array_key_exists($k,$a))$rr->set_param($k,$a[$k]);}$out=OAI_REST::gutenberg_locate($rr);break;
   case 'gutenberg_patch':$rr=new WP_REST_Request('POST','/okean-ai/v1/gutenberg/patch');foreach($a as $k=>$v)$rr->set_param($k,$v);$out=OAI_REST::gutenberg_patch($rr);break;
   case 'content_patch':$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::content_patch($r);break;
   case 'woocommerce_patch':$r=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::woocommerce_patch($r);break;
   case 'elementor_create_draft':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');foreach($a as $k=>$v)$r->set_param($k,$v);$out=OAI_REST::elementor_create_page($r);break;
   case 'operation_approve':return self::response($id,null,array('code'=>-32003,'message'=>'Human approval is required in wp-admin; MCP cannot approve operations'));
   case 'operation_apply':$license=OAI_License::can('mcp_apply');if(is_wp_error($license))return self::response($id,null,array('code'=>-32004,'message'=>$license->get_error_message()));return self::response($id,null,array('code'=>-32003,'message'=>'Human approval required in wp-admin before apply'));
   case 'operation_rollback':$license=OAI_License::can('mcp_apply');if(is_wp_error($license))return self::response($id,null,array('code'=>-32004,'message'=>$license->get_error_message()));return self::response($id,null,array('code'=>-32003,'message'=>'Rollback must be confirmed in wp-admin'));
   default:return self::response($id,null,array('code'=>-32602,'message'=>'Unknown tool'));
  }
  if(is_wp_error($out))return self::response($id,null,array('code'=>-32000,'message'=>$out->get_error_message(),'data'=>$out->get_error_data()));
  return self::response($id,array('content'=>array(array('type'=>'text','text'=>wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))));
 }
}
