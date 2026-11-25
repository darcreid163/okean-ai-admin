<?php
if($argc<3)exit(2);
$root=rtrim($argv[1],'/\\');
$_SERVER['REMOTE_ADDR']=$argv[2];
require $root.'/wp-load.php';
$request=new WP_REST_Request('POST','/okean-ai/v1/oauth/register');
$request->set_header('content-type','application/json');
$request->set_body(wp_json_encode(['client_name'=>'Concurrent cap fixture','redirect_uris'=>['https://example.com/callback']]));
$response=OAI_MCP_OAuth::register_client($request);
if(!$response instanceof WP_REST_Response)exit(3);
$status=(int)$response->get_status();
$data=$response->get_data();
if($status===201 && !empty($data['client_id'])){echo 'CREATED:'.$data['client_id']."\n";exit(0);}
if($status===429 && ($data['error']??'')==='client_registration_limit'){echo "FULL\n";exit(0);}
echo 'ERROR:'.$status.':'.wp_json_encode($data)."\n";
exit(1);
