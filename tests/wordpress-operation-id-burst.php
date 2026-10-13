<?php
if(getenv('WPAI_DISPOSABLE_TEST')!=='1')throw new RuntimeException('Explicit disposable test environment required');
wp_set_current_user(1);
function burst_ok($value,$label){if(is_wp_error($value))throw new RuntimeException($label.': '.$value->get_error_code());return $value;}
function burst_expect($ok,$label){if(!$ok)throw new RuntimeException($label);}
burst_ok(OAI_License::activate_local_owner(1),'license');
$prior_ai=get_option(OAI_AI_Lifecycle_Control::OPTION,array());$prior_workspace=get_option('oai_active_workspace',null);
burst_ok(OAI_AI_Lifecycle_Control::enable(1),'explicit disposable lifecycle consent');
$post=burst_ok(wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'WP AI Admin TEST — ID Burst','post_excerpt'=>'ID burst baseline'),true),'content fixture');
$workspace=burst_ok(OAI_Workspace::create(false),'isolated workspace');$workspace_id=$workspace['id'];$ids=array();$operations=array();$seed=bin2hex(random_bytes(4));
$remember=function($value,$label)use(&$operations){$value=burst_ok($value,$label);$operations[]=(int)$value['id'];return $value;};
try{
 for($i=0;$i<4;$i++){
  $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');$req->set_param('type','option_update');$req->set_param('target','wpai_id_burst_'.$seed.'_'.$i);$req->set_param('value','probe');$ops=array($remember(OAI_REST::sandbox_create($req),'generic creator'));
  $req=new WP_REST_Request('POST','/okean-ai/v1/content/patch');$req->set_param('post_id',$post);$req->set_param('field','post_excerpt');$req->set_param('old_content','ID burst baseline');$req->set_param('new_content','ID burst '.$i);$ops[]=$remember(OAI_REST::content_patch($req),'patch creator');
  $req=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');$req->set_param('title','WP AI Admin TEST — ID Burst '.$seed.' '.$i);$req->set_param('final_status','draft');$req->set_param('data',array(array('id'=>'burstc'.$i,'elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>'burstw'.$i,'elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>'<p>ID burst fixture</p>'),'elements'=>array())))));$ops[]=$remember(OAI_REST::elementor_create_page($req),'Elementor creator');
  $ops[]=$remember(OAI_P0::menu_create(array('name'=>'WP AI Admin TEST — ID Burst '.$seed.' '.$i)),'P0 creator');
  $req=new WP_REST_Request('POST','/okean-ai/v1/workspace/write');$req->set_param('workspace_id',$workspace_id);$req->set_param('path','burst-'.$i.'.txt');$req->set_param('content','Isolated draft only');$ops[]=$remember(OAI_REST::workspace_write($req),'workspace-file creator');
  foreach($ops as $op){$dbid=(int)$op['id'];$row=OAI_DB::get($dbid);$id=$row['operation_id'];burst_expect(strpos($id,'OAI-')===0&&strlen($id)>40&&strlen($id)<=64,'Creator still uses a short or schema-incompatible identifier');$ids[]=$id;burst_ok(OAI_AI_Lifecycle_Control::perform($dbid,'reject','qa38-id-burst',1),'reject planned operation');burst_expect(OAI_DB::get($dbid)['status']==='rejected','Burst operation did not finish rejected');}
 }
 burst_expect(count($ids)===20&&count(array_unique($ids))===20,'Operation creators produced duplicate IDs');burst_expect(get_post_field('post_excerpt',$post)==='ID burst baseline','Planning changed content');
 echo "PASS 20 ledger operations from all five creators have unique long IDs and finish rejected without applying live content\n";
}finally{
 foreach($operations as $id){$row=OAI_DB::get($id);if($row&&$row['status']==='pending')OAI_AI_Lifecycle_Control::perform($id,'reject','qa38-id-burst',1);}
 wp_delete_post($post,true);
 $directory=OAI_Workspace::root().'/'.$workspace_id;burst_expect(strpos($workspace_id,'ws-')===0&&dirname($directory)===OAI_Workspace::root(),'Workspace cleanup boundary invalid');$remove=new ReflectionMethod('OAI_Workspace','remove_tree');$remove->invoke(null,$directory);
 if($prior_workspace===null)delete_option('oai_active_workspace');else update_option('oai_active_workspace',$prior_workspace,false);
 update_option(OAI_AI_Lifecycle_Control::OPTION,$prior_ai,false);
}
