<?php
/** Disposable subprocess: same-key concurrent workspace creation. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable localhost WordPress only');
$actor=(int)getenv('OAI_WORKSPACE_TEST_ACTOR');
$key=(string)getenv('OAI_WORKSPACE_TEST_KEY');
if($actor<1||!preg_match('/^workspace-parallel-[a-z0-9]{16}$/D',$key))throw new RuntimeException('Invalid disposable fixture identity');
wp_set_current_user($actor);
$out=OAI_Workspace::create(false,$key);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>$out['id']]);
