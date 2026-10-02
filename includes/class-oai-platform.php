<?php
/** WP AI Admin 2.0.59 platform: strict batch state machine, pause/checkpoints/lease, batch receipts and proof. */
if (!defined('ABSPATH')) exit;
class OAI_Platform {
 public static function content_navigation_state(){
  $state=(string)get_option('oai_feature_content_navigation','experimental');
  return in_array($state,array('experimental','enabled','disabled'),true)?$state:'disabled';
 }
 public static function content_navigation_allowed(){return self::content_navigation_state()!=='disabled';}
 public static function site_id(){
  $id=(string)get_option('oai_site_id','');
  if($id===''){ $id='oai_'.wp_generate_uuid4(); update_option('oai_site_id',$id,false); }
  return $id;
 }
 public static function installation_id(){
  $id=(string)get_option('oai_installation_id','');
  if($id===''){ $id='inst_'.wp_generate_uuid4(); update_option('oai_installation_id',$id,false); }
  return $id;
 }
 public static function expected_tools(){
  $tools=array(
   'woodmart.status','woodmart.list_settings','woodmart.get_setting','wp.get_site','wp.inspect_html','wp.visual_qa_start','wp.visual_qa_result','system.page_qa_run','wp.get_theme_mods','wp.list_plugins','wp.list_themes','wp.list_post_types','wp.list_taxonomies','wp.list_media','wp.list_menus','wp.list_options','wp.list_users','wp.list_comments','wp.list_widgets','wp.health','wp.diagnostics','wp.list_operations','operation.get','operation.verify','operation.proof','operation.preview_link','wp.list_files','wp.search_files','wp.execution_readiness','elementor.get_structure','elementor.get_widget','elementor.get_schema','elementor.get_globals','elementor.get_container','elementor.get_page_layout','woocommerce.get_product','operation.get_receipt','gutenberg.get_blocks','gutenberg.get_outline','gutenberg.locate_block','builder.catalog','builder.detect','system.capabilities','wp.read_content','wp.read_post','wp.read_meta','wp.read_option','wp.rest_routes','wp.rest_read','file.read','wp.file.outline','woocommerce.search_products','woocommerce.get_variations','woocommerce.search_orders','woocommerce.get_product_full','operation.status','operation.diff','preview.get','verify.operation','proof.operation','elementor.inspect_page','elementor.inspect_widget','system.manifest','batch.plan','batch.preflight','batch.dry_run','batch.create','batch.list','batch.status','batch.resume','batch.pause','batch.retry','batch.cancel','batch.rollback','batch.receipt','batch.proof','file.diff','system.tool_catalog','system.self_test','system.security_status','system.test_automation_status','system.regression_run','system.regression_matrix','system.regression_history','system.coverage_report','system.operations_cleanup','system.security_fuzz',
   'wordpress.execute','gutenberg.patch','content.patch','woocommerce.patch','woocommerce.update_product_sandbox','woocommerce.create_product_sandbox','media.upload_sandbox','media.import_url_sandbox','media.update_alt_sandbox','media.update_title_sandbox','media.set_featured_sandbox','menu.create_sandbox','menu.add_item_sandbox','menu.remove_item_sandbox','menu.move_item_sandbox','menu.assign_location_sandbox','elementor.patch_container_settings','elementor.patch_page_layout','elementor.create_draft','elementor.patch_widget','elementor.patch_fragment','elementor.patch_settings','woodmart.update_setting_sandbox','wp.option_update_sandbox','file.write_sandbox','operation.reject','operation.approve','operation.apply','operation.rollback'
  );
  if(!self::content_navigation_allowed())$tools=array_values(array_diff($tools,array('wp.file.outline')));
  return $tools;
 }
 public static function write_tools(){ return array('system.regression_run','system.regression_matrix','system.operations_cleanup','batch.create','batch.resume','batch.pause','batch.retry','batch.cancel','batch.rollback','gutenberg.patch','content.patch','woocommerce.patch','woocommerce.update_product_sandbox','woocommerce.create_product_sandbox','media.upload_sandbox','media.import_url_sandbox','media.update_alt_sandbox','media.update_title_sandbox','media.set_featured_sandbox','menu.create_sandbox','menu.add_item_sandbox','menu.remove_item_sandbox','menu.move_item_sandbox','menu.assign_location_sandbox','elementor.patch_container_settings','elementor.patch_page_layout','elementor.create_draft','elementor.patch_widget','elementor.patch_fragment','elementor.patch_settings','woodmart.update_setting_sandbox','wp.option_update_sandbox','file.write_sandbox','operation.reject','operation.approve','operation.apply','operation.rollback'); }
 public static function tool_catalog(){
  $all=self::expected_tools(); $writes=self::write_tools(); $items=array();
  foreach($all as $name){
   $write=in_array($name,$writes,true);
   $lifecycle=in_array($name,array('operation.approve','operation.apply','operation.rollback','operation.reject'),true);
   $regression=in_array($name,array('system.regression_run','system.regression_matrix'),true);
   $items[]=array(
    'id'=>$name,
    'mode'=>$regression?'scoped_test_automation_runner':($write?'sandbox_or_human_lifecycle':'read_or_plan'),
    'live_write'=>$write?false:null,
    'human_approval'=>$lifecycle,
    'test_automation_exception'=>($lifecycle||$regression)?true:null,
    'temporary_live_mutations'=>$regression?true:null,
    'sequential'=>$regression?true:null
   );
  }
  return array('schema_version'=>2,'plugin_version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'expected_count'=>count($all),'catalog_sha256'=>hash('sha256',implode("\n",$all)),'items'=>$items,'feature_flags'=>array('content_navigation'=>self::content_navigation_state(),'test_automation'=>(class_exists('OAI_Test_Automation')?OAI_Test_Automation::status()['active']:false)),'legacy_aliases_preserved'=>true);
 }
 public static function release_profile(){
  $ta=class_exists('OAI_Test_Automation')?OAI_Test_Automation::status():array('active'=>false);
  $ai=class_exists('OAI_AI_Lifecycle_Control')?OAI_AI_Lifecycle_Control::status():array('active'=>false);
  return array(
   'channel'=>defined('OAI_RELEASE_CHANNEL')?OAI_RELEASE_CHANNEL:'development',
   'profile'=>defined('OAI_RELEASE_PROFILE')?OAI_RELEASE_PROFILE:'development',
   'candidate'=>defined('OAI_RELEASE_CANDIDATE')?(bool)OAI_RELEASE_CANDIDATE:false,
   'production_defaults'=>array(
    'direct_live_writes'=>false,
    'human_approval_default'=>true,
    'test_automation_default'=>false,
    'ai_lifecycle_default'=>false,
    'manual_controls_preserved'=>true,
    'sandbox_before_apply'=>true,
    'verify_after_apply'=>true,
    'rollback_supported'=>true
   ),
   'effective_state'=>array(
    'test_automation_active'=>!empty($ta['active']),
    'ai_lifecycle_active'=>!empty($ai['active']),
    'mode'=>!empty($ta['active'])?'scoped_test_automation':(!empty($ai['active'])?'explicit_ai_lifecycle_opt_in':'human_approval_only')
   ),
   'upgrade_contract'=>array(
    'site_identity_persistent'=>true,
    'installation_identity_persistent'=>true,
    'oauth_schema_in_place'=>true,
    'oauth_tokens_not_reset_by_plugin_upgrade'=>true,
    'test_automation_build_bound'=>true,
    'ai_lifecycle_build_bound'=>true
   ),
   'control_plane'=>array(
    'required_for_beta'=>false,
    'foundation_available'=>class_exists('OAI_Control_Plane'),
    'remote_entitlements_enforced'=>false
   ),
   'known_beta_limits'=>array(
    'visual_qa_result_requires_browser'=>true,
    'menu_location_assignment_not_auto_regression'=>true,
    'remote_control_plane_not_required_for_local_beta'=>true
   )
  );
 }
 public static function security_status(){
  $site=self::site_id(); $installation=self::installation_id();
  $cp=(string)get_option('oai_control_plane_url','');
  $registered=(bool)get_option('oai_control_plane_registration_id','');
  $server_key=(string)get_option('oai_control_plane_public_key','');
  $test_automation=class_exists('OAI_Test_Automation')?OAI_Test_Automation::status():array('supported'=>false,'active'=>false);
  $ai_lifecycle=class_exists('OAI_AI_Lifecycle_Control')?OAI_AI_Lifecycle_Control::status():array('supported'=>false,'active'=>false,'mode'=>'human_lifecycle_only');
  return array(
   'schema_version'=>1,'site_id'=>$site,'installation_id'=>$installation,
   'installation_fingerprint'=>hash('sha256',$installation.'|'.$site.'|'.home_url('/').'|'.wp_salt('auth')),
   'control_plane'=>array('configured'=>$cp!=='','registered'=>$registered,'server_public_key_configured'=>$server_key!=='','private_server_key_present_in_plugin'=>false,'signed_entitlement_verifier'=>class_exists('OAI_Control_Plane'),'sodium_available'=>function_exists('sodium_crypto_sign_verify_detached'),'remote_entitlements_enforced'=>false),
   'guards'=>array('direct_live_writes'=>false,'local_human_approval_enforced'=>empty($test_automation['active'])&&empty($ai_lifecycle['active']),'human_approval_default'=>true,'ai_lifecycle_active'=>!empty($ai_lifecycle['active']),'ai_lifecycle_opt_in'=>true,'manual_lifecycle_controls_preserved'=>true,'test_automation_active'=>!empty($test_automation['active']),'test_automation_scope_limited'=>true,'sandbox_before_apply'=>true,'verify_after_apply'=>true,'rollback_supported'=>true),
   'ai_lifecycle_control'=>$ai_lifecycle,
   'test_automation'=>$test_automation,
   'planned_protocol'=>array('short_lived_signed_tokens'=>true,'site_and_workspace_binding'=>true,'request_id_nonce_timestamp'=>true,'replay_protection'=>true,'token_rotation'=>true,'site_revoke'=>true,'workspace_freeze'=>true,'global_write_freeze'=>true,'signed_entitlements'=>true),
   'note'=>'Control Plane cryptographic enforcement is a foundation contract until a remote Control Plane is configured. Human lifecycle control is the default. AI Lifecycle Control is an explicit administrator-enabled, client-bound parallel control path that preserves sandbox/conflict/verify/rollback guards; scoped Test Automation remains a separate short-lived regression exception.'
  );
 }
 public static function self_test(){
  global $wpdb;
  $checks=array();
  $add=function($id,$ok,$details=array())use(&$checks){$checks[]=array('id'=>$id,'ok'=>(bool)$ok,'details'=>$details);};
  $runtime_version_ok=defined('OAI_VERSION')&&defined('OAI_BUILD_ID')&&preg_match('/^\d+\.\d+\.\d+$/',(string)OAI_VERSION)===1&&strpos((string)OAI_BUILD_ID,(string)OAI_VERSION.'-')===0;
  $add('runtime.version',$runtime_version_ok,array('version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'contract'=>'semantic_version_and_build_prefix'));
  $add('identity.site_id',self::site_id()!=='',array('site_id'=>self::site_id()));
  $add('identity.installation_id',self::installation_id()!=='',array('installation_id'=>self::installation_id()));
  $add('database.connection',isset($wpdb)&&$wpdb->dbh,array('prefix'=>isset($wpdb)?$wpdb->prefix:null));
  $add('wordpress.rest',function_exists('register_rest_route'));
  $add('oauth.schema_version',class_exists('OAI_MCP_OAuth')&&OAI_MCP_OAuth::SCHEMA_VERSION===1&&(int)get_option('oai_oauth_schema_version',0)===1);
  $release=self::release_profile();
  $add('commercial.release_profile',
   defined('OAI_VERSION')&&version_compare(OAI_VERSION,'2.1.0','>=')
   &&($release['channel']??'')==='commercial_beta'
   &&($release['profile']??'')==='commercial_beta_candidate'
   &&!empty($release['candidate']),
   $release
  );
  $ta_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-test-automation.php');
  $ai_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-ai-lifecycle-control.php');
  $prod=$release['production_defaults']??array();
  $add('commercial.production_default_contract',
   empty($prod['direct_live_writes'])
   &&!empty($prod['human_approval_default'])
   &&empty($prod['test_automation_default'])
   &&empty($prod['ai_lifecycle_default'])
   &&strpos($ta_source,"'build_id'")!==false
   &&strpos($ta_source,'hash_equals')!==false
   &&strpos($ai_source,"'build_id'")!==false
   &&strpos($ai_source,'hash_equals')!==false,
   array('production_defaults'=>$prod,'upgrade_contract'=>$release['upgrade_contract']??array())
  );
  $main_source=(string)@file_get_contents(OAI_DIR.'okean-ai-admin.php');
  $admin_guard_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-admin.php');
  $add('commercial.build_bound_reset_contract',
   method_exists('OAI_AI_Lifecycle_Control','reset_stale_build')
   &&method_exists('OAI_Test_Automation','reset_stale_build')
   &&method_exists('OAI_AI_Lifecycle_Control','reset_for_build_change')
   &&method_exists('OAI_Test_Automation','reset_for_build_change')
   &&strpos($main_source,'OAI_AI_Lifecycle_Control::reset_stale_build')!==false
   &&strpos($main_source,'OAI_Test_Automation::reset_stale_build')!==false
   &&strpos($admin_guard_source,'oai_runtime_build_id')!==false
   &&strpos($admin_guard_source,'OAI_BUILD_CHANGED_THIS_REQUEST')!==false
   &&strpos($ai_source,"'disabled_reason'=>'manual'")!==false
   &&strpos($ai_source,"'owner_user_id'=>0")!==false
   &&strpos($ta_source,"'disabled_reason'=>sanitize_key")!==false
   &&strpos($ta_source,"'allowed_post_ids'=>array()")!==false
   &&strpos($ta_source,"'action_count'=>0")!==false
  );
  $oauth_tables_ok=true;
  if(class_exists('OAI_MCP_OAuth')){
   foreach(array(OAI_MCP_OAuth::clients_table(),OAI_MCP_OAuth::codes_table(),OAI_MCP_OAuth::tokens_table()) as $table){
    $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
    if((string)$found!==$table)$oauth_tables_ok=false;
   }
  }else $oauth_tables_ok=false;
  $add('commercial.oauth_schema_ready',$oauth_tables_ok&&(int)get_option('oai_oauth_schema_version',0)===1,array('schema_version'=>(int)get_option('oai_oauth_schema_version',0)));
  $admin_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-admin.php');
  $generic_test_defaults=class_exists('OAI_Test_Automation')
   &&OAI_Test_Automation::FILE_PREFIX==='wp-ai-admin-tests/'
   &&strpos($admin_source,'30153')===false
   &&strpos($admin_source,'35404')===false
   &&strpos($admin_source,'35127')===false
   &&strpos($admin_source,'okean-core/wp-ai-admin-tests/')===false;
  $add('commercial.generic_test_defaults',$generic_test_defaults,array('file_prefix'=>class_exists('OAI_Test_Automation')?OAI_Test_Automation::FILE_PREFIX:null));
  $admin_ui_ok=class_exists('OAI_Admin')
   &&OAI_Admin::OPERATIONS_PER_PAGE===25
   &&strpos($admin_source,'oai_disconnect_all')!==false
   &&strpos($admin_source,'Подключённые клиенты · отключение')===false
   &&strpos($admin_source,'name="oai_test_automation_posts[]"')!==false
   &&strpos($admin_source,"'post_status'=>array('draft','private')")!==false;
  $add('commercial.admin_ui_contract',$admin_ui_ok,array('operations_per_page'=>class_exists('OAI_Admin')?OAI_Admin::OPERATIONS_PER_PAGE:null));
  $add('control_plane.entitlement_verifier',class_exists('OAI_Control_Plane')&&OAI_Control_Plane::CONTRACT_VERSION===1&&method_exists('OAI_Control_Plane','verify'),array('sodium_available'=>function_exists('sodium_crypto_sign_verify_detached'),'remote_enforcement'=>false));
  $caps=OAI_Capabilities::available();
  $execution=OAI_WP_CLI_Execution::readiness();
  $add('security.direct_live_writes_disabled',empty($caps['security']['direct_live_writes']) && empty($execution['direct_write_execution']));
  $guard_ok=true;
  foreach(array('approve','reject','apply','rollback') as $action){
   $probe=new WP_REST_Request('POST','/okean-ai/v1/sandbox/0/'.$action);
   $result=OAI_REST::sandbox_action($probe);
   if(!is_wp_error($result)||$result->get_error_code()!=='oai_human_approval_required')$guard_ok=false;
  }
  $add('security.human_approval_guard',$guard_ok);
  $ai_lifecycle_status=class_exists('OAI_AI_Lifecycle_Control')?OAI_AI_Lifecycle_Control::status():array();
  $add('security.ai_lifecycle_status_contract',
   !empty($ai_lifecycle_status['supported'])
   && isset($ai_lifecycle_status['active'],$ai_lifecycle_status['mode'],$ai_lifecycle_status['rules'])
   && !empty($ai_lifecycle_status['rules']['administrator_enable_required'])
   && !empty($ai_lifecycle_status['rules']['administrator_mcp_identity_required'])
   && !empty($ai_lifecycle_status['rules']['manual_controls_preserved'])
   && !empty($ai_lifecycle_status['rules']['conflict_guards_preserved'])
   && !empty($ai_lifecycle_status['rules']['verify_guards_preserved'])
   && !empty($ai_lifecycle_status['rules']['rollback_guards_preserved']),
   $ai_lifecycle_status
  );
  $ta_contract=class_exists('OAI_Test_Automation')?OAI_Test_Automation::self_test_contract():array();
  $add('test_automation.contract',!empty($ta_contract)&&!in_array(false,$ta_contract,true),$ta_contract);
  $p0_tools=array('woocommerce.get_product_full','woocommerce.create_product_sandbox','media.upload_sandbox','media.import_url_sandbox','media.update_alt_sandbox','media.update_title_sandbox','media.set_featured_sandbox','menu.create_sandbox','menu.add_item_sandbox','menu.remove_item_sandbox','menu.move_item_sandbox','menu.assign_location_sandbox','elementor.get_container','elementor.get_page_layout','elementor.patch_container_settings','elementor.patch_page_layout');
  $expected_now=self::expected_tools();
  $add('p0.tool_contract',count(array_diff($p0_tools,$expected_now))===0,array('required'=>$p0_tools,'missing'=>array_values(array_diff($p0_tools,$expected_now))));
  $mutation_policy_tools=array(
   'gutenberg.patch','content.patch','woocommerce.patch','woocommerce.update_product_sandbox','woocommerce.create_product_sandbox',
   'media.upload_sandbox','media.update_alt_sandbox','media.update_title_sandbox','media.set_featured_sandbox',
   'menu.create_sandbox','menu.add_item_sandbox','menu.remove_item_sandbox','menu.move_item_sandbox','menu.assign_location_sandbox',
   'elementor.patch_container_settings','elementor.patch_page_layout','elementor.create_draft','elementor.patch_widget','elementor.patch_fragment','elementor.patch_settings',
   'woodmart.update_setting_sandbox','wp.option_update_sandbox','file.write_sandbox','operation.reject','operation.approve','operation.apply','operation.rollback'
  );
  $write_now=self::write_tools();
  $missing_write_policy=array_values(array_diff($mutation_policy_tools,$write_now));
  $add('commercial.mutation_policy_complete',empty($missing_write_policy),array('required'=>$mutation_policy_tools,'missing'=>$missing_write_policy));
  $add('p0.runtime_contract',class_exists('OAI_P0')&&method_exists('OAI_P0','media_upload')&&method_exists('OAI_P0','media_import_url')&&method_exists('OAI_P0','menu_create')&&method_exists('OAI_P0','op_for_created_product')&&method_exists('OAI_P0','elementor_page_layout')&&method_exists('OAI_WooCommerce','get_full')&&method_exists('OAI_WooCommerce','create_draft')&&method_exists('OAI_Elementor','container')&&method_exists('OAI_Elementor','page_layout'));
  $media_desc=OAI_Lifecycle::descriptor('media_upload','123',null,array('before_exists'=>false,'attachment_id'=>123));
  $menu_desc=OAI_Lifecycle::descriptor('menu_create','456',null,array('before_exists'=>false,'menu_id'=>456));
  $product_desc=OAI_Lifecycle::descriptor('woocommerce_product_create','789',null,array('before_exists'=>false,'product_id'=>789));
  $add('p0.rollback_descriptor_contract',
   ($media_desc['action']??'')==='delete_created_attachment'
   &&($menu_desc['action']??'')==='delete_created_menu'
   &&($product_desc['action']??'')==='delete_created_product',
   array('media'=>$media_desc,'menu'=>$menu_desc,'product'=>$product_desc)
  );

  $ta_status=class_exists('OAI_Test_Automation')?OAI_Test_Automation::status():array();
  $add('test_automation.scoped_policy',isset($ta_status['rules'])&&!empty($ta_status['rules']['admin_enable_required'])&&!empty($ta_status['rules']['same_mcp_client_required'])&&!empty($ta_status['rules']['only_session_tagged_operations']));
  $add('regression.runner_available',class_exists('OAI_Regression_Runner')&&method_exists('OAI_Regression_Runner','run'));
  $add('regression.elementor_create_patch_many_contract',class_exists('OAI_Regression_Runner')&&method_exists('OAI_Regression_Runner','run')&&strpos((string)@file_get_contents(OAI_DIR.'includes/class-oai-regression-runner.php'),'elementor_create_patch_many')!==false);
  $runner_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-regression-runner.php');
  $matrix_source=(string)@file_get_contents(OAI_DIR.'includes/class-oai-regression-matrix.php');
  $add('regression.p0_suite_contract',
   strpos($runner_source,'woo_create_scenario')!==false
   &&strpos($runner_source,'media_suite_scenario')!==false
   &&strpos($runner_source,'menu_suite_scenario')!==false
   &&strpos($runner_source,'include_p0')!==false
  );
  $add('regression.matrix_p0_contract',
   strpos($matrix_source,"'include_p0'=>true")!==false
   &&strpos($matrix_source,"'menu.assign_location_sandbox','SKIP'")!==false
   &&strpos($matrix_source,"'wp.visual_qa_start'")!==false
   &&strpos($matrix_source,"'system.operations_cleanup'")!==false
  );
  $draft_schema_ok=false;
  if(class_exists('OAI_MCP')&&method_exists('OAI_MCP','tool_definitions')){
   foreach((array)OAI_MCP::tool_definitions() as $tool){
    if(($tool['name']??'')==='wp.inspect_html'){
     $props=$tool['inputSchema']['properties']??array();
     $draft_schema_ok=isset($props['allow_draft'])&&(($props['allow_draft']['type']??'')==='boolean');
     break;
    }
   }
  }
  $add('html_inspector.draft_render_contract',$draft_schema_ok&&class_exists('OAI_HTML_Inspector'));
  $visual_contract=class_exists('OAI_Visual_QA')?OAI_Visual_QA::self_test_contract():array();
  $add('visual_qa.available',class_exists('OAI_Visual_QA')&&method_exists('OAI_Visual_QA','start')&&method_exists('OAI_Visual_QA','result'));
  $add('visual_qa.contract',!empty($visual_contract)&&!in_array(false,$visual_contract,true),$visual_contract);
  $page_qa_contract=class_exists('OAI_Page_QA')?OAI_Page_QA::self_test_contract():array();
  $add('page_qa.contract',!empty($page_qa_contract)&&!in_array(false,$page_qa_contract,true),$page_qa_contract);
  $cleanup_contract=class_exists('OAI_Operations_Cleanup')?OAI_Operations_Cleanup::self_test_contract():array();
  $add('operations_cleanup.contract',!empty($cleanup_contract)&&!in_array(false,$cleanup_contract,true),$cleanup_contract);
  $add('woodmart.sandbox_contract',class_exists('OAI_Woodmart')&&in_array('woodmart.update_setting_sandbox',self::write_tools(),true)&&isset(OAI_Operation_Schema::catalog()['woodmart_theme_setting']),array('base_layer_only'=>true,'native_mcp'=>'1.0.1'));
  $add('options.sandbox_contract',
   in_array('wp.option_update_sandbox',self::expected_tools(),true)
   && in_array('wp.option_update_sandbox',self::write_tools(),true)
   && class_exists('OAI_Lifecycle'),
   array('tool'=>'wp.option_update_sandbox','lifecycle'=>'sandbox_approval_apply_verify_rollback','direct_live_write'=>false)
  );
  $add('regression.matrix_available',class_exists('OAI_Regression_Matrix')&&method_exists('OAI_Regression_Matrix','run'));
  $add('regression.history_available',class_exists('OAI_Regression_History')&&method_exists('OAI_Regression_History','history'));
  $hist_contract=class_exists('OAI_Regression_History')?OAI_Regression_History::self_test_contract():array();$add('regression.history_contract',!empty($hist_contract)&&!in_array(false,$hist_contract,true),$hist_contract);
  $add('security.fuzz_available',class_exists('OAI_Security_Fuzz')&&method_exists('OAI_Security_Fuzz','run'));
  $fuzz_contract=class_exists('OAI_Security_Fuzz')?OAI_Security_Fuzz::self_test_contract():array();$add('security.fuzz_contract',!empty($fuzz_contract)&&!in_array(false,$fuzz_contract,true),$fuzz_contract);
  $rest_contract=class_exists('OAI_REST_Resolver')?OAI_REST_Resolver::self_test_contract():array();$add('rest.resolver_contract',!empty($rest_contract)&&!in_array(false,$rest_contract,true),$rest_contract);
  $add('woocommerce.deep_read_contract',method_exists('OAI_WooCommerce','variations')&&method_exists('OAI_WooCommerce','orders'));
  $batch_probe=self::batch_preflight(array(array('id'=>'a','tool'=>'wp.get_site','arguments'=>array()),array('id'=>'b','tool'=>'wp.read_content','depends_on'=>array('a'),'arguments'=>array())));
  $batch_cycle=self::batch_preflight(array(array('id'=>'a','tool'=>'wp.get_site','depends_on'=>array('b')),array('id'=>'b','tool'=>'wp.get_site','depends_on'=>array('a'))));
  $add('batch.preflight_contract',is_array($batch_probe)&&!empty($batch_probe['valid'])&&($batch_probe['execution_order']??array())===array('a','b')&&is_wp_error($batch_cycle)&&$batch_cycle->get_error_code()==='oai_batch_cycle');
  $batch_contract=class_exists('OAI_Batch_Executor')?OAI_Batch_Executor::self_test_contract():array();
  $add('batch.executor_available',class_exists('OAI_Batch_Executor')&&method_exists('OAI_Batch_Executor','create')&&method_exists('OAI_Batch_Executor','resume')&&method_exists('OAI_Batch_Executor','rollback'));
  $add('batch.executor_contract',!empty($batch_contract)&&!in_array(false,$batch_contract,true),$batch_contract);
  $dry_probe=class_exists('OAI_Batch_Executor')?OAI_Batch_Executor::dry_run(array(array('id'=>'a','tool'=>'wp.get_site'),array('id'=>'b','tool'=>'wp.read_post','arguments'=>array('post_id'=>1),'depends_on'=>array('a')))):array();
  $add('batch.dry_run_contract',is_array($dry_probe)&&!empty($dry_probe['valid'])&&($dry_probe['reads']??0)===2&&($dry_probe['writes']??0)===0&&($dry_probe['max_dependency_depth']??0)===2);
  $add('batch.pause_contract',class_exists('OAI_Batch_Executor')&&method_exists('OAI_Batch_Executor','pause')&&!empty($batch_contract['manual_pause_available']));
  $add('batch.checkpoint_contract',!empty($batch_contract['lease_bounded'])&&!empty($batch_contract['checkpoint_events_bounded'])&&!empty($batch_contract['db_mutex_available']));
  $add('batch.receipt_proof_contract',!empty($batch_contract['receipt_and_proof_available']));
  $add('batch.strict_retry_contract',!empty($batch_contract['strict_retry_unresolved_guard']));
  $add('security.rest_users_blocked',!empty($rest_contract['wordpress_users_blocked']));
  $ops_probe_req=new WP_REST_Request('GET','/okean-ai/v1/sandbox');$ops_probe_req->set_param('page',2);$ops_probe_req->set_param('per_page',1);$ops_probe=OAI_REST::sandbox_list($ops_probe_req);
  $add('operations.mcp_pagination_contract',is_array($ops_probe)&&($ops_probe['page']??0)===2&&($ops_probe['per_page']??0)===1&&isset($ops_probe['total'],$ops_probe['total_pages'],$ops_probe['items']));
  $add('admin.operation_pagination_contract',class_exists('OAI_Admin')&&defined('OAI_VERSION')&&OAI_Admin::ACTION_TABLE_LIMIT===50&&OAI_Admin::OPERATIONS_PER_PAGE===25&&method_exists('OAI_DB','page')&&method_exists('OAI_DB','count_all'));
  $add('test_automation.managed_gutenberg_fixture_contract',class_exists('OAI_Test_Automation')&&method_exists('OAI_Test_Automation','managed_gutenberg_fixture_id'));
  $absence_probe=class_exists('OAI_Verify')&&method_exists('OAI_Verify','created_page_absence')?OAI_Verify::created_page_absence(2147483647):array();
  $add('verification.elementor_create_rollback_absence',is_array($absence_probe)&&!empty($absence_probe['verified'])&&($absence_probe['verification_source']??'')==='dual_absence',$absence_probe);
  $fixture_content=class_exists('OAI_Test_Automation')&&method_exists('OAI_Test_Automation','gutenberg_fixture_content')?OAI_Test_Automation::gutenberg_fixture_content():'';
  $add('test_automation.gutenberg_fixture_newlines',is_string($fixture_content)&&strpos($fixture_content,"\n\n<!-- wp:paragraph")!==false&&strpos($fixture_content,'\\n\\n')===false);
  $write_probe=OAI_Security::resolve_path_for_write('child_theme',OAI_Test_Automation::FILE_PREFIX.'nested/probe.txt');
  $add('security.test_file_write_path',!is_wp_error($write_probe)&&strpos(str_replace('\\','/',(string)$write_probe),'/'.trim(OAI_Test_Automation::FILE_PREFIX,'/').'/nested/probe.txt')!==false);
  $woo_norm_ok=false;
  if(function_exists('wc_stock_amount')&&function_exists('wc_format_decimal')&&method_exists('OAI_WooCommerce','runtime_matches_changes')){
   $before_probe=array('id'=>1,'sku'=>'T','name'=>'T','status'=>'publish','regular_price'=>'','sale_price'=>'','price'=>'','stock'=>0,'stock_status'=>'outofstock','manage_stock'=>true);
   $actual_probe=$before_probe;$actual_probe['stock']=1;$actual_probe['stock_status']='instock';
   $woo_norm=OAI_WooCommerce::runtime_matches_changes($actual_probe,array('stock_quantity'=>1),$before_probe);
   $bad_probe=$actual_probe;$bad_probe['stock_status']='onbackorder';
   $woo_bad=OAI_WooCommerce::runtime_matches_changes($bad_probe,array('stock_quantity'=>1),$before_probe);
   $woo_norm_ok=($woo_norm===true)&&is_wp_error($woo_bad)&&$woo_bad->get_error_code()==='oai_woo_runtime';
  }
  $add('woocommerce.runtime_normalization_guard',$woo_norm_ok);
  $redacted=OAI_Read::option_value('oai_bearer_token','synthetic-secret-probe');
  $add('security.option_redaction',!empty($redacted['redacted'])&&($redacted['value']??'')==='[REDACTED]');
  $hash=OAI_Read::option_value('oai_agent_key_hash','synthetic-hash-probe');
  $license=OAI_Read::option_value('oai_license',array('signature'=>'synthetic-signature-probe'));
  $nested=OAI_Read::option_value('synthetic_public',array('label'=>'public','client_secret'=>'synthetic-secret-probe'));
  $add('security.option_nested_redaction',!empty($hash['redacted'])&&$hash['value']==='[REDACTED]'&&!empty($license['redacted'])&&$license['value']==='[REDACTED]'&&!empty($nested['redacted'])&&($nested['value']['client_secret']??null)==='[REDACTED]'&&($nested['value']['label']??null)==='public');

  $health_probe=OAI_Read::health();
  $health_path=(string)($health_probe['uploads']['path']??'');
  $add('security.health_path_redaction',$health_path==='wp-content/uploads'&&strpos($health_path,'/')!==0&&strpos($health_path,'\\')!==0,array('uploads_path'=>$health_path));

  $diag_probe=OAI_Diagnostics::run();
  $diag_fs=is_array($diag_probe)?($diag_probe['checks']['filesystem']??array()):array();
  $diag_uploads=(string)($diag_fs['uploads_path']??'');
  $diag_theme=(string)($diag_fs['stylesheet_directory']??'');
  $add('security.diagnostics_path_redaction',
   $diag_uploads==='wp-content/uploads'
   && strpos($diag_theme,'wp-content/themes/')===0
   && strpos($diag_uploads,'/')!==0
   && strpos($diag_theme,'/')!==0
   && strpos($diag_uploads,'\\')!==0
   && strpos($diag_theme,'\\')!==0,
   array('uploads_path'=>$diag_uploads,'stylesheet_directory'=>$diag_theme)
  );

  $protected_req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
  $protected_req->set_param('type','option_update');$protected_req->set_param('target','siteurl');$protected_req->set_param('value','https://example.invalid');
  $protected_result=OAI_REST::sandbox_create($protected_req);
  $secret_req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
  $secret_req->set_param('type','option_update');$secret_req->set_param('target','synthetic_api_key');$secret_req->set_param('value','secret');
  $secret_result=OAI_REST::sandbox_create($secret_req);
  $add('security.protected_option_guard',
   is_wp_error($protected_result)&&$protected_result->get_error_code()==='oai_option_protected'
   &&is_wp_error($secret_result)&&$secret_result->get_error_code()==='oai_option_sensitive'
  );

  $users_req=new WP_REST_Request('GET','/okean-ai/v1/read/users');$users_req->set_param('limit',1);$users_req->set_param('page',1);
  $users_probe=OAI_Read::users($users_req);
  $users_items=is_array($users_probe)?($users_probe['items']??array()):array();
  $add('privacy.user_list_projection',!is_wp_error($users_probe)&&(!isset($users_items[0])||!array_key_exists('email',$users_items[0])));

  $comments_req=new WP_REST_Request('GET','/okean-ai/v1/read/comments');$comments_req->set_param('limit',1);$comments_req->set_param('page',1);
  $comments_probe=OAI_Read::comments($comments_req);
  $comments_items=is_array($comments_probe)?($comments_probe['items']??array()):array();
  $add('privacy.comment_list_projection',!is_wp_error($comments_probe)&&(!isset($comments_items[0])||!array_key_exists('email',$comments_items[0])));
  $separator=OAI_Command_Registry::resolve('core version; plugin deactivate woocommerce');
  $extra=OAI_Command_Registry::resolve('core version unexpected');
  $add('security.command_shape',is_wp_error($separator)&&$separator->get_error_code()==='oai_cli_metacharacter'&&is_wp_error($extra)&&$extra->get_error_code()==='oai_cli_args');
  $unsafe_query=OAI_Command_Registry::resolve('db query SELECT option_value FROM '.$wpdb->options);
  $unsafe_result=is_wp_error($unsafe_query)?$unsafe_query:OAI_WP_CLI_Execution::execute($unsafe_query);
  $add('security.db_query_scope',is_wp_error($unsafe_result)&&$unsafe_result->get_error_code()==='oai_db_scope');
  $all=self::expected_tools();
  $fragment='<h1>Карта</h1><p>Маршрут</p>';
  $patched=OAI_Elementor::replace_html_fragment($fragment,'Карта','Картa',hash('sha256',$fragment));
  $stale=OAI_Elementor::replace_html_fragment($fragment,'Карта','Картa',str_repeat('0',64));
  $ambiguous=OAI_Elementor::replace_html_fragment('AA','A','B',hash('sha256','AA'));
  $add('elementor.fragment_patch_contract',$patched==='<h1>Картa</h1><p>Маршрут</p>'&&is_wp_error($stale)&&is_wp_error($ambiguous));
  $add('elementor.single_source_contract',method_exists('OAI_Elementor','activate_single_source')&&method_exists('OAI_REST','elementor_patch_fragment'));
  $add('catalog.expected_tools',count($all)===count(array_unique($all))&&count($all)>=100,array('expected_count'=>count($all),'unique_count'=>count(array_unique($all)),'catalog_sha256'=>hash('sha256',implode("\n",$all))));
  $defs=class_exists('OAI_MCP')&&method_exists('OAI_MCP','tool_definitions')?OAI_MCP::tool_definitions():array();$def_names=array();foreach((array)$defs as $d)if(is_array($d)&&!empty($d['name']))$def_names[]=(string)$d['name'];
  $add('catalog.exported_tools',count($def_names)===count($all)&&!array_diff($all,$def_names),array('exported_count'=>count($def_names),'missing'=>array_values(array_diff($all,$def_names))));
  $add('navigation.gutenberg_outline_tool',in_array('gutenberg.get_outline',$def_names,true));
  $add('navigation.feature_state',in_array(self::content_navigation_state(),array('experimental','enabled','disabled'),true),array('state'=>self::content_navigation_state()));
  $add('woocommerce.runtime',class_exists('WooCommerce'),array('active'=>class_exists('WooCommerce')));
  $add('elementor.runtime',defined('ELEMENTOR_VERSION')||did_action('elementor/loaded'),array('version'=>defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:null));
  $sample=get_posts(array('post_type'=>'page','post_status'=>'any','numberposts'=>1,'fields'=>'ids'));
  if($sample){
   $cmd=OAI_Command_Registry::resolve('post get '.absint($sample[0]));
   $post_read=is_wp_error($cmd)?$cmd:OAI_WP_CLI_Execution::execute($cmd);
   $cron_cmd=OAI_Command_Registry::resolve('cron event list');
   $cron_read=is_wp_error($cron_cmd)?$cron_cmd:OAI_WP_CLI_Execution::execute($cron_cmd);
   $add('security.command_read_projection',is_array($post_read)&&isset($post_read['meta_keys'])&&!isset($post_read['meta'])&&is_array($cron_read)&&(!isset($cron_read[0])||(!isset($cron_read[0]['args'])&&isset($cron_read[0]['args_count']))));
   $probe=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.absint($sample[0]));
   $probe->set_url_params(array('id'=>absint($sample[0])));
   foreach(array('include_content','include_elementor','include_css') as $field)$probe->set_param($field,false);
   $brief=OAI_REST::content_detail($probe);
   $item=is_array($brief)?($brief['item']??array()):array();
   $add('navigation.post_projection',is_array($brief)&&!array_key_exists('content',$item)&&!array_key_exists('inline_css',$item)&&!array_key_exists('elementor_data',$item));
  }
  if(self::content_navigation_allowed()){
   $outline=OAI_Files::outline('child_theme','style.css',5);
   $file_ok=!is_wp_error($outline)&&isset($outline['sha256'],$outline['total_lines']);
   $add('navigation.file_outline',$file_ok,array('total_lines'=>$file_ok?$outline['total_lines']:null));
   $range=$file_ok&&$outline['total_lines']>0?OAI_Files::read_range('child_theme','style.css',1,min(2,$outline['total_lines']),1000,$outline['sha256']):null;
   $add('navigation.file_range',is_array($range)&&$file_ok&&($range['sha256']??'')===$outline['sha256']);
   if(class_exists('WooCommerce')){
    $catalog=OAI_WooCommerce::search('','',1,1,'id,sku');
    $add('navigation.woo_page',is_array($catalog)&&isset($catalog['total_pages'],$catalog['items'])&&(!isset($catalog['items'][0])||array_keys($catalog['items'][0])===array('id','sku')));
    if(!empty($catalog['items'][0]['id'])){
     $negative=OAI_WooCommerce::patch((int)$catalog['items'][0]['id'],array('regular_price'=>'-1'));
     $old_pending=OAI_WooCommerce::validate_snapshot(array('regular_price'=>'-1'));
     $add('security.woo_negative_price',is_wp_error($negative)&&$negative->get_error_code()==='oai_woo_price'&&is_wp_error($old_pending)&&$old_pending->get_error_code()==='oai_woo_price');
    }
   }
  }
  $passed=count(array_filter($checks,function($x){return !empty($x['ok']);}));
  return array('schema_version'=>1,'safe_mode'=>'read_only_no_mutations','passed'=>$passed,'failed'=>count($checks)-$passed,'total'=>count($checks),'checks'=>$checks,'next_phase'=>'Compare system.tool_catalog expected IDs with client-exported MCP tools; create reversible sandbox fixtures only after read-only checks pass.');
 }
 public static function manifest(){
  $catalog=self::tool_catalog();
  return array(
   'schema_version'=>2,'plugin_name'=>'WP AI Admin','plugin_version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'site_id'=>self::site_id(),'installation_id'=>self::installation_id(),
   'release'=>self::release_profile(),
   'control_plane'=>array('supported'=>true,'configured'=>(bool)get_option('oai_control_plane_url',''),'mode'=>'remote_identity_entitlements_catalog_quota','signed_entitlement_verifier'=>true,'remote_entitlements_enforced'=>false,'local_approval_enforced'=>true,'test_automation_exception_active'=>(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()),'direct_live_writes'=>false,'security_contract_version'=>1),
   'multi_site'=>array('foundation'=>true,'site_identity'=>true,'installation_identity'=>true,'workspace_aware'=>true,'central_fanout_planned'=>true),
   'batch'=>array('foundation'=>true,'mode'=>'resumable_sandbox_children','atomicity'=>'per_child_operation','rollback'=>'reverse_child_reconciliation','max_items'=>100,'retained_jobs'=>50,'human_approval_default'=>true,'manual_pause'=>true,'optimistic_versioning'=>true,'lease_seconds'=>60,'db_mutex'=>'mysql_named_lock','checkpoint_events'=>100,'batch_receipt'=>true,'batch_proof'=>true,'strict_retry_unresolved_guard'=>true),
   'feature_flags'=>array('content_navigation'=>self::content_navigation_state()),
   'next_stage'=>array('universal_rest_safe_get'=>true,'wordpress_users_blocked_in_universal_rest'=>true,'woocommerce_variations'=>true,'woocommerce_orders_summary'=>true,'batch_dependency_preflight'=>true,'resumable_batch_executor'=>true,'batch_state_machine_hardened'=>true,'batch_pause'=>true,'batch_checkpoint_versioning'=>true,'batch_receipt_proof'=>true,'operation_ui_pagination'=>true,'operation_mcp_pagination'=>true),
   'test_manifest'=>array('supported'=>true,'self_test_tool'=>'system.self_test','tool_catalog_tool'=>'system.tool_catalog','test_automation_status_tool'=>'system.test_automation_status','regression_runner_tool'=>'system.regression_run','regression_matrix_tool'=>'system.regression_matrix','regression_history_tool'=>'system.regression_history','coverage_report_tool'=>'system.coverage_report','security_fuzz_tool'=>'system.security_fuzz','gutenberg_outline_tool'=>'gutenberg.get_outline','scoped_mcp_lifecycle_automation'=>true,'sequential_regression_runner'=>true,'managed_gutenberg_fixture'=>true,'reusable_gutenberg_fixture'=>true,'test_automation_max_actions'=>100000,'lifecycle'=>array('exposed','read','sandbox','approval','apply','verify','rollback','rollback_verify')),
   'tool_catalog'=>array('expected_count'=>$catalog['expected_count'],'catalog_sha256'=>$catalog['catalog_sha256'],'expected_ids'=>self::expected_tools()),
   'security'=>array('status_tool'=>'system.security_status','installation_identity'=>true,'server_private_key_embedded'=>false,'short_lived_token_contract'=>true,'replay_protection_contract'=>true,'revocation_contract'=>true),
   'compatibility'=>array('legacy_tool_aliases'=>true,'primary_api'=>'2.0.59-elementor-single-source-fragment')
  );
 }
 public static function batch_plan($items){
  if(!is_array($items)||!$items)return new WP_Error('oai_batch','items must be a non-empty array',array('status'=>400));
  if(count($items)>100)return new WP_Error('oai_batch_limit','Batch plan is limited to 100 items',array('status'=>422));
  $out=array();
  foreach(array_values($items) as $i=>$item){
   if(!is_array($item))return new WP_Error('oai_batch_item','Each batch item must be an object',array('status'=>400,'index'=>$i));
   $tool=sanitize_text_field((string)($item['tool']??''));
   if($tool==='')return new WP_Error('oai_batch_tool','Each batch item requires tool',array('status'=>400,'index'=>$i));
   if(!in_array($tool,self::expected_tools(),true))return new WP_Error('oai_batch_unknown_tool','Batch item references an unknown tool',array('status'=>422,'index'=>$i,'tool'=>$tool));
   $write=in_array($tool,self::write_tools(),true);
   $out[]=array('index'=>$i,'tool'=>$tool,'arguments'=>is_array($item['arguments']??null)?$item['arguments']:array(),'mode'=>in_array($tool,array('system.regression_run','system.regression_matrix'),true)?'test_automation_runner':($write?'sandbox_child':'read'),'requires_human_approval'=>in_array($tool,array('operation.approve','operation.reject','operation.apply','operation.rollback'),true));
  }
  return array('site_id'=>self::site_id(),'installation_id'=>self::installation_id(),'count'=>count($out),'items'=>$out,'direct_live_writes'=>false,'execution'=>'not_executed_plan_only');
 }
 public static function batch_preflight($items){
  if(!is_array($items)||!$items)return new WP_Error('oai_batch','items must be a non-empty array',array('status'=>400));
  if(count($items)>100)return new WP_Error('oai_batch_limit','Batch preflight is limited to 100 items',array('status'=>422));
  $nodes=array();$index=0;
  foreach(array_values($items) as $item){
   if(!is_array($item))return new WP_Error('oai_batch_item','Each batch item must be an object',array('status'=>400,'index'=>$index));
   $id=sanitize_key((string)($item['id']??('step_'.($index+1))));if($id==='')$id='step_'.($index+1);if(isset($nodes[$id]))return new WP_Error('oai_batch_duplicate','Duplicate batch step id',array('status'=>422,'id'=>$id));
   $tool=sanitize_text_field((string)($item['tool']??''));if($tool===''||!in_array($tool,self::expected_tools(),true))return new WP_Error('oai_batch_unknown_tool','Batch item references an unknown tool',array('status'=>422,'index'=>$index,'tool'=>$tool));
   $deps=array_values(array_unique(array_filter(array_map('sanitize_key',(array)($item['depends_on']??array())))));
   $nodes[$id]=array('id'=>$id,'index'=>$index,'tool'=>$tool,'arguments'=>is_array($item['arguments']??null)?$item['arguments']:array(),'depends_on'=>$deps,'write'=>in_array($tool,self::write_tools(),true));$index++;
  }
  foreach($nodes as $id=>$node)foreach($node['depends_on'] as $dep)if(!isset($nodes[$dep]))return new WP_Error('oai_batch_dependency','Batch dependency does not exist',array('status'=>422,'id'=>$id,'depends_on'=>$dep));
  $incoming=array();$outgoing=array();foreach($nodes as $id=>$node){$incoming[$id]=count($node['depends_on']);foreach($node['depends_on'] as $dep)$outgoing[$dep][]=$id;}
  $queue=array();foreach($incoming as $id=>$n)if($n===0)$queue[]=$id;usort($queue,function($a,$b)use($nodes){return $nodes[$a]['index']<=>$nodes[$b]['index'];});$order=array();
  while($queue){$id=array_shift($queue);$order[]=$id;foreach((array)($outgoing[$id]??array()) as $next){$incoming[$next]--;if($incoming[$next]===0){$queue[]=$next;usort($queue,function($a,$b)use($nodes){return $nodes[$a]['index']<=>$nodes[$b]['index'];});}}}
  if(count($order)!==count($nodes))return new WP_Error('oai_batch_cycle','Batch dependency graph contains a cycle',array('status'=>422));
  $steps=array();foreach($order as $id){$n=$nodes[$id];$steps[]=array('id'=>$id,'tool'=>$n['tool'],'depends_on'=>$n['depends_on'],'mode'=>$n['write']?'sandbox_or_lifecycle':'read','requires_human_approval'=>in_array($n['tool'],array('operation.approve','operation.reject','operation.apply','operation.rollback'),true));}
  return array('site_id'=>self::site_id(),'count'=>count($steps),'valid'=>true,'execution_order'=>$order,'steps'=>$steps,'writes'=>count(array_filter($nodes,function($n){return !empty($n['write']);})),'execution'=>'not_executed_preflight_only','policy'=>'dependency_validation_no_live_execution');
 }

}
