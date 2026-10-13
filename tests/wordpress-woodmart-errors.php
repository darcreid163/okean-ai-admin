<?php
if(getenv('WPAI_DISPOSABLE_TEST')!=='1')throw new RuntimeException('Explicit disposable test environment required');
wp_set_current_user(1);
function wm_error_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function wm_error_plan($id,$value){$r=new WP_REST_Request('POST','/okean-ai/v1/woodmart/sandbox');$r->set_param('id',$id);$r->set_param('value',$value);return OAI_Woodmart::sandbox($r);}
global $wpdb;$before=get_option('xts-woodmart-options');$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table());
$original=\XTS\Admin\Modules\Options::$fields;
try{
 foreach(array('null'=>null,'false'=>false,'callback'=>function(){throw new RuntimeException('Must never execute unknown choice callback');}) as $suffix=>$options){
  $id='qa-choices-'.$suffix;\XTS\Admin\Modules\Options::$fields[]=new \XTS\Admin\Modules\OAI_CI_Field(array('id'=>$id,'type'=>'select','options'=>$options,'default'=>''));
  $descriptor=OAI_Woodmart::get_setting($id);wm_error_expect(is_wp_error($descriptor)||empty($descriptor['writable']),'Undeclared choices advertised writable');
  $denied=wm_error_plan($id,'__invalid_qa_header__');wm_error_expect(is_wp_error($denied)&&($denied->get_error_data()['status']??0)>=400,'Undeclared choice write must fail structurally');
 }
 $field=new class(array('id'=>'qa-native-throw','type'=>'text_input','default'=>'')) extends \XTS\Admin\Modules\OAI_CI_Field{public function sanitize($value){throw new RuntimeException('simulated native exception with private path marker');}};
 \XTS\Admin\Modules\Options::$fields[]=$field;
 $denied=wm_error_plan('qa-native-throw','value');wm_error_expect(is_wp_error($denied)&&$denied->get_error_code()==='oai_woodmart_value','Native sanitizer exception must return a stable validation error');wm_error_expect(strpos($denied->get_error_message(),'private path')===false,'Native exception leaked');
 wm_error_expect(get_option('xts-woodmart-options')===$before,'Invalid request changed theme settings');wm_error_expect((int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table())===$count,'Invalid request created an operation');
 echo "PASS Woodmart null/false/callable choices and sanitizer exceptions fail structurally with no write or operation\n";
}finally{\XTS\Admin\Modules\Options::$fields=$original;}
