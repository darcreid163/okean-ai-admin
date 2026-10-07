<?php
// Executed through WP-CLI against a disposable, fully installed WordPress.
function oai_boot_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
if(getenv('OAI_BOOT_CASE')==='seed'){
 $session=OAI_Test_Automation::enable(60,array(),array(),1);
 oai_boot_expect(!is_wp_error($session),'test session setup failed');
 $cfg=get_option(OAI_Test_Automation::OPTION);
 $id=absint($cfg['managed_gutenberg_fixture_id']);
 oai_boot_expect($id>0,'managed test fixture missing');
 $cfg['build_id']='before-upgrade-build';
 update_option(OAI_Test_Automation::OPTION,$cfg,false);
 update_option('oai_boot_test_fixture_id',$id,false);
 $ai=OAI_AI_Lifecycle_Control::enable(1);
 oai_boot_expect(!is_wp_error($ai),'AI lifecycle setup failed');
 $ai_cfg=get_option(OAI_AI_Lifecycle_Control::OPTION);
 $ai_cfg['build_id']='before-upgrade-build';
 update_option(OAI_AI_Lifecycle_Control::OPTION,$ai_cfg,false);
 echo "Real WordPress upgrade fixture seeded: ".$id."\n";
}else{
 oai_boot_expect(did_action('wp_loaded')>0,'full WordPress bootstrap required');
 $cfg=get_option(OAI_Test_Automation::OPTION);
 oai_boot_expect(empty($cfg['enabled'])&&$cfg['build_id']===OAI_BUILD_ID,'upgrade must reset old test permission');
 oai_boot_expect(!OAI_AI_Lifecycle_Control::is_active(),'upgrade must reset old AI permission');
 $id=absint(get_option('oai_boot_test_fixture_id'));
 $post=get_post($id);
 oai_boot_expect($post&&$post->post_status==='draft','fixture must remain a draft');
 oai_boot_expect($post->post_content===OAI_Test_Automation::gutenberg_fixture_content(),'deferred cleanup must restore fixture baseline');
 oai_boot_expect(get_post_meta($id,'_wpai_test_session_id',true)==='','deferred cleanup must remove stale fixture authorization');
 echo "Real WordPress upgrade bootstrap passed: WP ".get_bloginfo('version').", PHP ".PHP_VERSION.", plugin ".OAI_VERSION."\n";
}

