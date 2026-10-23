<?php
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!getenv('OAI_PENDING_POST')||(strpos(home_url(),'localhost')===false&&strpos(home_url(),'.test')===false))throw new RuntimeException('Disposable worker only');
wp_set_current_user(1);
$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');
foreach(['post_id'=>(int)getenv('OAI_PENDING_POST'),'field'=>'post_content','old_content'=>'alpha','new_content'=>'concurrent'] as $k=>$v)$r->set_param($k,$v);
$out=OAI_REST::content_patch($r);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>(int)$out['id']]);
