<?php
/** Disposable real-WP lifecycle with actual WoodMart MCP 1.0.1 and minimal theme API fixture. */
function oai_wm_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function oai_wm_ok($result,$message){oai_wm_expect(!is_wp_error($result),$message.(is_wp_error($result)?': '.$result->get_error_code().' '.wp_json_encode($result->get_error_data()):''));return $result;}
function oai_wm_plan($id,$value){$r=new WP_REST_Request('POST','/okean-ai/v1/woodmart/setting-sandbox');$r->set_param('id',$id);$r->set_param('value',$value);return OAI_Woodmart::sandbox($r);}
function oai_wm_action($id,$action){return OAI_AI_Lifecycle_Control::perform($id,$action,'disposable-woodmart-ci',1);}
function oai_wm_complete($id){oai_wm_ok(oai_wm_action($id,'approve'),'approve');oai_wm_ok(oai_wm_action($id,'apply'),'apply');oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'apply verified');oai_wm_ok(oai_wm_action($id,'rollback'),'rollback');oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'rollback verified');}
wp_set_current_user(1);
$phase=getenv('OAI_WOODMART_PHASE');
if($phase==='plan'){
 oai_wm_expect(OAI_Woodmart::available(),'adapter available');
 oai_wm_ok(OAI_AI_Lifecycle_Control::enable(1),'enable lifecycle');
 $baseline=array('primary-color'=>array('idle'=>'#111111'),'spacing'=>woodmart_compress(wp_json_encode(array('devices'=>array('desktop'=>array('top'=>'12'),'tablet'=>array('top'=>'7'),'mobile'=>array('top'=>'4'))))),
  'font'=>array(array('font-family'=>'Arial','font-size'=>1.0)),'mode'=>'wide','count'=>'2','google_api_key'=>'DO_NOT_EXPOSE_CI','unrelated'=>array('enabled'=>true,'categories'=>array('b','a')),
  'ci-preset'=>array('primary-color'=>array('idle'=>'#222222')));
 update_option('xts-woodmart-options',$baseline);update_option('xts-options-presets',array('ci-preset'=>array('name'=>'CI preset','condition'=>array('type'=>'page'))));
 update_option('oai_ci_preset_active',true,false);OAI_Woodmart::refresh();
 $d=oai_wm_ok(OAI_Woodmart::get_setting('primary-color'),'native descriptor');
 oai_wm_expect($d['current_value']['idle']==='#222222'&&$d['base_value']['idle']==='#111111'&&$d['has_preset_overrides'],'base and preset resolution separated');
 $req=new WP_REST_Request('GET','/okean-ai/v1/woodmart/settings');$req->set_param('per_page',50);
 $list=oai_wm_ok(OAI_Woodmart::list_settings($req),'list settings');
 oai_wm_expect(strpos(wp_json_encode($list),'DO_NOT_EXPOSE_CI')===false,'no secret leakage');
 foreach(array('google_api_key','custom_js') as $id)oai_wm_expect(is_wp_error(OAI_Woodmart::get_setting($id))&&is_wp_error(oai_wm_plan($id,'blocked')),'restricted setting excluded');
 oai_wm_expect(is_wp_error(oai_wm_plan('html','<script>bad</script>')),'editor readonly');
 foreach(array(array('mode','invalid'),array('count',50),array('spacing',array('watch'=>array('top'=>1))),array('spacing','10'),array('primary-color',array('api_key'=>'hidden'))) as $input)oai_wm_expect(is_wp_error(oai_wm_plan($input[0],$input[1])),'invalid input rejected');
 $noop=oai_wm_plan('primary-color','#111111');oai_wm_expect(is_wp_error($noop)&&$noop->get_error_code()==='oai_noop','normalized no-op');
 $plans=array();foreach(array('primary-color'=>'#333333','spacing'=>array('devices'=>array('desktop'=>array('top'=>'24'))),'inherited-color'=>'#abcdef','font'=>array('font-family'=>'Inter','font-size'=>2.0)) as $id=>$value){
  $op=oai_wm_ok(oai_wm_plan($id,$value),'plan '.$id);$plans[$id]=absint($op['id']);
  oai_wm_expect($op['status']==='pending'&&$op['type']==='woodmart_theme_setting','normal pending sandbox');
 }
 oai_wm_expect(OAI_REST::same_option_value(get_option('xts-woodmart-options'),$baseline),'planning has no writes');
 oai_wm_expect((int)get_option('oai_ci_css_theme_settings_default_reset',0)===0,'planning does not invalidate CSS');
 update_option('oai_ci_woodmart_baseline',$baseline,false);update_option('oai_ci_woodmart_plans',$plans,false);
 $names=array_column(OAI_MCP::tool_definitions(),'name');oai_wm_expect(count($names)===116&&count($names)===count(array_unique($names)),'116 unique tool exports');
 oai_wm_expect(count(array_diff(OAI_Platform::expected_tools(),$names))===0,'catalog and export agree');
 foreach(OAI_MCP::tool_definitions() as $tool)if(strpos($tool['name'],'woodmart.')===0){$write=$tool['name']==='woodmart.update_setting_sandbox';oai_wm_expect(($tool['securitySchemes'][0]['scopes'][0]??'')===($write?'mcp:write':'mcp:read'),'Woodmart OAuth scope');}
 $route=rest_do_request(new WP_REST_Request('GET','/okean-ai/v1/woodmart/status'));oai_wm_expect($route->get_status()===200&&!empty($route->get_data()['available']),'REST status route');
}elseif($phase==='apply'){
 $plans=get_option('oai_ci_woodmart_plans');$id=$plans['primary-color'];
 oai_wm_ok(oai_wm_action($id,'approve'),'cold approve');oai_wm_ok(oai_wm_action($id,'apply'),'cold apply');
 $d=oai_wm_ok(OAI_Woodmart::get_setting('primary-color'),'fresh descriptor');
 oai_wm_expect($d['base_value']['idle']==='#333333'&&$d['current_value']['idle']==='#222222','base written, preset preserved');
 oai_wm_expect((int)get_option('oai_ci_css_theme_settings_default_reset')>0&&(int)get_option('oai_ci_css_theme_settings_ci-preset_deleted')>0&&(int)get_option('oai_ci_css_icons_fonts_deleted')>0,'native CSS invalidation');
 oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'native apply verified');
}elseif($phase==='rollback'){
 $plans=get_option('oai_ci_woodmart_plans');$id=$plans['primary-color'];oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'cold apply verification');
 $applied=get_option('xts-woodmart-options');$newer=$applied;$newer['mode']='boxed';update_option('xts-woodmart-options',$newer);
 $blocked=oai_wm_action($id,'rollback');oai_wm_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_rollback_conflict','unrelated newer edit blocks rollback');
 oai_wm_expect(get_option('xts-woodmart-options')===$newer,'newer state preserved');update_option('xts-woodmart-options',$applied);
 oai_wm_ok(oai_wm_action($id,'rollback'),'cold rollback');oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'rollback verified');
}elseif($phase==='verify'){
 $baseline=get_option('oai_ci_woodmart_baseline');$plans=get_option('oai_ci_woodmart_plans');
 oai_wm_expect(!empty(OAI_Verify::operation($plans['primary-color'])['verified']),'cold rollback verification');
 oai_wm_expect(OAI_REST::same_option_value(get_option('xts-woodmart-options'),$baseline),'entire baseline restored');
 foreach(array('spacing','inherited-color','font') as $field){
  $id=$plans[$field];oai_wm_ok(oai_wm_action($id,'approve'),'approve '.$field);oai_wm_ok(oai_wm_action($id,'apply'),'apply '.$field);
  if($field==='spacing'){$d=OAI_Woodmart::get_setting($field);oai_wm_expect($d['base_value']['devices']['desktop']['top']===24&&$d['base_value']['devices']['tablet']['top']==='7'&&$d['base_value']['devices']['mobile']['top']==='4','unspecified devices preserved');}
  oai_wm_ok(oai_wm_action($id,'rollback'),'rollback '.$field);oai_wm_expect(!empty(OAI_Verify::operation($id)['verified']),'verified '.$field);
  oai_wm_expect(OAI_REST::same_option_value(get_option('xts-woodmart-options'),$baseline),'exact raw baseline '.$field);
 }
 $op=oai_wm_ok(oai_wm_plan('primary-color','#444444'),'conflict plan');oai_wm_ok(oai_wm_action($op['id'],'approve'),'conflict approve');
 $changed=$baseline;$changed['unrelated']['enabled']=1;update_option('xts-woodmart-options',$changed);
 $blocked=oai_wm_action($op['id'],'apply');oai_wm_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_conflict','unrelated type conflict');oai_wm_expect(get_option('xts-woodmart-options')===$changed,'conflict keeps newer state');update_option('xts-woodmart-options',$baseline);
 $op=oai_wm_ok(oai_wm_plan('primary-color','#444444'),'preset conflict plan');oai_wm_ok(oai_wm_action($op['id'],'approve'),'preset approve');
 $presets=get_option('xts-options-presets');$newpresets=$presets;$newpresets['ci-preset']['condition']['type']='product';update_option('xts-options-presets',$newpresets);
 $blocked=oai_wm_action($op['id'],'apply');oai_wm_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_conflict','preset condition conflict');update_option('xts-options-presets',$presets);
 // Native full-form sanitizer drift is blocked before any sandbox snapshot/write.
 $filter=function($value){$value['unrelated']['enabled']=false;return $value;};add_filter('sanitize_option_xts-woodmart-options',$filter);
 oai_wm_expect(is_wp_error(oai_wm_plan('primary-color','#555555')),'whole-option sanitizer drift blocked');remove_filter('sanitize_option_xts-woodmart-options',$filter);
 // A callback that unexpectedly edits another field is reverted atomically.
 $op=oai_wm_ok(oai_wm_plan('primary-color','#555555'),'atomic failure plan');oai_wm_ok(oai_wm_action($op['id'],'approve'),'atomic failure approve');
 $filter=function($value){$value['unrelated']['enabled']=false;return $value;};add_filter('pre_update_option_xts-woodmart-options',$filter);
 $blocked=oai_wm_action($op['id'],'apply');remove_filter('pre_update_option_xts-woodmart-options',$filter);
 oai_wm_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_woodmart_write','unexpected native write rejected');
 oai_wm_expect(OAI_REST::same_option_value(get_option('xts-woodmart-options'),$baseline),'transaction reverted unexpected write');
 oai_wm_expect(OAI_DB::get($op['id'])['status']==='apply_failed','no false rollback failure after transactional revert');
 // Legacy per-device scalar must be restored byte-for-byte, without re-encoding.
 $legacy=$baseline;$legacy['spacing']='13';update_option('xts-woodmart-options',$legacy);
 $op=oai_wm_ok(oai_wm_plan('spacing',array('desktop'=>array('top'=>'16'))),'legacy device plan');oai_wm_complete($op['id']);
 oai_wm_expect(get_option('xts-woodmart-options')['spacing']==='13','legacy scalar exact restore');update_option('xts-woodmart-options',$baseline);
 // Permission gate is independent of public native callback accessibility.
 wp_set_current_user(0);oai_wm_expect(is_wp_error(OAI_Woodmart::get_setting('primary-color'))&&is_wp_error(oai_wm_plan('primary-color','#555555')),'permission denied');wp_set_current_user(1);
}else{throw new RuntimeException('Unknown Woodmart phase');}
echo 'Woodmart native MCP 1.0.1 / minimal theme fixture '.$phase.': PASS'.PHP_EOL;
