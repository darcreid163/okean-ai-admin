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
  register_rest_route($n,'/agent/intent',array('methods'=>'POST','callback'=>array(__CLASS__,'intent_normalize'))+$args);
  register_rest_route($n,'/agent/plan',array('methods'=>'POST','callback'=>array(__CLASS__,'agent_plan'))+$args);
  register_rest_route($n,'/operations/catalog',array('methods'=>'GET','callback'=>array(__CLASS__,'operation_catalog'))+$args);
  register_rest_route($n,'/read/discover',array('methods'=>'POST','callback'=>array(__CLASS__,'read_discover'))+$args);
  register_rest_route($n,'/read/html',array('methods'=>'GET','callback'=>array('OAI_HTML_Inspector','inspect'))+$args);
  register_rest_route($n,'/visual-qa/start',array('methods'=>'POST','callback'=>array('OAI_Visual_QA','rest_start'))+$args);
  register_rest_route($n,'/visual-qa/result/(?P<probe>[A-Za-z0-9_-]+)',array('methods'=>'GET','callback'=>array('OAI_Visual_QA','rest_result'))+$args);
  register_rest_route($n,'/visual-qa/run/(?P<probe>[A-Za-z0-9_-]+)',array('methods'=>'GET','callback'=>array('OAI_Visual_QA','page'),'permission_callback'=>array('OAI_Visual_QA','signed_perm')));
  register_rest_route($n,'/visual-qa/frame/(?P<probe>[A-Za-z0-9_-]+)',array('methods'=>'GET','callback'=>array('OAI_Visual_QA','frame'),'permission_callback'=>array('OAI_Visual_QA','signed_perm')));
  register_rest_route($n,'/visual-qa/report/(?P<probe>[A-Za-z0-9_-]+)',array('methods'=>'POST','callback'=>array('OAI_Visual_QA','report'),'permission_callback'=>array('OAI_Visual_QA','signed_perm')));
  register_rest_route($n,'/read/theme-mods',array('methods'=>'GET','callback'=>array('OAI_Theme_Mods','get'))+$args);
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
  register_rest_route($n,'/files/outline',array('methods'=>'GET','callback'=>array(__CLASS__,'files_outline'))+$args);
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
  register_rest_route($n,'/elementor/patch-widget',array('methods'=>'POST','callback'=>array(__CLASS__,'elementor_patch_widget'))+$args);
  register_rest_route($n,'/elementor/patch-fragment',array('methods'=>'POST','callback'=>array(__CLASS__,'elementor_patch_fragment'))+$args);

 }

 public static function license_status(){return OAI_License::user_status();}
 public static function auth_status($req){return self::auth_status_info($req);}
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
  $license=OAI_License::can('elementor'); if(is_wp_error($license)) return $license;
  $target=sanitize_text_field((string)$req->get_param('target'));
  $changes=$req->get_param('changes');
  if(!is_array($changes))return new WP_Error('oai_changes','changes must be an object',array('status'=>400));
  $req->set_param('type','elementor_widget_settings');
  $req->set_param('target',$target);
  $req->set_param('value',$changes);
  return self::sandbox_create($req);
 }
 public static function elementor_create_page($req){
  $license=OAI_License::can('elementor'); if(is_wp_error($license)) return $license;
  if(!OAI_Elementor::available())return new WP_Error('oai_elementor_unavailable','Elementor is not available',array('status'=>503));
  $title_b64=(string)$req->get_param('title_b64'); $excerpt_b64=(string)$req->get_param('excerpt_b64'); $slug_b64=(string)$req->get_param('slug_b64'); $data_b64=(string)$req->get_param('data_b64');
  $title=$title_b64!==''?self::decode_b64_text($title_b64):sanitize_text_field((string)$req->get_param('title'));
  $excerpt=$excerpt_b64!==''?self::decode_b64_text($excerpt_b64):sanitize_textarea_field((string)$req->get_param('excerpt'));
  $slug=$slug_b64!==''?sanitize_title(self::decode_b64_text($slug_b64)):sanitize_title((string)$req->get_param('slug'));
  if($title===false||$title==='')return new WP_Error('oai_title','title is required',array('status'=>400));
  if($excerpt===false||$slug===false)return new WP_Error('oai_payload','Invalid text payload',array('status'=>400));
  $source_id=absint($req->get_param('source_post_id'));
  $source=$source_id?get_post($source_id):null;
  if($source_id && (!$source || $source->post_type!=='page' || !current_user_can('edit_post',$source_id)))return new WP_Error('oai_source_page','Readable editable source page required',array('status'=>403));
  if($source_id && get_post_meta($source_id,'_elementor_edit_mode',true)!=='builder')return new WP_Error('oai_source_page','Source page must be an Elementor document',array('status'=>422));
  $requested_template=$req->get_param('page_template');
  $template=sanitize_text_field((string)($requested_template!==null && $requested_template!==''?$requested_template:($source_id?get_page_template_slug($source_id):'elementor_header_footer')));
  if($template==='')$template='default';
  $template_type=sanitize_key((string)($req->get_param('template_type')?:'wp-page'));
  $status=sanitize_key((string)($req->get_param('final_status')?:'draft')); if(!in_array($status,array('draft','publish','private'),true))$status='draft';
  if($data_b64!==''){ $decoded=base64_decode($data_b64,true); if($decoded===false)return new WP_Error('oai_data_b64','Invalid base64 Elementor data',array('status'=>400)); $data=json_decode($decoded,true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('oai_data_json','Invalid JSON in data_b64',array('status'=>400,'message'=>json_last_error_msg())); }
  else { $data=$req->get_param('data'); if($data===null && $source_id)$data=OAI_Elementor::get_data($source_id); if(is_wp_error($data))return $data; if(is_string($data)){ $data=json_decode($data,true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('oai_data_json','Invalid JSON in data',array('status'=>400,'message'=>json_last_error_msg())); } }
  if(!is_array($data))return new WP_Error('oai_elementor_data','data must be an Elementor element array or element object',array('status'=>400));
  if(!self::is_list_array($data) && (isset($data['elType'])||isset($data['widgetType'])||isset($data['elements']))){$data=array($data);}
  $data=OAI_Elementor::decode_create_nodes($data); if(is_wp_error($data))return $data;
  $data=self::normalize_elementor_page_data($data); if(is_wp_error($data))return $data;

  $adapter=OAI_Builder_Manager::get('elementor');
  if(!$adapter)return new WP_Error('oai_builder_adapter','Elementor adapter is unavailable',array('status'=>500));
  $created=$adapter->create(array('title'=>$title,'excerpt'=>$excerpt,'slug'=>$slug,'status'=>'draft','template_type'=>$template_type,'post_type'=>'page','page_template'=>$template));
  if(is_wp_error($created))return $created;
  $post_id=(int)$created['id'];
  if($source_id){
   foreach(array('_elementor_page_settings','_woodmart_title_bg_color') as $key){
    if(metadata_exists('post',$source_id,$key))update_post_meta($post_id,$key,get_post_meta($source_id,$key,true));
   }
  }
  $saved=$adapter->save($post_id,$data,array('page_template'=>$template));
  if(is_wp_error($saved)){wp_delete_post($post_id,true);return $saved;}
  $verification=$adapter->verify($post_id,$data);
  if(empty($verification['ok'])){wp_delete_post($post_id,true);return new WP_Error('oai_elementor_verify','Elementor save completed but stored element data could not be verified',array('status'=>500,'verification'=>$verification));}
  $single=OAI_Elementor::activate_single_source($post_id);
  if(is_wp_error($single)){wp_delete_post($post_id,true);return $single;}

  $target='elementor_page:'.$post_id; $op='OAI-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999); $now=current_time('mysql',true); $expiry=gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS);
  $meta=array('preview_page_id'=>$post_id,'final_status'=>$status,'page_template'=>$template,'template_type'=>$template_type,'source_post_id'=>$source_id,'single_source'=>true,'sandbox_artifact'=>true,'created_by'=>get_current_user_id(),'builder'=>'elementor','builder_verified'=>true,'elementor_data_bytes'=>$verification['bytes'],'before_exists'=>false);
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
  if(!self::is_list_array($data))return new WP_Error('oai_elementor_data','data must be a list of Elementor elements',array('status'=>400));
  $has_container=false;
  foreach($data as $node){
   if(is_array($node) && in_array((string)($node['elType']??''),array('container','section','column'),true)){$has_container=true;break;}
  }
  if($has_container)return $data;
  $children=array();
  foreach($data as $node){
   if(!is_array($node))return new WP_Error('oai_elementor_data','Each Elementor element must be an object',array('status'=>400));
   if(empty($node['id']))$node['id']=substr(md5(uniqid('',true)),0,7);
   $node['elType']=isset($node['elType'])?(string)$node['elType']:'widget';
   $node['isInner']=isset($node['isInner'])?(bool)$node['isInner']:false;
   $node['elements']=isset($node['elements'])&&is_array($node['elements'])?$node['elements']:array();
   $children[]=$node;
  }
  return array(array(
   'id'=>substr(md5(uniqid('oai_container_',true)),0,7),
   'elType'=>'container',
   'isInner'=>false,
   'settings'=>array(),
   'elements'=>$children,
  ));
 }
 public static function preview_page($req){
  $op_id=absint($req['id']); $r=OAI_DB::get($op_id);
  if(!$r || $r['type']!=='elementor_page_create')return new WP_Error('oai_not_found','Elementor page sandbox operation not found',array('status'=>404));
  if(!in_array($r['status'],array('pending','approved','applied','rollback_failed'),true))return new WP_Error('oai_preview_state','Preview is available for pending, approved or applied operations with rollback context',array('status'=>409));
  $meta=json_decode($r['meta_data'],true); $page_id=absint($meta['preview_page_id']??0); $p=$page_id?get_post($page_id):null;
  if(!$p)return new WP_Error('oai_preview_page','Sandbox preview page not found',array('status'=>404));
  // REST callbacks normally return JSON. This endpoint is deliberately a raw HTML preview,
  // so set the response headers explicitly before emitting the document.
  nocache_headers();
  status_header(200);
  header('Content-Type: text/html; charset='.get_bloginfo('charset'));
  header('X-Okean-AI-Preview: sandbox');
  header('X-Robots-Tag: noindex, nofollow');
  // Do not make the sandbox page the global current post before Elementor renders it.
  // Elementor's get_builder_content_for_display() intentionally returns an empty string
  // when the requested document is the current post (recursive-render protection).
  // This was the reason 0.9.2 showed only the sandbox banner.
  $body='';
  $render_debug=array('page_id'=>$page_id,'post_status'=>$p->post_status,'elementor_data_bytes'=>strlen((string)get_post_meta($page_id,'_elementor_data',true)),'renderer_attempts'=>array());
  $previous_post=$GLOBALS['post']??null;
  $previous_wp_query=$GLOBALS['wp_query']??null;
  try{
   if(self::is_elementor_available_for_preview()){
    $frontend=\Elementor\Plugin::$instance->frontend;
    if(method_exists($frontend,'enqueue_styles'))$frontend->enqueue_styles();
    // Elementor has a recursion guard when the target document is the current
    // global post. Give the renderer an unrelated, real post as the current post
    // while keeping the sandbox target private/draft.
    $dummy_id=absint(get_option('page_on_front'));
    if(!$dummy_id || $dummy_id===$page_id)$dummy_id=absint(get_option('page_for_posts'));
    if(!$dummy_id || $dummy_id===$page_id)$dummy_id=absint(get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>1,'exclude'=>array($page_id),'fields'=>'ids'))[0]??0);
    if($dummy_id && $dummy_id!==$page_id){
     $dummy=get_post($dummy_id);
     if($dummy){$GLOBALS['post']=$dummy; setup_postdata($dummy);}
    }
    $render_debug['renderer_attempts'][]='get_builder_content_for_display';
    $body=$frontend->get_builder_content_for_display($page_id,true);
    if($body===''){
     $render_debug['renderer_attempts'][]='get_builder_content';
     if(method_exists($frontend,'get_builder_content'))$body=$frontend->get_builder_content($page_id,true);
    }
    if($body===''){
     $render_debug['renderer_attempts'][]='document_get_content';
     $document=\Elementor\Plugin::$instance->documents->get($page_id);
     if($document && method_exists($document,'get_content'))$body=$document->get_content();
    }
   }
   if($body===''){
    $render_debug['renderer_attempts'][]='the_content_fallback';
    $GLOBALS['post']=$p; setup_postdata($p);
    $body=apply_filters('the_content',(string)$p->post_content);
   }
  }catch(Throwable $e){
   if($previous_post!==null)$GLOBALS['post']=$previous_post; else unset($GLOBALS['post']);
   if($previous_wp_query!==null)$GLOBALS['wp_query']=$previous_wp_query;
   wp_reset_postdata();
   return new WP_Error('oai_preview_render','Could not render Elementor preview',array('status'=>500,'message'=>$e->getMessage(),'debug'=>$render_debug));
  }
  $render_debug['body_bytes']=strlen((string)$body);
  if($body===''){
   if($previous_post!==null)$GLOBALS['post']=$previous_post; else unset($GLOBALS['post']);
   if($previous_wp_query!==null)$GLOBALS['wp_query']=$previous_wp_query;
   wp_reset_postdata();
   return new WP_Error('oai_preview_empty','Elementor preview renderer returned empty output',array('status'=>500,'debug'=>$render_debug));
  }
  if($previous_post!==null)$GLOBALS['post']=$previous_post; else unset($GLOBALS['post']);
  if($previous_wp_query!==null)$GLOBALS['wp_query']=$previous_wp_query;
  $title=get_the_title($p);
  ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html($title); ?> — Okean AI Preview</title><?php wp_head(); ?><style>html,body{margin:0;padding:0}body{background:#fff}.oai-preview-banner{position:sticky;top:0;z-index:99999;background:#082b63;color:#fff;font:600 13px/1.4 Arial,sans-serif;padding:9px 14px;text-align:center;box-shadow:0 1px 8px rgba(0,0,0,.15)}.oai-preview-banner span{opacity:.8;font-weight:400}</style></head><body class="oai-elementor-preview"><div class="oai-preview-banner">Okean AI · Elementor sandbox preview <span>· изменения ещё не применены</span></div><?php echo $body; ?><?php wp_footer(); ?></body></html><?php
  wp_reset_postdata(); exit;
 }
 private static function is_elementor_available_for_preview(){return defined('ELEMENTOR_VERSION') && class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->frontend);}
 public static function plan($req){return OAI_Planner::plan($req);}

 public static function status(){return array('ok'=>true,'plugin_version'=>OAI_VERSION,'sandbox'=>true,'direct_live_writes'=>false,'database'=>OAI_DB::table(),'api_auth'=>'bearer_or_wp_admin','allowed_types'=>OAI_Capabilities::available()['sandbox'],'capabilities'=>OAI_Capabilities::available(),'site_url'=>home_url('/'),'builders'=>OAI_Builder_Manager::info(),'mcp'=>OAI_MCP::info(),'license'=>OAI_License::status(),'ai_lifecycle_control'=>class_exists('OAI_AI_Lifecycle_Control')?OAI_AI_Lifecycle_Control::status():array('supported'=>false),'preview'=>array('signed'=>true,'renderer'=>'elementor_frontend','draft_fallback'=>true,'ascii_safe_payloads'=>true,'ascii_safe_payloads_mode'=>'title_excerpt_slug_data_b64','build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'unknown','plugin_file'=>plugin_basename(OAI_DIR.'okean-ai-admin.php'),'plugin_dir'=>OAI_DIR));}
 public static function preview_link($req){
  $id=absint($req['id']);
  $r=OAI_DB::get($id);
  if(!$r)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  if(!in_array($r['status'],array('pending','approved'),true))return new WP_Error('oai_preview_state','Preview link is available only for pending or approved operations',array('status'=>409));
  if(!in_array($r['type'],array('post_title','post_excerpt','post_content','elementor_widget_html','elementor_widget_settings','elementor_page_create'),true))return new WP_Error('oai_preview_type','Visual preview currently supports post_content and Elementor widget operations only',array('status'=>400));
  if($r['type']==='elementor_page_create'){
   $exp=time()+30*60; $token=self::preview_token($id,$exp);
   return array('ok'=>true,'id'=>$id,'operation_id'=>$r['operation_id'],'status'=>$r['status'],'expires'=>$exp,'expires_in'=>30*60,'url'=>add_query_arg(array('preview_token'=>$token,'expires'=>$exp),rest_url('okean-ai/v1/preview-page/'.$id)));
  }
  $target=absint($r['target']);
  if(!get_post($target))return new WP_Error('oai_target','Content not found',array('status'=>404));
  $ttl=30*60;
  $expires=time()+$ttl;
  $token=self::preview_token($id,$expires);
  $url=add_query_arg(array('preview_token'=>$token,'expires'=>$expires),rest_url('okean-ai/v1/preview/'.$id));
  return array('ok'=>true,'id'=>$id,'operation_id'=>$r['operation_id'],'status'=>$r['status'],'expires'=>$expires,'expires_in'=>$ttl,'url'=>$url);
 }
 public static function site(){ $theme=wp_get_theme(); return array('name'=>get_bloginfo('name'),'description'=>get_bloginfo('description'),'url'=>home_url('/'),'wp_version'=>get_bloginfo('version'),'php_version'=>PHP_VERSION,'theme'=>$theme->get('Name').' '.$theme->get('Version'),'woocommerce'=>class_exists('WooCommerce'),'elementor'=>defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:null,'memory_limit'=>ini_get('memory_limit')); }
 public static function plugins(){ require_once ABSPATH.'wp-admin/includes/plugin.php'; $out=array(); foreach(get_plugins() as $file=>$p){$out[]=array('plugin'=>$file,'name'=>$p['Name'],'version'=>$p['Version'],'active'=>is_plugin_active($file));} return $out; }
 private static function elementor_meta($id){
  $data=get_post_meta($id,'_elementor_data',true); $edit=get_post_meta($id,'_elementor_edit_mode',true); $template=get_post_meta($id,'_elementor_template_type',true);
  $raw=is_string($data)?$data:wp_json_encode($data); $decoded=null; $valid=false; $nodes=0; $widget_types=array();
  if($raw!==''){ $decoded=json_decode($raw,true); $valid=(json_last_error()===JSON_ERROR_NONE && is_array($decoded)); }
  if($valid){ $walk=function($node) use (&$walk,&$nodes,&$widget_types){ if(!is_array($node))return; if(isset($node['id']))$nodes++; if(isset($node['widgetType'])){$t=(string)$node['widgetType']; if(!isset($widget_types[$t]))$widget_types[$t]=0; $widget_types[$t]++;} foreach($node as $v){if(is_array($v))$walk($v);} }; $walk($decoded); }
  return array('elementor_data_present'=>$raw!=='','elementor_data_bytes'=>strlen($raw),'elementor_data_valid_json'=>$valid,'elementor_edit_mode'=>$edit,'elementor_template_type'=>$template,'elementor_node_count'=>$nodes,'elementor_widget_types'=>$widget_types,'elementor_data'=>$decoded);
 }
 private static function content_row($p,$mode='summary',$opts=array()){
  $a=array('id'=>$p->ID,'type'=>$p->post_type,'status'=>$p->post_status,'title'=>get_the_title($p),'slug'=>$p->post_name,'modified'=>$p->post_modified,'permalink'=>get_permalink($p));
  if($mode==='detail'){
   $include_content=isset($opts['include_content'])?$opts['include_content']:true; $include_elementor=isset($opts['include_elementor'])?$opts['include_elementor']:true; $include_css=isset($opts['include_css'])?$opts['include_css']:true;
   $a['excerpt']=$p->post_excerpt; $a['author']=(int)$p->post_author; $a['template']=get_post_meta($p->ID,'_wp_page_template',true); $a['content_bytes']=strlen((string)$p->post_content);
   if($include_content)$a['content']=$p->post_content;
   if($include_css){$css=''; if(preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is',$p->post_content,$m))$css=implode("\n",$m[1]); $a['inline_css_bytes']=strlen($css); $a['inline_css']=$css;}
   if($include_elementor){$em=self::elementor_meta($p->ID); foreach($em as $k=>$v)$a[$k]=$v;}
  }
  return $a;
 }
 private static function bool_param($req,$name,$default=true){$v=$req->get_param($name); if(is_bool($v))return $v; if($v===null||$v==='')return $default; return !in_array(strtolower((string)$v),array('0','false','no','off'),true);}
 private static function get_content_opts($req){return array('include_content'=>self::bool_param($req,'include_content',true),'include_elementor'=>self::bool_param($req,'include_elementor',true),'include_css'=>self::bool_param($req,'include_css',true));}
 public static function content($req){
  $type=sanitize_key($req->get_param('type') ?: 'page'); $mode=strtolower((string)$req->get_param('mode')); if(!$mode)$mode='summary'; $id=absint($req->get_param('id'));
  if($id){$p=get_post($id); if(!$p||$p->post_type!==$type)return new WP_Error('oai_not_found','Content not found',array('status'=>404)); if($mode!=='detail')$mode='summary'; return array('mode'=>$mode,'item'=>self::content_row($p,$mode,self::get_content_opts($req)));}
  $limit=min(200,max(1,absint($req->get_param('limit')?:10))); $page=max(1,absint($req->get_param('page')?:1)); $status=$req->get_param('status'); $search=sanitize_text_field((string)$req->get_param('search')); $args=array('post_type'=>$type,'post_status'=>$status?sanitize_key($status):'any','posts_per_page'=>$limit,'paged'=>$page,'orderby'=>'modified','order'=>'DESC','no_found_rows'=>false); if($search!=='')$args['s']=$search; $q=new WP_Query($args); $items=array(); foreach($q->posts as $p)$items[]=self::content_row($p,'summary'); return array('mode'=>'summary','type'=>$type,'count'=>count($items),'page'=>$page,'per_page'=>$limit,'total'=>(int)$q->found_posts,'total_pages'=>(int)$q->max_num_pages,'items'=>$items);
 }
 public static function content_detail($req){$id=absint($req['id']); $p=get_post($id); if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404)); return array('mode'=>'detail','item'=>self::content_row($p,'detail',self::get_content_opts($req)));}
 public static function elementor_find($req){
  $id=absint($req['id']); $p=get_post($id); if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404));
  $data=get_post_meta($id,'_elementor_data',true); $raw=is_string($data)?$data:wp_json_encode($data); $decoded=json_decode((string)$raw,true);
  if(json_last_error()!==JSON_ERROR_NONE || !is_array($decoded))return new WP_Error('oai_elementor_data','Elementor data is missing or invalid',array('status'=>404));
  $find=(string)$req->get_param('find'); $class=(string)$req->get_param('class'); $widget=(string)$req->get_param('widget'); $limit=min(50,max(1,absint($req->get_param('limit')?:10)));
  if($find==='' && $class==='' && $widget==='')return new WP_Error('oai_query','Specify find, class or widget',array('status'=>400));
  $matches=array();
  $walk=function($node,$path=array(),$ancestors=array()) use (&$walk,&$matches,$find,$class,$widget,$limit){
   if(count($matches)>=$limit || !is_array($node))return;
   $text=wp_json_encode(isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   $own=array(); if(isset($node['id']))$own['id']=$node['id']; if(isset($node['elType']))$own['elType']=$node['elType']; if(isset($node['widgetType']))$own['widgetType']=$node['widgetType'];
   $settings=isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array();
   $hit=false; $reasons=array(); $matched_fields=array();
   if($find!=='' && $text!==false && mb_stripos($text,$find)!==false){$hit=true;$reasons[]='elementor_data';}
   if($class!==''){
     $class_hit=false;
     foreach(array('css_classes','_css_classes','custom_css_classes') as $k){if(isset($settings[$k]) && mb_stripos((string)$settings[$k],$class)!==false){$class_hit=true;$matched_fields[]=$k;}}
     if(!$class_hit && $text!==false && mb_stripos($text,$class)!==false)$class_hit=true;
     if($class_hit){$hit=true;$reasons[]='css_class';}
   }
   if($widget!=='' && isset($node['widgetType']) && strcasecmp((string)$node['widgetType'],$widget)===0){$hit=true;$reasons[]='widget_type';}
   if($hit){
     $summary=$node;
     if(isset($summary['settings'])&&is_array($summary['settings'])){
       $ss=$summary['settings']; $filtered=array();
       foreach($ss as $k=>$v){$str=is_scalar($v)?(string)$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if(($find!=='' && $str!==false && mb_stripos($str,$find)!==false) || ($class!=='' && $str!==false && mb_stripos($str,$class)!==false))$filtered[$k]=$v;}
       $summary['settings_match']=$filtered; unset($summary['settings']);
     }
     $matches[]=array('source'=>'elementor_data','path'=>$path,'reasons'=>$reasons,'matched_fields'=>$matched_fields,'node'=>$summary,'ancestors'=>$ancestors);
   }
   $label=array(); if(isset($node['id']))$label[]='id='.$node['id']; if(isset($node['elType']))$label[]='elType='.$node['elType']; if(isset($node['widgetType']))$label[]='widgetType='.$node['widgetType'];
   $nextAnc=$ancestors; if($label)$nextAnc[]=implode(' ',$label);
   if(isset($node['elements'])&&is_array($node['elements'])){ foreach($node['elements'] as $i=>$child){$nextPath=$path; $nextPath[]=(int)$i; $walk($child,$nextPath,$nextAnc);} }
   elseif(self::is_list_array($node)){ foreach($node as $i=>$child){$nextPath=$path; $nextPath[]=(int)$i; $walk($child,$nextPath,$nextAnc);} }
  };
  $walk($decoded);
  $content_matches=array();
  if(count($matches)<$limit && ($find!=='' || $class!=='')){
    $html=(string)$p->post_content; $needles=array(); if($find!=='')$needles[]=$find; if($class!=='')$needles[]=$class;
    foreach($needles as $needle){
      $offset=0; $len=mb_strlen($html);
      while($offset<$len && count($matches)+count($content_matches)<$limit){
        $pos=mb_stripos($html,$needle,$offset); if($pos===false)break;
        $start=max(0,$pos-700); $snippet=mb_substr($html,$start,mb_strlen($needle)+1400); $content_matches[]=array('source'=>'post_content','query'=>$needle,'offset'=>$pos,'snippet'=>$snippet);
        $offset=$pos+max(1,mb_strlen($needle));
      }
    }
  }
  if($content_matches)$matches=array_merge($matches,$content_matches);
  return array('id'=>$id,'type'=>$p->post_type,'title'=>get_the_title($p),'permalink'=>get_permalink($p),'query'=>array('find'=>$find,'class'=>$class,'widget'=>$widget,'limit'=>$limit),'elementor_data_bytes'=>strlen((string)$raw),'matches_found'=>count($matches),'matches'=>$matches,'search_fallback_used'=>!empty($content_matches));
 }
 public static function elementor_tree($req){
  $id=absint($req['id']); $p=get_post($id); if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404));
  $data=get_post_meta($id,'_elementor_data',true); $raw=is_string($data)?$data:wp_json_encode($data); $decoded=json_decode((string)$raw,true);
  if(json_last_error()!==JSON_ERROR_NONE || !is_array($decoded))return new WP_Error('oai_elementor_data','Elementor data is missing or invalid',array('status'=>404));
  $widget_filter=strtolower(sanitize_text_field((string)$req->get_param('widget')));
  $limit=min(500,max(1,absint($req->get_param('limit')?:200)));
  $include_html=filter_var($req->get_param('include_html'),FILTER_VALIDATE_BOOLEAN);
  $nodes=array();
  $walk=function($node,$path=array(),$ancestors=array()) use (&$walk,&$nodes,$widget_filter,$limit,$include_html){
   if(count($nodes)>=$limit || !is_array($node))return;
   $widget_type=isset($node['widgetType'])?(string)$node['widgetType']:'';
   $el_type=isset($node['elType'])?(string)$node['elType']:'';
   $is_widget=($el_type==='widget');
   if($is_widget && ($widget_filter==='' || strtolower($widget_type)===$widget_filter)){
     $settings=isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array();
     $row=array('id'=>(string)($node['id']??''),'elType'=>$el_type,'widgetType'=>$widget_type,'path'=>$path,'ancestors'=>$ancestors,'html_bytes'=>isset($settings['html'])?strlen((string)$settings['html']):0);
     if($include_html && array_key_exists('html',$settings))$row['html']=(string)$settings['html'];
     $nodes[]=$row;
   }
   $label=array(); if(isset($node['id']))$label[]='id='.$node['id']; if($el_type!=='')$label[]='elType='.$el_type; if($widget_type!=='')$label[]='widgetType='.$widget_type;
   $next=$ancestors; if($label)$next[]=implode(' ',$label);
   if(isset($node['elements'])&&is_array($node['elements']))foreach($node['elements'] as $i=>$child){$pp=$path; $pp[]=(int)$i; $walk($child,$pp,$next);}
  };
  if(self::is_list_array($decoded)){
   foreach($decoded as $i=>$root){ $walk($root,array((int)$i),array()); }
  } else {
   $walk($decoded);
  }
  return array('id'=>$id,'type'=>$p->post_type,'title'=>get_the_title($p),'permalink'=>get_permalink($p),'elementor_data_bytes'=>strlen((string)$raw),'query'=>array('widget'=>$widget_filter,'limit'=>$limit,'include_html'=>$include_html),'count'=>count($nodes),'nodes'=>$nodes);
 }
 public static function elementor_node($req){
  $id=absint($req['id']); $widget_id=sanitize_text_field((string)$req['widget_id']); $p=get_post($id); if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404));
  $data=get_post_meta($id,'_elementor_data',true); $raw=is_string($data)?$data:wp_json_encode($data); $decoded=json_decode((string)$raw,true);
  if(json_last_error()!==JSON_ERROR_NONE || !is_array($decoded))return new WP_Error('oai_elementor_data','Elementor data is missing or invalid',array('status'=>404));
  $found=null;
  $walk=function($node,$path=array(),$ancestors=array()) use (&$walk,&$found,$widget_id){
   if($found!==null || !is_array($node))return;
   if(isset($node['id']) && (string)$node['id']===$widget_id){$found=array('path'=>$path,'ancestors'=>$ancestors,'node'=>$node);return;}
   $label=array(); if(isset($node['id']))$label[]='id='.$node['id']; if(isset($node['elType']))$label[]='elType='.$node['elType']; if(isset($node['widgetType']))$label[]='widgetType='.$node['widgetType'];
   $next=$ancestors; if($label)$next[]=implode(' ',$label);
   if(isset($node['elements'])&&is_array($node['elements'])){foreach($node['elements'] as $i=>$child){$pp=$path; $pp[]=(int)$i; $walk($child,$pp,$next);}}
   elseif(self::is_list_array($node)){foreach($node as $i=>$child){$pp=$path; $pp[]=(int)$i; $walk($child,$pp,$next);}}
  }; $walk($decoded);
  if($found===null)return new WP_Error('oai_elementor_node','Elementor node not found',array('status'=>404));
  $node=$found['node']; $settings=isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array();
  $html=isset($settings['html'])?(string)$settings['html']:'';
  $view=sanitize_key((string)($req->get_param('view')?:'full'));
  if(!in_array($view,array('full','outline','html_range'),true))return new WP_Error('oai_elementor_view','Invalid widget view',array('status'=>400));
  if($view!=='full'&&!OAI_Platform::content_navigation_allowed())return new WP_Error('oai_feature_disabled','Content navigation is disabled',array('status'=>403));
  if($view!=='full'){
   $base=array('id'=>$id,'widget_id'=>$widget_id,'path'=>$found['path'],'ancestors'=>$found['ancestors'],'elType'=>$node['elType']??null,'widgetType'=>$node['widgetType']??null,'setting_keys'=>array_keys($settings),'html_bytes'=>strlen($html),'html_sha256'=>hash('sha256',$html));
   if($view==='outline'){$base['html_lines']=$html===''?0:count(preg_split('/\r\n|\n|\r/',$html));return $base;}
   $start=absint($req->get_param('start_line')); $end=absint($req->get_param('end_line'));
   if($start<1||$end<$start||$end-$start+1>200)return new WP_Error('oai_line_range','Use an inclusive range of 1 to 200 lines',array('status'=>400));
   $expected=(string)$req->get_param('expected_sha256');
   if($expected!==''&&!hash_equals($expected,$base['html_sha256']))return new WP_Error('oai_conflict','Widget HTML changed since outline',array('status'=>409));
   $lines=preg_split('/\r\n|\n|\r/',$html); $total=$html===''?0:count($lines);
   if($start>$total)return new WP_Error('oai_line_range','start_line exceeds HTML length',array('status'=>416));
   $max=max(100,min(20000,absint($req->get_param('max_chars')?:12000)));
   $slice=implode("\n",array_slice($lines,$start-1,$end-$start+1)); $base['html']=mb_substr($slice,0,$max); $base['truncated']=mb_strlen($slice)>$max; $base['total_lines']=$total; $base['start_line']=$start; $base['end_line']=min($end,$total);return $base;
  }
  return array('id'=>$id,'type'=>$p->post_type,'title'=>get_the_title($p),'permalink'=>get_permalink($p),'widget_id'=>$widget_id,'path'=>$found['path'],'ancestors'=>$found['ancestors'],'elType'=>$node['elType']??null,'widgetType'=>$node['widgetType']??null,'settings'=>$settings,'html'=>$html,'html_bytes'=>strlen($html));
 }
 public static function content_meta($req){$id=absint($req['id']); $p=get_post($id); if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404)); $em=self::elementor_meta($id); unset($em['elementor_data']); $all=get_post_meta($id); $elementor_related=array(); foreach($all as $k=>$v){if(stripos($k,'elementor')!==false)$elementor_related[$k]=array('present'=>true,'bytes'=>strlen(wp_json_encode($v)));} return array('id'=>$id,'type'=>$p->post_type,'title'=>get_the_title($p),'permalink'=>get_permalink($p),'elementor'=>$em,'elementor_related_meta'=>$elementor_related,'all_meta_keys'=>array_keys($all));}
 public static function preview($req){
  $id=absint($req['id']);
  $r=OAI_DB::get($id);
  if(!$r)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  if(!in_array($r['status'],array('pending','approved'),true))return new WP_Error('oai_preview_state','Preview is available only for pending or approved operations',array('status'=>409));
  if(!in_array($r['type'],array('post_title','post_excerpt','post_content','elementor_widget_html','elementor_widget_settings','elementor_page_create'),true))return new WP_Error('oai_preview_type','Visual preview currently supports post_content and Elementor widget operations only',array('status'=>400));
  $target=absint($r['target']); $p=get_post($target);
  if(!$p)return new WP_Error('oai_target','Content not found',array('status'=>404));
  $before=json_decode($r['before_data'],true); $after=json_decode($r['after_data'],true);
  $settings_before=is_array($before)?$before:array(); $settings_after=is_array($after)?$after:array();
  $before=is_string($before)?$before:''; $after=is_string($after)?$after:'';

  /*
   * Elementor-aware preview:
   * - Prefer a widget_id supplied as ?widget_id=...
   * - Otherwise, when the target page has exactly one HTML widget, use it
   *   automatically.
   * - The browser replaces only that widget's innerHTML, identified by
   *   Elementor's data-id attribute. WordPress is never written.
   *
   * This deliberately avoids replacing the whole post_content because
   * Elementor renders the public page through its own frontend structure.
   */
  $widget_id=sanitize_text_field((string)$req->get_param('widget_id'));
  $elementor_node=null; $elementor_html_widgets=array();
  $ed=get_post_meta($target,'_elementor_data',true);
  $eraw=is_string($ed)?$ed:wp_json_encode($ed);
  $edata=json_decode((string)$eraw,true);

  if(is_array($edata)){
    $seen=array();
    $walk=function($node,$path=array()) use (&$walk,&$elementor_html_widgets,&$seen){
      if(!is_array($node))return;
      if(isset($node['id']) && isset($node['widgetType']) && (string)$node['widgetType']==='html'){
        $wid=(string)$node['id'];
        if($wid!=='' && !isset($seen[$wid])){
          $settings=isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array();
          $elementor_html_widgets[] = array(
            'id'=>$wid,
            'html'=>isset($settings['html'])?(string)$settings['html']:'',
            'path'=>$path
          );
          $seen[$wid]=true;
        }
      }
      foreach($node as $key=>$value){
        if(is_array($value)){
          $next=$path;
          if($key==='elements' && is_int($key))$next[]=$key;
          $walk($value,$next);
        }
      }
    };
    $walk($edata);
  }

  if($widget_id!==''){
    foreach($elementor_html_widgets as $w){if($w['id']===$widget_id){$elementor_node=$w;break;}}
    if(!$elementor_node)return new WP_Error('oai_elementor_node','Requested Elementor widget was not found',array('status'=>404));
  } elseif(count($elementor_html_widgets)===1) {
    $elementor_node=$elementor_html_widgets[0];
    $widget_id=$elementor_node['id'];
  }

  $elementor_preview=false;
  $elementor_mode='none';
  $elementor_settings_css=array();
  if($r['type']==='elementor_widget_settings'){
    $parts=explode(':',(string)$r['target'],2); $page_id=absint($parts[0]??0); $wid=sanitize_text_field($parts[1]??'');
    $elementor_preview=true; $widget_id=$wid; $elementor_mode='settings';
    $elementor_settings_css=self::preview_elementor_settings_css($wid,$settings_after);
  }
  if($r['type']==='elementor_widget_html' && $widget_id!==''){ $ref=self::elementor_widget_ref($r['target']); if(!is_wp_error($ref)){$elementor_node=$ref['node'];} }
  if($elementor_node && $after!==''){
    $elementor_preview=true;
    /*
     * A sandbox post_content operation normally contains the complete HTML
     * that will replace the Elementor HTML widget.  Our diagnostic operation
     * #7, however, intentionally contains only a tiny test fragment.  Treat
     * such a short replacement as a non-destructive visual overlay so the
     * original page remains visible during preview.
     */
    $widget_original=(string)$elementor_node['html'];
    $after_trim=trim($after);
    $orig_len=strlen($widget_original);
    $after_len=strlen($after_trim);
    $looks_like_full_widget=($orig_len>0 && $after_len >= max(256,(int)floor($orig_len*0.35)));
    if($looks_like_full_widget){
      $elementor_mode='replace';
    }else{
      $elementor_mode='overlay';
    }
  }

  $styles=self::preview_added_styles(is_string($before)?$before:'',is_string($after)?$after:'');
  if($elementor_settings_css)$styles[]=$elementor_settings_css;
  $html=self::preview_added_html($before,$after);
  $url=$p->post_status==='publish'?get_permalink($target):get_preview_post_link($p); if(!$url)$url=get_permalink($target);
  nocache_headers();
  status_header(200);
  header('Content-Type: text/html; charset='.get_bloginfo('charset'));
  $title='Okean AI Preview #'.$id.' — '.get_the_title($p);
  $payload=array(
    'id'=>$id,'operation_id'=>$r['operation_id'],'status'=>$r['status'],'target'=>$target,
    'url'=>$url,'added_style_blocks'=>count($styles),'added_html_blocks'=>count($html),
    'elementor_preview'=>$elementor_preview,'elementor_mode'=>$elementor_mode,'widget_id'=>$widget_id ?: null,
    'elementor_html_widgets_count'=>count($elementor_html_widgets)
  );
  ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
html,body{height:100%;margin:0;background:#111;color:#fff;font-family:Arial,sans-serif}
.oai-bar{min-height:54px;box-sizing:border-box;padding:9px 14px;background:#181818;border-bottom:1px solid #333;display:flex;align-items:center;gap:10px;position:relative;z-index:2147483647;flex-wrap:wrap}
.oai-bar strong{font-size:14px}.oai-bar span{font-size:12px;color:#aaa}.oai-frame{width:100%;height:calc(100% - 54px);border:0;background:#fff}
.oai-ok{color:#8be28b}.oai-warn{color:#ffd166}.oai-preview-actions{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap}.oai-preview-action{appearance:none;border:1px solid #666;background:#292929;color:#fff;border-radius:7px;padding:7px 11px;font:600 12px Arial,sans-serif;cursor:pointer}.oai-preview-action.primary{background:#1769ff;border-color:#1769ff}.oai-preview-action.danger{border-color:#c84b4b;color:#ffd9d9}.oai-preview-action:disabled{opacity:.55;cursor:wait}.oai-preview-msg{font-size:12px;color:#8be28b}
</style>
</head>
<body>
<div class="oai-bar">
<strong>Okean AI Preview</strong>
<span>Sandbox #<?php echo (int)$id; ?> · <?php echo esc_html($r['status']); ?> · <?php echo esc_html(get_the_title($p)); ?></span>
<?php if($elementor_preview || count($styles)||count($html)): ?>
<span class="oai-ok">Preview: Elementor <?php echo $elementor_preview ? 'виджет '.esc_html($widget_id).' · '.($elementor_mode==='overlay'?'тестовый фрагмент поверх оригинала':'замена содержимого') : 'нет'; ?> · CSS <?php echo count($styles); ?> · HTML <?php echo count($html); ?></span>
<?php else: ?>
<?php if($r['type']==='post_title'): ?>
<span class="oai-ok">Предложенный заголовок: <?php echo esc_html($after); ?> · изменения ещё не применены</span>
<?php elseif($r['type']==='post_excerpt'): ?>
<span class="oai-ok">Предложенный анонс: <?php echo esc_html(wp_trim_words($after,15)); ?> · изменения ещё не применены</span>
<?php else: ?><span class="oai-warn">Предпросмотр не определил HTML-виджет. Укажите ?widget_id=...</span><?php endif; ?>
<?php endif; ?>
<?php if(current_user_can('manage_options')): ?>
<div class="oai-preview-actions" id="oai-preview-actions">
<?php if($r['status']==='pending'): ?><button class="oai-preview-action primary" data-action="approve">Подтвердить</button><button class="oai-preview-action danger" data-action="reject">Отклонить</button><?php endif; ?>
<?php if($r['status']==='approved'): ?><button class="oai-preview-action primary" data-action="apply">Применить</button><button class="oai-preview-action danger" data-action="reject">Отклонить</button><?php endif; ?>
<?php if($r['status']==='applied'||$r['status']==='rollback_failed'): ?><button class="oai-preview-action danger" data-action="rollback">Откатить</button><?php endif; ?>
<span class="oai-preview-msg" id="oai-preview-msg"></span>
</div>
<?php endif; ?>
</div>
<iframe id="oai-frame" class="oai-frame" title="Okean AI sandbox preview" src="<?php echo esc_url($url); ?>"></iframe>
<script>
const OAI_PREVIEW=<?php echo wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_REST_NONCE=<?php echo wp_json_encode(current_user_can('manage_options')?wp_create_nonce('wp_rest'):''); ?>;
const OAI_ACTION_BASE=<?php echo wp_json_encode(rest_url('okean-ai/v1/sandbox/'.$id.'/')); ?>;
const OAI_STYLES=<?php echo wp_json_encode($styles,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_HTML=<?php echo wp_json_encode($html,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_ELEMENTOR_PREVIEW=<?php echo wp_json_encode($elementor_preview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_ELEMENTOR_MODE=<?php echo wp_json_encode($elementor_mode,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_WIDGET_ID=<?php echo wp_json_encode($widget_id,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const OAI_AFTER=<?php echo wp_json_encode($after,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;
const OAI_BEFORE=<?php echo wp_json_encode($before,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;
const OAI_TYPE=<?php echo wp_json_encode($r['type']); ?>;
const OAI_SETTINGS_AFTER=<?php echo wp_json_encode($settings_after,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;
const frame=document.getElementById('oai-frame');

document.querySelectorAll('.oai-preview-action').forEach(function(btn){
  btn.addEventListener('click',async function(){
    const action=String(btn.getAttribute('data-action')||'');
    if(!action||!OAI_REST_NONCE)return;
    const labels={approve:'Подтвердить операцию?',reject:'Отклонить операцию?',apply:'Применить изменение на сайте?',rollback:'Откатить применённое изменение?'};
    if(labels[action] && !window.confirm(labels[action]))return;
    const all=[...document.querySelectorAll('.oai-preview-action')];
    all.forEach(function(x){x.disabled=true;});
    const msg=document.getElementById('oai-preview-msg'); if(msg)msg.textContent='Выполняется…';
    try{
      const res=await fetch(OAI_ACTION_BASE+action,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':OAI_REST_NONCE,'Content-Type':'application/json'},body:'{}'});
      const data=await res.json();
      if(!res.ok || (data && data.code)){throw new Error((data&&data.message)||('HTTP '+res.status));}
      if(msg)msg.textContent='Готово';
      window.setTimeout(function(){window.location.reload();},350);
    }catch(e){
      if(msg){msg.textContent='Ошибка: '+(e&&e.message?e.message:String(e));msg.style.color='#ff9b9b';}
      all.forEach(function(x){x.disabled=false;});
    }
  });
});

function oaiFindWidget(doc, widgetId){
  const safe=String(widgetId||'').replace(/[^A-Za-z0-9_-]/g,'');
  if(!safe)return null;
  const selectors=[
    '[data-id=\"'+safe+'\"]',
    '.elementor-element-'+safe,
    '#'+safe
  ];
  for(const selector of selectors){
    try{const el=doc.querySelector(selector); if(el)return el;}catch(e){}
  }
  return null;
}

function oaiSetWidgetContent(doc, widgetId, markup){
  const el=oaiFindWidget(doc,widgetId);
  if(!el)return false;
  const tmp=doc.createElement('div');
  tmp.innerHTML=markup;
  while(el.firstChild)el.removeChild(el.firstChild);
  while(tmp.firstChild)el.appendChild(tmp.firstChild);
  el.setAttribute('data-okean-ai-preview','sandbox-<?php echo (int)$id; ?>');
  return true;
}

frame.addEventListener('load',function(){
  try{
    const doc=frame.contentDocument||frame.contentWindow.document;
    if(!doc)return;

    if(OAI_TYPE==='post_title'){
      doc.title=OAI_AFTER;
      const headings=doc.querySelectorAll('h1, .entry-title, .page-title');
      headings.forEach(function(el){if(el.textContent.trim()===OAI_BEFORE)el.textContent=OAI_AFTER;});
    }
    // A theme may hide its WordPress title or excerpt. The preview bar always
    // shows the proposed value even when the theme does not render that field.
    if(OAI_TYPE==='elementor_widget_settings' && OAI_WIDGET_ID && Object.prototype.hasOwnProperty.call(OAI_SETTINGS_AFTER,'title')){
      const widget=oaiFindWidget(doc,OAI_WIDGET_ID);
      const title=widget && widget.querySelector('h1,h2,h3,h4,h5,h6,.elementor-heading-title');
      if(title){ title.textContent=String(OAI_SETTINGS_AFTER.title); title.setAttribute('data-okean-ai-preview','sandbox-<?php echo (int)$id; ?>'); }
      else { const notice=document.createElement('div');notice.className='oai-warn';notice.style.cssText='position:fixed;right:12px;top:60px;padding:12px;background:#fff4e5;color:#704b00;z-index:99999';notice.textContent='Заголовок виджета не найден на странице. Проверьте изменение в таблице «Было / Станет».';document.body.appendChild(notice); }
    }
    let applied=false;
    if(OAI_ELEMENTOR_PREVIEW && OAI_WIDGET_ID && OAI_AFTER){
      if(OAI_ELEMENTOR_MODE==='replace'){
        applied=oaiSetWidgetContent(doc,OAI_WIDGET_ID,OAI_AFTER);
      }else if(OAI_ELEMENTOR_MODE==='overlay'){
        const el=oaiFindWidget(doc,OAI_WIDGET_ID);
        if(el){
          const box=doc.createElement('div');
          box.setAttribute('data-okean-ai-preview','sandbox-<?php echo (int)$id; ?>');
          box.style.cssText='position:relative;z-index:2147483000;margin:16px;padding:20px;border:3px dashed red;background:rgba(255,255,255,.97);box-shadow:0 8px 30px rgba(0,0,0,.18);color:#111;';
          const label=doc.createElement('div');
          label.textContent='Okean AI Sandbox Preview #<?php echo (int)$id; ?> — тестовый фрагмент';
          label.style.cssText='font:600 13px Arial,sans-serif;margin-bottom:10px;color:#b42318;';
          box.appendChild(label);
          const content=doc.createElement('div');
          content.innerHTML=OAI_AFTER;
          while(content.firstChild)box.appendChild(content.firstChild);
          el.insertBefore(box,el.firstChild);
          applied=true;
        }
      }
      if(!applied){
        const note=document.createElement('div');
        note.style.cssText='position:fixed;top:60px;right:10px;max-width:420px;background:#b42318;color:#fff;padding:12px;z-index:99999;font:13px/1.45 Arial,sans-serif;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.25)';
        note.textContent='Elementor-виджет '+OAI_WIDGET_ID+' не найден на отрендеренной странице. Preview не изменяет live-страницу.';
        document.body.appendChild(note);
      }
    }

    OAI_STYLES.forEach(function(css,i){
      const el=doc.createElement('style');
      el.setAttribute('data-okean-ai-preview','sandbox-<?php echo (int)$id; ?>-style-'+i);
      el.textContent=css;
      doc.head.appendChild(el);
    });

    OAI_HTML.forEach(function(markup,i){
      const box=doc.createElement('div');
      box.setAttribute('data-okean-ai-preview','sandbox-<?php echo (int)$id; ?>-html-'+i);
      box.innerHTML=markup;
      while(box.firstChild)doc.body.appendChild(box.firstChild);
    });
  }catch(e){
    const note=document.createElement('div');
    note.textContent='Не удалось применить Elementor preview: '+e.message;
    note.style='position:fixed;top:60px;right:10px;background:#b42318;color:#fff;padding:10px;z-index:99999';
    document.body.appendChild(note);
  }
});
</script>
</body>
</html>
<?php
  exit;
 }
 private static function preview_elementor_settings_css($widget_id,$settings){
  $id=preg_replace('/[^A-Za-z0-9_-]/','',$widget_id); if($id==='')return ''; $sel='[data-id=\"'.esc_attr($id).'\"]'; $css=array();
  $map=array('align'=>'text-align','text_align'=>'text-align','_background_color'=>'background-color','background_color'=>'background-color','_text_color'=>'color','text_color'=>'color','title_color'=>'color');
  foreach($map as $key=>$prop){if(isset($settings[$key])&&is_scalar($settings[$key])&&(string)$settings[$key]!=='')$css[]=$sel.'{'.$prop.':'.esc_attr((string)$settings[$key]).' !important;}';}
  foreach(array('_margin'=>'margin','_padding'=>'padding') as $key=>$prop){
   if(isset($settings[$key])&&is_array($settings[$key])){ $v=$settings[$key]; $vals=array(); foreach(array('top','right','bottom','left') as $side){$x=$v[$side]??null; if(is_array($x)){$num=$x['size']??null;$unit=$x['unit']??'px';$vals[]=$num===null?'0':$num.$unit;} elseif(is_scalar($x))$vals[]=(string)$x; else $vals[]='0';} if(count($vals)===4)$css[]=$sel.'{'.$prop.':'.implode(' ',$vals).' !important;}';}
  }
  if(isset($settings['_border_radius'])&&is_array($settings['_border_radius'])){ $v=$settings['_border_radius']; $vals=array(); foreach(array('top','right','bottom','left') as $side){$x=$v[$side]??null;if(is_array($x)){$num=$x['size']??null;$unit=$x['unit']??'px';$vals[]=$num===null?'0':$num.$unit;}else $vals[]='0';} if(count($vals)===4)$css[]=$sel.'{border-radius:'.implode(' ',$vals).' !important;}';}
  return $css?implode('', $css):'';
 }
 private static function preview_extract_styles($html){
  $out=array();
  if(preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is',$html,$m)){
    foreach($m[1] as $css){$css=trim($css); if($css!=='')$out[]=$css;}
  }
  return $out;
 }
 private static function preview_added_styles($before,$after){
  $old=self::preview_extract_styles($before); $new=self::preview_extract_styles($after); $added=array();
  foreach($new as $css){if(!in_array($css,$old,true))$added[]=$css;}
  return $added;
 }
 private static function preview_strip_unsafe($html){
  $html=preg_replace('/<script\b[^>]*>.*?<\/script>/is','',$html);
  $html=preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is','',$html);
  $html=preg_replace('/<style\b[^>]*>.*?<\/style>/is','',$html);
  return trim($html);
 }
 private static function preview_added_html($before,$after){
  $old=self::preview_strip_unsafe($before);
  $new=self::preview_strip_unsafe($after);
  if($new==='' || $new===$old)return array();
  $out=array();
  // Most useful safe case: new content is the old content plus an HTML fragment.
  if(strpos($new,$old)!==false){
    $parts=explode($old,$new,2);
    $candidate=trim($parts[1]);
    if($candidate!=='')$out[]=$candidate;
    return $out;
  }
  // Also support a simple appended fragment where the new value starts with the old value.
  if(str_starts_with($new,$old)){
    $candidate=trim(substr($new,strlen($old)));
    if($candidate!=='')$out[]=$candidate;
    return $out;
  }
  return $out;
 }
 public static function products($req){
  if(!class_exists('WooCommerce'))return array('items'=>array());
  if($req->get_param('page')!==null||$req->get_param('fields')!==null||$req->get_param('query')!==null||$req->get_param('sku')!==null){
   return OAI_WooCommerce::search((string)$req->get_param('query'),(string)$req->get_param('sku'),absint($req->get_param('limit')?:20),absint($req->get_param('page')?:1),(string)($req->get_param('fields')?:'all'));
  }
  $limit=min(100,max(1,absint($req->get_param('limit')?:10)));
  $ps=wc_get_products(array('limit'=>$limit,'status'=>array('publish','draft','private'),'return'=>'objects'));
  $out=array();foreach($ps as $p)$out[]=array('id'=>$p->get_id(),'sku'=>$p->get_sku(),'name'=>$p->get_name(),'status'=>$p->get_status(),'regular_price'=>$p->get_regular_price(),'price'=>$p->get_price(),'stock'=>$p->get_stock_quantity(),'stock_status'=>$p->get_stock_status(),'manage_stock'=>$p->managing_stock(),'permalink'=>$p->get_permalink());
  return array('items'=>$out);
 }
 private static function elementor_widget_ref($target){
  $parts=explode(':',(string)$target,2); $post_id=absint($parts[0]??0); $widget_id=sanitize_text_field($parts[1]??'');
  if(!$post_id || $widget_id==='') return new WP_Error('oai_target','Elementor target must be POST_ID:WIDGET_ID',array('status'=>400));
  if(!class_exists('OAI_Elementor')) return new WP_Error('oai_elementor_unavailable','Elementor integration is unavailable',array('status'=>503));
  $data=OAI_Elementor::get_data($post_id);
  if(is_wp_error($data)) return $data;
  $found=null; $found_path=array();
  $walk=function(&$nodes,$path=array()) use (&$walk,&$found,&$found_path,$widget_id){
   if(!is_array($nodes)||$found!==null)return;
   foreach($nodes as $i=>&$node){
    if(!is_array($node))continue;
    $p=$path; $p[]=(int)$i;
    if((string)($node['id']??'')===$widget_id){$found=$node; $found_path=$p; return;}
    if(isset($node['elements'])&&is_array($node['elements'])){$walk($node['elements'],$p); if($found!==null)return;}
   }
  };
  $walk($data,array());
  if($found===null)return new WP_Error('oai_elementor_node','Elementor widget not found',array('status'=>404));
  return array('post_id'=>$post_id,'widget_id'=>$widget_id,'data'=>$data,'node'=>$found,'path'=>$found_path);
 }
 private static function elementor_widget_html_current($target){$ref=self::elementor_widget_ref($target); if(is_wp_error($ref))return array(false,null); return array(true,(string)($ref['node']['settings']['html']??''));}
 private static function elementor_widget_html_write($target,$html){
  $ref=self::elementor_widget_ref($target); if(is_wp_error($ref))return $ref; $data=$ref['data']; $wid=$ref['widget_id']; $changed=false;
  $walk=function(&$nodes) use (&$walk,$wid,$html,&$changed){ if(!is_array($nodes)||$changed)return; foreach($nodes as &$node){ if(!is_array($node))continue; if((string)($node['id']??'')===$wid){ if(!isset($node['settings'])||!is_array($node['settings']))$node['settings']=array(); $node['settings']['html']=$html; $changed=true; return;} if(isset($node['elements'])&&is_array($node['elements']))$walk($node['elements']); } };
  $walk($data); if(!$changed)return new WP_Error('oai_elementor_node','Elementor widget not found',array('status'=>404));
  return OAI_Elementor::save_document($ref['post_id'],$data);
 }
 public static function snapshot_for_verify($type,$target){ return self::current($type,$target); }
 private static function current($type,$target){ if(class_exists('OAI_P0')){$p0=OAI_P0::current($type,$target);if($p0!==null)return $p0;} switch($type){case 'elementor_page_create': $parts=explode(':',(string)$target,2); $pid=absint($parts[1]??0); if(!$pid)return array(false,null); $v=OAI_Verify::elementor_page($pid); return is_wp_error($v)?array(false,$v):array(true,$v); case 'option_update': $exists=get_option($target,null); return array($exists!==null,$exists===null?null:self::option_storage_value($exists)); case 'post_title': return array(true,get_post_field('post_title',(int)$target)); case 'post_excerpt': return array(true,get_post_field('post_excerpt',(int)$target)); case 'post_content': return array(true,get_post_field('post_content',(int)$target)); case 'elementor_widget_html': return self::elementor_widget_html_current($target); case 'elementor_widget_settings': $parts=explode(':',(string)$target,2); $ref=OAI_Elementor::find_node(absint($parts[0]??0),sanitize_text_field($parts[1]??'')); return is_wp_error($ref)?array(false,null):array(true,is_array($ref['node']['settings']??null)?$ref['node']['settings']:array()); case 'file_write': $parts=explode(':',$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400)); $r=OAI_Files::read($parts[0],$parts[1]); if(is_wp_error($r))return array(false,null); return array(true,$r['content']??''); case 'theme_file_write': $parts=explode(':',$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400)); $r=OAI_Files::read($parts[0],$parts[1]); if(is_wp_error($r))return array(false,null); return array(true,$r['content']??''); case 'woo_product_price': if(!class_exists('WooCommerce'))return array(false,null); $p=wc_get_product((int)$target); return array((bool)$p,$p?$p->get_regular_price():null); case 'woo_product_stock': if(!class_exists('WooCommerce'))return array(false,null); $p=wc_get_product((int)$target); return array((bool)$p,$p?array('stock'=>$p->get_stock_quantity(),'manage_stock'=>$p->managing_stock(),'stock_status'=>$p->get_stock_status()):null); case 'woocommerce_product': if(!class_exists('WooCommerce'))return array(false,null); $v=OAI_WooCommerce::get_full((int)explode(':',(string)$target,2)[0]); return is_wp_error($v)?array(false,$v):array(true,$v); } return array(false,null); }
 public static function sandbox_list($req=null){
  $page=1;$per_page=50;$statuses=array();
  if($req instanceof WP_REST_Request){
   $page=max(1,absint($req->get_param('page')?:1));$per_page=max(1,min(100,absint($req->get_param('per_page')?:50)));
   $status=$req->get_param('status');if(is_array($status))$statuses=$status;elseif(is_string($status)&&trim($status)!=='')$statuses=preg_split('/[\s,;]+/',$status,-1,PREG_SPLIT_NO_EMPTY);
  }
  $statuses=array_values(array_filter(array_map('sanitize_key',(array)$statuses)));$offset=($page-1)*$per_page;
  if($statuses){$total=OAI_DB::count_by_status($statuses);$rows=OAI_DB::by_status($statuses,$per_page,$offset);}else{$total=OAI_DB::count_all();$rows=OAI_DB::page($per_page,$offset);}
  foreach($rows as &$r){foreach(array('before_data','after_data','applied_data','meta_data','diff_data','rollback_data') as $k)if(array_key_exists($k,$r))$r[$k]=json_decode($r[$k],true);$r=OAI_Lifecycle::decorate($r);}unset($r);
  return array('page'=>$page,'per_page'=>$per_page,'total'=>$total,'total_pages'=>max(1,(int)ceil($total/$per_page)),'statuses'=>$statuses,'items'=>$rows);
 }
 private static function validate_sandbox_target($type,$target){
  if($type==='option_update'){
   global $wpdb;
   $key=(string)$target;
   $protected=array(
    'siteurl','home','admin_email','new_admin_email','active_plugins',
    'recently_activated','uninstall_plugins','rewrite_rules','cron',
    'users_can_register','default_role','stylesheet','template'
   );
   if(isset($wpdb) && is_object($wpdb) && !empty($wpdb->prefix))$protected[]=$wpdb->prefix.'user_roles';
   if(in_array($key,$protected,true))return new WP_Error('oai_option_protected','Protected WordPress option cannot be modified through the generic option sandbox',array('status'=>403,'key'=>$key));
   if(preg_match('/(?:^|_)(?:password|passwd|secret|auth|token|private_key|api_key|apikey|client_secret)(?:_|$)/i',$key)){
    return new WP_Error('oai_option_sensitive','Sensitive credential/security option cannot be modified through the generic option sandbox',array('status'=>403,'key'=>$key));
   }
  }
  if(in_array($type,array('post_title','post_excerpt','post_content'),true)){
   $id=absint($target); if(!$id || !get_post($id))return new WP_Error('oai_target','Post not found',array('status'=>404));
   if($type==='post_content'){$allowed=OAI_Elementor::content_write_allowed($id);if(is_wp_error($allowed))return $allowed;}
  }
  if(in_array($type,array('elementor_widget_html','elementor_widget_settings'),true)){
   $parts=explode(':',(string)$target,2); $pid=absint($parts[0]??0); $wid=sanitize_text_field($parts[1]??'');
   if(!$pid || $wid==='')return new WP_Error('oai_target','Invalid Elementor widget target',array('status'=>400));
   $ref=OAI_Elementor::find_node($pid,$wid); if(is_wp_error($ref))return $ref;
  }
  if(in_array($type,array('woo_product_price','woo_product_stock'),true)){
   $pid=absint($target); if(!$pid)return new WP_Error('oai_target','Invalid WooCommerce product target',array('status'=>400));
   $product=wc_get_product($pid); if(!$product)return new WP_Error('oai_target','Product not found',array('status'=>404));
  }
  if($type==='file_write'){
   $parts=explode(':',(string)$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400));
   $file=OAI_Security::resolve_path_for_write($parts[0],$parts[1]); if(is_wp_error($file))return $file;
   if(is_dir($file))return new WP_Error('oai_not_file','File target points to a directory',array('status'=>400));
  }
  return true;
 }
 public static function elementor_patch_widget($req){
  $post_id=absint($req->get_param('post_id'));
  $widget_id=sanitize_text_field((string)$req->get_param('widget_id'));
  $old_html=$req->get_param('old_html');
  $new_html=$req->get_param('new_html');
  if(!$post_id || $widget_id==='')return new WP_Error('oai_target','post_id and widget_id are required',array('status'=>400));
  if(!is_string($old_html) || !is_string($new_html))return new WP_Error('oai_changes','old_html and new_html must be strings',array('status'=>400));
  if($old_html===$new_html)return new WP_Error('oai_noop','Elementor widget HTML is unchanged',array('status'=>409));
  $target=$post_id.':'.$widget_id;
  $ref=OAI_Elementor::find_node($post_id,$widget_id); if(is_wp_error($ref))return $ref;
  if(($ref['node']['widgetType']??'')!=='html')return new WP_Error('oai_widget_type','elementor.patch_widget currently supports HTML widgets only',array('status'=>400));
  $current=(string)($ref['node']['settings']['html']??'');
  if($current!==$old_html)return new WP_Error('oai_snapshot_mismatch','old_html does not match the current Elementor widget snapshot',array('status'=>409,'current_sha256'=>hash('sha256',$current),'expected_sha256'=>hash('sha256',$old_html)));
  $clone_guard=OAI_Elementor::reject_cloned_widget($current,$new_html);if(is_wp_error($clone_guard))return $clone_guard;
  $r=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
  $r->set_param('type','elementor_widget_html');
  $r->set_param('target',$target);
  $r->set_param('old_data',$old_html);
  $r->set_param('new_data',$new_html);
  return self::sandbox_create($r);
 }
 public static function elementor_patch_fragment($req){
  $post_id=absint($req->get_param('post_id'));
  $widget_id=sanitize_text_field((string)$req->get_param('widget_id'));
  if(!$post_id || $widget_id==='')return new WP_Error('oai_target','post_id and widget_id are required',array('status'=>400));
  $ref=OAI_Elementor::find_node($post_id,$widget_id);if(is_wp_error($ref))return $ref;
  if(($ref['node']['widgetType']??'')!=='html')return new WP_Error('oai_widget_type','HTML widget required',array('status'=>422));
  $old=(string)($ref['node']['settings']['html']??'');
  $new=OAI_Elementor::replace_html_fragment($old,$req->get_param('old_fragment'),$req->get_param('new_fragment'),$req->get_param('expected_sha256'));
  if(is_wp_error($new))return $new;
  $patch=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-widget');
  $patch->set_param('post_id',$post_id);$patch->set_param('widget_id',$widget_id);$patch->set_param('old_html',$old);$patch->set_param('new_html',$new);
  return self::elementor_patch_widget($patch);
 }
 private static function validate_woo_payload($type,$after){
  if($type==='woocommerce_product')return OAI_WooCommerce::validate_snapshot($after);
  if($type==='woo_product_price')return OAI_WooCommerce::validate_price($after);
  if($type==='woo_product_stock'){
   $stock=is_array($after)?($after['stock']??null):$after;
   if($stock!==null && (!is_numeric($stock)||(float)$stock<0))return new WP_Error('oai_woo_stock','Invalid WooCommerce stock quantity',array('status'=>422));
   if(is_array($after)&&isset($after['stock_status'])&&!in_array($after['stock_status'],array('instock','outofstock','onbackorder'),true))return new WP_Error('oai_woo_stock_status','Invalid WooCommerce stock_status',array('status'=>422));
  }
  return true;
 }
 public static function option_storage_value($value){
  // Top-level scalar options persist as strings in wp_options. Arrays retain
  // their nested types through serialization. Do not coerce nested values.
  return is_scalar($value)||$value===null?(string)$value:$value;
 }
 public static function same_snapshot($type,$current,$expected){
  if($type==='option_update'){
   return self::same_option_value($current,$expected);
  }
  return OAI_Verify::same($current,$expected);
 }
 public static function same_option_value($current,$expected){
  return self::same_option_node(self::option_storage_value($current),self::option_storage_value($expected));
 }
 private static function same_option_node($current,$expected){
  // Sanitizers may rebuild maps in a different insertion order (Yoast logo
  // caches do this). Keep every key, value and nested type authoritative;
  // ordered lists, including those named categories/tags, must stay ordered.
  if(!is_array($current)||!is_array($expected))return $current===$expected;
  if(count($current)!==count($expected))return false;
  $current_list=$current===array()||array_keys($current)===range(0,count($current)-1);
  $expected_list=$expected===array()||array_keys($expected)===range(0,count($expected)-1);
  if($current_list!==$expected_list)return false;
  foreach($current as $key=>$value){
   if(!array_key_exists($key,$expected)||!self::same_option_node($value,$expected[$key]))return false;
  }
  return true;
 }
 public static function normalize_option_snapshot($target,$value){
  // Match WordPress' sanitize_option pipeline before the diff is approved.
  // Never learn the expected state from a post-write read: that would conceal
  // unexpected writes and weaken authoritative verification.
  try {
   $normalized=sanitize_option($target,$value);
   if(is_wp_error($normalized))return $normalized;
   $normalized=self::option_storage_value($normalized);
   $second=sanitize_option($target,$normalized);
   if(is_wp_error($second))return $second;
   if(!self::same_option_value($normalized,$second))return new WP_Error('oai_option_unstable','Option sanitization is not idempotent; no operation was created',array('status'=>422));
   return self::option_storage_value($normalized);
  } catch(Throwable $e){
   return new WP_Error('oai_option_sanitize','Option sanitization failed; no operation was created',array('status'=>422));
  }
 }
 public static function sandbox_create($req){
  $type=sanitize_key($req->get_param('type'));
  $target=sanitize_text_field((string)$req->get_param('target'));
  $allowed=array('option_update','post_title','post_excerpt','post_content','woo_product_price','woo_product_stock','elementor_widget_html','elementor_widget_settings','elementor_page_create','file_write');
  if(!in_array($type,$allowed,true))return new WP_Error('oai_type','Unsupported type',array('status'=>400));
  $target_guard=self::validate_sandbox_target($type,$target); if(is_wp_error($target_guard))return $target_guard;
  $raw=$req->get_param('value');
  $requested_before=$req->get_param('old_data');
  if($type==='option_update'&&$requested_before!==null)$requested_before=self::option_storage_value($requested_before);
  $requested_after=$req->get_param('new_data');
  if($type==='elementor_widget_settings'){
   if(!is_array($raw))return new WP_Error('oai_changes','Elementor changes must be an object',array('status'=>400));
   // Pure sandbox calculation: read current settings and build before/after snapshots.
   // No Elementor save pipeline is called here.
   $patch=OAI_Elementor::patch_settings($target,$raw); if(is_wp_error($patch))return $patch;
   $before=$patch['before_settings']; $after=$patch['after_settings']; $exists=true;
  } else {
   list($exists,$before)=self::current($type,$target);
   if($requested_after!==null){
    if($requested_before!==null && $exists && $before!==$requested_before){
     return new WP_Error('oai_snapshot_mismatch','old_data does not match the current target snapshot',array('status'=>409,'current'=>$before,'expected'=>$requested_before));
    }
    if($requested_before!==null && !$exists && $requested_before!==null){
     return new WP_Error('oai_snapshot_mismatch','Target does not exist but old_data was supplied',array('status'=>409));
    }
    $after=$requested_after;
   } else {
    $after=is_string($raw)?$raw:$raw;
   }
  }
  if($type==='option_update'){
   if($exists){
    $roundtrip=self::normalize_option_snapshot($target,$before);
    if(is_wp_error($roundtrip))return $roundtrip;
    if(!self::same_option_value($roundtrip,$before))return new WP_Error('oai_option_rollback_unsafe','Existing option cannot be restored exactly through WordPress sanitization; save it in its native settings screen before creating a sandbox operation',array('status'=>409));
   }
   $after=self::normalize_option_snapshot($target,$after);
   if(is_wp_error($after))return $after;
  }
  $woo_guard=self::validate_woo_payload($type,$after);if(is_wp_error($woo_guard))return $woo_guard;
  $op='OAI-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999);
  $now=current_time('mysql',true); $expiry=gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS);
  $diff=array('changed'=>$type==='option_update'?(!$exists||!self::same_option_value($before,$after)):$before!==$after,'before'=>$before,'after'=>$after);
  if(empty($diff['changed']))return new WP_Error('oai_noop','Operation does not change the target',array('status'=>409));
  $meta=array('before_exists'=>$exists,'after_exists'=>true);
  if($type==='elementor_widget_settings'){$parts=explode(':',$target,2);$meta['post_id']=absint($parts[0]??0);$meta['widget_id']=sanitize_text_field($parts[1]??'');$meta['save_pipeline']='elementor_document';}
  $data=array('operation_id'=>$op,'status'=>'pending','type'=>$type,'target'=>$target,'before_data'=>wp_json_encode($exists?$before:null),'after_data'=>wp_json_encode($after),'meta_data'=>wp_json_encode(array_merge($meta,array('rollback_descriptor'=>OAI_Lifecycle::descriptor($type,$target,$exists?$before:null,array('before_exists'=>$exists))))),'diff_data'=>wp_json_encode($diff),'phase'=>'awaiting_approval','workspace_id'=>isset($meta['workspace_id'])?$meta['workspace_id']:null,'rollback_data'=>wp_json_encode(OAI_Lifecycle::descriptor($type,$target,$exists?$before:null,array('before_exists'=>$exists))),'risk'=>$type==='option_update'?'high':(in_array($type,array('post_content','woo_product_price','woo_product_stock','elementor_widget_html','elementor_widget_settings','elementor_page_create','file_write'),true)?'medium':'low'),'created_at'=>$now,'expires_at'=>$expiry);
  $id=OAI_DB::insert($data); if(is_wp_error($id))return $id; $row=OAI_DB::get($id); OAI_Audit::log('created',$row); return self::row($id);
 }
 private static function row($id){$r=OAI_DB::get($id); if(!$r)return null; foreach(array('before_data','after_data','applied_data','meta_data','diff_data','rollback_data') as $k){if(array_key_exists($k,$r))$r[$k]=json_decode($r[$k],true);} return OAI_Lifecycle::decorate($r);}
 public static function sandbox_action($req){
  $id=absint($req['id']);
  $path=$req->get_route();
  if(!preg_match('#/sandbox/\d+/(approve|reject|apply|rollback)$#',$path,$m))return new WP_Error('oai_action','Unknown action',array('status'=>400));
  $action=$m[1];
  $test_automation_authorized=class_exists('OAI_Test_Automation') && OAI_Test_Automation::request_authorized($id,$action);
  $ai_lifecycle_authorized=class_exists('OAI_AI_Lifecycle_Control') && OAI_AI_Lifecycle_Control::request_authorized($id,$action);
  if(in_array($action,array('approve','reject','apply','rollback'),true) && !defined('OAI_ADMIN_HUMAN_APPROVAL') && !$test_automation_authorized && !$ai_lifecycle_authorized){
   $nonce=(string)$req->get_header('X-WP-Nonce');
   if($nonce==='' || !wp_verify_nonce($nonce,'wp_rest'))return new WP_Error('oai_human_approval_required','Approve, Apply and Rollback require an authenticated WP-admin human approval context, AI Lifecycle Control, or an active scoped Test Automation session',array('status'=>403));
  }
  $r=OAI_DB::get($id);
  if(!$r)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  $meta=json_decode((string)$r['meta_data'],true); if(!is_array($meta))$meta=array();

  // A sandbox snapshot is valid only until its explicit expiry. Never approve
  // or apply an operation against a stale snapshot; rollback of an already
  // applied operation remains available independently.
  if(in_array($action,array('approve','apply'),true) && in_array($r['status'],array('pending','approved'),true) && !empty($r['expires_at']) && strtotime((string)$r['expires_at']) < time()){
   // Elementor page creation uses a private draft as a preview artifact. An
   // expired operation must not leave orphaned draft pages behind.
   if($r['type']==='elementor_page_create'){
    $preview_id=absint($meta['preview_page_id']??0);
    if($preview_id && get_post($preview_id))wp_delete_post($preview_id,true);
   }
   if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($r['type']))OAI_P0::cleanup_artifact($r['type'],$r['target'],$meta);
   OAI_DB::update($id,array('status'=>'expired','phase'=>'expired'));
   OAI_Audit::log('expired',array_merge($r,array('status'=>'expired')),array('reason'=>'sandbox_expired','preview_artifact_cleaned'=>($r['type']==='elementor_page_create')));
   OAI_Receipts::store($id,array('event'=>'expired','status'=>'expired','at'=>gmdate('c'),'reason'=>'sandbox_expired','preview_artifact_cleaned'=>($r['type']==='elementor_page_create')),OAI_Proof::make($id));
   return new WP_Error('oai_expired','Sandbox operation has expired; create a fresh snapshot before approval/apply',array('status'=>409,'expires_at'=>$r['expires_at']));
  }

  if($action==='approve'){
   if($r['status']!=='pending')return new WP_Error('oai_state','Operation is not pending',array('status'=>409));
   OAI_DB::update($id,array('status'=>'approved','phase'=>'approved','approved_at'=>current_time('mysql',true)));
   OAI_Audit::log('approved',array_merge($r,array('status'=>'approved')));
  }
  elseif($action==='reject'){
   if(!in_array($r['status'],array('pending','approved'),true))return new WP_Error('oai_state','Cannot reject',array('status'=>409));
   if($r['type']==='elementor_page_create' && !empty($meta['preview_page_id']))wp_delete_post(absint($meta['preview_page_id']),true);
   if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($r['type']))OAI_P0::cleanup_artifact($r['type'],$r['target'],$meta);
   OAI_DB::update($id,array('status'=>'rejected','phase'=>'rejected','rejected_at'=>current_time('mysql',true)));
   OAI_Audit::log('rejected',array_merge($r,array('status'=>'rejected')));
  }
  elseif($action==='apply'){
   // Preserve the real conflict on retries/replays. A first conflict intentionally
   // transitions the operation to apply_failed; a transport/client replay must not
   // downgrade that into the misleading generic "must be approved" state error.
   if($r['status']==='apply_failed'){
    $stored_receipt=json_decode((string)($r['receipt']??''),true);
    if(is_array($stored_receipt) && ($stored_receipt['reason']??'')==='conflict'){
     return new WP_Error('oai_conflict','Target changed since sandbox snapshot',array(
      'status'=>409,
      'current'=>$stored_receipt['current']??null,
      'expected_before'=>$stored_receipt['expected_before']??null,
      'replayed'=>true,
     ));
    }
   }
   if($r['status']!=='approved')return new WP_Error('oai_state','Operation must be approved',array('status'=>409));
   $after=json_decode((string)$r['after_data'],true); $before=json_decode((string)$r['before_data'],true);
   $woo_guard=self::validate_woo_payload($r['type'],$after);if(is_wp_error($woo_guard))return $woo_guard;

   // Conflict checks happen BEFORE entering applying, so a rejected conflict can never leave the operation stuck in applying.
   // Every existing live target must still match the sandbox snapshot before apply.
   // Page creation is the only operation whose target is intentionally created by apply.
   if($r['type']!=='elementor_page_create' && !(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($r['type']))){
    list($cur_exists,$current_now)=self::current($r['type'],$r['target']);
    $before_exists=array_key_exists('before_exists',$meta)?(bool)$meta['before_exists']:true;
    $conflict=false;
    if($cur_exists !== $before_exists){
     $conflict=true;
    } elseif($cur_exists){
     if($r['type']==='woocommerce_product'){
      if(!self::same_snapshot($r['type'],$current_now,$before)) $conflict=true;
     } else {
      $conflict=!self::same_snapshot($r['type'],$current_now,$before);
     }
    }
    if($conflict){
     OAI_DB::update($id,array('status'=>'apply_failed','phase'=>'apply_failed'));
     OAI_Receipts::store($id,array('event'=>'apply_failed','status'=>'apply_failed','reason'=>'conflict','at'=>gmdate('c'),'current'=>$current_now,'expected_before'=>$before),OAI_Proof::make($id));
     OAI_Audit::log('apply_failed',array_merge($r,array('status'=>'apply_failed')),array('reason'=>'conflict','current'=>$current_now,'expected_before'=>$before));
     return new WP_Error('oai_conflict','Target changed since sandbox snapshot',array('status'=>409,'current'=>$current_now,'expected_before'=>$before));
    }
   }

   OAI_DB::update($id,array('status'=>'applying','phase'=>'applying'));
   try {
    if($r['type']==='woocommerce_product'){
     $parts=explode(':',$r['target'],2); $err=OAI_WooCommerce::apply(absint($parts[0]),is_array($after)?$after:array());
    } elseif(in_array($r['type'],array('post_title','post_excerpt','post_content'),true)){
     $parts=explode(':',$r['target'],2); $err=OAI_Content::apply_patch(absint($parts[0]),$parts[1]??'post_content',(string)$after);
    } elseif($r['type']==='elementor_page_create'){
     $expected_file_sha=(in_array($r['type'],array('file_write','theme_file_write'),true))?hash('sha256',(string)$before):'';
     $err=self::write($r['type'],$r['target'],$after,$expected_file_sha);
    } else {
     $err=self::write($r['type'],$r['target'],$after);
    }
   } catch (Throwable $e) {
    $err=new WP_Error('oai_apply_exception',$e->getMessage(),array('status'=>500));
   }

   if(is_wp_error($err)){
    return self::recover_after_apply_failure($r,$before,$meta,$err);
   }

   $verification_expected=$after;
   if($r['type']==='woocommerce_product' && is_array($err)){
    $changes=is_array($meta['changes']??null)?$meta['changes']:array();
    $runtime_guard=OAI_WooCommerce::runtime_matches_changes($err,$changes,is_array($before)?$before:array(),is_array($after)?$after:array());
    if(is_wp_error($runtime_guard))return self::recover_after_apply_failure($r,$before,$meta,$runtime_guard);
    $verification_expected=$err;
    if(!OAI_Verify::same($after,$err)){
     $meta['runtime_normalized']=array('at'=>gmdate('c'),'requested_after'=>$after,'actual_after'=>$err);
     OAI_DB::update($id,array('meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    }
   }

   self::refresh_verification_target($r['type'],$r['target'],$meta);
   $verification=OAI_Verify::operation($id,$verification_expected,'after',true);
   if(is_wp_error($verification) || empty($verification['verified'])){
    return self::recover_after_apply_failure($r,$before,$meta,$verification);
   }

   // Independent second read after a successful verification closes the cache/race window.
   self::refresh_verification_target($r['type'],$r['target'],$meta);
   $verification2=OAI_Verify::operation($id,$verification_expected,'after',true);
   if(is_wp_error($verification2) || empty($verification2['verified'])){
    return self::recover_after_apply_failure($r,$before,$meta,$verification2);
   }

   OAI_DB::update($id,array('status'=>'applied','phase'=>'applied','applied_data'=>wp_json_encode($verification_expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'applied_at'=>current_time('mysql',true)));
   OAI_Audit::log('applied',array_merge($r,array('status'=>'applied')),array('verified'=>true,'verification'=>$verification2));
   OAI_Receipts::store($id,array('event'=>'apply','status'=>'applied','at'=>gmdate('c'),'verification'=>$verification2),OAI_Proof::make($id));
  }
  elseif($action==='rollback'){
   // A failed rollback may be retried only for an operation that was applied.
   // The live-state conflict check below still runs before any write.
   $retry_owned_created=($r['status']==='rollback_failed'
    && class_exists('OAI_P0')
    && OAI_P0::is_created_artifact((string)$r['type'])
    && !empty($meta['created_by_ai_admin']));
   if($r['status']!=='applied' && !($r['status']==='rollback_failed' && (!empty($r['applied_at'])||$retry_owned_created)))return new WP_Error('oai_state','Operation must be applied',array('status'=>409));
   $before=json_decode((string)$r['before_data'],true);
   $applied=json_decode((string)$r['applied_data'],true);
   if($r['applied_data']===null || $r['applied_data']==='')$applied=json_decode((string)$r['after_data'],true);
   if(in_array($r['type'],array('file_write','theme_file_write'),true))$meta['applied_sha256']=hash('sha256',(string)$applied);

   // Rollback is destructive too: never overwrite a change made after Apply.
   // The live target must still equal the state AI Admin itself verified after Apply.
   $rollback_conflict=self::rollback_conflict_check($r,$applied,$meta);
   if(is_wp_error($rollback_conflict)){
    OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
    $details=$rollback_conflict->get_error_data();
    OAI_Receipts::store($id,array('event'=>'rollback_conflict','status'=>'rollback_failed','at'=>gmdate('c'),'reason'=>'post_apply_change','details'=>$details),OAI_Proof::make($id));
    OAI_Audit::log('rollback_conflict',array_merge($r,array('status'=>'rollback_failed')),array('reason'=>'post_apply_change','details'=>$details));
    return $rollback_conflict;
   }

   OAI_DB::update($id,array('status'=>'rollback_pending','phase'=>'rollback_pending'));
   OAI_DB::update($id,array('status'=>'rolling_back','phase'=>'rolling_back'));
   try { $err=self::rollback_write($r['type'],$r['target'],$before,$meta); }
   catch(Throwable $e){ $err=new WP_Error('oai_rollback_exception',$e->getMessage(),array('status'=>500)); }
   if(is_wp_error($err)){
    OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
    OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'error'=>$err->get_error_message()),OAI_Proof::make($id));
    OAI_Audit::log('rollback_failed',array_merge($r,array('status'=>'rollback_failed')),array('error'=>$err->get_error_message()));
    return $err;
   }

   self::refresh_verification_target($r['type'],$r['target'],$meta);
   $verification=OAI_Verify::operation($id,$before,'rollback',true);
   if(is_wp_error($verification) || empty($verification['verified'])){
    OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
    OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'verification'=>$verification),OAI_Proof::make($id));
    OAI_Audit::log('rollback_failed',array_merge($r,array('status'=>'rollback_failed')),array('verification'=>$verification));
    return new WP_Error('oai_rollback_verify_failed','Rollback completed but verification failed',array('status'=>500,'verification'=>$verification));
   }

   self::refresh_verification_target($r['type'],$r['target'],$meta);
   $verification2=OAI_Verify::operation($id,$before,'rollback',true);
   if(is_wp_error($verification2) || empty($verification2['verified'])){
    OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
    OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'verification'=>$verification2),OAI_Proof::make($id));
    OAI_Audit::log('rollback_failed',array_merge($r,array('status'=>'rollback_failed')),array('verification'=>$verification2));
    return new WP_Error('oai_rollback_verify_failed','Rollback verification was not stable on the second authoritative read',array('status'=>500,'verification'=>$verification2));
   }

   OAI_DB::update($id,array('status'=>'rolled_back','phase'=>'rolled_back','rolled_back_at'=>current_time('mysql',true)));
   OAI_Audit::log('rolled_back',array_merge($r,array('status'=>'rolled_back')),array('verified'=>true,'verification'=>$verification2));
   OAI_Receipts::store($id,array('event'=>'rollback','status'=>'rolled_back','at'=>gmdate('c'),'verification'=>$verification2),OAI_Proof::make($id));
  }
  return self::row($id);
 }

 private static function rollback_conflict_check($r,$applied,$meta=array()){
  $type=(string)$r['type']; $target=(string)$r['target'];

  if($type==='elementor_page_create'){
   $page_id=absint($meta['preview_page_id']??0);
   if(!$page_id){$parts=explode(':',$target,2);$page_id=absint($parts[1]??0);}
   $current=OAI_Verify::elementor_page($page_id);
   if(is_wp_error($current)){
    // Idempotent cleanup: if the object created by this operation is already
    // absent, the rollback target state has already been reached. Continue to
    // rollback verification instead of treating expected absence as conflict.
    if($current->get_error_code()==='oai_not_found')return true;
    return new WP_Error('oai_rollback_conflict','Created Elementor page could not be read; refusing destructive rollback',array('status'=>409,'current'=>null,'expected_applied'=>$applied,'read_error'=>$current->get_error_code()));
   }
   $current_data=$current['data']??null;
   if(!OAI_Verify::same($current_data,$applied))return new WP_Error('oai_rollback_conflict','Target changed after Apply; rollback was blocked to protect the newer change',array('status'=>409,'current'=>$current_data,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));
   return true;
  }

  if($type==='woocommerce_product_create'){
   list($exists,$current)=self::current($type,$target);
   // Idempotent cleanup: an already-absent object has reached rollback state.
   if(!$exists)return true;
   if(!class_exists('OAI_WooCommerce')||!OAI_WooCommerce::created_snapshot_matches($current,$applied)){
    return new WP_Error('oai_rollback_conflict','Created WooCommerce product changed after Apply; rollback was blocked',array('status'=>409,'current'=>$current,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));
   }
   return true;
  }

  list($exists,$current)=self::current($type,$target);
  $expected_exists=array_key_exists('after_exists',$meta)?(bool)$meta['after_exists']:true;
  if($exists!==$expected_exists)return new WP_Error('oai_rollback_conflict','Target existence changed after Apply; rollback was blocked',array('status'=>409,'current_exists'=>$exists,'expected_exists'=>$expected_exists,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));

  if($exists){
   if($type==='woocommerce_product'){
    if(!self::snapshot_fields_match($current,$applied,array('id','sku','name','regular_price','sale_price','price','stock','stock_status','manage_stock','status')))return new WP_Error('oai_rollback_conflict','WooCommerce product changed after Apply; rollback was blocked',array('status'=>409,'current'=>$current,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));
   } elseif(in_array($type,array('woo_product_price','woo_product_stock'),true)){
    if(!self::same_snapshot($r['type'],$current,$applied))return new WP_Error('oai_rollback_conflict','WooCommerce product state changed after Apply; rollback was blocked',array('status'=>409,'current'=>$current,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));
   } else {
    if(!self::same_snapshot($r['type'],$current,$applied))return new WP_Error('oai_rollback_conflict','Target changed after Apply; rollback was blocked to protect the newer change',array('status'=>409,'current'=>$current,'expected_applied'=>$applied,'type'=>$type,'target'=>$target));
   }
  }
  return true;
 }
 private static function snapshot_fields_match($current,$before,$fields){
  if(!is_array($current)||!is_array($before)) return OAI_Verify::same($current,$before);
  foreach($fields as $field){
   $a=array_key_exists($field,$current)?$current[$field]:null;
   $b=array_key_exists($field,$before)?$before[$field]:null;
   if(!OAI_Verify::same($a,$b)) return false;
  }
  return true;
 }
 private static function refresh_verification_target($type,$target,$meta=array()){
  if(in_array($type,array('post_title','post_excerpt','post_content','elementor_widget_html','elementor_widget_settings','elementor_page_create'),true)){
   if($type==='elementor_page_create')$post_id=absint($meta['preview_page_id']??0);
   else{$parts=explode(':',(string)$target,2);$post_id=absint($parts[0]??0);}
   if($post_id)clean_post_cache($post_id);
  }
  if(in_array($type,array('woo_product_price','woo_product_stock','woocommerce_product'),true)){
   $parts=explode(':',(string)$target,2); $product_id=absint($parts[0]??0); if($product_id && function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);
   if($product_id) clean_post_cache($product_id);
  }
  if($type==='option_update' && $target!=='') wp_cache_delete((string)$target,'options');
 }

 private static function recover_after_apply_failure($r,$before,$meta,$failure){
  $id=(int)$r['id'];
  $verification=is_wp_error($failure)?array('verified'=>false,'error'=>$failure->get_error_code(),'message'=>$failure->get_error_message()):$failure;
  OAI_DB::update($id,array('status'=>'apply_failed','phase'=>'apply_failed'));
  OAI_Audit::log('apply_failed',array_merge($r,array('status'=>'apply_failed')),array('verification'=>$verification));
  OAI_Receipts::store($id,array('event'=>'apply_failed','status'=>'apply_failed','at'=>gmdate('c'),'verification'=>$verification,'rollback_attempted'=>false),OAI_Proof::make($id));

  // Automatic recovery is destructive as well. Only roll back when the live
  // target still equals the intended post-apply state; otherwise preserve the
  // newer/partial state and require manual inspection.
  $expected_after=json_decode((string)$r['after_data'],true);
  // File rollback must carry the exact post-apply SHA into the write/delete guard.
  // The conflict check above is intentionally followed by a second, write-time
  // SHA check to close the TOCTOU window between verification and recovery.
  if(in_array($r['type'],array('file_write','theme_file_write'),true)){
   $meta['applied_sha256']=hash('sha256',(string)$expected_after);
  }
  $recovery_conflict=self::rollback_conflict_check($r,$expected_after,$meta);
  if(is_wp_error($recovery_conflict)){
   OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
   $details=$recovery_conflict->get_error_data();
   OAI_Receipts::store($id,array('event'=>'rollback_conflict','status'=>'rollback_failed','at'=>gmdate('c'),'reason'=>'automatic_recovery_post_apply_change','details'=>$details),OAI_Proof::make($id));
   OAI_Audit::log('rollback_conflict',array_merge($r,array('status'=>'rollback_failed')),array('reason'=>'automatic_recovery_post_apply_change','details'=>$details));
   return new WP_Error('oai_apply_rollback_conflict','Apply failed and automatic rollback was blocked because the live target no longer matches the intended applied state',array('status'=>409,'apply_failure'=>$verification,'conflict'=>$details));
  }
  OAI_DB::update($id,array('status'=>'rollback_pending','phase'=>'rollback_pending'));
  OAI_DB::update($id,array('status'=>'rolling_back','phase'=>'rolling_back'));
  try { $rollback=self::rollback_write($r['type'],$r['target'],$before,$meta); }
  catch(Throwable $e){ $rollback=new WP_Error('oai_rollback_exception',$e->getMessage(),array('status'=>500)); }
  if(is_wp_error($rollback)){
   OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
   OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'apply_failure'=>$verification,'rollback_attempted'=>true,'rollback_ok'=>false,'rollback_error'=>$rollback->get_error_message()),OAI_Proof::make($id));
   OAI_Audit::log('rollback_failed',array_merge($r,array('status'=>'rollback_failed')),array('apply_failure'=>$verification,'error'=>$rollback->get_error_message()));
   return new WP_Error('oai_apply_rollback_failed','Apply failed and automatic rollback could not be completed',array('status'=>500,'apply_failure'=>$verification,'rollback_error'=>$rollback->get_error_message()));
  }

  self::refresh_verification_target($r['type'],$r['target'],$meta);
  $rv=OAI_Verify::operation($id,$before,'rollback',true);
  if(is_wp_error($rv) || empty($rv['verified'])){
   OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
   OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'apply_failure'=>$verification,'rollback_attempted'=>true,'rollback_ok'=>true,'rollback_verification'=>$rv),OAI_Proof::make($id));
   OAI_Audit::log('rollback_failed',array_merge($r,array('status'=>'rollback_failed')),array('apply_failure'=>$verification,'rollback_verification'=>$rv));
   return new WP_Error('oai_apply_rollback_verify_failed','Apply failed and rollback verification failed',array('status'=>500,'apply_failure'=>$verification,'rollback_verification'=>$rv));
  }
  self::refresh_verification_target($r['type'],$r['target'],$meta);
  $rv2=OAI_Verify::operation($id,$before,'rollback',true);
  if(is_wp_error($rv2) || empty($rv2['verified'])){
   OAI_DB::update($id,array('status'=>'rollback_failed','phase'=>'rollback_failed'));
   OAI_Receipts::store($id,array('event'=>'rollback_failed','status'=>'rollback_failed','at'=>gmdate('c'),'apply_failure'=>$verification,'rollback_attempted'=>true,'rollback_ok'=>true,'rollback_verification'=>$rv2),OAI_Proof::make($id));
   return new WP_Error('oai_apply_rollback_unstable','Apply failed and rollback was not stable on the second authoritative read',array('status'=>500,'apply_failure'=>$verification,'rollback_verification'=>$rv2));
  }

  OAI_DB::update($id,array('status'=>'rolled_back','phase'=>'rolled_back','rolled_back_at'=>current_time('mysql',true)));
  OAI_Receipts::store($id,array('event'=>'apply_failed','status'=>'rolled_back','at'=>gmdate('c'),'apply_failure'=>$verification,'rollback_attempted'=>true,'rollback_ok'=>true,'rollback_verification'=>$rv2),OAI_Proof::make($id));
  OAI_Audit::log('rolled_back',array_merge($r,array('status'=>'rolled_back')),array('reason'=>'apply_failed','verification'=>$rv2));
  return new WP_Error('oai_verify_failed','Apply failed; automatic rollback completed and was independently verified',array('status'=>500,'verification'=>$verification,'rollback_ok'=>true,'rollback_verification'=>$rv2,'final_status'=>'rolled_back'));
 }

 private static function elementor_page_publish($target){
  $parts=explode(':',(string)$target,2); $page_id=absint($parts[1]??0);
  if(!$page_id)return new WP_Error('oai_target','Invalid Elementor page target',array('status'=>400));
  $p=get_post($page_id); if(!$p)return new WP_Error('oai_target','Sandbox Elementor page not found',array('status'=>404));
  $meta=OAI_DB::all(200);
  foreach($meta as $row){if($row['type']==='elementor_page_create' && $row['target']===$target && $row['status']!=='rejected' && $row['status']!=='rolled_back'){ $m=json_decode($row['meta_data'],true); $final=sanitize_key((string)($m['final_status']??'draft')); if(!in_array($final,array('draft','publish','private'),true))$final='draft'; return wp_update_post(array('ID'=>$page_id,'post_status'=>$final),true);}}
  return wp_update_post(array('ID'=>$page_id,'post_status'=>'publish'),true);
 }
 private static function write($type,$target,$v,$expected_sha256=''){ if(class_exists('OAI_P0')&&in_array($type,array('elementor_page_layout','media_alt','media_title','media_featured','media_upload','menu_create','menu_add_item','menu_remove_item','menu_move_item','menu_assign_location','woocommerce_product_create'),true))return OAI_P0::write($type,$target,$v,array()); switch($type){case 'elementor_page_create': return self::elementor_page_publish($target); case 'option_update':update_option($target,$v,false);return true; case 'post_title':return wp_update_post(array('ID'=>(int)$target,'post_title'=>$v),true); case 'post_excerpt':return wp_update_post(array('ID'=>(int)$target,'post_excerpt'=>$v),true); case 'post_content':$allowed=OAI_Elementor::content_write_allowed((int)$target);return is_wp_error($allowed)?$allowed:wp_update_post(array('ID'=>(int)$target,'post_content'=>$v),true); case 'elementor_widget_html':return self::elementor_widget_html_write($target,$v); case 'elementor_widget_settings':return OAI_Elementor::save_settings($target,is_array($v)?$v:array()); case 'file_write': $parts=explode(':',$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400)); $w=OAI_Files::write($parts[0],$parts[1],(string)$v,$expected_sha256); return is_wp_error($w)?$w:true; case 'theme_file_write': $parts=explode(':',$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400)); $w=OAI_Files::write($parts[0],$parts[1],(string)$v,$expected_sha256); return is_wp_error($w)?$w:true; case 'woo_product_price':$p=wc_get_product((int)$target);if(!$p)return new WP_Error('oai_target','Product not found',array('status'=>404));$p->set_regular_price($v);$p->set_price($v);$p->save();return true; case 'woo_product_stock':$p=wc_get_product((int)$target);if(!$p)return new WP_Error('oai_target','Product not found',array('status'=>404));$is_array=is_array($v);$stock=$is_array?($v['stock']??null):$v;$manage=$is_array&&array_key_exists('manage_stock',$v)?(bool)$v['manage_stock']:true;$status=$is_array&&array_key_exists('stock_status',$v)?sanitize_key((string)$v['stock_status']):($stock===null?'instock':((int)$stock>0?'instock':'outofstock'));$p->set_manage_stock($manage);$p->set_stock_quantity($stock===null?null:(int)$stock);$p->set_stock_status($status);$p->save();return true;} return new WP_Error('oai_type','Unsupported type',array('status'=>400)); }
 private static function rollback_write($type,$target,$v,$meta){ if(class_exists('OAI_P0')&&in_array($type,array('media_alt','media_title','media_featured','media_upload','menu_create','menu_add_item','menu_remove_item','menu_move_item','menu_assign_location','woocommerce_product_create'),true))return OAI_P0::rollback($type,$target,$v,$meta); if($type==='elementor_page_create'){ $page_id=absint($meta['preview_page_id']??0); if($page_id){wp_delete_post($page_id,true);} return true; } if($type==='file_write'||$type==='theme_file_write'){ $parts=explode(':',$target,2); if(count($parts)!==2)return new WP_Error('oai_target','File target must be SCOPE:PATH',array('status'=>400)); if(empty($meta['before_exists'])){ $expected=isset($meta['applied_sha256'])?(string)$meta['applied_sha256']:''; $d=OAI_Files::delete($parts[0],$parts[1],$expected); return is_wp_error($d)?$d:true; } $expected=isset($meta['applied_sha256'])?(string)$meta['applied_sha256']:''; $w=OAI_Files::write($parts[0],$parts[1],(string)$v,$expected); return is_wp_error($w)?$w:true; } if($type==='option_update' && empty($meta['before_exists'])){delete_option($target);return true;} if($type==='woo_product_stock' && is_array($v)){ $p=wc_get_product((int)$target);if(!$p)return new WP_Error('oai_target','Product not found',array('status'=>404));$p->set_manage_stock((bool)($v['manage_stock']??false));$p->set_stock_quantity($v['stock']??null);$p->set_stock_status($v['stock_status']??'instock');$p->save();return true;} if($type==='woocommerce_product'){ $parts=explode(':',$target,2); $product_id=absint($parts[0]??0); if(!$product_id)return new WP_Error('oai_target','Invalid WooCommerce product target',array('status'=>400)); $w=OAI_WooCommerce::apply($product_id,is_array($v)?$v:array()); return is_wp_error($w)?$w:true; } return self::write($type,$target,$v); }
 public static function files_list($req){return OAI_Files::list_files((string)$req->get_param('scope'),(string)$req->get_param('path'));}
 public static function files_read($req){
  $scope=(string)$req->get_param('scope'); $path=(string)$req->get_param('path');
  $start=$req->get_param('start_line'); $end=$req->get_param('end_line');
  if($start!==null||$end!==null){if(!OAI_Platform::content_navigation_allowed())return new WP_Error('oai_feature_disabled','Content navigation is disabled',array('status'=>403));if($start===null||$end===null)return new WP_Error('oai_line_range','Both start_line and end_line are required',array('status'=>400));return OAI_Files::read_range($scope,$path,$start,$end,$req->get_param('max_chars')?:12000,(string)$req->get_param('expected_sha256'));}
  return OAI_Files::read($scope,$path);
 }
 public static function files_outline($req){if(!OAI_Platform::content_navigation_allowed())return new WP_Error('oai_feature_disabled','Content navigation is disabled',array('status'=>403));return OAI_Files::outline((string)$req->get_param('scope'),(string)$req->get_param('path'),$req->get_param('limit')?:120);}
 public static function files_search($req){return OAI_Files::search((string)$req->get_param('scope'),(string)$req->get_param('q'),(string)$req->get_param('path'),min(100,max(1,absint($req->get_param('limit')?:50))));}
 public static function files_write($req){
  $scope=sanitize_key((string)$req->get_param('scope')); $path=(string)$req->get_param('path'); $content=$req->get_param('content');
  if(!is_string($content))return new WP_Error('oai_content','content must be a string',array('status'=>400));
  $target=$scope.':'.$path;
  $req->set_param('type','file_write'); $req->set_param('target',$target); $req->set_param('value',$content);
  return self::sandbox_create($req);
 }
 public static function audit($req){return array('items'=>OAI_Audit::list($req->get_param('limit')?:200));}
 public static function verify($req){ $id=absint($req['id']); $row=OAI_DB::get($id); if(!$row)return new WP_Error('oai_not_found','Operation not found',array('status'=>404)); if($row['status']==='rolled_back')return OAI_Verify::operation($id,null,'rollback'); return OAI_Verify::operation($id); }
 public static function proof($req){ $x=OAI_Proof::make(absint($req['id'])); return $x?:new WP_Error('oai_not_found','Operation not found',array('status'=>404)); }
 public static function operation($req){ $r=OAI_DB::get(absint($req['id'])); if(!$r)return new WP_Error('oai_not_found','Operation not found',array('status'=>404)); return self::row($r['id']); }
 public static function workspace_status($req){ return OAI_Workspace::list_workspaces(); }
 public static function workspace_create($req){
  $clone=!in_array(strtolower((string)$req->get_param('clone_theme')),array('0','false','no'),true);
  return OAI_Workspace::create($clone);
 }
 public static function workspace_files($req){return OAI_Workspace::files((string)$req->get_param('workspace_id'),(string)$req->get_param('path'));}
 public static function workspace_read($req){return OAI_Workspace::read((string)$req->get_param('workspace_id'),(string)$req->get_param('path'));}
 public static function workspace_write($req){
  $wid=sanitize_text_field((string)$req->get_param('workspace_id')); $path=(string)$req->get_param('path'); $content=$req->get_param('content');
  if(!is_string($content))return new WP_Error('oai_content','content must be a string',array('status'=>400));
  $old=OAI_Workspace::read($wid,$path); if(is_wp_error($old) && $old->get_error_code()!=='oai_not_file')return $old;
  $before_exists=is_array($old)&&isset($old['content']); $before=$before_exists?$old['content']:'';
  $next=OAI_Workspace::write($wid,$path,$content); if(is_wp_error($next))return $next;
  // Workspace changes are isolated from the live theme. The operation records
  // the live target that will be changed only after explicit approval.
  if($before_exists && $before===$content)return new WP_Error('oai_noop','Workspace write does not change the target',array('status'=>409));
  $target='child_theme:'.$path;
  $op='OAI-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999);
  $meta=array('workspace_id'=>$wid,'workspace_path'=>$path,'before_exists'=>$before_exists,'after_exists'=>true,'sandbox_artifact'=>true,'rollback_descriptor'=>OAI_Lifecycle::descriptor('theme_file_write',$target,$before,array('before_exists'=>$before_exists)));
  $row=array('operation_id'=>$op,'status'=>'pending','type'=>'theme_file_write','target'=>$target,'before_data'=>wp_json_encode($before),'after_data'=>wp_json_encode($content),'meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'diff_data'=>wp_json_encode(OAI_Operations::diff($before,$content),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'risk'=>'high','phase'=>'awaiting_approval','workspace_id'=>$wid,'rollback_data'=>wp_json_encode($meta['rollback_descriptor']),'created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS));
  $id=OAI_DB::insert($row); if(is_wp_error($id)){
   // A failed ledger insert must not leave an untracked workspace draft.
   $restore=OAI_Workspace::restore($wid,$path,$before_exists,$before);
   if(is_wp_error($restore))OAI_Audit::log('workspace_restore_failed',array('workspace_id'=>$wid,'path'=>$path,'error'=>$restore->get_error_code()));
   return $id;
  } OAI_Audit::log('workspace_file_changed',array_merge($row,array('id'=>$id)),array('workspace_id'=>$wid,'path'=>$path)); return self::row($id);
 }
 public static function workspace_surfaces($req){return OAI_Workspace::surfaces((string)$req->get_param('workspace_id'));}
 public static function surfaces_catalog($req){return OAI_Surfaces::catalog();}
 public static function foundation_capabilities(){ return array('version'=>OAI_VERSION,'architecture'=>'modular-bootstrap-workspace-editable-surfaces-sandbox-plan-diff-preview-approval-apply-verify-proof-receipt-rollback','visual_ui'=>'deferred','wpvibe_foundations'=>array('modular_bootstrap'=>true,'operation_audit'=>true,'change_receipts'=>true,'proof_hashes'=>true,'builder_adapters'=>true,'elementor_document_save_and_repair'=>true,'elementor_frontend_preview'=>true,'file_scopes'=>array('child_theme','theme'),'workspace'=>true,'starter_theme_classic'=>true,'editable_surfaces'=>true,'operation_lifecycle'=>true,'rollback_descriptors'=>true,'file_conflict_sha256'=>true,'content_patch'=>true,'woocommerce_safe_patch'=>true,'gutenberg_read'=>true,'abilities_api'=>function_exists('wp_register_ability'),'mcp_jsonrpc'=>true,'agent_hmac'=>true,'rate_limit'=>true,'wp_filesystem'=>true,'future'=>array('detached_background_ops','live_reload','advanced_change_tracker','builder_login','classic_theme_tools','beaver_builder','bricks','breakdance','divi','seedprod')),'security'=>array('direct_live_writes'=>false,'arbitrary_php'=>false,'arbitrary_sql'=>false,'arbitrary_routes'=>false,'approval_required'=>true)); }
 public static function receipt($req){$x=OAI_Receipts::build(absint($req['id']));return $x?:new WP_Error('oai_not_found','Operation not found',array('status'=>404));}
 public static function gutenberg($req){return OAI_Gutenberg::blocks(absint($req['id']));}
 public static function woocommerce($req){return OAI_WooCommerce::get(absint($req['id']));}
 public static function content_patch($req){$id=absint($req->get_param('post_id'));$field=sanitize_key((string)$req->get_param('field'));$old=(string)$req->get_param('old_content');$new=(string)$req->get_param('new_content');$all=filter_var($req->get_param('replace_all'),FILTER_VALIDATE_BOOLEAN);$x=OAI_Content::patch($id,$field,$old,$new,$all);if(is_wp_error($x))return $x;return self::sandbox_create_from_patch($field,$id,$field,$x['before'],$x['after'],array('kind'=>'content_patch','field'=>$field,'before_exists'=>true));}
 public static function woocommerce_patch($req){$license=OAI_License::can('woocommerce');if(is_wp_error($license))return $license;$id=absint($req->get_param('product_id'));$changes=$req->get_param('changes');if(is_string($changes))$changes=json_decode($changes,true);$x=OAI_WooCommerce::patch($id,is_array($changes)?$changes:array());if(is_wp_error($x))return $x;return self::sandbox_create_from_patch('woocommerce_product',$id,'product',$x['before'],$x['after'],array('kind'=>'woocommerce_patch','changes'=>$changes));}
 private static function sandbox_create_from_patch($type,$id,$field,$before,$after,$meta=array()){ if($type==='post_content'){$allowed=OAI_Elementor::content_write_allowed($id);if(is_wp_error($allowed))return $allowed;} $diff=OAI_Operations::diff($before,$after); if(empty($diff['changed']))return new WP_Error('oai_noop','Operation does not change the target',array('status'=>409)); $op='OAI-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999);$target=$id.':'.$field;$descriptor=OAI_Lifecycle::descriptor($type,$target,$before,array_merge(array('before_exists'=>true),$meta));$meta=array_merge($meta,array('rollback_descriptor'=>$descriptor));$row=array('operation_id'=>$op,'status'=>'pending','type'=>$type,'target'=>$target,'before_data'=>wp_json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'after_data'=>wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'applied_data'=>null,'meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'diff_data'=>wp_json_encode($diff,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'phase'=>'awaiting_approval','risk'=>OAI_Operations::risk($type,$field),'created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS));$row['rollback_data']=wp_json_encode($descriptor,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$idb=OAI_DB::insert($row);if(is_wp_error($idb))return $idb;OAI_Audit::log('created',array_merge($row,array('id'=>$idb)));return self::row($idb);}

 public static function runtime_fingerprint(){
  $out=array('ok'=>true,'version'=>defined('OAI_VERSION')?OAI_VERSION:null,'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:null,'plugin_main'=>defined('OAI_FILE')?OAI_FILE:null,'plugin_dir'=>defined('OAI_DIR')?OAI_DIR:dirname(__DIR__),'php_version'=>PHP_VERSION,'methods'=>array(),'routes'=>array());
  foreach(array('execution_wordpress','execution_plan','execution_input','runtime_fingerprint') as $m){
   try{$rm=new ReflectionMethod('OAI_REST',$m);$file=$rm->getFileName();$out['methods'][$m]=array('file'=>$file,'start_line'=>$rm->getStartLine(),'end_line'=>$rm->getEndLine(),'sha256'=>($file&&is_readable($file)?hash_file('sha256',$file):null));}catch(Throwable $e){$out['methods'][$m]=array('error'=>$e->getMessage());}
  }
  try {
   $routes=rest_get_server()->get_routes();
   $needle='/okean-ai/v1/execution/wordpress';
   if(isset($routes[$needle])) {
    foreach($routes[$needle] as $idx=>$route) {
     $cb=$route['callback']??null;
     if(is_array($cb)&&count($cb)>=2&&is_object($cb[0])) $cb=array(get_class($cb[0]),$cb[1]);
     $out['routes'][]=array('index'=>$idx,'methods'=>$route['methods']??null,'callback'=>is_array($cb)?$cb:(is_string($cb)?$cb:(is_object($cb)?get_class($cb):gettype($cb))));
    }
   }
   $out['route_count']=count($out['routes']);
  } catch(Throwable $e) { $out['route_error']=$e->getMessage(); }
  return $out;
 }
 public static function execution_readiness(){return array('version'=>OAI_VERSION,'command_registry'=>true,'command_count'=>count(OAI_Command_Registry::all()),'registry_audit'=>OAI_Command_Registry::handler_audit(),'execution'=>OAI_WP_CLI_Execution::readiness(),'normalizer'=>true,'argument_parser'=>true,'permission_resolver'=>true,'dispatcher'=>true,'wp_post_resolver'=>true,'gutenberg_resolver'=>true,'parse_blocks'=>function_exists('parse_blocks'),'serialize_blocks'=>function_exists('serialize_blocks'),'direct_live_writes'=>false,'approval_required'=>true);}
 public static function execution_input($req,$key,$default=null){
  $json=$req->get_json_params();
  if(is_array($json) && array_key_exists($key,$json)) return $json[$key];
  $body=(string)$req->get_body();
  if($body!==''){
    $decoded=json_decode($body,true);
    if(is_array($decoded) && array_key_exists($key,$decoded)) return $decoded[$key];
  }
  $query=$req->get_query_params();
  if(is_array($query) && array_key_exists($key,$query)) return $query[$key];
  $param=$req->get_param($key);
  if($param!==null && $param!=='') return $param;
  if(isset($_REQUEST[$key]) && $_REQUEST[$key]!=='') return wp_unslash($_REQUEST[$key]);
  return $default;
}
 public static function execution_trace($req){
  $cmd=self::execution_input($req,'command',null);
  $mode=sanitize_key((string)self::execution_input($req,'mode','read'));
  $out=array('ok'=>true,'version'=>OAI_VERSION,'request_method'=>$req instanceof WP_REST_Request?$req->get_method():null,'command'=>array('type'=>gettype($cmd),'present'=>($cmd!==null&&$cmd!==''),'length'=>is_string($cmd)?strlen($cmd):null,'sha256'=>is_string($cmd)?hash('sha256',$cmd):null),'mode'=>$mode,'stages'=>array());
  if($cmd===null||$cmd===''){ $out['stages']['input']='empty'; return $out; }
  try{$n=OAI_Command_Normalizer::normalize($cmd); if(is_wp_error($n)){ $out['stages']['normalize']=array('ok'=>false,'code'=>$n->get_error_code(),'message'=>$n->get_error_message()); return $out; } $out['stages']['normalize']=array('ok'=>true,'family'=>$n['family']??null,'verb'=>$n['verb']??null,'subverb'=>$n['subverb']??null,'args_count'=>isset($n['args'])&&is_array($n['args'])?count($n['args']):null,'flags_count'=>isset($n['flags'])&&is_array($n['flags'])?count($n['flags']):null); }catch(Throwable $e){ $out['stages']['normalize']=array('ok'=>false,'exception'=>get_class($e),'message'=>$e->getMessage()); return $out; }
  try{$r=OAI_Command_Registry::resolve($cmd); if(is_wp_error($r)){ $out['stages']['registry']=array('ok'=>false,'code'=>$r->get_error_code(),'message'=>$r->get_error_message(),'data'=>$r->get_error_data()); return $out; } $out['stages']['registry']=array('ok'=>true,'canonical'=>$r['canonical']??null,'family'=>$r['family']??null,'verb'=>$r['verb']??null,'handler'=>$r['handler']??null,'read'=>!empty($r['read']),'approval'=>!empty($r['approval'])); }catch(Throwable $e){ $out['stages']['registry']=array('ok'=>false,'exception'=>get_class($e),'message'=>$e->getMessage()); return $out; }
  try{$perm=OAI_Permission_Resolver::resolve($r); $out['stages']['permission']=array('ok'=>true,'allowed'=>!empty($perm['allowed']),'capability'=>$perm['capability']??null);}catch(Throwable $e){$out['stages']['permission']=array('ok'=>false,'exception'=>get_class($e),'message'=>$e->getMessage()); return $out;}
  $out['stages']['dispatcher']=array('ok'=>true,'would_execute'=>($mode!=='plan'&&!empty($r['read'])),'would_plan'=>($mode==='plan'||empty($r['read'])));
  return $out;
 }
 public static function execution_plan($req){$cmd=self::execution_input($req,'command',null);return OAI_Execution_Dispatcher::dispatch($cmd,'plan');}
 public static function execution_wordpress($req){$license=OAI_License::can('wp_cli');if(is_wp_error($license))return $license;$cmd=self::execution_input($req,'command',null);$mode=sanitize_key((string)self::execution_input($req,'mode','read'));return OAI_Execution_Dispatcher::dispatch($cmd,$mode);}
 public static function gutenberg_resolved($req){$view=(string)$req->get_param('view');if($view!==''&&!in_array($view,array('outline','full'),true))return new WP_Error('oai_view','Invalid Gutenberg view',array('status'=>400));$out=OAI_Gutenberg_Resolver::resolve_post(absint($req['id']));return $view==='outline'?OAI_Gutenberg_Resolver::outline($out):$out;}
 public static function gutenberg_locate($req){$view=(string)$req->get_param('view');if($view!==''&&!in_array($view,array('outline','full'),true))return new WP_Error('oai_view','Invalid Gutenberg view',array('status'=>400));$sel=array();foreach(array('block_name','path','text','html_contains') as $k){$v=$req->get_param($k);if($v!==null)$sel[$k]=sanitize_text_field((string)$v);}$out=OAI_Gutenberg_Resolver::locate(absint($req['id']),$sel);return $view==='outline'?OAI_Gutenberg_Resolver::outline($out,true):$out;}
 public static function gutenberg_patch($req){$license=OAI_License::can('gutenberg');if(is_wp_error($license))return $license;$id=absint($req->get_param('post_id'));$selector=$req->get_param('selector');if(is_string($selector))$selector=json_decode($selector,true);if(!is_array($selector))$selector=array();$attrs=$req->get_param('attrs');$html=$req->get_param('html');$x=OAI_Gutenberg_Resolver::patch($id,$selector,$attrs,$html);if(is_wp_error($x))return $x;return self::sandbox_create_from_patch('post_content',$id,'post_content',$x['before'],$x['after'],array('kind'=>'gutenberg_block_patch','selector'=>$selector,'block_path'=>$x['path']));}

}
