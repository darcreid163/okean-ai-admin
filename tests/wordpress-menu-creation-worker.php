<?php
/** Disposable real WordPress concurrent menu creation worker. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Loopback only');
$actor=(int)getenv('OAI_MENU_TEST_ACTOR');
$token=(string)getenv('OAI_MENU_TEST_TOKEN');
if($actor<1||!preg_match('/^[a-f0-9]{16}$/D',$token))throw new RuntimeException('Invalid menu worker fixture');
wp_set_current_user($actor);
$out=OAI_P0::menu_create(['name'=>'WP AI Menu Parallel '.$token,'idempotency_key'=>'menu-parallel-'.$token]);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>(int)$out['id'],'target'=>(string)$out['target']]);
