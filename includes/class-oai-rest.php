<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_REST {
 private static function is_list_array($a){ if(function_exists('array_is_list')) return array_is_list($a); $i=0; foreach($a as $k=>$v){if($k!==$i++) return false;} return true; }
 public static function token(){return (string)(get_option('oai_bearer_token','') ?: get_option('oai_api_token',''));}
 private static function authorization_header($req){
  $auth=(string)$req->get_header('authorization');
  if(!$auth) $auth=(string)$req->get_header('x-authorization');
  if(!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth=(string)wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
  if(!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) $auth=(string)wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
  return trim($auth);
 }
 public static function application_password_user($req){
  if(!function_exists('wp_authenticate_application_password')) return new WP_Error('oai_app_password_unavailable','WordPress Application Passwords are unavailable',array('status'=>501));
  $auth=self::authorization_header($req);
  if($auth==='' || stripos($auth,'basic ')!==0) return new WP_Error('oai_app_password_required','Basic Application Password authorization required',array('status'=>401));
  $raw=base64_decode(trim(substr($auth,6)),true);
  if($raw===false || strpos($raw,':')===false) return new WP_Error('oai_app_password_invalid','Invalid Basic authorization header',array('status'=>401));
  list($username,$password)=explode(':',$raw,2);
  $user=wp_authenticate_application_password(null,$username,$password);
  if(is_wp_error($user)) return new WP_Error('oai_app_password_invalid','Invalid WordPress Application Password',array('status'=>401));
  if(!$user || !($user instanceof WP_User) || !user_can($user,'manage_options')) return new WP_Error('oai_app_password_forbidden','Application Password belongs to a user without administrator capability',array('status'=>403));
  wp_set_current_user($user->ID);
  return $user;
 }
 public static function perm($req){
  if(current_user_can('manage_options')) return true;
  $auth=self::authorization_header($req);
  if(stripos($auth,'basic ')===0){ $user=self::application_password_user($req); return !is_wp_error($user); }
  $p=preg_split('/\s+/',trim($auth));
  if(count($p)!==2 || strtolower($p[0])!=='bearer' || self::token()==='' || !hash_equals(self::token(),$p[1])) return false;
  // Bearer auth is a WordPress API credential, not a logged-in browser session.
  // Bind it to the local-owner administrator so callbacks that use current_user_can()
  // see the same security principal that authenticated the request.
  $uid=absint(get_option('oai_bearer_user_id',0));
  if(!$uid){
   $license=OAI_License::status();
   $uid=absint($license['owner_user_id']??0);
  }
  if($uid && user_can($uid,'manage_options')) wp_set_current_user($uid);
  return (bool)$uid && user_can($uid,'manage_options');
 }
 public static function auth_status_info($req){
  $user=wp_get_current_user();
  return array('ok'=>true,'application_passwords_supported'=>function_exists('wp_authenticate_application_password'),'authenticated_user'=>($user&&$user->exists())?array('id'=>(int)$user->ID,'login'=>$user->user_login,'display_name'=>$user->display_name,'administrator'=>user_can($user,'manage_options')):null,'authorization_modes'=>array('wordpress_admin_cookie','application_password_basic','ai_admin_bearer'));
 }
 public static function preview_token($id,$expires){ return hash_hmac('sha256',(string)(int)$id.'|'.(string)(int)$expires,wp_salt('auth')); }
 public static function preview_perm($req){
  /*
   * Preview supports both authentication modes used by the API:
   * 1) Bearer token / WP admin, like the other Okean AI endpoints.
   * 2) A short-lived signed preview_token used by the WP admin UI links.
   *
   * 0.5.0 accidentally exposed only mode #2 here, so direct API calls
   * to /preview/{id} with the valid Bearer token returned 401.
   */
  if(self::perm($req)) return true;
  $id=absint($req->get_param('id')); $token=(string)$req->get_param('preview_token'); $expires=absint($req->get_param('expires'));
  if(!$id || !$token || !$expires || $expires < time()) return false;
  return hash_equals(self::preview_token($id,$expires),$token);
 }
 public static function register_routes(){ $n='okean-ai/v1'; $args=array('permission_callback'=>array(__CLASS__,'perm'));
  register_rest_route($n,'/status',array('methods'=>'GET','callback'=>array(__CLASS__,'status'))+$args);
  register_rest_route($n,'/mcp/status',array('methods'=>'GET','callback'=>array('OAI_MCP','info'))+$args);
  register_rest_route($n,'/mcp/token',array('methods'=>'POST','callback'=>array('OAI_MCP','admin_generate_token'))+$args);
  register_rest_route($n,'/license/status',array('methods'=>'GET','callback'=>array(__CLASS__,'license_status'))+$args);
  register_rest_route($n,'/auth/status',array('methods'=>'GET','callback'=>array(__CLASS__,'auth_status'))+$args);
  register_rest_route($n,'/license/local-activate',array('methods'=>'POST','callback'=>array(__CLASS__,'license_local_activate'),'permission_callback'=>array(__CLASS__,'perm')));
  register_rest_route($n,'/license/local-deactivate',array('methods'=>'POST','callback'=>array(__CLASS__,'license_local_deactivate'),'permission_callback'=>array(__CLASS__,'perm')));
  register_rest_route($n,'/agent/intent',array('methods'=>'POST','callback'=>array(__CLASS__,'intent_normalize'))+$args);
  register_rest_route($n,'/agent/plan',array('methods'=>'POST','callback'=>array(__CLASS__,'agent_plan'))+$args);
  register_rest_route($n,'/operations/catalog',array('methods'=>'GET','callback'=>array(__CLASS__,'operation_catalog'))+$args);
  register_rest_route($n,'/read/discover',array('methods'=>'POST','callback'=>array(__CLASS__,'read_discover'))+$args);
  register_rest_route($n,'/errors/contract',array('methods'=>'GET','callback'=>array(__CLASS__,'error_contract'))+$args);
  register_rest_route($n,'/agent/manifest',array('methods'=>'GET','callback'=>array('OAI_Agent','manifest'),'permission_callback'=>array('OAI_Agent','perm')));
  register_rest_route($n,'/agent/execute',array('methods'=>'POST','callback'=>array('OAI_Agent','execute'),'permission_callback'=>array('OAI_Agent','perm')));
  register_rest_route($n,'/runtime/fingerprint',array('methods'=>'GET','callback'=>array(__CLASS__,'runtime_fingerprint'))+$args);
 register_rest_route($n,'/execution/readiness',array('methods'=>'GET','callback'=>array(__CLASS__,'execution_readiness'))+$args);
  register_rest_route($n,'/execution/plan',array('methods'=>'POST','callback'=>array(__CLASS__,'execution_plan'))+$args);
  register_rest_route($n,'/execution/wordpress',array('methods'=>'POST','callback'=>array(__CLASS__,'execution_wordpress'))+$args);
  register_rest_route($n,'/execution/trace',array('methods'=>array('GET','POST'),'callback'=>array(__CLASS__,'execution_trace'))+$args);
  register_rest_route($n,'/read/gutenberg/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'gutenberg_resolved'))+$args);
  register_rest_route($n,'/read/gutenberg/(?P<id>\d+)/locate',array('methods'=>'GET','callback'=>array(__CLASS__,'gutenberg_locate'))+$args);
  register_rest_route($n,'/gutenberg/patch',array('methods'=>'POST','callback'=>array(__CLASS__,'gutenberg_patch'))+$args);
  register_rest_route($n,'/read/site',array('methods'=>'GET','callback'=>array(__CLASS__,'site'))+$args);
  register_rest_route($n,'/read/plugins',array('methods'=>'GET','callback'=>array(__CLASS__,'plugins'))+$args);
  register_rest_route($n,'/read/capabilities',array('methods'=>'GET','callback'=>array(__CLASS__,'capabilities'))+$args);
  register_rest_route($n,'/read/adapters',array('methods'=>'GET','callback'=>array(__CLASS__,'adapter_catalog'))+$args);
  register_rest_route($n,'/read/adapters/detect/(?P<id>\\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'adapter_detect'))+$args);
  register_rest_route($n,'/read/post-types',array('methods'=>'GET','callback'=>array(__CLASS__,'post_types'))+$args);
  register_rest_route($n,'/read/taxonomies',array('methods'=>'GET','callback'=>array(__CLASS__,'taxonomies'))+$args);
  register_rest_route($n,'/read/media',array('methods'=>'GET','callback'=>array(__CLASS__,'media'))+$args);
  register_rest_route($n,'/read/menus',array('methods'=>'GET','callback'=>array(__CLASS__,'menus'))+$args);
  register_rest_route($n,'/read/themes',array('methods'=>'GET','callback'=>array(__CLASS__,'themes'))+$args);
  register_rest_route($n,'/read/options',array('methods'=>'GET','callback'=>array(__CLASS__,'options'))+$args);
  register_rest_route($n,'/read/users',array('methods'=>'GET','callback'=>array(__CLASS__,'users'))+$args);
  register_rest_route($n,'/read/comments',array('methods'=>'GET','callback'=>array(__CLASS__,'comments'))+$args);
  register_rest_route($n,'/read/widgets',array('methods'=>'GET','callback'=>array(__CLASS__,'widgets'))+$args);
  register_rest_route($n,'/read/health',array('methods'=>'GET','callback'=>array(__CLASS__,'health'))+$args);
  register_rest_route($n,'/diagnostics',array('methods'=>'GET','callback'=>array(__CLASS__,'diagnostics'))+$args);
  register_rest_route($n,'/plan',array('methods'=>'POST','callback'=>array(__CLASS__,'plan'))+$args);

  register_rest_route($n,'/read/content',array('methods'=>'GET','callback'=>array(__CLASS__,'content'))+$args);
  register_rest_route($n,'/read/content/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'content_detail'))+$args);
  register_rest_route($n,'/read/content/(?P<id>\d+)/meta',array('methods'=>'GET','callback'=>array(__CLASS__,'content_meta'))+$args);
  register_rest_route($n,'/read/content/(?P<id>\d+)/elementor',array('methods'=>'GET','callback'=>array(__CLASS__,'elementor_find'))+$args);
  register_rest_route($n,'/read/content/(?P<id>\d+)/elementor-tree',array('methods'=>'GET','callback'=>array(__CLASS__,'elementor_tree'))+$args);
  register_rest_route($n,'/read/content/(?P<id>\d+)/elementor/(?P<widget_id>[A-Za-z0-9_-]+)',array('methods'=>'GET','callback'=>array(__CLASS__,'elementor_node'))+$args);
  register_rest_route($n,'/read/elementor/schema/(?P<widget_type>[A-Za-z0-9_-]+)',array('methods'=>'GET','callback'=>array(__CLASS__,'elementor_schema'))+$args);
  register_rest_route($n,'/read/elementor/globals',array('methods'=>'GET','callback'=>array(__CLASS__,'elementor_globals'))+$args);
  register_rest_route($n,'/elementor/patch',array('methods'=>'POST','callback'=>array(__CLASS__,'elementor_patch'))+$args);
  register_rest_route($n,'/elementor/create-page',array('methods'=>'POST','callback'=>array(__CLASS__,'elementor_create_page'))+$args);
  register_rest_route($n,'/preview-page/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'preview_page'),'permission_callback'=>array(__CLASS__,'preview_perm')));
  register_rest_route($n,'/read/products',array('methods'=>'GET','callback'=>array(__CLASS__,'products'))+$args);
  register_rest_route($n,'/sandbox',array('methods'=>'GET','callback'=>array(__CLASS__,'sandbox_list'))+$args);
  register_rest_route($n,'/sandbox',array('methods'=>'POST','callback'=>array(__CLASS__,'sandbox_create'))+$args);
  register_rest_route($n,'/sandbox/(?P<id>\d+)/(approve|reject|apply|rollback)',array('methods'=>'POST','callback'=>array(__CLASS__,'sandbox_action'))+$args);
  register_rest_route($n,'/preview-link/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'preview_link'),'permission_callback'=>array(__CLASS__,'perm')));
  register_rest_route($n,'/preview/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'preview'),'permission_callback'=>array(__CLASS__,'preview_perm')));
  register_rest_route($n,'/files/list',array('methods'=>'GET','callback'=>array(__CLASS__,'files_list'))+$args);
  register_rest_route($n,'/workspace',array('methods'=>'GET','callback'=>array(__CLASS__,'workspace_status'))+$args);
  register_rest_route($n,'/workspace/create',array('methods'=>'POST','callback'=>array(__CLASS__,'workspace_create'))+$args);
  register_rest_route($n,'/workspace/files',array('methods'=>'GET','callback'=>array(__CLASS__,'workspace_files'))+$args);
  register_rest_route($n,'/workspace/read',array('methods'=>'GET','callback'=>array(__CLASS__,'workspace_read'))+$args);
  register_rest_route($n,'/workspace/write',array('methods'=>'POST','callback'=>array(__CLASS__,'workspace_write'))+$args);
  register_rest_route($n,'/workspace/surfaces',array('methods'=>'GET','callback'=>array(__CLASS__,'workspace_surfaces'))+$args);
  register_rest_route($n,'/surfaces',array('methods'=>'GET','callback'=>array(__CLASS__,'surfaces_catalog'))+$args);
  register_rest_route($n,'/files/read',array('methods'=>'GET','callback'=>array(__CLASS__,'files_read'))+$args);
  register_rest_route($n,'/files/search',array('methods'=>'GET','callback'=>array(__CLASS__,'files_search'))+$args);
  register_rest_route($n,'/files/write',array('methods'=>'POST','callback'=>array(__CLASS__,'files_write'))+$args);
  register_rest_route($n,'/audit',array('methods'=>'GET','callback'=>array(__CLASS__,'audit'))+$args);
  register_rest_route($n,'/verify/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'verify'))+$args);
  register_rest_route($n,'/proof/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'proof'))+$args);
  register_rest_route($n,'/operations/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'operation'))+$args);
  register_rest_route($n,'/capabilities/foundation',array('methods'=>'GET','callback'=>array(__CLASS__,'foundation_capabilities'))+$args);
  register_rest_route($n,'/operations/(?P<id>\d+)/receipt',array('methods'=>'GET','callback'=>array(__CLASS__,'receipt'))+$args);
  register_rest_route($n,'/read/gutenberg/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'gutenberg'))+$args);
  register_rest_route($n,'/read/woocommerce/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'woocommerce'))+$args);
  register_rest_route($n,'/content/patch',array('methods'=>'POST','callback'=>array(__CLASS__,'content_patch'))+$args);
  register_rest_route($n,'/woocommerce/patch',array('methods'=>'POST','callback'=>array(__CLASS__,'woocommerce_patch'))+$args);

 }

 public static function license_status(){return OAI_License::status();}
 public static function auth_status($req){return self::auth_status_info($req);}
 public static function license_local_activate(){return OAI_License::activate_local_owner();}
 public static function license_local_deactivate(){return OAI_License::deactivate_local_owner();}
 public static function intent_normalize($req){ $context=$req->get_param('context'); return OAI_Intent::normalize($req->get_param('intent'),is_array($context)?$context:array()); }
 public static function operation_catalog(){ return array('contract'=>'ai-admin/operations/v1','items'=>OAI_Operation_Schema::catalog()); }
 public static function read_discover($req){ $intent=$req->get_param('intent'); $normalized=is_array($intent)?$intent:OAI_Intent::normalize($intent,$req->get_param('context')); if(is_wp_error($normalized))return $normalized; $params=$req->get_json_params(); if(!is_array($params))$params=array(); return OAI_Read_Engine::discover($normalized,$params); }
 public static function error_contract(){ return array('contract'=>'ai-admin/v1','shape'=>array('ok'=>'boolean','code'=>'string','message'=>'string','status'=>'integer','details'=>'object')); }
 public static function agent_plan($req){ $raw=$req->get_param('intent'); $normalized=is_array($raw)?$raw:OAI_Intent::normalize($raw,$req->get_param('context')); if(is_wp_error($normalized))return $normalized; $plan=OAI_Planner::plan_normalized($normalized); return $plan; }
 public static function capabilities(){return OAI_Capabilities::available();}
 public static function adapter_catalog(){return OAI_Adapter_Catalog::catalog();}
 public static function adapter_detect($req){return OAI_Adapter_Catalog::detect(absint($req['id']));}
 public static function post_types(){return array('count'=>count(OAI_Read::post_types()),'items'=>OAI_Read::post_types());}
 public static function taxonomies($req){return OAI_Read::taxonomies($req);}
 public static function media($req){return OAI_Read::media($req);}
 public static function menus(){return OAI_Read::menus();}
 public static function themes(){return OAI_Read::themes();}
 public static function options($req){return OAI_Read::options($req);}
 public static function users($req){return OAI_Read::users($req);}
 public static function comments($req){return OAI_Read::comments($req);}
 public static function widgets(){return OAI_Read::widgets();}
 public static function health(){return OAI_Read::health();}
 public static function diagnostics(){return OAI_Diagnostics::run();}
 public static function elementor_schema($req){return OAI_Elementor::widget_schema((string)$req['widget_type']);}
 public static function elementor_globals(){return OAI_Elementor::globals();}
 public static function elementor_patch($req){
  $target=sanitize_text_field((string)$req->get_param('target'));
  $changes=$req->get_param('changes');
  if(!is_array($changes))return new WP_Error('oai_changes','changes must be an object',array('status'=>400));
  $req->set_param('type','elementor_widget_settings');
  $req->set_param('target',$target);
  $req->set_param('value',$changes);
  return self::sandbox_create($req);
 }
 public static function elementor_create_page($req){
  if(!OAI_Elementor::available())return new WP_Error('oai_elementor_unavailable','Elementor is not available',array('status'=>503));
  $title_b64=(string)$req->get_param('title_b64'); $excerpt_b64=(string)$req->get_param('excerpt_b64'); $slug_b64=(string)$req->get_param('slug_b64'); $data_b64=(string)$req->get_param('data_b64');
  $title=$title_b64!==''?self::decode_b64_text($title_b64):sanitize_text_field((string)$req->get_param('title'));
  $excerpt=$excerpt_b64!==''?self::decode_b64_text($excerpt_b64):sanitize_textarea_field((string)$req->get_param('excerpt'));
  $slug=$slug_b64!==''?sanitize_title(self::decode_b64_text($slug_b64)):sanitize_title((string)$req->get_param('slug'));
  if($title===false||$title==='')return new WP_Error('oai_title','title is required',array('status'=>400));
  if($excerpt===false||$slug===false)return new WP_Error('oai_payload','Invalid text payload',array('status'=>400));
  $template=sanitize_text_field((string)($req->get_param('page_template')?:'elementor_header_footer'));
  $template_type=sanitize_key((string)($req->get_param('template_type')?:'wp-page'));
  $status=sanitize_key((string)($req->get_param('final_status')?:'draft')); if(!in_array($status,array('draft','publish','private'),true))$status='draft';
  if($data_b64!==''){ $decoded=base64_decode($data_b64,true); if($decoded===false)return new WP_Error('oai_data_b64','Invalid base64 Elementor data',array('status'=>400)); $data=json_decode($decoded,true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('oai_data_json','Invalid JSON in data_b64',array('status'=>400,'message'=>json_last_error_msg())); }
  else { $data=$req->get_param('data'); if(is_string($data)){ $data=json_decode($data,true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('oai_data_json','Invalid JSON in data',array('status'=>400,'message'=>json_last_error_msg())); } }
  if(!is_array($data))return new WP_Error('oai_elementor_data','data must be an Elementor element array or element object',array('status'=>400));
  if(!self::is_list_array($data) && (isset($data['elType'])||isset($data['widgetType'])||isset($data['elements']))){$data=array($data);}
  $data=self::normalize_elementor_page_data($data); if(is_wp_error($data))return $data;

  $adapter=OAI_Builder_Manager::get('elementor');
  if(!$adapter)return new WP_Error('oai_builder_adapter','Elementor adapter is unavailable',array('status'=>500));
  $created=$adapter->create(array('title'=>$title,'excerpt'=>$excerpt,'slug'=>$slug,'status'=>'draft','template_type'=>$template_type,'post_type'=>'page','page_template'=>$template));
  if(is_wp_error($created))return $created;
  $post_id=(int)$created['id'];
  $saved=$adapter->save($post_id,$data,array('page_template'=>$template));
  if(is_wp_error($saved)){wp_delete_post($post_id,true);return $saved;}
  $verification=$adapter->verify($post_id,$data);
  if(empty($verification['ok'])){wp_delete_post($post_id,true);return new WP_Error('oai_elementor_verify','Elementor save completed but stored element data could not be verified',array('status'=>500,'verification'=>$verification));}

  $target='elementor_page:'.$post_id; $op='OAI-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999); $now=current_time('mysql',true); $expiry=gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS);
  $meta=array('preview_page_id'=>$post_id,'final_status'=>$status,'page_template'=>$template,'template_type'=>$template_type,'sandbox_artifact'=>true,'created_by'=>get_current_user_id(),'builder'=>'elementor','builder_verified'=>true,'elementor_data_bytes'=>$verification['bytes'],'before_exists'=>false);
  $diff=array('action'=>'create','builder'=>'elementor','before'=>null,'after'=>array('id'=>$post_id,'title'=>$title,'slug'=>get_post_field('post_name',$post_id),'status'=>$status,'data'=>$data,'verification'=>$verification));
  $rollback_descriptor=OAI_Lifecycle::descriptor('elementor_page_create',$target,null,array('before_exists'=>false,'preview_page_id'=>$post_id));
  $meta['rollback_descriptor']=$rollback_descriptor;
  $data_row=array('operation_id'=>$op,'status'=>'pending','type'=>'elementor_page_create','target'=>$target,'before_data'=>wp_json_encode(null),'after_data'=>wp_json_encode($data),'meta_data'=>wp_json_encode($meta),'diff_data'=>wp_json_encode($diff),'phase'=>'awaiting_approval','workspace_id'=>isset($meta['workspace_id'])?$meta['workspace_id']:null,'rollback_data'=>wp_json_encode($rollback_descriptor),'risk'=>'medium','created_at'=>$now,'expires_at'=>$expiry);
  $id=OAI_DB::insert($data_row); if(is_wp_error($id)){wp_delete_post($post_id,true);return $id;}
  $row=OAI_DB::get($id); OAI_Audit::log('created',$row); $out=self::row($id); $out['preview_page_id']=$post_id; $out['view_url']=get_permalink($post_id); $out['editor_url']=admin_url('post.php?post='.$post_id.'&action=elementor');
  $exp=time()+30*60; $token=self::preview_token($id,$exp); $out['preview_url']=add_query_arg(array('preview_token'=>$token,'expires'=>$exp),rest_url('okean-ai/v1/preview-page/'.$id)); $out['preview_expires']=$exp; $out['builder_verification']=$verification; return $out;
 }
 private static function decode_b64_text($value){
  $decoded=base64_decode((string)$value,true);
  if($decoded===false)return false;
  if(function_exists('mb_check_encoding') && !mb_check_encoding($decoded,'UTF-8'))return false;
  return $decoded;
 }
 private static function normalize_elementor_page_data($data){