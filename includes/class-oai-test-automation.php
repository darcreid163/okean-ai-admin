<?php
/**
 * WP AI Admin temporary autonomous regression harness.
 *
 * Disabled by default. It never grants arbitrary MCP live writes: an
 * administrator must open a short-lived test session first, and only
 * operations created/tagged by the same MCP client inside that session can
 * use the lifecycle exception.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Test_Automation {
 const OPTION='oai_test_automation';
 const MAX_TTL=120;
 const MAX_ACTIONS=100000;
 const FILE_PREFIX='okean-core/wp-ai-admin-tests/';
 const GUTENBERG_FIXTURE_TITLE='WP AI Admin TEST — Gutenberg Fixture';
 const LAST_REGRESSION_OPTION='oai_test_automation_last_regression';
 const LAST_MATRIX_OPTION='oai_test_automation_last_matrix';
 private static $request_context=null;

 public static function parse_ids($value){
  if(is_array($value))$parts=$value;else$parts=preg_split('/[\s,;]+/',(string)$value,-1,PREG_SPLIT_NO_EMPTY);
  $out=array();
  foreach($parts as $part){$id=absint($part);if($id>0)$out[$id]=$id;}
  return array_values($out);
 }

 public static function sanitize_ttl($minutes){
  $minutes=(int)$minutes;
  if(!in_array($minutes,array(15,30,60,120),true))$minutes=60;
  return min(self::MAX_TTL,max(15,$minutes));
 }

 private static function raw(){
  $cfg=get_option(self::OPTION,array());
  return is_array($cfg)?$cfg:array();
 }

 private static function save($cfg){update_option(self::OPTION,$cfg,false);}

 private static function cleanup_gutenberg_fixture($cfg){
  $id=absint($cfg['managed_gutenberg_fixture_id']??0);
  if(!$id)return true;
  $post=get_post($id);if(!$post)return true;
  $kind=(string)get_post_meta($id,'_wpai_test_fixture',true);
  $session=(string)get_post_meta($id,'_wpai_test_session_id',true);
  if($kind!=='gutenberg_regression')return false;
  if(!in_array((string)$post->post_status,array('draft','private','trash'),true))return false;
  if($session!=='' && !empty($cfg['session_id']) && !hash_equals((string)$cfg['session_id'],$session))return false;
  return (bool)wp_delete_post($id,true);
 }

 public static function gutenberg_fixture_content(){
  return "<!-- wp:heading {\"level\":2} --><h2 class=\"wp-block-heading\">WP AI Admin Gutenberg Fixture</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>Baseline paragraph for reversible Gutenberg regression tests.</p><!-- /wp:paragraph -->";
 }

 private static function create_gutenberg_fixture($owner,$session_id){
  $post_id=wp_insert_post(array(
   'post_type'=>'page','post_status'=>'draft','post_author'=>absint($owner),
   'post_title'=>self::GUTENBERG_FIXTURE_TITLE,
   'post_content'=>self::gutenberg_fixture_content()
  ),true);
  if(is_wp_error($post_id))return $post_id;
  update_post_meta($post_id,'_wpai_test_fixture','gutenberg_regression');
  update_post_meta($post_id,'_wpai_test_session_id',(string)$session_id);
  return absint($post_id);
 }

 private static function find_reusable_gutenberg_fixture(){
  $q=new WP_Query(array(
   'post_type'=>'page','post_status'=>array('draft','private'),'posts_per_page'=>20,
   'orderby'=>'ID','order'=>'DESC','fields'=>'ids','no_found_rows'=>true,
   'meta_key'=>'_wpai_test_fixture','meta_value'=>'gutenberg_regression'
  ));
  foreach((array)$q->posts as $id){
   $post=get_post(absint($id));
   if($post && in_array((string)$post->post_status,array('draft','private'),true))return absint($id);
  }
  return 0;
 }

 private static function ensure_gutenberg_fixture($owner,$session_id){
  $post_id=self::find_reusable_gutenberg_fixture();
  if(!$post_id)return self::create_gutenberg_fixture($owner,$session_id);
  $updated=wp_update_post(array(
   'ID'=>$post_id,'post_status'=>'draft','post_author'=>absint($owner),
   'post_title'=>self::GUTENBERG_FIXTURE_TITLE,
   'post_content'=>self::gutenberg_fixture_content()
  ),true);
  if(is_wp_error($updated))return $updated;
  update_post_meta($post_id,'_wpai_test_fixture','gutenberg_regression');
  update_post_meta($post_id,'_wpai_test_session_id',(string)$session_id);
  return absint($post_id);
 }

 private static function release_gutenberg_fixture($cfg){
  $id=absint($cfg['managed_gutenberg_fixture_id']??0);
  if(!$id)return true;
  $post=get_post($id);if(!$post)return true;
  if((string)get_post_meta($id,'_wpai_test_fixture',true)!=='gutenberg_regression')return false;
  if(!in_array((string)$post->post_status,array('draft','private','trash'),true))return false;
  if((string)$post->post_status==='trash')wp_untrash_post($id);
  $updated=wp_update_post(array(
   'ID'=>$id,'post_status'=>'draft','post_title'=>self::GUTENBERG_FIXTURE_TITLE,
   'post_content'=>self::gutenberg_fixture_content()
  ),true);
  if(is_wp_error($updated))return false;
  update_post_meta($id,'_wpai_test_fixture','gutenberg_regression');
  delete_post_meta($id,'_wpai_test_session_id');
  return true;
 }

 public static function managed_gutenberg_fixture_id(){
  $cfg=self::raw();$id=absint($cfg['managed_gutenberg_fixture_id']??0);
  $p=$id?get_post($id):false;
  return ($p&&in_array((string)$p->post_status,array('draft','private'),true))?$id:0;
 }

 public static function enable($ttl_minutes,$post_ids=array(),$product_ids=array(),$owner_user_id=0){
  $ttl=self::sanitize_ttl($ttl_minutes);
  $owner=absint($owner_user_id);
  if(!$owner || !user_can($owner,'manage_options'))return new WP_Error('oai_test_admin','An administrator is required to enable Test Automation Mode',array('status'=>403));
  $old=self::raw();if(!empty($old['managed_gutenberg_fixture_id']))self::release_gutenberg_fixture($old);
  $now=time();$session_id='tas_'.wp_generate_uuid4();
  $fixture=self::ensure_gutenberg_fixture($owner,$session_id);
  if(is_wp_error($fixture))return new WP_Error('oai_test_gutenberg_fixture','Could not create the managed Gutenberg regression fixture: '.$fixture->get_error_message(),array('status'=>500));
  $allowed_posts=self::parse_ids($post_ids);$allowed_posts[]=$fixture;$allowed_posts=self::parse_ids($allowed_posts);
  $cfg=array(
   'enabled'=>true,
   'session_id'=>$session_id,
   'enabled_at'=>$now,
   'expires_at'=>$now+($ttl*MINUTE_IN_SECONDS),
   'owner_user_id'=>$owner,
   'bound_client_id_hash'=>'',
   'allowed_post_ids'=>$allowed_posts,
   'allowed_product_ids'=>self::parse_ids($product_ids),
   'session_created_post_ids'=>array(),
   'managed_gutenberg_fixture_id'=>$fixture,
   'file_prefix'=>self::FILE_PREFIX,
   'action_count'=>0,
   'max_actions'=>self::MAX_ACTIONS,
   'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'',
  );
  self::save($cfg);
  return self::status();
 }

 public static function disable($reason='manual',$actor_user_id=0){
  $cfg=self::raw();
  self::release_gutenberg_fixture($cfg);
  $cfg['enabled']=false;
  $cfg['managed_gutenberg_fixture_id']=0;
  $cfg['disabled_at']=time();
  $cfg['disabled_reason']=sanitize_key((string)$reason);
  $cfg['disabled_by']=absint($actor_user_id);
  self::save($cfg);
  self::$request_context=null;
  return self::status();
 }

 public static function is_active(){
  $cfg=self::raw();
  if(empty($cfg['enabled']))return false;
  if(empty($cfg['session_id'])||empty($cfg['expires_at']))return false;
  if((int)$cfg['expires_at']<=time())return false;
  if(!empty($cfg['build_id']) && defined('OAI_BUILD_ID') && !hash_equals((string)$cfg['build_id'],(string)OAI_BUILD_ID))return false;
  if((int)($cfg['action_count']??0)>=(int)($cfg['max_actions']??self::MAX_ACTIONS))return false;
  return true;
 }

 public static function status(){
  $cfg=self::raw();
  $active=self::is_active();
  if(!$active && !empty($cfg['managed_gutenberg_fixture_id'])){
   if(self::release_gutenberg_fixture($cfg)){$cfg['managed_gutenberg_fixture_id']=0;self::save($cfg);}
  }
  $expires=absint($cfg['expires_at']??0);
  return array(
   'supported'=>true,
   'active'=>$active,
   'mode'=>$active?'scoped_mcp_test_automation':'human_approval_only',
   'session_id'=>$active?(string)($cfg['session_id']??''):'',
   'enabled_at'=>absint($cfg['enabled_at']??0),
   'expires_at'=>$expires,
   'expires_in'=>$active?max(0,$expires-time()):0,
   'owner_user_id'=>absint($cfg['owner_user_id']??0),
   'client_bound'=>!empty($cfg['bound_client_id_hash']),
   'allowed_post_ids'=>self::parse_ids($cfg['allowed_post_ids']??array()),
   'allowed_product_ids'=>self::parse_ids($cfg['allowed_product_ids']??array()),
   'session_created_post_ids'=>self::parse_ids($cfg['session_created_post_ids']??array()),
   'managed_gutenberg_fixture_id'=>self::managed_gutenberg_fixture_id(),
   'file_prefix'=>(string)($cfg['file_prefix']??self::FILE_PREFIX),
   'action_count'=>absint($cfg['action_count']??0),
   'max_actions'=>absint($cfg['max_actions']??self::MAX_ACTIONS),
   'last_regression'=>get_option(self::LAST_REGRESSION_OPTION,null),
   'last_matrix'=>get_option(self::LAST_MATRIX_OPTION,null),
   'rules'=>array(
    'admin_enable_required'=>true,
    'short_lived'=>true,
    'same_mcp_client_required'=>true,
    'only_session_tagged_operations'=>true,
    'live_write_still_uses_sandbox_lifecycle'=>true,
    'conflict_verify_and_rollback_guards_preserved'=>true,
    'plugin_theme_user_arbitrary_actions'=>false,
    'managed_gutenberg_draft_fixture'=>true,
   ),
  );
 }

 public static function request_authorized($id,$action){
  if(!is_array(self::$request_context))return false;
  return absint(self::$request_context['id']??0)===absint($id) && (string)(self::$request_context['action']??'')===(string)$action && !empty(self::$request_context['authorized']);
 }
 public static function clear_request_context(){self::$request_context=null;}

 private static function row_meta($row){$meta=json_decode((string)($row['meta_data']??''),true);return is_array($meta)?$meta:array();}
 private static function title_is_test($post){if(!$post)return false;$title=wp_strip_all_tags((string)$post->post_title);$ru=function_exists('mb_stripos')?mb_stripos($title,'тест')!==false:(strpos($title,'ТЕСТ')!==false||strpos($title,'тест')!==false);return $ru || stripos($title,'test')!==false;}
 private static function post_allowed($post_id,$cfg){
  $post_id=absint($post_id);if(!$post_id)return false;
  $allowed=self::parse_ids($cfg['allowed_post_ids']??array());
  if(in_array($post_id,$allowed,true))return true;
  $post=get_post($post_id);if(!$post)return false;
  return in_array((string)$post->post_status,array('draft','private'),true) && self::title_is_test($post);
 }
 private static function allow_created_post($post_id,$session_id){
  $post_id=absint($post_id);if(!$post_id)return false;
  $cfg=self::raw();
  if(empty($cfg['enabled'])||empty($cfg['session_id'])||!hash_equals((string)$cfg['session_id'],(string)$session_id))return false;
  $post=get_post($post_id);if(!$post||!in_array((string)$post->post_status,array('draft','private'),true))return false;
  $allowed=self::parse_ids($cfg['allowed_post_ids']??array());$allowed[]=$post_id;$cfg['allowed_post_ids']=self::parse_ids($allowed);
  $created=self::parse_ids($cfg['session_created_post_ids']??array());$created[]=$post_id;$cfg['session_created_post_ids']=self::parse_ids($created);
  self::save($cfg);
  update_post_meta($post_id,'_wpai_test_created_draft_session',(string)$session_id);
  return true;
 }
 private static function product_allowed($product_id,$cfg){$product_id=absint($product_id);return $product_id>0&&in_array($product_id,self::parse_ids($cfg['allowed_product_ids']??array()),true);}
 private static function file_allowed($target,$cfg){
  $parts=explode(':',(string)$target,2);if(count($parts)!==2)return false;
  $scope=sanitize_key($parts[0]);$path=ltrim(str_replace('\\','/',(string)$parts[1]),'/');
  if(!in_array($scope,array('child_theme','theme'),true))return false;
  $prefix=(string)($cfg['file_prefix']??self::FILE_PREFIX);
  return $prefix!=='' && strpos($path,$prefix)===0 && strpos($path,'..')===false;
 }
 private static function option_allowed($target){$target=(string)$target;return strpos($target,'oai_test_')===0||strpos($target,'wp_ai_admin_test_')===0;}

 public static function eligibility($row){
  $cfg=self::raw();
  if(!is_array($row))return array('eligible'=>false,'reason'=>'row_missing');
  $type=(string)($row['type']??'');$target=(string)($row['target']??'');$meta=self::row_meta($row);
  $allowed=false;$reason='unsupported_type';
  if(in_array($type,array('post_title','post_excerpt','post_content'),true)){
   $parts=explode(':',$target,2);$allowed=self::post_allowed(absint($parts[0]??0),$cfg);$reason=$allowed?'allowed_test_post':'post_not_allowlisted_or_test_draft';
  }elseif(in_array($type,array('elementor_widget_html','elementor_widget_settings'),true)){
   $parts=explode(':',$target,2);$allowed=self::post_allowed(absint($parts[0]??0),$cfg);$reason=$allowed?'allowed_test_elementor':'elementor_post_not_allowlisted_or_test_draft';
  }elseif($type==='elementor_page_create'){
   $preview=absint($meta['preview_page_id']??0);$post=$preview?get_post($preview):false;$final=sanitize_key((string)($meta['final_status']??'draft'));
   $allowed=$post && in_array((string)$post->post_status,array('draft','private'),true) && in_array($final,array('draft','private'),true);
   $reason=$allowed?'allowed_created_test_draft':'elementor_create_must_remain_draft_or_private';
  }elseif(in_array($type,array('woocommerce_product','woo_product_price','woo_product_stock'),true)){
   $parts=explode(':',$target,2);$allowed=self::product_allowed(absint($parts[0]??0),$cfg);$reason=$allowed?'allowed_test_product':'product_not_allowlisted';
  }elseif($type==='woocommerce_product_create'){
   $pid=absint($target);$post=$pid?get_post($pid):false;
   $allowed=$post && $post->post_type==='product' && (string)$post->post_status==='draft' && (string)get_post_meta($pid,'_oai_sandbox_artifact',true)==='1';
   $reason=$allowed?'allowed_created_test_product':'created_product_not_sandbox_draft';
  }elseif(in_array($type,array('media_upload','media_alt','media_title'),true)){
   $aid=absint($target);$post=$aid?get_post($aid):false;
   $allowed=$post && $post->post_type==='attachment' && (string)get_post_meta($aid,'_oai_sandbox_artifact',true)==='1';
   $reason=$allowed?'allowed_created_test_media':'media_not_session_sandbox_artifact';
  }elseif($type==='media_featured'){
   $post_id=absint($target);$allowed=self::post_allowed($post_id,$cfg);$reason=$allowed?'allowed_test_featured_post':'featured_post_not_allowlisted';
  }elseif(in_array($type,array('menu_create','menu_add_item','menu_remove_item','menu_move_item'),true)){
   $menu_id=absint($meta['menu_id']??0);
   if(!$menu_id && $type==='menu_create')$menu_id=absint($target);
   if(!$menu_id && $type!=='menu_create'){$terms=wp_get_object_terms(absint($target),'nav_menu',array('fields'=>'ids'));if(!is_wp_error($terms)&&$terms)$menu_id=absint($terms[0]);}
   $allowed=$menu_id && (string)get_term_meta($menu_id,'_oai_sandbox_artifact',true)==='1';
   $reason=$allowed?'allowed_created_test_menu':'menu_not_session_sandbox_artifact';
  }elseif($type==='menu_assign_location'){
   $menu_id=absint(json_decode((string)($row['after_data']??''),true));$allowed=$menu_id && (string)get_term_meta($menu_id,'_oai_sandbox_artifact',true)==='1';
   $reason=$allowed?'allowed_created_test_menu_location':'menu_location_target_not_sandbox_menu';
  }elseif(in_array($type,array('file_write','theme_file_write'),true)){
   $allowed=self::file_allowed($target,$cfg);$reason=$allowed?'allowed_test_file_prefix':'file_outside_test_prefix';
  }elseif($type==='option_update'){
   $allowed=self::option_allowed($target);$reason=$allowed?'allowed_test_option':'option_outside_test_prefix';
  }
  return array('eligible'=>$allowed,'reason'=>$reason,'type'=>$type,'target'=>$target);
 }

 public static function tag_operation($id,$tool,$client_id,$user_id){
  $status=self::status();
  if(empty($status['active']))return array('active'=>false,'tagged'=>false,'reason'=>'test_automation_inactive');
  $cfg=self::raw();
  $user_id=absint($user_id);
  if(!$user_id || $user_id!==absint($cfg['owner_user_id']??0))return array('active'=>true,'tagged'=>false,'reason'=>'session_owner_mismatch');
  $client_hash=hash('sha256',(string)$client_id);
  if(empty($cfg['bound_client_id_hash'])){$cfg['bound_client_id_hash']=$client_hash;self::save($cfg);}elseif(!hash_equals((string)$cfg['bound_client_id_hash'],$client_hash))return array('active'=>true,'tagged'=>false,'reason'=>'session_bound_to_other_client');
  $row=OAI_DB::get(absint($id));if(!$row)return array('active'=>true,'tagged'=>false,'reason'=>'operation_not_found');
  $elig=self::eligibility($row);
  $meta=self::row_meta($row);
  if(empty($elig['eligible'])){
   $meta['test_automation']=array('session_id'=>$status['session_id'],'tagged'=>false,'reason'=>$elig['reason'],'tool'=>sanitize_text_field((string)$tool),'at'=>time());
   OAI_DB::update((int)$row['id'],array('meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
   return array('active'=>true,'tagged'=>false,'eligible'=>false,'reason'=>$elig['reason']);
  }
  if($elig['reason']==='allowed_created_test_draft'){
   $preview=absint($meta['preview_page_id']??0);
   if(!self::allow_created_post($preview,(string)$status['session_id'])){
    $meta['test_automation']=array('session_id'=>$status['session_id'],'tagged'=>false,'reason'=>'created_draft_scope_registration_failed','tool'=>sanitize_text_field((string)$tool),'at'=>time());
    OAI_DB::update((int)$row['id'],array('meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    return array('active'=>true,'tagged'=>false,'eligible'=>false,'reason'=>'created_draft_scope_registration_failed');
   }
  }
  $meta['test_automation']=array(
   'session_id'=>$status['session_id'],'tagged'=>true,'tool'=>sanitize_text_field((string)$tool),'client_id_hash'=>$client_hash,'user_id'=>$user_id,'tagged_at'=>time(),'eligibility'=>$elig['reason']
  );
  OAI_DB::update((int)$row['id'],array('meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
  $fresh=OAI_DB::get((int)$row['id']);
  OAI_Audit::log('test_auto_tagged',$fresh,array('session_id'=>$status['session_id'],'tool'=>$tool,'eligibility'=>$elig['reason']));
  return array('active'=>true,'tagged'=>true,'eligible'=>true,'session_id'=>$status['session_id'],'reason'=>$elig['reason']);
 }

 public static function runner_authorized($client_id,$user_id){
  $status=self::status();if(empty($status['active']))return new WP_Error('oai_test_inactive','Test Automation Mode is not active',array('status'=>403));
  $user_id=absint($user_id);if(!$user_id||!user_can($user_id,'manage_options'))return new WP_Error('oai_test_admin','Administrator MCP identity required',array('status'=>403));
  $cfg=self::raw();
  if($user_id!==absint($cfg['owner_user_id']??0))return new WP_Error('oai_test_owner','Test session belongs to a different administrator',array('status'=>403));
  $current_client_hash=hash('sha256',(string)$client_id);
  if(!empty($cfg['bound_client_id_hash'])&&!hash_equals((string)$cfg['bound_client_id_hash'],$current_client_hash))return new WP_Error('oai_test_client','Test session is bound to a different MCP client',array('status'=>403));
  return true;
 }
 public static function store_last_regression($summary){update_option(self::LAST_REGRESSION_OPTION,$summary,false);}
 private static function authorize($id,$action,$client_id,$user_id){
  $status=self::status();if(empty($status['active']))return new WP_Error('oai_test_inactive','Test Automation Mode is not active',array('status'=>403));
  $user_id=absint($user_id);if(!$user_id||!user_can($user_id,'manage_options'))return new WP_Error('oai_test_admin','Administrator MCP identity required',array('status'=>403));
  $cfg=self::raw();
  if($user_id!==absint($cfg['owner_user_id']??0))return new WP_Error('oai_test_owner','Test session belongs to a different administrator',array('status'=>403));
  $current_client_hash=hash('sha256',(string)$client_id);
  $row=OAI_DB::get(absint($id));if(!$row)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  $meta=self::row_meta($row);$tag=is_array($meta['test_automation']??null)?$meta['test_automation']:array();
  if(empty($tag['tagged'])||!hash_equals((string)$status['session_id'],(string)($tag['session_id']??'')))return new WP_Error('oai_test_untagged','Operation was not created/tagged in the active test session',array('status'=>403));
  if(empty($cfg['bound_client_id_hash'])||!hash_equals((string)$cfg['bound_client_id_hash'],$current_client_hash))return new WP_Error('oai_test_client','Test session is bound to a different MCP client',array('status'=>403));
  if(empty($tag['client_id_hash'])||!hash_equals((string)$tag['client_id_hash'],$current_client_hash))return new WP_Error('oai_test_client','Operation belongs to a different MCP client',array('status'=>403));
  if(!empty($tag['user_id']) && absint($tag['user_id'])!==$user_id)return new WP_Error('oai_test_user','Operation belongs to a different administrator identity',array('status'=>403));
  if($action!=='reject'){
   $elig=self::eligibility($row);if(empty($elig['eligible']))return new WP_Error('oai_test_scope','Operation is outside the current test automation allowlist',array('status'=>403,'reason'=>$elig['reason']));
  }
  return $row;
 }

 private static function increment_actions(){
  $cfg=self::raw();$cfg['action_count']=absint($cfg['action_count']??0)+1;self::save($cfg);
 }

 public static function perform($id,$action,$client_id,$user_id){
  $action=sanitize_key((string)$action);
  if(!in_array($action,array('approve','reject','apply','rollback'),true))return new WP_Error('oai_test_action','Unsupported test automation action',array('status'=>400));
  $row=self::authorize($id,$action,$client_id,$user_id);if(is_wp_error($row))return $row;
  self::$request_context=array('id'=>absint($id),'action'=>$action,'authorized'=>true);
  OAI_Audit::log('test_auto_'.$action.'_requested',$row,array('session_id'=>(string)(self::status()['session_id']??''),'client_id_hash'=>hash('sha256',(string)$client_id)));
  try{
   $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.absint($id).'/'.$action);
   $req->set_url_params(array('id'=>absint($id),'action'=>$action));
   $result=OAI_REST::sandbox_action($req);
  }catch(Throwable $e){$result=new WP_Error('oai_test_exception',$e->getMessage(),array('status'=>500));}
  self::$request_context=null;
  self::increment_actions();
  $fresh=OAI_DB::get(absint($id));
  OAI_Audit::log(is_wp_error($result)?'test_auto_'.$action.'_failed':'test_auto_'.$action,$fresh?:$row,array('session_id'=>(string)(self::status()['session_id']??''),'error'=>is_wp_error($result)?$result->get_error_message():null));
  return $result;
 }

 public static function self_test_contract(){
  $cfg=array('allowed_post_ids'=>array(),'allowed_product_ids'=>array(),'file_prefix'=>self::FILE_PREFIX);
  return array(
   'ttl_values'=>self::sanitize_ttl(5)===60 && self::sanitize_ttl(120)===120,
   'test_file_allowed'=>self::file_allowed('child_theme:'.self::FILE_PREFIX.'probe.txt',$cfg),
   'normal_file_blocked'=>!self::file_allowed('child_theme:functions.php',$cfg),
   'test_option_allowed'=>self::option_allowed('oai_test_probe'),
   'normal_option_blocked'=>!self::option_allowed('blogname'),
   'request_context_default_denied'=>!self::request_authorized(1,'apply'),
   'managed_gutenberg_fixture_contract'=>method_exists(__CLASS__,'managed_gutenberg_fixture_id'),
   'max_actions_100000'=>self::MAX_ACTIONS===100000,
   'reusable_gutenberg_fixture_contract'=>method_exists(__CLASS__,'ensure_gutenberg_fixture')&&method_exists(__CLASS__,'find_reusable_gutenberg_fixture')&&method_exists(__CLASS__,'release_gutenberg_fixture'),
   'created_draft_scope_contract'=>method_exists(__CLASS__,'allow_created_post'),
  );
 }
}
