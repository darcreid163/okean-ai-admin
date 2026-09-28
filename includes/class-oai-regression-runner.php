<?php
/**
 * WP AI Admin sequential regression runner for short-lived Test Automation sessions.
 * Every mutation uses the normal sandbox ledger and is rolled back before the
 * runner proceeds to the next scenario. The runner stops if cleanup cannot be
 * proven, so later tests never stack on an unknown live state.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Regression_Runner {
 private static function err($e){
  if(is_wp_error($e))return array('code'=>$e->get_error_code(),'message'=>$e->get_error_message(),'data'=>$e->get_error_data());
  return array('code'=>'oai_regression_error','message'=>is_scalar($e)?(string)$e:'Unknown regression error');
 }
 private static function fake_row($type,$target){return array('type'=>$type,'target'=>$target,'meta_data'=>'{}');}
 private static function fixture_allowed($type,$target){
  $x=OAI_Test_Automation::eligibility(self::fake_row($type,$target));
  return !empty($x['eligible'])?true:new WP_Error('oai_regression_scope','Regression fixture is outside the active Test Automation allowlist',array('status'=>403,'reason'=>$x['reason']??'not_allowed','type'=>$type,'target'=>$target));
 }
 private static function require_test_draft($post_id,$label){
  $p=get_post(absint($post_id));
  if(!$p)return new WP_Error('oai_regression_post',$label.' post not found',array('status'=>404,'post_id'=>absint($post_id)));
  if(!in_array((string)$p->post_status,array('draft','private'),true))return new WP_Error('oai_regression_post',$label.' must be draft or private for automatic live lifecycle tests',array('status'=>409,'post_id'=>(int)$p->ID,'status'=>(string)$p->post_status));
  return $p;
 }
 private static function tag($op,$tool,$client_id,$user_id){
  $id=absint(is_array($op)?($op['id']??0):0);if(!$id)return new WP_Error('oai_regression_operation','Sandbox operation was not created',array('status'=>500));
  $tag=OAI_Test_Automation::tag_operation($id,$tool,$client_id,$user_id);
  if(empty($tag['tagged']))return new WP_Error('oai_regression_tag','Regression operation could not be tagged for the active test session',array('status'=>403,'operation_id'=>$id,'tag'=>$tag));
  return $id;
 }
 private static function stage(&$scenario,$name,$value){$scenario['stages'][$name]=$value;}
 private static function lifecycle($name,$op,$tool,$client_id,$user_id){
  $scenario=array('name'=>$name,'status'=>'FAIL','operation_id'=>null,'baseline_restored'=>false,'stages'=>array());
  if(is_wp_error($op)){$scenario['error']=self::err($op);self::stage($scenario,'create','FAIL');return $scenario;}
  $id=self::tag($op,$tool,$client_id,$user_id);
  if(is_wp_error($id)){$scenario['error']=self::err($id);self::stage($scenario,'tag','FAIL');return $scenario;}
  $scenario['operation_id']=$id;self::stage($scenario,'create','PASS');self::stage($scenario,'tag','PASS');

  $preview_types=array('post_title','post_excerpt','post_content','elementor_widget_html','elementor_widget_settings','elementor_page_create');
  $op_type=(string)($op['type']??'');
  if(in_array($op_type,$preview_types,true)){
   $preq=new WP_REST_Request('GET','/okean-ai/v1/preview-link/'.$id);$preq->set_url_params(array('id'=>$id));
   $preview=OAI_REST::preview_link($preq);
   if(is_wp_error($preview)||empty($preview['url'])){self::stage($scenario,'preview','WARN');$scenario['preview_error']=self::err($preview);}else{self::stage($scenario,'preview','PASS');$scenario['preview_expires_in']=absint($preview['expires_in']??0);}
  }else self::stage($scenario,'preview','SKIP');

  $approve=OAI_Test_Automation::perform($id,'approve',$client_id,$user_id);
  if(is_wp_error($approve)){$scenario['error']=self::err($approve);self::stage($scenario,'approve','FAIL');return $scenario;}
  self::stage($scenario,'approve','PASS');

  $apply=OAI_Test_Automation::perform($id,'apply',$client_id,$user_id);
  if(is_wp_error($apply)){
   $scenario['error']=self::err($apply);self::stage($scenario,'apply','FAIL');
   $row=OAI_DB::get($id);$scenario['terminal_status']=$row['status']??null;
   $scenario['baseline_restored']=in_array((string)($row['status']??''),array('rolled_back','rejected'),true);
   return $scenario;
  }
  self::stage($scenario,'apply','PASS');

  $verify=OAI_Verify::operation($id);
  if(is_wp_error($verify)||empty($verify['verified'])){$scenario['error']=self::err(is_wp_error($verify)?$verify:'Apply verification failed');self::stage($scenario,'verify','FAIL');return $scenario;}
  self::stage($scenario,'verify','PASS');
  $receipt=OAI_Receipts::build($id);$proof=OAI_Proof::make($id);
  self::stage($scenario,'receipt',is_array($receipt)&&!empty($receipt['verified'])?'PASS':'WARN');
  self::stage($scenario,'proof',is_array($proof)&&!empty($proof['hash'])?'PASS':'WARN');
  if(is_array($proof)&&!empty($proof['hash']))$scenario['proof_hash']=$proof['hash'];

  $rollback=OAI_Test_Automation::perform($id,'rollback',$client_id,$user_id);
  if(is_wp_error($rollback)){$scenario['error']=self::err($rollback);self::stage($scenario,'rollback','FAIL');return $scenario;}
  self::stage($scenario,'rollback','PASS');
  $verify2=OAI_Verify::operation($id,null,'rollback');
  if(is_wp_error($verify2)||empty($verify2['verified'])){$scenario['error']=self::err(is_wp_error($verify2)?$verify2:'Rollback verification failed');self::stage($scenario,'rollback_verify','FAIL');return $scenario;}
  self::stage($scenario,'rollback_verify','PASS');
  $scenario['baseline_restored']=true;$scenario['cleanup_effective']=true;$scenario['status']='PASS';$scenario['terminal_status']='rolled_back';
  if(is_array($verify2)&&!empty($verify2['verification_source']))$scenario['cleanup_verification_source']=$verify2['verification_source'];
  return $scenario;
 }
 private static function reject_cycle($post_id,$run_id,$client_id,$user_id){
  $scenario=array('name'=>'reject_guard','status'=>'FAIL','operation_id'=>null,'baseline_restored'=>true,'stages'=>array());
  $p=self::require_test_draft($post_id,'Reject fixture');if(is_wp_error($p)){$scenario['status']='SKIP';$scenario['reason']=$p->get_error_message();return $scenario;}
  $allowed=self::fixture_allowed('post_title',$post_id.':post_title');if(is_wp_error($allowed)){$scenario['status']='SKIP';$scenario['reason']=$allowed->get_error_message();return $scenario;}
  $old=(string)$p->post_title;$new=$old.' [WP AI Admin '.$run_id.' reject]';
  $req=new WP_REST_Request('POST','/okean-ai/v1/content/patch');$req->set_param('post_id',$post_id);$req->set_param('field','post_title');$req->set_param('old_content',$old);$req->set_param('new_content',$new);$req->set_param('replace_all',false);
  $op=OAI_REST::content_patch($req);if(is_wp_error($op)){$scenario['error']=self::err($op);return $scenario;}
  $id=self::tag($op,'content.patch',$client_id,$user_id);if(is_wp_error($id)){$scenario['error']=self::err($id);return $scenario;}
  $scenario['operation_id']=$id;self::stage($scenario,'create','PASS');self::stage($scenario,'tag','PASS');
  $reject=OAI_Test_Automation::perform($id,'reject',$client_id,$user_id);if(is_wp_error($reject)){$scenario['error']=self::err($reject);self::stage($scenario,'reject','FAIL');return $scenario;}
  self::stage($scenario,'reject','PASS');
  $current=(string)get_post_field('post_title',$post_id);
  if($current!==$old){$scenario['error']=self::err('Rejected operation changed live title');$scenario['baseline_restored']=false;self::stage($scenario,'baseline','FAIL');return $scenario;}
  self::stage($scenario,'baseline','PASS');$scenario['status']='PASS';$scenario['terminal_status']='rejected';return $scenario;
 }
 private static function first_elementor_widget($nodes,$widget_type){
  foreach((array)$nodes as $node){
   if(!is_array($node))continue;
   if(($node['elType']??'')==='widget' && ($node['widgetType']??'')===$widget_type)return $node;
   if(!empty($node['elements'])){$x=self::first_elementor_widget($node['elements'],$widget_type);if($x)return $x;}
  }
  return null;
 }

 private static function elementor_settings_scenario($post_id,$run_id,$client_id,$user_id){
  $p=self::require_test_draft($post_id,'Elementor settings fixture');if(is_wp_error($p))return array('name'=>'elementor_settings','status'=>'SKIP','baseline_restored'=>true,'reason'=>$p->get_error_message());
  $data=OAI_Elementor::get_data($post_id);if(is_wp_error($data))return array('name'=>'elementor_settings','status'=>'SKIP','baseline_restored'=>true,'reason'=>$data->get_error_message());
  $node=self::first_elementor_widget($data,'heading');if(!$node)return array('name'=>'elementor_settings','status'=>'SKIP','baseline_restored'=>true,'reason'=>'No heading widget found');
  $node_id=(string)($node['id']??'');$target=$post_id.':'.$node_id;$allowed=self::fixture_allowed('elementor_widget_settings',$target);if(is_wp_error($allowed))return array('name'=>'elementor_settings','status'=>'SKIP','baseline_restored'=>true,'reason'=>$allowed->get_error_message());
  $old=(string)($node['settings']['title']??'');$new=$old.' [WP AI Admin '.$run_id.']';
  $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch');$req->set_param('target',$target);$req->set_param('changes',array('title'=>$new));
  return self::lifecycle('elementor_settings',OAI_REST::elementor_patch($req),'elementor.patch_settings',$client_id,$user_id);
 }

 private static function elementor_create_scenario($run_id,$client_id,$user_id){
  $data=array(array('id'=>'wpai'.substr(md5($run_id),0,5),'elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>'wpaih'.substr(md5($run_id.'h'),0,4),'elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'WP AI Admin '.$run_id,'header_size'=>'h2'),'elements'=>array()))));
  $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');$req->set_param('title','WP AI Admin TEST — '.$run_id);$req->set_param('data',$data);$req->set_param('page_template','elementor_canvas');$req->set_param('final_status','draft');
  return self::lifecycle('elementor_create',OAI_REST::elementor_create_page($req),'elementor.create_draft',$client_id,$user_id);
 }

 private static function first_gutenberg_leaf($nodes){
  foreach((array)$nodes as $node){
   $name=(string)($node['blockName']??'');$children=$node['innerBlocks']??array();
   if(empty($children)&&in_array($name,array('core/paragraph','core/html'),true)&&trim((string)($node['innerHTML']??''))!=='')return $node;
   if(!empty($children)){$x=self::first_gutenberg_leaf($children);if($x)return $x;}
  }
  return null;
 }
 private static function gutenberg_scenario($post_id,$run_id,$client_id,$user_id){
  $p=self::require_test_draft($post_id,'Gutenberg fixture');if(is_wp_error($p))return array('name'=>'gutenberg','status'=>'SKIP','baseline_restored'=>true,'reason'=>$p->get_error_message());
  $allowed=self::fixture_allowed('post_content',$post_id.':post_content');if(is_wp_error($allowed))return array('name'=>'gutenberg','status'=>'SKIP','baseline_restored'=>true,'reason'=>$allowed->get_error_message());
  $resolved=OAI_Gutenberg_Resolver::resolve_post($post_id);if(is_wp_error($resolved))return array('name'=>'gutenberg','status'=>'SKIP','baseline_restored'=>true,'reason'=>$resolved->get_error_message());
  $leaf=self::first_gutenberg_leaf($resolved['blocks']??array());if(!$leaf)return array('name'=>'gutenberg','status'=>'SKIP','baseline_restored'=>true,'reason'=>'No safe paragraph/html leaf block found');
  $old=(string)$leaf['innerHTML'];$new=$old;
  if(($leaf['blockName']??'')==='core/paragraph'&&preg_match('/<\/p>\s*$/',$old))$new=preg_replace('/<\/p>\s*$/',' <span data-wpai-regression="'.esc_attr($run_id).'"></span></p>',$old,1);
  else $new=$old."\n<!-- wp-ai-admin-regression ".esc_html($run_id)." -->";
  $req=new WP_REST_Request('POST','/okean-ai/v1/gutenberg/patch');$req->set_param('post_id',$post_id);$req->set_param('selector',array('path'=>(string)$leaf['path']));$req->set_param('html',$new);
  return self::lifecycle('gutenberg',OAI_REST::gutenberg_patch($req),'gutenberg.patch',$client_id,$user_id);
 }
 public static function run($args,$client_id,$user_id){
  $auth=OAI_Test_Automation::runner_authorized($client_id,$user_id);if(is_wp_error($auth))return $auth;
  $args=is_array($args)?$args:array();
  $content_id=absint($args['content_post_id']??0);$elementor_id=absint($args['elementor_post_id']??0);$widget=sanitize_text_field((string)($args['elementor_widget_id']??''));$product_id=absint($args['product_id']??0);$gutenberg_id=absint($args['gutenberg_post_id']??0);if(!$gutenberg_id&&class_exists('OAI_Test_Automation'))$gutenberg_id=OAI_Test_Automation::managed_gutenberg_fixture_id();
  if(!$content_id||!$elementor_id||$widget===''||!$product_id)return new WP_Error('oai_regression_args','content_post_id, elementor_post_id, elementor_widget_id and product_id are required',array('status'=>400));
  $include_file=!array_key_exists('include_file',$args)||!empty($args['include_file']);$include_reject=!array_key_exists('include_reject',$args)||!empty($args['include_reject']);$include_elementor_settings=!array_key_exists('include_elementor_settings',$args)||!empty($args['include_elementor_settings']);$include_elementor_create=!array_key_exists('include_elementor_create',$args)||!empty($args['include_elementor_create']);
  $run_id='rr_'.gmdate('Ymd_His').'_'.substr(wp_generate_uuid4(),0,8);$started=microtime(true);
  $report=array('schema_version'=>1,'run_id'=>$run_id,'plugin_version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'mode'=>'sequential_stop_on_cleanup_failure','started_at'=>gmdate('c'),'session_id'=>(string)(OAI_Test_Automation::status()['session_id']??''),'scenarios'=>array(),'baseline_restored'=>true,'stopped_early'=>false);

  // Content excerpt lifecycle.
  $p=self::require_test_draft($content_id,'Content fixture');
  if(is_wp_error($p))$report['scenarios'][]=array('name'=>'content','status'=>'SKIP','baseline_restored'=>true,'reason'=>$p->get_error_message());
  else{
   $allowed=self::fixture_allowed('post_excerpt',$content_id.':post_excerpt');
   if(is_wp_error($allowed))$report['scenarios'][]=array('name'=>'content','status'=>'SKIP','baseline_restored'=>true,'reason'=>$allowed->get_error_message());
   else{
    $old=(string)get_post_field('post_excerpt',$content_id);$new=$old.($old!==''?' ':'').'WP AI Admin '.$run_id.' fixture';
    $req=new WP_REST_Request('POST','/okean-ai/v1/content/patch');$req->set_param('post_id',$content_id);$req->set_param('field','post_excerpt');$req->set_param('old_content',$old);$req->set_param('new_content',$new);$req->set_param('replace_all',false);
    $report['scenarios'][]=self::lifecycle('content',OAI_REST::content_patch($req),'content.patch',$client_id,$user_id);
   }
  }

  // Stop immediately if a previous live scenario cannot prove restoration.
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  // Woo lifecycle intentionally changes quantity only; this is the regression
  // for stock_quantity -> stock_status runtime normalization.
  if(!$report['stopped_early']){
   $allowed=self::fixture_allowed('woocommerce_product',$product_id.':product');
   if(is_wp_error($allowed))$report['scenarios'][]=array('name'=>'woocommerce','status'=>'SKIP','baseline_restored'=>true,'reason'=>$allowed->get_error_message());
   else{
    $before=OAI_WooCommerce::get($product_id);
    if(is_wp_error($before)||empty($before['manage_stock'])||$before['stock']===null)$report['scenarios'][]=array('name'=>'woocommerce','status'=>'SKIP','baseline_restored'=>true,'reason'=>'Woo fixture must use managed numeric stock');
    else{
     $new_stock=((int)$before['stock'])+1;
     $req=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');$req->set_param('product_id',$product_id);$req->set_param('changes',array('stock_quantity'=>$new_stock));
     $report['scenarios'][]=self::lifecycle('woocommerce',OAI_REST::woocommerce_patch($req),'woocommerce.update_product_sandbox',$client_id,$user_id);
    }
   }
  }
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  // Elementor HTML widget lifecycle.
  if(!$report['stopped_early']){
   $p=self::require_test_draft($elementor_id,'Elementor fixture');
   $allowed=self::fixture_allowed('elementor_widget_html',$elementor_id.':'.$widget);
   if(is_wp_error($p)||is_wp_error($allowed))$report['scenarios'][]=array('name'=>'elementor','status'=>'SKIP','baseline_restored'=>true,'reason'=>is_wp_error($p)?$p->get_error_message():$allowed->get_error_message());
   else{
    $ref=OAI_Elementor::find_node($elementor_id,$widget);
    if(is_wp_error($ref)||($ref['node']['widgetType']??'')!=='html')$report['scenarios'][]=array('name'=>'elementor','status'=>'SKIP','baseline_restored'=>true,'reason'=>is_wp_error($ref)?$ref->get_error_message():'Fixture widget is not HTML');
    else{
     $old=(string)($ref['node']['settings']['html']??'');$new=$old."\n<!-- wp-ai-admin-regression ".$run_id." -->";
     $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-widget');$req->set_param('post_id',$elementor_id);$req->set_param('widget_id',$widget);$req->set_param('old_html',$old);$req->set_param('new_html',$new);
     $report['scenarios'][]=self::lifecycle('elementor',OAI_REST::elementor_patch_widget($req),'elementor.patch_widget',$client_id,$user_id);
    }
   }
  }
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_elementor_settings)$report['scenarios'][]=self::elementor_settings_scenario($elementor_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_elementor_create)$report['scenarios'][]=self::elementor_create_scenario($run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  // File lifecycle inside the fixed Test Automation prefix. The write path may
  // create missing intermediate directories, but only inside the normal theme scope.
  if(!$report['stopped_early']&&$include_file){
   $path=OAI_Test_Automation::FILE_PREFIX.'regression-'.$run_id.'.txt';$target='child_theme:'.$path;$allowed=self::fixture_allowed('file_write',$target);
   if(is_wp_error($allowed))$report['scenarios'][]=array('name'=>'file','status'=>'SKIP','baseline_restored'=>true,'reason'=>$allowed->get_error_message());
   else{
    $req=new WP_REST_Request('POST','/okean-ai/v1/files/write');$req->set_param('scope','child_theme');$req->set_param('path',$path);$req->set_param('content','WP AI Admin '.$run_id." file fixture.\n");
    $report['scenarios'][]=self::lifecycle('file',OAI_REST::files_write($req),'file.write_sandbox',$client_id,$user_id);
   }
  }
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$gutenberg_id)$report['scenarios'][]=self::gutenberg_scenario($gutenberg_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_reject)$report['scenarios'][]=self::reject_cycle($content_id,$run_id,$client_id,$user_id);

  $pass=0;$fail=0;$skip=0;foreach($report['scenarios'] as $s){$st=$s['status']??'FAIL';if($st==='PASS')$pass++;elseif($st==='SKIP')$skip++;else{$fail++;if(empty($s['baseline_restored']))$report['baseline_restored']=false;}}
  $report['summary']=array('passed'=>$pass,'failed'=>$fail,'skipped'=>$skip,'total'=>count($report['scenarios']));
  $report['finished_at']=gmdate('c');$report['duration_ms']=(int)round((microtime(true)-$started)*1000);$report['status']=$fail===0&&$report['baseline_restored']?'PASS':'FAIL';
  OAI_Test_Automation::store_last_regression(array('run_id'=>$run_id,'status'=>$report['status'],'summary'=>$report['summary'],'baseline_restored'=>$report['baseline_restored'],'finished_at'=>$report['finished_at'],'build_id'=>OAI_BUILD_ID));
  if(class_exists('OAI_Regression_History'))OAI_Regression_History::record('regression_run',$report);
  return $report;
 }
}
