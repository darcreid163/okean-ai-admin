<?php
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!getenv('OAI_PENDING_POST')||(strpos(home_url(),'localhost')===false&&strpos(home_url(),'.test')===false))throw new RuntimeException('Disposable worker only');
wp_set_current_user(1);
if(getenv('OAI_PENDING_MCP_TOKEN')){
 $body=['jsonrpc'=>'2.0','id'=>'pending-worker','method'=>'tools/call','params'=>['name'=>'content.patch','arguments'=>['post_id'=>(int)getenv('OAI_PENDING_POST'),'field'=>'post_content','old_content'=>'alpha','new_content'=>'concurrent']]];
 $request=new WP_REST_Request('POST','/okean-ai/mcp');$request->set_header('content-type','application/json');$request->set_body(wp_json_encode($body));$_SERVER['HTTP_AUTHORIZATION']='Bearer '.getenv('OAI_PENDING_MCP_TOKEN');
 $data=OAI_MCP::handle($request)->get_data();
 if(isset($data['error'])){echo wp_json_encode(['error'=>'rpc_error','message'=>(string)($data['error']['message']??'')]);exit;}
 $payload=json_decode((string)($data['result']['content'][0]['text']??''),true);
 echo wp_json_encode(['id'=>(int)($payload['id']??0)]);
 exit;
}
$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');
foreach(['post_id'=>(int)getenv('OAI_PENDING_POST'),'field'=>'post_content','old_content'=>'alpha','new_content'=>'concurrent'] as $k=>$v)$r->set_param($k,$v);
$out=OAI_REST::content_patch($r);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code()]:['id'=>(int)$out['id']]);
