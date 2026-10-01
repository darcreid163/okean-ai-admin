<?php
// Cross-request lifecycle on a disposable real WordPress, never on production.
function oai_option_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function oai_option_ok($value,$message){oai_option_expect(!is_wp_error($value),$message.(is_wp_error($value)?': '.$value->get_error_code():''));return $value;}
function oai_option_plan($key,$value){
 $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
 $req->set_param('type','option_update');$req->set_param('target',$key);$req->set_param('value',$value);
 return OAI_REST::sandbox_create($req);
}
function oai_option_action($id,$action){return OAI_AI_Lifecycle_Control::perform($id,$action,'disposable-options-ci',1);}
wp_set_current_user(1);
$phase=getenv('OAI_OPTION_PHASE');
if($phase==='plan'){
 oai_option_ok(OAI_AI_Lifecycle_Control::enable(1),'enable lifecycle');
 update_option('thumbnail_size_w',150,false);
 $op=oai_option_ok(oai_option_plan('thumbnail_size_w','0151'),'native integer planning');
 oai_option_expect($op['before_data']==='150'&&$op['after_data']==='151','persisted snapshots must be strings');
 oai_option_expect((int)get_option('thumbnail_size_w')===150,'planning must not write');
 update_option('oai_test_options_ci_operation',absint($op['id']),false);
 $noop=oai_option_plan('thumbnail_size_w','0150');
 oai_option_expect(is_wp_error($noop)&&$noop->get_error_code()==='oai_noop','native numeric no-op');
}elseif($phase==='apply'){
 $id=absint(get_option('oai_test_options_ci_operation'));
 oai_option_ok(oai_option_action($id,'approve'),'approve across request');
 oai_option_ok(oai_option_action($id,'apply'),'apply across request');
 oai_option_expect(!empty(OAI_Verify::operation($id)['verified']),'same-request apply verify');
}elseif($phase==='rollback'){
 $id=absint(get_option('oai_test_options_ci_operation'));
 oai_option_expect(!empty(OAI_Verify::operation($id)['verified']),'fresh-request apply verify');
 update_option('thumbnail_size_w',152,false);
 $blocked=oai_option_action($id,'rollback');
 oai_option_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_rollback_conflict','rollback must reject a newer value');
 oai_option_expect((int)get_option('thumbnail_size_w')===152,'rollback conflict must preserve newer value');
 // Restore only our disposable fixture to the known applied state, then retry.
 update_option('thumbnail_size_w',151,false);
 oai_option_ok(oai_option_action($id,'rollback'),'rollback retry');
 oai_option_expect(!empty(OAI_Verify::operation($id)['verified']),'same-request rollback verify');
}elseif($phase==='verify'){
 $id=absint(get_option('oai_test_options_ci_operation'));
 oai_option_expect(!empty(OAI_Verify::operation($id)['verified']),'fresh-request rollback verify');
 oai_option_expect((int)get_option('thumbnail_size_w')===150,'native baseline restored');
 update_option('oai_test_options_conflict',1,false);
 $conflict=oai_option_ok(oai_option_plan('oai_test_options_conflict',2),'conflict planning');
 oai_option_ok(oai_option_action($conflict['id'],'approve'),'conflict approve');
 update_option('oai_test_options_conflict',3,false);
 $blocked=oai_option_action($conflict['id'],'apply');
 oai_option_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_conflict','apply must reject a newer value');
 oai_option_expect((int)get_option('oai_test_options_conflict')===3,'apply conflict must preserve newer value');
 $booleans=oai_option_ok(oai_option_plan('oai_test_options_boolean',false),'boolean planning');
 oai_option_ok(oai_option_action($booleans['id'],'approve'),'boolean approve');
 oai_option_ok(oai_option_action($booleans['id'],'apply'),'boolean apply');
 oai_option_expect(!empty(OAI_Verify::operation($booleans['id'])['verified']),'boolean verify');
 oai_option_ok(oai_option_action($booleans['id'],'rollback'),'boolean rollback');
 oai_option_expect(!empty(OAI_Verify::operation($booleans['id'])['verified']),'missing baseline verify');
 update_option('oai_test_options_nested',array('count'=>1),false);
 $nested=oai_option_ok(oai_option_plan('oai_test_options_nested',array('count'=>2)),'nested planning');
 oai_option_ok(oai_option_action($nested['id'],'approve'),'nested approve');
 update_option('oai_test_options_nested',array('count'=>'1'),false);
 $blocked=oai_option_action($nested['id'],'apply');
 oai_option_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_conflict','nested type changes must remain conflicts');
 foreach(array('oai_test_options_ci_operation','oai_test_options_conflict','oai_test_options_nested') as $key)delete_option($key);
}else throw new RuntimeException('Unknown option CI phase');
echo 'Real WordPress option lifecycle passed: '.$phase.', WP '.get_bloginfo('version').', PHP '.PHP_VERSION."\n";
