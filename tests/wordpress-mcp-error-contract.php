<?php
/**
 * #136 real WordPress transport contract: every non-WP_Error business failure
 * returned by OAI_MCP::tool_result() must carry isError:true, and a WP_Error
 * must stay a JSON-RPC error. Runs only on the disposable CI WordPress.
 */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
    throw new RuntimeException('Disposable localhost WordPress required');

global $mcp_error_checks;$mcp_error_checks=0;
function meAssert($value,$message){global $mcp_error_checks;++$mcp_error_checks;if(!$value)throw new RuntimeException($message);}
function meBody($response){
    meAssert($response instanceof WP_REST_Response,'MCP response is not a REST response');
    $data=$response->get_data();
    meAssert(is_array($data),'MCP response has no JSON body');
    return $data;
}
function meText(array $body){return json_decode((string)($body['result']['content'][0]['text']??''),true);}

wp_set_current_user(1);
$license=OAI_License::activate_local_owner(1);
meAssert(!is_wp_error($license),'Disposable license activation failed');
$token=OAI_MCP::generate_token(1);
meAssert(is_string($token)&&$token!=='','Cannot issue a disposable MCP token');

// Successful business result: no isError flag.
$ok=meBody(OAI_MCP::tool_result('ok',['ok'=>true,'id'=>7,'status'=>'publish']));
meAssert(!isset($ok['result']['isError']),'Successful result was flagged as an error');
meAssert((meText($ok)['id']??0)===7,'Successful payload was altered');

// Explicit isError marker.
$isError=meBody(OAI_MCP::tool_result('is-error',['ok'=>false,'code'=>'permission_denied','message'=>'Missing capability','status'=>403,'isError'=>true]));
meAssert(($isError['result']['isError']??false)===true,'Explicit isError was not forwarded');

// Legacy ok:false content without isError.
$legacy=meBody(OAI_MCP::tool_result('legacy',['ok'=>false,'code'=>'validation_error','message'=>'Bad argument','status'=>400]));
meAssert(($legacy['result']['isError']??false)===true,'ok:false content was not flagged as an error');
meAssert((meText($legacy)['ok']??true)===false,'Failure payload lost its ok:false marker');

// Legacy success:false and top-level error member.
meAssert((meBody(OAI_MCP::tool_result('success-false',['success'=>false,'message'=>'Handler failed']))['result']['isError']??false)===true,'success:false was not flagged');
meAssert((meBody(OAI_MCP::tool_result('error-member',['error'=>'wp_error','message'=>'Handler threw']))['result']['isError']??false)===true,'Top-level error member was not flagged');

// WordPress REST error objects (status >= 400).
$rest=meBody(OAI_MCP::tool_result('rest-error',new WP_REST_Response(['ok'=>false,'code'=>'unprocessable','status'=>422],422)));
meAssert(($rest['result']['isError']??false)===true,'REST error response was not flagged');
meAssert((meText($rest)['status']??0)===422,'REST error status was lost');

// WP_Error stays a JSON-RPC error, which the Gateway already treats as failed.
$wpError=meBody(OAI_MCP::tool_result('wp-error',new WP_Error('oai_forbidden','Forbidden',['status'=>403])));
meAssert(isset($wpError['error'])&&!isset($wpError['result']),'WP_Error did not stay a JSON-RPC error');
meAssert((int)($wpError['error']['data']['status']??0)===403,'WP_Error status was lost');

// Lists of rows may contain failed children but the read itself was successful.
$list=meBody(OAI_MCP::tool_result('list',[['ok'=>false,'code'=>'child_failed'],['ok'=>true,'id'=>2]]));
meAssert(!isset($list['result']['isError']),'A successful list with child failures was misclassified');
$entity=meBody(OAI_MCP::tool_result('entity',['ok'=>true,'status'=>'publish','id'=>9]));
meAssert(!isset($entity['result']['isError']),'Entity status "publish" was mistaken for an HTTP failure');

// Real tools/call through the public handler: success is unflagged, a real
// WordPress failure is a JSON-RPC error.
final class McpErrorRequest { public function __construct(private $body){} public function get_json_params(){return $this->body;} }
function meCall($token,string $name,array $arguments){
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
    return OAI_MCP::handle(new McpErrorRequest(['jsonrpc'=>'2.0','id'=>'contract','method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$arguments]]));
}
$realOk=meBody(meCall($token,'wp_get_site',[]));
meAssert(isset($realOk['result'])&&!isset($realOk['error']),'Real wp_get_site call failed');
meAssert(!isset($realOk['result']['isError']),'Real successful tool was flagged as an error');
$realFail=meBody(meCall($token,'wp_read_post',['post_id'=>99999999]));
meAssert(isset($realFail['error'])&&!isset($realFail['result']),'Real missing post was not a JSON-RPC error');

unset($_SERVER['HTTP_AUTHORIZATION']);
wp_set_current_user(1);
echo 'PASS '.$mcp_error_checks." real WordPress MCP error-contract assertions\n";
