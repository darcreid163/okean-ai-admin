<?php
/** Disposable six-process menu-item creation worker. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable loopback required');
$token=(string)getenv('OAI_MENU_ITEM_TEST_TOKEN');$menu=(int)getenv('OAI_MENU_ITEM_TEST_MENU');
if(!preg_match('/^[a-f0-9]{16}$/D',$token)||$menu<1)throw new RuntimeException('Invalid menu item fixture');
wp_set_current_user(1);
$out=OAI_P0::menu_add_item(['menu_id'=>$menu,'title'=>'WP AI Menu Item Parallel '.$token,'url'=>'https://example.invalid/item','idempotency_key'=>'menu-item-parallel-'.$token]);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>(int)$out['id'],'item'=>(string)$out['target']]);
