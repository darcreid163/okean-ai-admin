<?php
/**
 * WP AI Admin extended regression matrix.
 * Produces a bounded PASS/FAIL/SKIP/BLOCKED view for the exported MCP surface.
 * Mutating coverage delegates to OAI_Regression_Runner so every live fixture is
 * sequential, verified and rolled back before the next scenario.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Regression_Matrix {
 private static function ok($value){return !is_wp_error($value);}
 private static function set(&$matrix,$tool,$status,$evidence=''){
  if(!isset($matrix[$tool]))return;
  $matrix[$tool]=array('tool'=>$tool,'status'=>$status,'evidence'=>is_scalar($evidence)?(string)$evidence:$evidence);
 }
 private static function req($path=''){$r=new WP_REST_Request('GET',$path?:'/okean-ai/v1');return $r;}
 private static function ids($result){
  if(is_wp_error($result)||!is_array($result))return array();$items=$result['items']??array();$out=array();foreach((array)$items as $x)if(is_array($x)&&isset($x['id']))$out[]=(string)$x['id'];return $out;
 }
 private static function distinct_pages($a,$b){$x=self::ids($a);$y=self::ids($b);return $x&&$y&&count(array_intersect($x,$y))===0;}
 private static function scenario($runs,$name){
  foreach((array)$runs as $run)foreach((array)($run['scenarios']??array()) as $s)if(($s['name']??'')===$name)return $s;
  return null;
 }
 private static function first_operation($runs){
  foreach((array)$runs as $run)foreach((array)($run['scenarios']??array()) as $s)if(!empty($s['operation_id']))return absint($s['operation_id']);
  return 0;
 }
 public static function run($args,$client_id,$user_id){
  $auth=OAI_Test_Automation::runner_authorized($client_id,$user_id);if(is_wp_error($auth))return $auth;
  $args=is_array($args)?$args:array();
  $content_id=absint($args['content_post_id']??0);$elementor_id=absint($args['elementor_post_id']??0);$widget=sanitize_text_field((string)($args['elementor_widget_id']??''));$container_id=sanitize_text_field((string)($args['elementor_container_id']??''));$product_id=absint($args['product_id']??0);
  $iterations=min(3,max(1,absint($args['iterations']??1)));
  if(!$content_id||!$elementor_id||$widget===''||!$product_id)return new WP_Error('oai_matrix_args','content_post_id, elementor_post_id, elementor_widget_id and product_id are required',array('status'=>400));
  $started=microtime(true);$run_id='mx_'.gmdate('Ymd_His').'_'.substr(wp_generate_uuid4(),0,8);
  $expected=OAI_Platform::expected_tools();$matrix=array();foreach($expected as $tool)$matrix[$tool]=array('tool'=>$tool,'status'=>'SKIP','evidence'=>'not directly exercised in this run');

  // Contract/export surface.
  $defs=method_exists('OAI_MCP','tool_definitions')?OAI_MCP::tool_definitions():array();$def_names=array();foreach((array)$defs as $d)if(is_array($d)&&!empty($d['name']))$def_names[]=(string)$d['name'];
  $missing=array_values(array_diff($expected,$def_names));
  foreach($expected as $tool)if(in_array($tool,$def_names,true))$matrix[$tool]['evidence']='exported MCP contract present; runtime exercise pending';
  foreach($missing as $tool)self::set($matrix,$tool,'FAIL','expected tool missing from MCP export');

  // Core reads.
  self::set($matrix,'wp.get_site',self::ok(OAI_REST::site())?'PASS':'FAIL','site snapshot');
  self::set($matrix,'wp.list_plugins',self::ok(OAI_REST::plugins())?'PASS':'FAIL','plugin list');
  self::set($matrix,'wp.list_themes',self::ok(OAI_REST::themes())?'PASS':'FAIL','theme list');
  self::set($matrix,'wp.list_post_types',self::ok(OAI_REST::post_types())?'PASS':'FAIL','post types');
  $tax=self::req('/okean-ai/v1/read/taxonomies');self::set($matrix,'wp.list_taxonomies',self::ok(OAI_REST::taxonomies($tax))?'PASS':'FAIL','taxonomy list');
  self::set($matrix,'wp.list_menus',self::ok(OAI_REST::menus())?'PASS':'FAIL','menus');
  self::set($matrix,'wp.list_widgets',self::ok(OAI_REST::widgets())?'PASS':'FAIL','widgets');
  self::set($matrix,'wp.health',self::ok(OAI_REST::health())?'PASS':'FAIL','health');
  self::set($matrix,'wp.diagnostics',self::ok(OAI_REST::diagnostics())?'PASS':'FAIL','diagnostics');
  $ops1=self::req('/okean-ai/v1/sandbox');$ops1->set_param('page',1);$ops1->set_param('per_page',1);$opsp1=OAI_REST::sandbox_list($ops1);$ops2=self::req('/okean-ai/v1/sandbox');$ops2->set_param('page',2);$ops2->set_param('per_page',1);$opsp2=OAI_REST::sandbox_list($ops2);$op_page_ok=self::ok($opsp1)&&self::ok($opsp2)&&isset($opsp1['total'],$opsp1['total_pages']);if(($opsp1['total']??0)>1)$op_page_ok=$op_page_ok&&self::distinct_pages($opsp1,$opsp2);self::set($matrix,'wp.list_operations',$op_page_ok?'PASS':'FAIL',array('page1'=>self::ids($opsp1),'page2'=>self::ids($opsp2),'total'=>$opsp1['total']??null));

  // Pagination checks.
  $page=function($kind,$page){$r=self::req('/okean-ai/v1/read/'.$kind);$r->set_param('limit',3);$r->set_param('page',$page);return $kind==='media'?OAI_REST::media($r):($kind==='users'?OAI_REST::users($r):OAI_REST::comments($r));};
  foreach(array('media'=>'wp.list_media','users'=>'wp.list_users','comments'=>'wp.list_comments') as $kind=>$tool){$a=$page($kind,1);$b=$page($kind,2);$ok=self::ok($a)&&self::ok($b)&&self::distinct_pages($a,$b);self::set($matrix,$tool,$ok?'PASS':'FAIL',array('page1'=>self::ids($a),'page2'=>self::ids($b)));}

  // Options and redaction.
  $or=self::req('/okean-ai/v1/read/options');$or->set_param('keys','blogname,oai_agent_key_hash,oai_license');$opts=OAI_REST::options($or);$safe=!is_wp_error($opts)&&!empty($opts['items']['oai_agent_key_hash']['redacted'])&&!empty($opts['items']['oai_license']['redacted']);
  self::set($matrix,'wp.list_options',$safe?'PASS':'FAIL','sensitive option redaction');self::set($matrix,'wp.read_option',$safe?'PASS':'FAIL','same redaction policy');

  // Content projections and meta-key-only read.
  $lr=self::req('/okean-ai/v1/read/content');$lr->set_param('type','page');$lr->set_param('limit',2);$lr->set_param('page',1);$list=OAI_REST::content($lr);self::set($matrix,'wp.read_content',self::ok($list)?'PASS':'FAIL','bounded page list');
  $dr=self::req('/okean-ai/v1/read/content/'.$content_id);$dr->set_url_params(array('id'=>$content_id));$dr->set_param('include_content',false);$dr->set_param('include_elementor',false);$dr->set_param('include_css',false);$detail=OAI_REST::content_detail($dr);$item=is_array($detail)?($detail['item']??array()):array();$proj=self::ok($detail)&&!array_key_exists('content',$item)&&!array_key_exists('elementor_data',$item);self::set($matrix,'wp.read_post',$proj?'PASS':'FAIL','projection omits large fields');
  $mr=self::req('/okean-ai/v1/read/content/'.$content_id.'/meta');$mr->set_url_params(array('id'=>$content_id));$meta=OAI_REST::content_meta($mr);$meta_ok=self::ok($meta)&&isset($meta['all_meta_keys'])&&!isset($meta['all_meta_values']);self::set($matrix,'wp.read_meta',$meta_ok?'PASS':'FAIL','meta keys only');

  // HTML inspector: outline and selector safety.
  $hr=self::req('/okean-ai/v1/read/html');$hr->set_param('url',home_url('/'));$hr->set_param('mode','outline');$hr->set_param('max_results',20);$html=OAI_HTML_Inspector::inspect($hr);self::set($matrix,'wp.inspect_html',self::ok($html)?'PASS':'FAIL','same-site outline');
  $tm=self::req('/okean-ai/v1/read/theme-mods');$mods=OAI_Theme_Mods::get($tm);self::set($matrix,'wp.get_theme_mods',self::ok($mods)?'PASS':'FAIL','theme mods redaction path');
  $vqa=OAI_Visual_QA::start($elementor_id);$vqa_ok=self::ok($vqa)&&!empty($vqa['probe_id'])&&!empty($vqa['url'])&&!empty($vqa['requires_browser_open']);self::set($matrix,'wp.visual_qa_start',$vqa_ok?'PASS':'FAIL','signed non-mutating visual probe created');
  self::set($matrix,'wp.visual_qa_result','SKIP','requires external browser execution of the signed probe');
  $pqa=OAI_Page_QA::run($elementor_id);$pqa_ok=self::ok($pqa)&&($pqa['status']??'')==='pending_browser'&&!empty($pqa['static'])&&!empty($pqa['probe_id']);self::set($matrix,'system.page_qa_run',$pqa_ok?'PASS':'FAIL','static page QA passed phase-1 and produced browser probe');

  // Universal same-site REST resolver: discovery + bounded redacted GET.
  $rest_routes=OAI_REST_Resolver::discover('wp/v2','types',20);self::set($matrix,'wp.rest_routes',self::ok($rest_routes)&&!empty($rest_routes['items'])?'PASS':'FAIL','registered GET route discovery');
  $rest_read=OAI_REST_Resolver::read('/wp/v2/types',array(),'summary',20);self::set($matrix,'wp.rest_read',self::ok($rest_read)&&($rest_read['status']??0)===200?'PASS':'FAIL','same-site GET with bounded redaction');

  // Files: list/search/outline/range/diff without writes.
  $fl=self::req('/okean-ai/v1/files/list');$fl->set_param('scope','child_theme');$fl->set_param('path','');$files=OAI_REST::files_list($fl);self::set($matrix,'wp.list_files',self::ok($files)?'PASS':'FAIL','child theme scope');
  $fs=self::req('/okean-ai/v1/files/search');$fs->set_param('scope','child_theme');$fs->set_param('q','okean');$fs->set_param('limit',5);$search=OAI_REST::files_search($fs);self::set($matrix,'wp.search_files',self::ok($search)?'PASS':'FAIL','bounded search');
  $outline=OAI_Files::outline('child_theme','style.css',20);$outline_ok=self::ok($outline)&&isset($outline['sha256']);if(isset($matrix['wp.file.outline']))self::set($matrix,'wp.file.outline',$outline_ok?'PASS':'FAIL','style.css outline');
  $range=$outline_ok?OAI_Files::read_range('child_theme','style.css',1,min(3,(int)$outline['total_lines']),2000,$outline['sha256']):new WP_Error('oai_matrix','outline unavailable');self::set($matrix,'file.read',self::ok($range)?'PASS':'FAIL','bounded range + hash');
  $cur=$outline_ok?OAI_Files::read('child_theme','style.css'):new WP_Error('oai_matrix','outline unavailable');$diff=!is_wp_error($cur)?OAI_Operations::diff((string)($cur['content']??''),(string)($range['content']??'')):$cur;self::set($matrix,'file.diff',self::ok($diff)?'PASS':'FAIL','diff-only');

  // Elementor reads and aliases.
  $tree_req=self::req('/okean-ai/v1/read/content/'.$elementor_id.'/elementor-tree');$tree_req->set_url_params(array('id'=>$elementor_id));$tree_req->set_param('limit',50);$tree_req->set_param('include_html',false);$tree=OAI_REST::elementor_tree($tree_req);self::set($matrix,'elementor.get_structure',self::ok($tree)?'PASS':'FAIL','bounded tree');self::set($matrix,'elementor.inspect_page',self::ok($tree)?'PASS':'FAIL','alias contract');
  $node_req=self::req('/okean-ai/v1/read/content/'.$elementor_id.'/elementor/'.$widget);$node_req->set_url_params(array('id'=>$elementor_id,'widget_id'=>$widget));$node_req->set_param('view','outline');$node=OAI_REST::elementor_node($node_req);self::set($matrix,'elementor.get_widget',self::ok($node)?'PASS':'FAIL','widget outline');self::set($matrix,'elementor.inspect_widget',self::ok($node)?'PASS':'FAIL','alias contract');
  self::set($matrix,'elementor.get_schema',self::ok(OAI_Elementor::widget_schema('html'))?'PASS':'FAIL','HTML widget schema');self::set($matrix,'elementor.get_globals',self::ok(OAI_Elementor::globals())?'PASS':'FAIL','Elementor globals');
  if($container_id!==''){$container=OAI_Elementor::container($elementor_id,$container_id);self::set($matrix,'elementor.get_container',self::ok($container)?'PASS':'FAIL','explicit regression container snapshot');}else self::set($matrix,'elementor.get_container','SKIP','elementor_container_id not supplied');
  $layout=OAI_Elementor::page_layout($elementor_id);self::set($matrix,'elementor.get_page_layout',self::ok($layout)?'PASS':'FAIL','Elementor page template snapshot');
  self::set($matrix,'builder.catalog',self::ok(OAI_Adapter_Catalog::catalog())?'PASS':'FAIL','adapter catalog');self::set($matrix,'builder.detect',self::ok(OAI_Adapter_Catalog::detect($elementor_id))?'PASS':'FAIL','builder detection');

  // Gutenberg compact/full/locate on managed fixture.
  $gbid=OAI_Test_Automation::managed_gutenberg_fixture_id();
  if($gbid){$gr=self::req('/okean-ai/v1/read/gutenberg/'.$gbid);$gr->set_url_params(array('id'=>$gbid));$gr->set_param('view','outline');$gbo=OAI_REST::gutenberg_resolved($gr);$full=OAI_Gutenberg_Resolver::resolve_post($gbid);$compact=self::ok($gbo)&&self::ok($full)&&strlen(wp_json_encode($gbo))<strlen(wp_json_encode($full));self::set($matrix,'gutenberg.get_blocks',$compact?'PASS':'FAIL','outline smaller than full');self::set($matrix,'gutenberg.get_outline',$compact?'PASS':'FAIL','explicit compact outline');$loc=self::req('/okean-ai/v1/read/gutenberg/'.$gbid.'/locate');$loc->set_url_params(array('id'=>$gbid));$loc->set_param('path','1');$loc->set_param('view','outline');$located=OAI_REST::gutenberg_locate($loc);self::set($matrix,'gutenberg.locate_block',self::ok($located)?'PASS':'FAIL','managed fixture locate');}
  else{foreach(array('gutenberg.get_blocks','gutenberg.get_outline','gutenberg.locate_block') as $t)if(isset($matrix[$t]))self::set($matrix,$t,'BLOCKED','managed Gutenberg fixture unavailable');}

  // Woo reads.
  $woo=OAI_WooCommerce::get($product_id);self::set($matrix,'woocommerce.get_product',self::ok($woo)?'PASS':'FAIL','allowlisted fixture product');$woo_full=OAI_WooCommerce::get_full($product_id);self::set($matrix,'woocommerce.get_product_full',self::ok($woo_full)?'PASS':'FAIL','full product snapshot');
  $catalog=OAI_WooCommerce::search('', '',1,3,'id,sku,stock_status');self::set($matrix,'woocommerce.search_products',self::ok($catalog)?'PASS':'FAIL','bounded catalog projection');
  $vars=OAI_WooCommerce::variations($product_id,1,5);self::set($matrix,'woocommerce.get_variations',self::ok($vars)&&isset($vars['items'],$vars['total_pages'])?'PASS':'FAIL','paginated variation summary');
  $orders=OAI_WooCommerce::orders(1,3,'any');$orders_ok=self::ok($orders)&&isset($orders['items'],$orders['privacy'])&&($orders['privacy']??'')==='summary_no_customer_pii';self::set($matrix,'woocommerce.search_orders',$orders_ok?'PASS':'FAIL','paginated order summary without customer PII');

  // Command allowlist and plan-only writes.
  $core=OAI_Execution_Dispatcher::dispatch('core version','read');$blocked=OAI_Command_Registry::resolve('core version; plugin deactivate woocommerce');$plan=OAI_Execution_Dispatcher::dispatch('cache purge','plan');$cmd_ok=self::ok($core)&&is_wp_error($blocked)&&self::ok($plan);self::set($matrix,'wordpress.execute',$cmd_ok?'PASS':'FAIL','read + plan-only + metacharacter guard');
  $ready=OAI_REST::execution_readiness();$caps=OAI_Capabilities::available();$adapters=OAI_Adapter_Catalog::catalog();self::set($matrix,'wp.execution_readiness',self::ok($ready)?'PASS':'FAIL','resolver readiness');self::set($matrix,'system.capabilities',(is_array($caps)&&is_array($adapters)&&self::ok($ready))?'PASS':'FAIL','capabilities + adapters + execution available');

  // Platform/system contracts.
  self::set($matrix,'system.manifest',self::ok(OAI_Platform::manifest())?'PASS':'FAIL','manifest');self::set($matrix,'system.tool_catalog',empty($missing)?'PASS':'FAIL',array('missing'=>$missing,'count'=>count($def_names)));$self=OAI_Platform::self_test();self::set($matrix,'system.self_test',empty($self['failed'])?'PASS':'FAIL',array('passed'=>$self['passed']??0,'failed'=>$self['failed']??0));self::set($matrix,'system.security_status',self::ok(OAI_Platform::security_status())?'PASS':'FAIL','security contract');self::set($matrix,'system.test_automation_status',!empty(OAI_Test_Automation::status()['active'])?'PASS':'FAIL','active scoped session');
  $cleanup_probe=OAI_Operations_Cleanup::run(array('dry_run'=>true,'include_applied'=>false));self::set($matrix,'system.operations_cleanup',self::ok($cleanup_probe)&&($cleanup_probe['mode']??'')==='dry_run'&&absint($cleanup_probe['deleted']??-1)===0?'PASS':'FAIL','dry-run cleanup only; audit ledger unchanged');
  $bp=OAI_Platform::batch_plan(array(array('tool'=>'wp.get_site','arguments'=>array()),array('tool'=>'content.patch','arguments'=>array('post_id'=>$content_id))));self::set($matrix,'batch.plan',self::ok($bp)&&($bp['execution']??'')==='not_executed_plan_only'?'PASS':'FAIL','plan-only');
  $batch_fixture=array(array('id'=>'site','tool'=>'wp.get_site'),array('id'=>'content','tool'=>'wp.read_content','depends_on'=>array('site')));
  $bpf=OAI_Platform::batch_preflight($batch_fixture);self::set($matrix,'batch.preflight',self::ok($bpf)&&!empty($bpf['valid'])&&($bpf['execution_order']??array())===array('site','content')?'PASS':'FAIL','dependency graph preflight only');
  $dry=OAI_Batch_Executor::dry_run($batch_fixture);self::set($matrix,'batch.dry_run',self::ok($dry)&&!empty($dry['valid'])&&($dry['execution']??'')==='not_executed_dry_run'?'PASS':'FAIL','executor support/depth/payload dry-run');

  // Resumable batch executor: pause/checkpoint/resume + receipt/proof + guarded retry/cancel.
  $batch_happy=OAI_Batch_Executor::create(array(
   array('id'=>'site','tool'=>'wp.get_site','arguments'=>array()),
   array('id'=>'post','tool'=>'wp.read_post','arguments'=>array('post_id'=>$content_id),'depends_on'=>array('site'))
  ),'regression '.$run_id,$client_id,$user_id);
  $batch_create_ok=self::ok($batch_happy)&&!empty($batch_happy['id'])&&absint($batch_happy['version']??0)>=1;self::set($matrix,'batch.create',$batch_create_ok?'PASS':'FAIL','durable versioned job created after preflight');
  $batch_id=$batch_create_ok?(string)$batch_happy['id']:'';
  $batch_status=$batch_id!==''?OAI_Batch_Executor::get($batch_id,false,$client_id,$user_id):new WP_Error('oai_matrix','batch create failed');self::set($matrix,'batch.status',self::ok($batch_status)&&isset($batch_status['version'],$batch_status['checkpoint_seq'])?'PASS':'FAIL','versioned checkpoint state readable');
  $batch_pause=$batch_id!==''?OAI_Batch_Executor::pause($batch_id,'regression pause',$client_id,$user_id,absint($batch_status['version']??0)):new WP_Error('oai_matrix','batch create failed');$pause_ok=self::ok($batch_pause)&&(($batch_pause['status']??'')==='paused')&&!empty($batch_pause['manual_pause']);self::set($matrix,'batch.pause',$pause_ok?'PASS':'FAIL','manual pause preserves pending steps');
  $batch_resume=$batch_id!==''?OAI_Batch_Executor::resume($batch_id,10,$client_id,$user_id,absint($batch_pause['version']??0)):new WP_Error('oai_matrix','batch create failed');$batch_resume_ok=self::ok($batch_resume)&&(($batch_resume['job']['status']??'')==='completed')&&empty($batch_resume['job']['lease']['active']);self::set($matrix,'batch.resume',$batch_resume_ok?'PASS':'FAIL','leased/versioned read-only dependency job completed');
  $batch_list=OAI_Batch_Executor::list_jobs(10,$client_id,$user_id);$batch_list_ok=self::ok($batch_list)&&$batch_id!==''&&in_array($batch_id,array_map(function($x){return (string)($x['id']??'');},(array)($batch_list['items']??array())),true);self::set($matrix,'batch.list',$batch_list_ok?'PASS':'FAIL','client-owned batch list with version');
  $receipt=$batch_id!==''?OAI_Batch_Executor::receipt($batch_id,$client_id,$user_id):new WP_Error('oai_matrix','batch create failed');self::set($matrix,'batch.receipt',self::ok($receipt)&&!empty($receipt['payload_hash'])?'PASS':'FAIL','batch-level bounded receipt');
  $batch_proof=$batch_id!==''?OAI_Batch_Executor::proof($batch_id,$client_id,$user_id):new WP_Error('oai_matrix','batch create failed');self::set($matrix,'batch.proof',self::ok($batch_proof)&&!empty($batch_proof['hash'])&&($batch_proof['payload_hash']??'')===($receipt['payload_hash']??'')?'PASS':'FAIL','site/install/build-bound SHA-256 proof');
  $batch_rb=$batch_id!==''?OAI_Batch_Executor::rollback($batch_id,$client_id,$user_id,absint($batch_resume['job']['version']??0)):new WP_Error('oai_matrix','batch create failed');self::set($matrix,'batch.rollback',self::ok($batch_rb)&&(($batch_rb['job']['status']??'')==='rolled_back')?'PASS':'FAIL','read-only rollback reconciliation');
  $batch_fail=OAI_Batch_Executor::create(array(array('id'=>'guard','tool'=>'wp.rest_read','arguments'=>array('route'=>'/wp/v2/users'))),'guard '.$run_id,$client_id,$user_id);
  $fail_id=self::ok($batch_fail)?(string)($batch_fail['id']??''):'';$fail_run=$fail_id!==''?OAI_Batch_Executor::resume($fail_id,2,$client_id,$user_id):new WP_Error('oai_matrix','guard batch create failed');
  $retry=$fail_id!==''?OAI_Batch_Executor::retry($fail_id,'guard',$client_id,$user_id):new WP_Error('oai_matrix','guard batch create failed');$retry_ok=self::ok($retry)&&(($retry['steps']['guard']['state']??'')==='pending');
  $cancel=$fail_id!==''?OAI_Batch_Executor::cancel($fail_id,'regression cleanup',$client_id,$user_id):new WP_Error('oai_matrix','guard batch create failed');self::set($matrix,'batch.cancel',self::ok($cancel)&&(($cancel['job']['status']??'')==='cancelled')?'PASS':'FAIL','pending retried job cancelled without live writes');
  // Reproduce the 2.0.53 bug: retrying a cancelled downstream step while another child is unresolved must now be blocked.
  $strict_job=OAI_Batch_Executor::create(array(array('id'=>'write','tool'=>'content.patch','arguments'=>array('post_id'=>$content_id,'field'=>'post_excerpt','old_content'=>'','new_content'=>'WP AI Admin strict retry '.$run_id)),array('id'=>'downstream','tool'=>'wp.read_post','arguments'=>array('post_id'=>$content_id),'depends_on'=>array('write'))),'strict retry '.$run_id,$client_id,$user_id);
  $strict_id=self::ok($strict_job)?(string)($strict_job['id']??''):'';$strict_run=$strict_id!==''?OAI_Batch_Executor::resume($strict_id,5,$client_id,$user_id):new WP_Error('oai_matrix','strict batch create failed');$strict_op=self::ok($strict_run)?absint($strict_run['job']['steps']['write']['operation_id']??0):0;$strict_cancel=$strict_id!==''?OAI_Batch_Executor::cancel($strict_id,'strict retry guard',$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');$strict_retry=$strict_id!==''?OAI_Batch_Executor::retry($strict_id,'downstream',$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');$strict_blocked=is_wp_error($strict_retry)&&$strict_retry->get_error_code()==='oai_batch_retry_unresolved';
  $strict_reject=$strict_op?OAI_Test_Automation::perform($strict_op,'reject',$client_id,$user_id):new WP_Error('oai_matrix','strict child missing');
  $strict_status=$strict_id!==''?OAI_Batch_Executor::get($strict_id,false,$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');
  $strict_receipt=$strict_id!==''?OAI_Batch_Executor::receipt($strict_id,$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');
  $strict_reconciled=self::ok($strict_reject)&&self::ok($strict_status)&&($strict_status['steps']['write']['state']??'')==='failed'&&absint($strict_status['summary']['waiting_approval']??-1)===0&&self::ok($strict_receipt)&&absint($strict_receipt['summary']['waiting_approval']??-1)===0&&($strict_receipt['steps'][0]['state']??'')==='failed'&&($strict_receipt['steps'][0]['operation_status']??'')==='rejected';
  $terminal_retry=$strict_id!==''?OAI_Batch_Executor::retry($strict_id,'downstream',$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');
  $terminal_cancel=$strict_id!==''?OAI_Batch_Executor::cancel($strict_id,'regression cleanup',$client_id,$user_id):new WP_Error('oai_matrix','strict batch missing');
  $terminal_clean=self::ok($terminal_retry)&&($terminal_retry['steps']['write']['state']??'')==='failed'&&self::ok($terminal_cancel)&&($terminal_cancel['job']['status']??'')==='cancelled'&&absint($terminal_cancel['job']['summary']['waiting_approval']??-1)===0;
  self::set($matrix,'batch.retry',($retry_ok&&$strict_blocked&&$strict_reconciled&&$terminal_clean)?'PASS':'FAIL',array('terminal_retry'=>$retry_ok,'unresolved_child_guard'=>$strict_blocked,'terminal_child_reconciled'=>$strict_reconciled,'retry_then_cancel_consistent'=>$terminal_clean));

  // Sequential mutation suite, including managed Gutenberg fixture.
  $runs=array();for($i=0;$i<$iterations;$i++){$r=OAI_Regression_Runner::run(array('content_post_id'=>$content_id,'elementor_post_id'=>$elementor_id,'elementor_widget_id'=>$widget,'elementor_container_id'=>$container_id,'product_id'=>$product_id,'gutenberg_post_id'=>$gbid,'include_file'=>true,'include_reject'=>true,'include_elementor_settings'=>true,'include_elementor_create'=>true,'include_elementor_create_patch_many'=>true,'include_p0'=>true),$client_id,$user_id);if(is_wp_error($r))return $r;$runs[]=$r;if(($r['status']??'')!=='PASS'||empty($r['baseline_restored']))break;}
  $mutation_ok=$runs && !array_filter($runs,function($r){return ($r['status']??'')!=='PASS'||empty($r['baseline_restored']);});self::set($matrix,'system.regression_run',$mutation_ok?'PASS':'FAIL',array('iterations'=>count($runs)));

  $scenario_map=array('content'=>'content.patch','woocommerce'=>'woocommerce.update_product_sandbox','woocommerce_create'=>'woocommerce.create_product_sandbox','elementor'=>'elementor.patch_widget','elementor_fragment'=>'elementor.patch_fragment','elementor_settings'=>'elementor.patch_settings','elementor_container'=>'elementor.patch_container_settings','elementor_page_layout'=>'elementor.patch_page_layout','elementor_create'=>'elementor.create_draft','option_update'=>'wp.option_update_sandbox','file'=>'file.write_sandbox','gutenberg'=>'gutenberg.patch','reject_guard'=>'operation.reject');
  foreach($scenario_map as $name=>$tool){$sc=self::scenario($runs,$name);if(!$sc){self::set($matrix,$tool,'SKIP','scenario not produced');continue;}self::set($matrix,$tool,($sc['status']??'')==='PASS'?'PASS':(($sc['status']??'')==='SKIP'?'SKIP':'FAIL'),array('operation_id'=>$sc['operation_id']??null,'stages'=>$sc['stages']??array()));}
  $media_sc=self::scenario($runs,'media_suite');
  foreach(array('media_upload'=>'media.upload_sandbox','media_alt'=>'media.update_alt_sandbox','media_title'=>'media.update_title_sandbox','media_featured'=>'media.set_featured_sandbox') as $stage=>$tool){$ok=$media_sc&&($media_sc['status']??'')==='PASS'&&($media_sc['stages'][$stage]??'')==='PASS';self::set($matrix,$tool,$ok?'PASS':(($media_sc&&($media_sc['status']??'')==='SKIP')?'SKIP':'FAIL'),$media_sc?array('stages'=>$media_sc['stages']??array(),'operations'=>$media_sc['operation_ids']??array()):'media suite not produced');}
  $menu_sc=self::scenario($runs,'menu_suite');
  $menu_map=array('menu_create'=>'menu.create_sandbox','menu_add_item_1'=>'menu.add_item_sandbox','menu_move_item'=>'menu.move_item_sandbox','menu_remove_item'=>'menu.remove_item_sandbox');
  foreach($menu_map as $stage=>$tool){$ok=$menu_sc&&($menu_sc['status']??'')==='PASS'&&($menu_sc['stages'][$stage]??'')==='PASS';self::set($matrix,$tool,$ok?'PASS':(($menu_sc&&($menu_sc['status']??'')==='SKIP')?'SKIP':'FAIL'),$menu_sc?array('stages'=>$menu_sc['stages']??array(),'operations'=>$menu_sc['operation_ids']??array()):'menu suite not produced');}
  self::set($matrix,'menu.assign_location_sandbox','SKIP','safety skip: registered production menu locations are not reassigned by automated regression');
  $woo_sc=self::scenario($runs,'woocommerce');if($woo_sc)self::set($matrix,'woocommerce.patch',($woo_sc['status']??'')==='PASS'?'PASS':'FAIL','legacy alias uses same validated sandbox endpoint');
  $opid=self::first_operation($runs);
  if($opid){$og=OAI_DB::get($opid);self::set($matrix,'operation.get',$og?'PASS':'FAIL','regression operation');self::set($matrix,'operation.status',$og?'PASS':'FAIL','alias reads same ledger row');$vf=OAI_Verify::operation($opid);self::set($matrix,'operation.verify',self::ok($vf)&&!empty($vf['verified'])?'PASS':'FAIL','terminal rollback verify');self::set($matrix,'verify.operation',self::ok($vf)&&!empty($vf['verified'])?'PASS':'FAIL','legacy verify alias');$proof=OAI_Proof::make($opid);self::set($matrix,'operation.proof',is_array($proof)&&!empty($proof['hash'])?'PASS':'FAIL','proof hash');self::set($matrix,'proof.operation',is_array($proof)&&!empty($proof['hash'])?'PASS':'FAIL','legacy proof alias');$receipt=OAI_Receipts::build($opid);self::set($matrix,'operation.get_receipt',is_array($receipt)?'PASS':'FAIL','receipt');$diff=$og?json_decode((string)$og['diff_data'],true):null;self::set($matrix,'operation.diff',is_array($diff)?'PASS':'FAIL','stored diff');}
  $content_sc=self::scenario($runs,'content');$preview_ok=$content_sc&&(($content_sc['stages']['preview']??'')==='PASS');self::set($matrix,'operation.preview_link',$preview_ok?'PASS':'FAIL','pending preview generated by lifecycle');self::set($matrix,'preview.get',$preview_ok?'PASS':'FAIL','preview alias contract');
  $lifecycle_ok=true;foreach(array('content','woocommerce','elementor','file','gutenberg') as $n){$sc=self::scenario($runs,$n);if($sc&&($sc['status']??'')==='PASS'){foreach(array('approve','apply','rollback') as $stage)if(($sc['stages'][$stage]??'')!=='PASS')$lifecycle_ok=false;}}
  foreach(array('operation.approve','operation.apply','operation.rollback') as $t)self::set($matrix,$t,$lifecycle_ok?'PASS':'FAIL','sequential scoped lifecycle');

  // Mark the matrix tool itself only after the report reached this point.
  self::set($matrix,'system.regression_matrix','PASS','extended matrix completed');
  if(isset($matrix['system.regression_history'])){ $hh=OAI_Regression_History::history(5,'all'); self::set($matrix,'system.regression_history',is_array($hh)?'PASS':'FAIL','bounded persisted history'); }
  if(isset($matrix['system.coverage_report'])){ $cc=OAI_Regression_History::coverage(20); self::set($matrix,'system.coverage_report',is_array($cc)&&isset($cc['tools'])?'PASS':'FAIL','per-tool stage coverage'); }
  if(isset($matrix['system.security_fuzz'])){ $fz=OAI_Security_Fuzz::run(1); self::set($matrix,'system.security_fuzz',($fz['status']??'')==='PASS'?'PASS':'FAIL',array('summary'=>$fz['summary']??array())); }
  $counts=array('PASS'=>0,'FAIL'=>0,'SKIP'=>0,'BLOCKED'=>0);foreach($matrix as $row){$st=$row['status']??'SKIP';if(!isset($counts[$st]))$counts[$st]=0;$counts[$st]++;}
  $finished=gmdate('c');$baseline=true;foreach($runs as $r)if(empty($r['baseline_restored']))$baseline=false;
  $result=array('schema_version'=>1,'run_id'=>$run_id,'plugin_version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'started_at'=>gmdate('c',(int)$started),'finished_at'=>$finished,'duration_ms'=>(int)round((microtime(true)-$started)*1000),'iterations'=>count($runs),'baseline_restored'=>$baseline,'summary'=>$counts,'tool_count'=>count($matrix),'tools'=>array_values($matrix),'mutation_runs'=>array_map(function($r){return array('run_id'=>$r['run_id']??null,'status'=>$r['status']??null,'summary'=>$r['summary']??array(),'baseline_restored'=>$r['baseline_restored']??false,'duration_ms'=>$r['duration_ms']??null);},$runs));
  $result['status']=$counts['FAIL']===0&&$baseline?'PASS':'FAIL';
  update_option('oai_test_automation_last_matrix',array('run_id'=>$run_id,'status'=>$result['status'],'summary'=>$counts,'baseline_restored'=>$baseline,'finished_at'=>$finished,'build_id'=>OAI_BUILD_ID),false);
  if(class_exists('OAI_Regression_History'))OAI_Regression_History::record('regression_matrix',$result);
  return $result;
 }
}
