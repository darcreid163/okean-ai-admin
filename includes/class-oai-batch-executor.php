<?php
/**
 * Durable, resumable batch executor foundation.
 *
 * It never performs direct live writes. Read steps may execute immediately.
 * Write steps only create normal sandbox operations and then pause until the
 * corresponding operation is handled through the existing approval/apply
 * lifecycle. Rollback is automatic only inside the already-scoped Test
 * Automation session; otherwise wp-admin human confirmation remains required.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Batch_Executor {
 const OPTION='oai_batch_jobs_v1';
 const MAX_JOBS=50;
 const MAX_ITEMS=100;
 const MAX_ATTEMPTS=5;
 const MAX_JOB_BYTES=524288;
 const RESULT_MAX_CHARS=2000;
 const LEASE_SECONDS=60;
 const EVENT_LIMIT=100;
 const MUTEX_NAME='oai_batch_jobs_v1_mutex';

 private static function now(){return gmdate('c');}
 private static function client_hash($client_id){return hash('sha256',(string)$client_id);}
 private static function mutex_name($wpdb){
  return self::MUTEX_NAME.'_'.substr(hash('sha256',(string)($wpdb->dbname??'').'|'.(string)($wpdb->prefix??'')),0,16);
 }
 private static function with_mutex($callback){
  global $wpdb;
  if(!isset($wpdb)||!is_object($wpdb)||!method_exists($wpdb,'get_var')||!method_exists($wpdb,'prepare'))
   return new WP_Error('oai_batch_mutex_unavailable','Database lock is unavailable',array('status'=>503));
  $name=self::mutex_name($wpdb);
  $result=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));
  if((string)$result!=='1')
   return new WP_Error((string)$result==='0'?'oai_batch_busy':'oai_batch_mutex_unavailable','Batch mutation is already running or database lock is unavailable',array('status'=>(string)$result==='0'?409:503));
  try{return $callback();}
  finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
 }
 private static function all_jobs(){
  $jobs=get_option(self::OPTION,array());
  return is_array($jobs)?$jobs:array();
 }
 private static function save_jobs($jobs){
  if(!is_array($jobs))$jobs=array();
  uasort($jobs,function($a,$b){return strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??''));});
  if(count($jobs)>self::MAX_JOBS)$jobs=array_slice($jobs,0,self::MAX_JOBS,true);
  update_option(self::OPTION,$jobs,false);
 }
 private static function save_job($job){
  $jobs=self::all_jobs();$job['updated_at']=self::now();$jobs[(string)$job['id']]=$job;self::save_jobs($jobs);return $job;
 }
 private static function checkpoint(&$job,$event,$details=array()){
  $job['version']=max(1,absint($job['version']??1))+1;
  $job['checkpoint_seq']=absint($job['checkpoint_seq']??0)+1;
  $entry=array(
   'seq'=>$job['checkpoint_seq'],'event'=>sanitize_key((string)$event),'at'=>self::now(),
   'version'=>$job['version'],'status'=>(string)($job['status']??''),'details'=>is_array($details)?$details:array()
  );
  if(!isset($job['events'])||!is_array($job['events']))$job['events']=array();
  $job['events'][]=$entry;if(count($job['events'])>self::EVENT_LIMIT)$job['events']=array_slice($job['events'],-self::EVENT_LIMIT);
  $job['last_checkpoint']=$entry;
 }
 private static function load_job($id){$jobs=self::all_jobs();return $jobs[(string)$id]??null;}
 private static function assert_version($job,$expected_version){
  $expected=absint($expected_version);if(!$expected)return true;
  $actual=absint($job['version']??1);
  return $expected===$actual?true:new WP_Error('oai_batch_version_conflict','Batch job version changed; refresh batch.status before mutating it',array('status'=>409,'expected_version'=>$expected,'actual_version'=>$actual));
 }
 private static function lease_active($job){
  $lease=is_array($job['lease']??null)?$job['lease']:array();
  return !empty($lease['token_hash'])&&absint($lease['expires_at']??0)>time();
 }
 private static function acquire_lease(&$job){
  if(self::lease_active($job))return new WP_Error('oai_batch_lease','Batch job is already leased by another resume execution',array('status'=>409,'lease_expires_at'=>absint($job['lease']['expires_at']??0)));
  $token=wp_generate_uuid4();$job['lease']=array('token_hash'=>hash('sha256',$token),'acquired_at'=>self::now(),'expires_at'=>time()+self::LEASE_SECONDS);
  self::checkpoint($job,'lease_acquired',array('expires_at'=>$job['lease']['expires_at']));self::save_job($job);return $token;
 }
 private static function release_lease(&$job,$token,$event='lease_released'){
  $hash=hash('sha256',(string)$token);$current=(string)($job['lease']['token_hash']??'');
  if($current!==''&&hash_equals($current,$hash))$job['lease']=array('token_hash'=>'','acquired_at'=>null,'expires_at'=>0);
  self::checkpoint($job,$event,array());
 }
 private static function auth_job($id,$client_id,$user_id){
  $job=self::load_job($id);if(!$job)return new WP_Error('oai_batch_not_found','Batch job not found',array('status'=>404,'batch_id'=>$id));
  $user_id=absint($user_id);if(!$user_id||!user_can($user_id,'manage_options'))return new WP_Error('oai_batch_admin','Administrator identity required',array('status'=>403));
  if(absint($job['owner_user_id']??0)!==$user_id)return new WP_Error('oai_batch_owner','Batch job belongs to another administrator',array('status'=>403));
  $expected=(string)($job['client_id_hash']??'');$actual=self::client_hash($client_id);
  if($expected!==''&&!hash_equals($expected,$actual))return new WP_Error('oai_batch_client','Batch job belongs to another MCP client',array('status'=>403));
  return $job;
 }
 private static function new_id(){return 'batch_'.str_replace('-','',wp_generate_uuid4());}

 public static function supported_tools(){
  return array(
   // Safe read steps.
   'wp.get_site','wp.read_content','wp.read_post','wp.read_meta','wp.read_option','wp.rest_read',
   'woocommerce.get_product','woocommerce.search_products','woocommerce.get_variations','woocommerce.search_orders',
   'gutenberg.get_outline','builder.detect','file.read',
   // Write steps create sandbox operations only; never direct live writes.
   'content.patch','gutenberg.patch','woocommerce.patch','woocommerce.update_product_sandbox',
   'elementor.create_draft','elementor.patch_widget','elementor.patch_fragment','elementor.patch_settings','file.write_sandbox'
  );
 }
 private static function sandbox_tools(){return array('content.patch','gutenberg.patch','woocommerce.patch','woocommerce.update_product_sandbox','elementor.create_draft','elementor.patch_widget','elementor.patch_fragment','elementor.patch_settings','file.write_sandbox');}
 private static function is_sandbox_tool($tool){return in_array((string)$tool,self::sandbox_tools(),true);}

 private static function compact_result($value){
  if(is_wp_error($value))return array('error'=>$value->get_error_code(),'message'=>$value->get_error_message(),'data'=>$value->get_error_data());
  $json=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($json))return array('type'=>gettype($value));
  $truncated=strlen($json)>self::RESULT_MAX_CHARS;
  if($truncated)$json=substr($json,0,self::RESULT_MAX_CHARS).'…[TRUNCATED]';
  return array('json'=>$json,'truncated'=>$truncated,'sha256'=>hash('sha256',wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
 }
 private static function public_job($job,$include_arguments=false){
  if(!$job)return $job;self::reconcile_child_steps($job);$out=$job;unset($out['client_id_hash']);
  $lease=is_array($job['lease']??null)?$job['lease']:array();
  $out['lease']=array('active'=>self::lease_active($job),'acquired_at'=>$lease['acquired_at']??null,'expires_at'=>absint($lease['expires_at']??0));
  if(!$include_arguments){foreach((array)($out['steps']??array()) as $id=>$step){unset($out['steps'][$id]['arguments']);}}
  return $out;
 }

 public static function create($items,$label,$client_id,$user_id){
  return self::with_mutex(function()use($items,$label,$client_id,$user_id){return self::create_locked($items,$label,$client_id,$user_id);});
 }
 private static function create_locked($items,$label,$client_id,$user_id){
  $user_id=absint($user_id);if(!$user_id||!user_can($user_id,'manage_options'))return new WP_Error('oai_batch_admin','Administrator identity required',array('status'=>403));
  $preflight=OAI_Platform::batch_preflight($items);if(is_wp_error($preflight))return $preflight;
  if(count((array)$items)>self::MAX_ITEMS)return new WP_Error('oai_batch_limit','Batch executor is limited to 100 items',array('status'=>422));
  $encoded=wp_json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($encoded)||strlen($encoded)>self::MAX_JOB_BYTES)return new WP_Error('oai_batch_payload','Batch payload is too large',array('status'=>413,'max_bytes'=>self::MAX_JOB_BYTES));
  $supported=self::supported_tools();$raw=array();$idx=0;
  foreach(array_values((array)$items) as $item){
   $id=sanitize_key((string)($item['id']??('step_'.($idx+1))));if($id==='')$id='step_'.($idx+1);
   $tool=sanitize_text_field((string)($item['tool']??''));
   if(!in_array($tool,$supported,true))return new WP_Error('oai_batch_executor_tool','Tool is not enabled for resumable batch execution yet',array('status'=>422,'tool'=>$tool,'supported_tools'=>$supported));
   $raw[$id]=array('id'=>$id,'tool'=>$tool,'arguments'=>is_array($item['arguments']??null)?$item['arguments']:array(),'depends_on'=>array_values(array_unique(array_filter(array_map('sanitize_key',(array)($item['depends_on']??array()))))));$idx++;
  }
  $steps=array();foreach((array)$preflight['execution_order'] as $id){$item=$raw[$id];$steps[$id]=array(
   'id'=>$id,'tool'=>$item['tool'],'arguments'=>$item['arguments'],'depends_on'=>$item['depends_on'],
   'mode'=>self::is_sandbox_tool($item['tool'])?'sandbox_child':'read','state'=>'pending','attempts'=>0,
   'operation_id'=>null,'operation_history'=>array(),'last_error'=>null,'result'=>null,'updated_at'=>self::now()
  );}
  $id=self::new_id();$created=self::now();$job=array(
   'schema_version'=>2,'id'=>$id,'label'=>sanitize_text_field((string)$label),'status'=>'ready','owner_user_id'=>$user_id,'client_id_hash'=>self::client_hash($client_id),
   'created_at'=>$created,'updated_at'=>$created,'execution_order'=>array_values((array)$preflight['execution_order']),'steps'=>$steps,
   'summary'=>array('total'=>count($steps),'completed'=>0,'waiting_approval'=>0,'failed'=>0,'cancelled'=>0,'rolled_back'=>0),
   'version'=>1,'checkpoint_seq'=>1,'last_checkpoint'=>array('seq'=>1,'event'=>'created','at'=>$created,'version'=>1,'status'=>'ready','details'=>array()),
   'events'=>array(array('seq'=>1,'event'=>'created','at'=>$created,'version'=>1,'status'=>'ready','details'=>array())),
   'manual_pause'=>false,'pause_reason'=>'','lease'=>array('token_hash'=>'','acquired_at'=>null,'expires_at'=>0),
   'policy'=>array('direct_live_writes'=>false,'write_steps'=>'sandbox_only','human_approval_default'=>true,'test_automation_rollback_exception'=>true,'max_attempts'=>self::MAX_ATTEMPTS,'lease_seconds'=>self::LEASE_SECONDS,'optimistic_versioning'=>true)
  );
  self::save_job($job);return self::public_job($job,false);
 }

 public static function get($id,$include_arguments,$client_id,$user_id){$job=self::auth_job($id,$client_id,$user_id);return is_wp_error($job)?$job:self::public_job($job,(bool)$include_arguments);}
 public static function list_jobs($limit,$client_id,$user_id){
  $user_id=absint($user_id);if(!$user_id||!user_can($user_id,'manage_options'))return new WP_Error('oai_batch_admin','Administrator identity required',array('status'=>403));
  $limit=max(1,min(50,absint($limit?:20)));$hash=self::client_hash($client_id);$items=array();
  foreach(self::all_jobs() as $job){if(absint($job['owner_user_id']??0)!==$user_id)continue;if(!empty($job['client_id_hash'])&&!hash_equals((string)$job['client_id_hash'],$hash))continue;self::reconcile_child_steps($job);$items[]=array('id'=>$job['id'],'label'=>$job['label']??'','status'=>$job['status'],'version'=>absint($job['version']??1),'checkpoint_seq'=>absint($job['checkpoint_seq']??0),'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at'],'summary'=>$job['summary']??array());if(count($items)>=$limit)break;}
  return array('count'=>count($items),'retained_max'=>self::MAX_JOBS,'items'=>$items);
 }

 public static function dry_run($items){
  $preflight=OAI_Platform::batch_preflight($items);if(is_wp_error($preflight))return $preflight;
  $supported=self::supported_tools();$writes=0;$reads=0;$unsupported=array();$depth=array();$max_depth=0;
  $encoded=wp_json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$payload_bytes=is_string($encoded)?strlen($encoded):0;
  foreach((array)($preflight['steps']??array()) as $step){
   $tool=(string)($step['tool']??'');if(!in_array($tool,$supported,true))$unsupported[]=$tool;
   if(self::is_sandbox_tool($tool))$writes++;else$reads++;
   $d=1;foreach((array)($step['depends_on']??array()) as $dep)$d=max($d,1+absint($depth[$dep]??0));$depth[(string)$step['id']]=$d;$max_depth=max($max_depth,$d);
  }
  if($unsupported)return new WP_Error('oai_batch_executor_tool','One or more tools are not enabled for resumable batch execution',array('status'=>422,'unsupported_tools'=>array_values(array_unique($unsupported)),'supported_tools'=>$supported));
  if($payload_bytes>self::MAX_JOB_BYTES)return new WP_Error('oai_batch_payload','Batch payload is too large',array('status'=>413,'max_bytes'=>self::MAX_JOB_BYTES,'payload_bytes'=>$payload_bytes));
  return array('schema_version'=>1,'valid'=>true,'count'=>absint($preflight['count']??count((array)$items)),'reads'=>$reads,'writes'=>$writes,'expected_pause_points'=>$writes,'estimated_child_operations'=>$writes,'max_dependency_depth'=>$max_depth,'payload_bytes'=>$payload_bytes,'max_payload_bytes'=>self::MAX_JOB_BYTES,'execution_order'=>$preflight['execution_order']??array(),'direct_live_writes'=>false,'execution'=>'not_executed_dry_run','policy'=>'executor_supported_tools_dependency_and_size_validation');
 }

 private static function execute_read($tool,$a){
  switch($tool){
   case 'wp.get_site':return OAI_REST::site();
   case 'wp.read_content':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content');foreach($a as $k=>$v)$r->set_param($k,$v);if(isset($a['post_type']))$r->set_param('type',$a['post_type']);if(isset($a['per_page']))$r->set_param('limit',$a['per_page']);return OAI_REST::content($r);
   case 'wp.read_post':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.absint($a['post_id']??0));$r->set_url_params(array('id'=>absint($a['post_id']??0)));foreach(array('include_content','include_elementor','include_css') as $k)if(array_key_exists($k,$a))$r->set_param($k,$a[$k]);return OAI_REST::content_detail($r);
   case 'wp.read_meta':$r=new WP_REST_Request('GET','/okean-ai/v1/read/content/'.absint($a['post_id']??0).'/meta');$r->set_url_params(array('id'=>absint($a['post_id']??0)));return OAI_REST::content_meta($r);
   case 'wp.read_option':$r=new WP_REST_Request('GET','/okean-ai/v1/read/options');$r->set_param('keys',$a['keys']??'');return OAI_REST::options($r);
   case 'wp.rest_read':return OAI_REST_Resolver::read((string)($a['route']??''),is_array($a['params']??null)?$a['params']:array(),(string)($a['fields']??'summary'),absint($a['max_items']??50));
   case 'woocommerce.get_product':$pid=absint($a['product_id']??0);$r=new WP_REST_Request('GET','/okean-ai/v1/read/woocommerce/'.$pid);$r->set_url_params(array('id'=>$pid));return OAI_REST::woocommerce($r);
   case 'woocommerce.search_products':return OAI_WooCommerce::search((string)($a['query']??''),(string)($a['sku']??''),absint($a['limit']??20),absint($a['page']??1),(string)($a['fields']??'all'));
   case 'woocommerce.get_variations':return OAI_WooCommerce::variations(absint($a['product_id']??0),absint($a['page']??1),absint($a['limit']??20));
   case 'woocommerce.search_orders':return OAI_WooCommerce::orders(absint($a['page']??1),absint($a['limit']??20),(string)($a['status']??'any'));
   case 'gutenberg.get_outline':$r=new WP_REST_Request('GET','/okean-ai/v1/read/gutenberg/'.absint($a['post_id']??0));$r->set_url_params(array('id'=>absint($a['post_id']??0)));$r->set_param('view','outline');return OAI_REST::gutenberg_resolved($r);
   case 'builder.detect':return OAI_Adapter_Catalog::detect(absint($a['post_id']??0));
   case 'file.read':$r=new WP_REST_Request('GET','/okean-ai/v1/files/read');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::files_read($r);
  }
  return new WP_Error('oai_batch_executor_tool','Unsupported read tool in batch executor',array('status'=>422,'tool'=>$tool));
 }
 private static function execute_sandbox($tool,$a){
  switch($tool){
   case 'content.patch':$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::content_patch($r);
   case 'gutenberg.patch':$r=new WP_REST_Request('POST','/okean-ai/v1/gutenberg/patch');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::gutenberg_patch($r);
   case 'woocommerce.patch':case 'woocommerce.update_product_sandbox':$r=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::woocommerce_patch($r);
   case 'elementor.create_draft':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::elementor_create_page($r);
   case 'elementor.patch_widget':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-widget');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::elementor_patch_widget($r);
   case 'elementor.patch_fragment':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-fragment');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::elementor_patch_fragment($r);
   case 'elementor.patch_settings':$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch');$r->set_param('target',absint($a['post_id']??0).':'.sanitize_text_field((string)($a['widget_id']??'')));$r->set_param('changes',$a['changes']??array());return OAI_REST::elementor_patch($r);
   case 'file.write_sandbox':$r=new WP_REST_Request('POST','/okean-ai/v1/files/write');foreach($a as $k=>$v)$r->set_param($k,$v);return OAI_REST::files_write($r);
  }
  return new WP_Error('oai_batch_executor_tool','Unsupported sandbox tool in batch executor',array('status'=>422,'tool'=>$tool));
 }

 private static function refresh_operation_step(&$step){
  $opid=absint($step['operation_id']??0);if(!$opid)return;
  $row=OAI_DB::get($opid);if(!$row){$step['state']='failed';$step['last_error']=array('code'=>'oai_batch_operation_missing','message'=>'Child operation is missing');return;}
  $status=(string)$row['status'];
  if(in_array($status,array('applied','proved'),true)){$step['state']='completed';$step['last_error']=null;return;}
  if($status==='rolled_back'){$step['state']='rolled_back';$step['last_error']=null;return;}
  if($status==='rejected'){$step['state']='failed';$step['last_error']=array('code'=>'oai_batch_child_rejected','message'=>'Child operation was rejected','operation_status'=>$status);return;}
  if(in_array($status,array('apply_failed','rollback_failed','expired'),true)){$step['state']='failed';$step['last_error']=array('code'=>'oai_batch_child_failed','message'=>'Child operation entered a failure state','operation_status'=>$status);return;}
  if(in_array($status,array('pending','approved','applying','rollback_pending','rolling_back'),true)){$step['state']='waiting_approval';return;}
 }
 private static function recalc(&$job){
  $sum=array('total'=>count((array)$job['steps']),'completed'=>0,'waiting_approval'=>0,'failed'=>0,'cancelled'=>0,'rolled_back'=>0);
  foreach((array)$job['steps'] as $step){$s=(string)($step['state']??'pending');if(isset($sum[$s]))$sum[$s]++;}
  $job['summary']=$sum;
 }
 private static function reconcile_child_steps(&$job){
  foreach($job['steps'] as &$step){
   if(!empty($step['operation_id'])&&in_array((string)($step['state']??''),array('waiting_approval','completed','rolled_back','failed'),true))self::refresh_operation_step($step);
  }
  unset($step);self::recalc($job);
 }
 private static function unresolved_child_operations($job){
  $active=array('pending','approved','applying','applied','proved','rollback_pending','rolling_back');$out=array();
  foreach((array)($job['steps']??array()) as $sid=>$step){$opid=absint($step['operation_id']??0);if(!$opid)continue;$row=OAI_DB::get($opid);$status=(string)($row['status']??'missing');if(in_array($status,$active,true))$out[]=array('step_id'=>$sid,'operation_id'=>$opid,'operation_status'=>$status);}
  return $out;
 }
 private static function busy_guard($job){return self::lease_active($job)?new WP_Error('oai_batch_lease','Batch job is currently leased by a resume execution',array('status'=>409,'lease_expires_at'=>absint($job['lease']['expires_at']??0))):true;}

 public static function pause($id,$reason,$client_id,$user_id,$expected_version=0){
  return self::with_mutex(function()use($id,$reason,$client_id,$user_id,$expected_version){return self::pause_locked($id,$reason,$client_id,$user_id,$expected_version);});
 }
 private static function pause_locked($id,$reason,$client_id,$user_id,$expected_version){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$ver=self::assert_version($job,$expected_version);if(is_wp_error($ver))return $ver;$busy=self::busy_guard($job);if(is_wp_error($busy))return $busy;
  if(in_array((string)$job['status'],array('completed','cancelled','rolled_back'),true))return new WP_Error('oai_batch_terminal','Terminal batch job cannot be paused',array('status'=>409,'batch_status'=>$job['status']));
  self::reconcile_child_steps($job);$job['manual_pause']=true;$job['pause_reason']=sanitize_text_field((string)$reason);$job['status']='paused';self::checkpoint($job,'manual_pause',array('reason'=>$job['pause_reason']));self::save_job($job);return self::public_job($job,false);
 }

 public static function resume($id,$max_steps,$client_id,$user_id,$expected_version=0){
  return self::with_mutex(function()use($id,$max_steps,$client_id,$user_id,$expected_version){return self::resume_locked($id,$max_steps,$client_id,$user_id,$expected_version);});
 }
 private static function resume_locked($id,$max_steps,$client_id,$user_id,$expected_version){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$ver=self::assert_version($job,$expected_version);if(is_wp_error($ver))return $ver;
  if(in_array((string)$job['status'],array('cancelled','rolled_back'),true))return new WP_Error('oai_batch_terminal','Batch job is in a terminal state',array('status'=>409,'batch_status'=>$job['status']));
  $lease_token=self::acquire_lease($job);if(is_wp_error($lease_token))return $lease_token;
  $max_steps=max(1,min(20,absint($max_steps?:10)));$processed=0;$job['manual_pause']=false;$job['pause_reason']='';$job['status']='running';
  foreach($job['execution_order'] as $sid){
   if($processed>=$max_steps)break;$step=&$job['steps'][$sid];
   if(!empty($step['operation_id'])&&in_array((string)$step['state'],array('waiting_approval','completed','rolled_back','failed'),true))self::refresh_operation_step($step);
   if(in_array((string)$step['state'],array('completed','rolled_back','cancelled'),true)){unset($step);continue;}
   if((string)$step['state']==='waiting_approval'){$job['status']='waiting_approval';unset($step);break;}
   if((string)$step['state']==='failed'){$job['status']='failed';unset($step);break;}
   $deps_ok=true;foreach((array)$step['depends_on'] as $dep){$ds=(string)($job['steps'][$dep]['state']??'pending');if($ds!=='completed'){$deps_ok=false;if(in_array($ds,array('failed','cancelled','rolled_back'),true)){$step['state']='failed';$step['last_error']=array('code'=>'oai_batch_dependency_failed','message'=>'A dependency is not in completed state','dependency'=>$dep,'dependency_state'=>$ds);$job['status']='failed';}break;}}
   if(!$deps_ok){unset($step);if($job['status']==='failed')break;continue;}
   $step['attempts']=absint($step['attempts']??0)+1;$step['updated_at']=self::now();$processed++;
   $result=self::is_sandbox_tool($step['tool'])?self::execute_sandbox($step['tool'],$step['arguments']):self::execute_read($step['tool'],$step['arguments']);
   if(is_wp_error($result)){$step['state']='failed';$step['last_error']=array('code'=>$result->get_error_code(),'message'=>$result->get_error_message(),'data'=>$result->get_error_data());$step['result']=self::compact_result($result);$job['status']='failed';unset($step);break;}
   $step['result']=self::compact_result($result);$step['last_error']=null;
   if(self::is_sandbox_tool($step['tool'])){
    $opid=absint(is_array($result)?($result['id']??0):0);if(!$opid){$step['state']='failed';$step['last_error']=array('code'=>'oai_batch_no_operation','message'=>'Sandbox step did not create an operation');$job['status']='failed';unset($step);break;}
    if(!empty($step['operation_id']))$step['operation_history'][]=absint($step['operation_id']);$step['operation_id']=$opid;$step['state']='waiting_approval';
    if(class_exists('OAI_Test_Automation'))OAI_Test_Automation::tag_operation($opid,$step['tool'],$client_id,$user_id);
    $job['status']='waiting_approval';unset($step);break;
   }else{$step['state']='completed';}
   unset($step);
  }
  $all_completed=true;foreach((array)$job['steps'] as $s){if((string)($s['state']??'')!=='completed'){$all_completed=false;break;}}
  if($all_completed)$job['status']='completed';elseif($job['status']==='running')$job['status']='paused';
  self::recalc($job);self::release_lease($job,$lease_token,'resume_checkpoint');self::save_job($job);return array('processed_steps'=>$processed,'job'=>self::public_job($job,false));
 }

 public static function retry($id,$step_id,$client_id,$user_id,$expected_version=0){
  return self::with_mutex(function()use($id,$step_id,$client_id,$user_id,$expected_version){return self::retry_locked($id,$step_id,$client_id,$user_id,$expected_version);});
 }
 private static function retry_locked($id,$step_id,$client_id,$user_id,$expected_version){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$ver=self::assert_version($job,$expected_version);if(is_wp_error($ver))return $ver;$busy=self::busy_guard($job);if(is_wp_error($busy))return $busy;$step_id=sanitize_key((string)$step_id);if(empty($job['steps'][$step_id]))return new WP_Error('oai_batch_step','Batch step not found',array('status'=>404,'step_id'=>$step_id));
  $unresolved=self::unresolved_child_operations($job);if($unresolved)return new WP_Error('oai_batch_retry_unresolved','Retry is blocked while any child operation is unresolved',array('status'=>409,'unresolved_child_operations'=>$unresolved));
  self::reconcile_child_steps($job);
  $step=&$job['steps'][$step_id];if(absint($step['attempts']??0)>=self::MAX_ATTEMPTS)return new WP_Error('oai_batch_retry_limit','Batch step retry limit reached',array('status'=>409,'step_id'=>$step_id));
  if(!empty($step['operation_id'])){self::refresh_operation_step($step);$row=OAI_DB::get(absint($step['operation_id']));$st=(string)($row['status']??'');$step['operation_history'][]=absint($step['operation_id']);$step['operation_id']=null;}
  if(!in_array((string)$step['state'],array('failed','rolled_back','cancelled'),true))return new WP_Error('oai_batch_retry_state','Only failed/rolled_back/cancelled steps can be retried',array('status'=>409,'step_state'=>$step['state']));
  $step['state']='pending';$step['last_error']=null;$step['result']=null;$step['updated_at']=self::now();$job['status']='ready';$job['manual_pause']=false;$job['pause_reason']='';self::recalc($job);self::checkpoint($job,'retry',array('step_id'=>$step_id));self::save_job($job);return self::public_job($job,false);
 }

 public static function cancel($id,$reason,$client_id,$user_id,$expected_version=0){
  return self::with_mutex(function()use($id,$reason,$client_id,$user_id,$expected_version){return self::cancel_locked($id,$reason,$client_id,$user_id,$expected_version);});
 }
 private static function cancel_locked($id,$reason,$client_id,$user_id,$expected_version){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$ver=self::assert_version($job,$expected_version);if(is_wp_error($ver))return $ver;$busy=self::busy_guard($job);if(is_wp_error($busy))return $busy;if(in_array((string)$job['status'],array('completed','rolled_back'),true))return new WP_Error('oai_batch_terminal','Completed/rolled-back batch cannot be cancelled',array('status'=>409));
  self::reconcile_child_steps($job);foreach($job['steps'] as &$step){if((string)$step['state']==='pending')$step['state']='cancelled';}unset($step);
  $job['status']='cancelled';$job['manual_pause']=false;$job['cancel_reason']=sanitize_text_field((string)$reason);$job['cancelled_at']=self::now();self::recalc($job);$unresolved=self::unresolved_child_operations($job);self::checkpoint($job,'cancel',array('reason'=>$job['cancel_reason'],'unresolved_count'=>count($unresolved)));self::save_job($job);
  return array('job'=>self::public_job($job,false),'unresolved_operation_ids'=>array_values(array_map(function($x){return absint($x['operation_id']);},$unresolved)),'note'=>$unresolved?'Child operations remain in the normal operation ledger and must be handled separately.':'No unresolved child operations.');
 }

 public static function rollback($id,$client_id,$user_id,$expected_version=0){
  return self::with_mutex(function()use($id,$client_id,$user_id,$expected_version){return self::rollback_locked($id,$client_id,$user_id,$expected_version);});
 }
 private static function rollback_locked($id,$client_id,$user_id,$expected_version){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$ver=self::assert_version($job,$expected_version);if(is_wp_error($ver))return $ver;$busy=self::busy_guard($job);if(is_wp_error($busy))return $busy;$needs_human=array();$failed=array();$rolled=array();
  self::reconcile_child_steps($job);$order=array_reverse((array)$job['execution_order']);
  foreach($order as $sid){$step=&$job['steps'][$sid];if(empty($step['operation_id'])){unset($step);continue;}$opid=absint($step['operation_id']);$row=OAI_DB::get($opid);if(!$row){$failed[]=$opid;unset($step);continue;}$st=(string)$row['status'];
   if($st==='rolled_back'){$step['state']='rolled_back';$rolled[]=$opid;unset($step);continue;}
   if(!in_array($st,array('applied','proved'),true)){unset($step);continue;}
   if(class_exists('OAI_Test_Automation')&&OAI_Test_Automation::is_active()){$res=OAI_Test_Automation::perform($opid,'rollback',$client_id,$user_id);if(is_wp_error($res)){$failed[]=$opid;}else{$step['state']='rolled_back';$rolled[]=$opid;}}
   else{$needs_human[]=$opid;}
   unset($step);
  }
  if($failed)$job['status']='rollback_failed';elseif($needs_human)$job['status']='rollback_pending';else{$job['status']='rolled_back';$job['rolled_back_at']=self::now();}
  self::recalc($job);self::checkpoint($job,'rollback',array('rolled_back_operation_ids'=>$rolled,'human_confirmation_required_operation_ids'=>$needs_human,'failed_operation_ids'=>$failed));self::save_job($job);
  return array('job'=>self::public_job($job,false),'rolled_back_operation_ids'=>$rolled,'human_confirmation_required_operation_ids'=>$needs_human,'failed_operation_ids'=>$failed,'policy'=>$needs_human?'Use wp-admin rollback buttons, then call batch.rollback again to verify completion.':'Rollback reconciliation completed.');
 }

 private static function receipt_payload($job){
  self::reconcile_child_steps($job);
  $steps=array();foreach((array)($job['steps']??array()) as $sid=>$step){$opid=absint($step['operation_id']??0);$row=$opid?OAI_DB::get($opid):null;$steps[]=array('id'=>$sid,'tool'=>$step['tool']??'','state'=>$step['state']??'pending','attempts'=>absint($step['attempts']??0),'operation_id'=>$opid?:null,'operation_status'=>$row?(string)($row['status']??''):null,'operation_history'=>array_values(array_map('absint',(array)($step['operation_history']??array()))),'last_error_code'=>is_array($step['last_error']??null)?($step['last_error']['code']??null):null);}
  return array('schema_version'=>1,'batch_id'=>$job['id'],'label'=>$job['label']??'','status'=>$job['status']??'','version'=>absint($job['version']??1),'checkpoint_seq'=>absint($job['checkpoint_seq']??0),'summary'=>$job['summary']??array(),'created_at'=>$job['created_at']??null,'updated_at'=>$job['updated_at']??null,'steps'=>$steps,'events'=>array_values((array)($job['events']??array())),'plugin_version'=>defined('OAI_VERSION')?OAI_VERSION:null,'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:null);
 }
 public static function receipt($id,$client_id,$user_id){
  $job=self::auth_job($id,$client_id,$user_id);if(is_wp_error($job))return $job;$payload=self::receipt_payload($job);$payload_hash=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$payload['generated_at']=self::now();$payload['payload_hash']=$payload_hash;return $payload;
 }
 public static function proof($id,$client_id,$user_id){
  $receipt=self::receipt($id,$client_id,$user_id);if(is_wp_error($receipt))return $receipt;$payload_hash=(string)($receipt['payload_hash']??'');$binding=(class_exists('OAI_Platform')?OAI_Platform::site_id():'').'|'.(class_exists('OAI_Platform')?OAI_Platform::installation_id():'').'|'.$payload_hash.'|'.(defined('OAI_BUILD_ID')?OAI_BUILD_ID:'');
  return array('proof_kind'=>'batch_execution','algorithm'=>'sha256','hash'=>hash('sha256',$binding),'payload_hash'=>$payload_hash,'batch_id'=>$id,'status'=>$receipt['status']??'','version'=>$receipt['version']??null,'checkpoint_seq'=>$receipt['checkpoint_seq']??null,'generated_at'=>self::now());
 }

 public static function self_test_contract(){
  $supported=self::supported_tools();
  return array(
   'retention_bounded'=>self::MAX_JOBS===50,
   'max_items_100'=>self::MAX_ITEMS===100,
   'retry_limit_bounded'=>self::MAX_ATTEMPTS===5,
   'payload_bounded'=>self::MAX_JOB_BYTES===524288&&self::RESULT_MAX_CHARS===2000,
   'sandbox_only_writes'=>!array_diff(self::sandbox_tools(),$supported),
   'no_lifecycle_tools_in_executor'=>!in_array('operation.apply',$supported,true)&&!in_array('operation.approve',$supported,true)&&!in_array('operation.rollback',$supported,true),
   'read_support'=>in_array('wp.get_site',$supported,true)&&in_array('wp.rest_read',$supported,true),
   'write_support'=>in_array('content.patch',$supported,true)&&in_array('woocommerce.update_product_sandbox',$supported,true),
   'strict_retry_unresolved_guard'=>method_exists(__CLASS__,'unresolved_child_operations'),
   'manual_pause_available'=>method_exists(__CLASS__,'pause'),
   'lease_bounded'=>self::LEASE_SECONDS===60,
   'db_mutex_available'=>method_exists(__CLASS__,'with_mutex')&&self::MUTEX_NAME==='oai_batch_jobs_v1_mutex',
   'checkpoint_events_bounded'=>self::EVENT_LIMIT===100,
   'receipt_and_proof_available'=>method_exists(__CLASS__,'receipt')&&method_exists(__CLASS__,'proof'),
   'dry_run_available'=>method_exists(__CLASS__,'dry_run'),
  );
 }
}
