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
 private static function apply_step(&$scenario,$label,$op,$tool,$client_id,$user_id){
  if(is_wp_error($op)){$scenario['error']=self::err($op);self::stage($scenario,$label,'FAIL');return 0;}
  $id=self::tag($op,$tool,$client_id,$user_id);
  if(is_wp_error($id)){$scenario['error']=self::err($id);self::stage($scenario,$label,'FAIL');return 0;}
  if(!isset($scenario['operation_ids'])||!is_array($scenario['operation_ids']))$scenario['operation_ids']=array();
  $scenario['operation_ids'][$label]=$id;
  $approve=OAI_Test_Automation::perform($id,'approve',$client_id,$user_id);
  if(is_wp_error($approve)){$scenario['error']=self::err($approve);self::stage($scenario,$label,'FAIL');return 0;}
  $apply=OAI_Test_Automation::perform($id,'apply',$client_id,$user_id);
  if(is_wp_error($apply)){$scenario['error']=self::err($apply);self::stage($scenario,$label,'FAIL');return 0;}
  $verify=OAI_Verify::operation($id);
  if(is_wp_error($verify)||empty($verify['verified'])){$scenario['error']=self::err(is_wp_error($verify)?$verify:'Apply verification failed');self::stage($scenario,$label,'FAIL');return 0;}
  self::stage($scenario,$label,'PASS');
  return $id;
 }
 private static function cleanup_steps(&$scenario,$ids,$client_id,$user_id){
  $ok=true;$seen=array();
  foreach(array_reverse((array)$ids) as $id){
   $id=absint($id);if(!$id||isset($seen[$id]))continue;$seen[$id]=true;
   $row=OAI_DB::get($id);if(!$row)continue;
   $status=(string)($row['status']??'');$type=(string)($row['type']??'');$target=(string)($row['target']??'');
   $meta=json_decode((string)($row['meta_data']??''),true);if(!is_array($meta))$meta=array();
   if(in_array($status,array('applied','rollback_failed'),true)){
    $rb=OAI_Test_Automation::perform($id,'rollback',$client_id,$user_id);
    if(is_wp_error($rb)){
     if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($type))OAI_P0::cleanup_artifact($type,$target,$meta);
     $ok=false;continue;
    }
    $rv=OAI_Verify::operation($id,null,'rollback');
    if(is_wp_error($rv)||empty($rv['verified']))$ok=false;
   }elseif(in_array($status,array('pending','approved'),true)){
    $reject=OAI_Test_Automation::perform($id,'reject',$client_id,$user_id);
    if(is_wp_error($reject))$ok=false;
    if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($type))OAI_P0::cleanup_artifact($type,$target,$meta);
   }elseif(in_array($status,array('rolled_back','rejected','expired'),true)){
    if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($type))OAI_P0::cleanup_artifact($type,$target,$meta);
   }else{
    if(class_exists('OAI_P0')&&OAI_P0::is_created_artifact($type))OAI_P0::cleanup_artifact($type,$target,$meta);
    $ok=false;
   }
  }
  $scenario['baseline_restored']=$ok;self::stage($scenario,'cleanup',$ok?'PASS':'FAIL');
  return $ok;
 }
 private static function woo_create_scenario($run_id,$client_id,$user_id){
  $snapshot=OAI_WooCommerce::create_draft(array(
   'type'=>'variable','name'=>'WP AI Admin TEST — '.$run_id,'catalog_visibility'=>'hidden',
   'variations'=>array(
    array('regular_price'=>'13.00','manage_stock'=>true,'stock_quantity'=>2,'stock_status'=>'instock'),
    array('regular_price'=>'27.00','manage_stock'=>true,'stock_quantity'=>4,'stock_status'=>'instock')
   )
  ));
  if(is_wp_error($snapshot))return array('name'=>'woocommerce_create','status'=>'FAIL','baseline_restored'=>true,'error'=>self::err($snapshot),'stages'=>array('create'=>'FAIL'));
  return self::lifecycle('woocommerce_create',OAI_P0::op_for_created_product($snapshot),'woocommerce.create_product_sandbox',$client_id,$user_id);
 }
 private static function elementor_container_scenario($post_id,$container_id,$run_id,$client_id,$user_id){
  if($container_id==='')return array('name'=>'elementor_container','status'=>'SKIP','baseline_restored'=>true,'reason'=>'elementor_container_id not supplied');
  $p=self::require_test_draft($post_id,'Elementor container fixture');if(is_wp_error($p))return array('name'=>'elementor_container','status'=>'SKIP','baseline_restored'=>true,'reason'=>$p->get_error_message());
  $container=OAI_Elementor::container($post_id,$container_id);if(is_wp_error($container))return array('name'=>'elementor_container','status'=>'SKIP','baseline_restored'=>true,'reason'=>$container->get_error_message());
  $settings=is_array($container['settings']??null)?$container['settings']:array();$current=(string)($settings['content_width']??'');$next=$current==='full'?'boxed':'full';
  $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch');$req->set_param('target',$post_id.':'.$container_id);$req->set_param('changes',array('content_width'=>$next));
  return self::lifecycle('elementor_container',OAI_REST::elementor_patch($req),'elementor.patch_container_settings',$client_id,$user_id);
 }
 private static function elementor_page_layout_scenario($post_id,$run_id,$client_id,$user_id){
  $p=self::require_test_draft($post_id,'Elementor page-layout fixture');if(is_wp_error($p))return array('name'=>'elementor_page_layout','status'=>'SKIP','baseline_restored'=>true,'reason'=>$p->get_error_message());
  $before=OAI_Elementor::page_layout($post_id);if(is_wp_error($before))return array('name'=>'elementor_page_layout','status'=>'SKIP','baseline_restored'=>true,'reason'=>$before->get_error_message());
  $current=(string)($before['page_template']??'default');$next=$current==='elementor_header_footer'?'elementor_canvas':'elementor_header_footer';
  return self::lifecycle('elementor_page_layout',OAI_P0::elementor_page_layout(array('post_id'=>$post_id,'page_template'=>$next)),'elementor.patch_page_layout',$client_id,$user_id);
 }
 private static function option_scenario($run_id,$client_id,$user_id){
  $key='oai_test_matrix_'.substr(md5($run_id),0,10);
  $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');$req->set_param('type','option_update');$req->set_param('target',$key);$req->set_param('value','WP AI Admin '.$run_id);
  return self::lifecycle('option_update',OAI_REST::sandbox_create($req),'wp.option_update_sandbox',$client_id,$user_id);
 }
 private static function media_suite_scenario($post_id,$run_id,$client_id,$user_id){
  $scenario=array('name'=>'media_suite','status'=>'FAIL','baseline_restored'=>false,'stages'=>array(),'operation_ids'=>array());
  $png='iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8//8/AwMDAwMTAxQAADAGAwG63ES+AAAAAElFTkSuQmCC';
  $upload=OAI_P0::media_upload(array('filename'=>'wp-ai-admin-'.sanitize_file_name($run_id).'.png','content_base64'=>$png,'mime_type'=>'image/png','title'=>'WP AI Admin '.$run_id,'alt'=>'baseline'));
  $uid=self::apply_step($scenario,'media_upload',$upload,'media.upload_sandbox',$client_id,$user_id);
  $aid=$uid&&is_array($upload)?absint($upload['target']??0):0;
  if($uid&&$aid)self::apply_step($scenario,'media_alt',OAI_P0::media_update_alt(array('attachment_id'=>$aid,'alt'=>'WP AI Admin alt '.$run_id)),'media.update_alt_sandbox',$client_id,$user_id);
  if(empty($scenario['error'])&&$aid)self::apply_step($scenario,'media_title',OAI_P0::media_update_title(array('attachment_id'=>$aid,'title'=>'WP AI Admin title '.$run_id)),'media.update_title_sandbox',$client_id,$user_id);
  if(empty($scenario['error'])&&$aid)self::apply_step($scenario,'media_featured',OAI_P0::media_set_featured(array('post_id'=>$post_id,'attachment_id'=>$aid)),'media.set_featured_sandbox',$client_id,$user_id);
  $clean=self::cleanup_steps($scenario,array_values($scenario['operation_ids']),$client_id,$user_id);
  if(empty($scenario['error'])&&$clean&&($scenario['stages']['media_upload']??'')==='PASS'&&($scenario['stages']['media_alt']??'')==='PASS'&&($scenario['stages']['media_title']??'')==='PASS'&&($scenario['stages']['media_featured']??'')==='PASS'){$scenario['status']='PASS';$scenario['terminal_status']='rolled_back';}
  return $scenario;
 }
 private static function menu_suite_scenario($run_id,$client_id,$user_id){
  $scenario=array('name'=>'menu_suite','status'=>'FAIL','baseline_restored'=>false,'stages'=>array(),'operation_ids'=>array());
  $create=OAI_P0::menu_create(array('name'=>'WP AI Admin TEST '.$run_id));
  $cid=self::apply_step($scenario,'menu_create',$create,'menu.create_sandbox',$client_id,$user_id);$menu_id=$cid&&is_array($create)?absint($create['target']??0):0;
  $a1=0;$a2=0;$item1=0;$item2=0;
  if($cid&&$menu_id){$op1=OAI_P0::menu_add_item(array('menu_id'=>$menu_id,'title'=>'Test Home','url'=>home_url('/')));$a1=self::apply_step($scenario,'menu_add_item_1',$op1,'menu.add_item_sandbox',$client_id,$user_id);$item1=$a1&&is_array($op1)?absint($op1['target']??0):0;}
  if(empty($scenario['error'])&&$menu_id){$op2=OAI_P0::menu_add_item(array('menu_id'=>$menu_id,'title'=>'Test Contact','url'=>home_url('/kontakty/')));$a2=self::apply_step($scenario,'menu_add_item_2',$op2,'menu.add_item_sandbox',$client_id,$user_id);$item2=$a2&&is_array($op2)?absint($op2['target']??0):0;}
  if(empty($scenario['error'])&&$item1&&$item2)self::apply_step($scenario,'menu_move_item',OAI_P0::menu_move_item(array('item_id'=>$item2,'parent'=>$item1,'position'=>1)),'menu.move_item_sandbox',$client_id,$user_id);
  if(empty($scenario['error'])&&$item2)self::apply_step($scenario,'menu_remove_item',OAI_P0::menu_remove_item(array('item_id'=>$item2)),'menu.remove_item_sandbox',$client_id,$user_id);
  $clean=self::cleanup_steps($scenario,array_values($scenario['operation_ids']),$client_id,$user_id);
  if(empty($scenario['error'])&&$clean&&($scenario['stages']['menu_create']??'')==='PASS'&&($scenario['stages']['menu_add_item_1']??'')==='PASS'&&($scenario['stages']['menu_add_item_2']??'')==='PASS'&&($scenario['stages']['menu_move_item']??'')==='PASS'&&($scenario['stages']['menu_remove_item']??'')==='PASS'){$scenario['status']='PASS';$scenario['terminal_status']='rolled_back';}
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

 private static function elementor_create_patch_many_scenario($run_id,$client_id,$user_id){
  $scenario=array('name'=>'elementor_create_patch_many','status'=>'FAIL','baseline_restored'=>false,'stages'=>array(),'patch_operations'=>array());
  $container='multi'.substr(md5($run_id),0,3);$widget='html'.substr(md5($run_id.'widget'),0,3);
  $baseline='<section data-wpai-multi="'.esc_attr($run_id).'"><h2>Create once</h2><p>Baseline fragment</p></section>';
  $data=array(array('id'=>$container,'elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>$widget,'elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>$baseline),'elements'=>array()))));
  $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');$req->set_param('title','WP AI Admin MULTI — '.$run_id);$req->set_param('data',$data);$req->set_param('page_template','elementor_canvas');$req->set_param('final_status','draft');
  $op=OAI_REST::elementor_create_page($req);
  if(is_wp_error($op)){$scenario['error']=self::err($op);self::stage($scenario,'create','FAIL');return $scenario;}
  $create_id=self::tag($op,'elementor.create_draft',$client_id,$user_id);
  if(is_wp_error($create_id)){$scenario['error']=self::err($create_id);return $scenario;}
  $page_id=absint($op['preview_page_id']??0);$scenario['operation_id']=$create_id;$scenario['page_id']=$page_id;$scenario['widget_id']=$widget;
  self::stage($scenario,'create','PASS');self::stage($scenario,'tag','PASS');
  $approve=OAI_Test_Automation::perform($create_id,'approve',$client_id,$user_id);if(is_wp_error($approve)){$scenario['error']=self::err($approve);return $scenario;}self::stage($scenario,'approve','PASS');
  $apply=OAI_Test_Automation::perform($create_id,'apply',$client_id,$user_id);if(is_wp_error($apply)){$scenario['error']=self::err($apply);return $scenario;}self::stage($scenario,'apply','PASS');
  $verify=OAI_Verify::operation($create_id);if(is_wp_error($verify)||empty($verify['verified'])){$scenario['error']=self::err(is_wp_error($verify)?$verify:'Create verification failed');return $scenario;}self::stage($scenario,'verify','PASS');

  $applied=array();$current=$baseline;$labels=array('Patch one','Patch two','Patch three');
  foreach($labels as $index=>$label){
   $ref=OAI_Elementor::find_node($page_id,$widget);
   if(is_wp_error($ref)){$scenario['error']=self::err($ref);break;}
   $stored=(string)($ref['node']['settings']['html']??'');
   if($stored!==$current){$scenario['error']=self::err('Stored HTML diverged before patch '.($index+1));break;}
   $next=str_replace($index===0?'Baseline fragment':$labels[$index-1],$label,$current);
   $pr=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-fragment');$pr->set_param('post_id',$page_id);$pr->set_param('widget_id',$widget);$pr->set_param('expected_sha256',hash('sha256',$stored));$pr->set_param('old_fragment',$index===0?'Baseline fragment':$labels[$index-1]);$pr->set_param('new_fragment',$label);
   $patch=OAI_REST::elementor_patch_fragment($pr);
   if(is_wp_error($patch)){$scenario['error']=self::err($patch);break;}
   $pid=self::tag($patch,'elementor.patch_fragment',$client_id,$user_id);if(is_wp_error($pid)){$scenario['error']=self::err($pid);break;}
   $scenario['patch_operations'][]=$pid;
   $a=OAI_Test_Automation::perform($pid,'approve',$client_id,$user_id);if(is_wp_error($a)){$scenario['error']=self::err($a);break;}
   $ap=OAI_Test_Automation::perform($pid,'apply',$client_id,$user_id);if(is_wp_error($ap)){$scenario['error']=self::err($ap);break;}
   $vv=OAI_Verify::operation($pid);if(is_wp_error($vv)||empty($vv['verified'])){$scenario['error']=self::err(is_wp_error($vv)?$vv:'Patch verification failed');break;}
   $tree=OAI_Elementor::get_data($page_id);$node=OAI_Elementor::find_node($page_id,$widget);$html=is_wp_error($node)?'':(string)($node['node']['settings']['html']??'');
   $count=0;$walk=function($nodes)use(&$walk,&$count,$widget){foreach((array)$nodes as $n){if(!is_array($n))continue;if(($n['id']??'')===$widget)$count++;if(!empty($n['elements']))$walk($n['elements']);}};$walk($tree);
   if($count!==1 || substr_count($html,$label)!==1){$scenario['error']=self::err('Duplicate or missing Elementor widget/content after patch '.($index+1));break;}
   self::stage($scenario,'patch_'.($index+1),'PASS');$applied[]=$pid;$current=$next;
  }

  $cleanup_ok=true;
  foreach(array_reverse($applied) as $pid){$rb=OAI_Test_Automation::perform($pid,'rollback',$client_id,$user_id);if(is_wp_error($rb)){$cleanup_ok=false;break;}$rv=OAI_Verify::operation($pid,null,'rollback');if(is_wp_error($rv)||empty($rv['verified'])){$cleanup_ok=false;break;}}
  if($cleanup_ok){$rb=OAI_Test_Automation::perform($create_id,'rollback',$client_id,$user_id);if(is_wp_error($rb))$cleanup_ok=false;else{$rv=OAI_Verify::operation($create_id,null,'rollback');if(is_wp_error($rv)||empty($rv['verified']))$cleanup_ok=false;}}
  $scenario['baseline_restored']=$cleanup_ok;self::stage($scenario,'cleanup',$cleanup_ok?'PASS':'FAIL');
  if(empty($scenario['error']) && count($applied)===3 && $cleanup_ok){$scenario['status']='PASS';$scenario['terminal_status']='rolled_back';}
  return $scenario;
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
  $content_id=absint($args['content_post_id']??0);$elementor_id=absint($args['elementor_post_id']??0);$widget=sanitize_text_field((string)($args['elementor_widget_id']??''));$container_id=sanitize_text_field((string)($args['elementor_container_id']??''));$product_id=absint($args['product_id']??0);$gutenberg_id=absint($args['gutenberg_post_id']??0);if(!$gutenberg_id&&class_exists('OAI_Test_Automation'))$gutenberg_id=OAI_Test_Automation::managed_gutenberg_fixture_id();
  if(!$content_id||!$elementor_id||$widget===''||!$product_id)return new WP_Error('oai_regression_args','content_post_id, elementor_post_id, elementor_widget_id and product_id are required',array('status'=>400));
  $include_file=!array_key_exists('include_file',$args)||!empty($args['include_file']);$include_reject=!array_key_exists('include_reject',$args)||!empty($args['include_reject']);$include_elementor_settings=!array_key_exists('include_elementor_settings',$args)||!empty($args['include_elementor_settings']);$include_elementor_create=!array_key_exists('include_elementor_create',$args)||!empty($args['include_elementor_create']);$include_elementor_create_patch_many=!array_key_exists('include_elementor_create_patch_many',$args)||!empty($args['include_elementor_create_patch_many']);$include_p0=!empty($args['include_p0']);
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

  // Edit one existing HTML widget through the bounded fragment contract.
  if(!$report['stopped_early']){
   $p=self::require_test_draft($elementor_id,'Elementor fragment fixture');
   $allowed=self::fixture_allowed('elementor_widget_html',$elementor_id.':'.$widget);
   $ref=OAI_Elementor::find_node($elementor_id,$widget);
   if(is_wp_error($p)||is_wp_error($allowed)||is_wp_error($ref)||($ref['node']['widgetType']??'')!=='html')$report['scenarios'][]=array('name'=>'elementor_fragment','status'=>'SKIP','baseline_restored'=>true,'reason'=>'HTML widget test fixture unavailable');
   else{
    $old=(string)($ref['node']['settings']['html']??'');
    if($old==='')$report['scenarios'][]=array('name'=>'elementor_fragment','status'=>'SKIP','baseline_restored'=>true,'reason'=>'HTML widget is empty');
    else{
     $fragment=substr($old,-min(96,strlen($old)));
     if(substr_count($old,$fragment)!==1)$report['scenarios'][]=array('name'=>'elementor_fragment','status'=>'SKIP','baseline_restored'=>true,'reason'=>'No unique trailing HTML fragment');
     else{
      $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/patch-fragment');$req->set_param('post_id',$elementor_id);$req->set_param('widget_id',$widget);$req->set_param('expected_sha256',hash('sha256',$old));$req->set_param('old_fragment',$fragment);$req->set_param('new_fragment',$fragment."\n<!-- wp-ai-admin-fragment ".$run_id." -->");
      $report['scenarios'][]=self::lifecycle('elementor_fragment',OAI_REST::elementor_patch_fragment($req),'elementor.patch_fragment',$client_id,$user_id);
     }
    }
   }
  }
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_elementor_settings)$report['scenarios'][]=self::elementor_settings_scenario($elementor_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_elementor_create)$report['scenarios'][]=self::elementor_create_scenario($run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}

  if(!$report['stopped_early']&&$include_elementor_create_patch_many)$report['scenarios'][]=self::elementor_create_patch_many_scenario($run_id,$client_id,$user_id);
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

  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::woo_create_scenario($run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}
  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::elementor_container_scenario($elementor_id,$container_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}
  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::elementor_page_layout_scenario($elementor_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}
  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::option_scenario($run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}
  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::media_suite_scenario($content_id,$run_id,$client_id,$user_id);
  foreach($report['scenarios'] as $s)if(($s['status']??'')==='FAIL'&&empty($s['baseline_restored'])){$report['baseline_restored']=false;$report['stopped_early']=true;break;}
  if(!$report['stopped_early']&&$include_p0)$report['scenarios'][]=self::menu_suite_scenario($run_id,$client_id,$user_id);
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
