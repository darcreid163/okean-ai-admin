<?php
/** Disposable Elementor create-page worker; do not run on customer sites. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable localhost WordPress required');
$token=(string)getenv('OAI_ELEMENTOR_TEST_TOKEN');
if(!preg_match('/^[a-f0-9]{16}$/D',$token))throw new RuntimeException('Invalid Elementor fixture token');
wp_set_current_user(1);
$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');
$r->set_param('title','WP AI Elementor Parallel '.$token);
$r->set_param('idempotency_key','elementor-parallel-'.$token);
$r->set_param('data',[[ 'id'=>'a1b2c3d', 'elType'=>'container', 'settings'=>[], 'elements'=>[] ]]);
$out=OAI_REST::elementor_create_page($r);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>(int)$out['id'],'page'=>(int)$out['preview_page_id']]);
