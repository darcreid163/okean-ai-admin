<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Gateway,Fault};

$checks=0;
function gpcheck(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function gpreject(callable $fn,string $reason): void {
    try{$fn();}catch(Fault $e){gpcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}
    throw new RuntimeException('Expected '.$reason);
}

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-gateway-protocol-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();
$core=fixtureCore($store,$transport,100);
$core->ensureAccount('protocol-owner');
$site=$core->connect('protocol-owner','https://protocol.example.com','fixture-protocol-site-token-aaaaaaaa',$now);
$token=$core->mint('protocol-owner',[$site['id']],['mcp:read','mcp:write'],$now);
$actor=$core->authenticate($token['access_token'],$now);
$gateway=new Gateway($core);

gpreject(fn()=>$gateway->handle($actor,[], $now),'invalid_json_rpc');
gpreject(fn()=>$gateway->handle($actor,['jsonrpc'=>'1.0','id'=>1,'method'=>'ping'],$now),'invalid_json_rpc');
gpreject(fn()=>$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>null,'method'=>'ping'],$now),'null_request_id');
gpreject(fn()=>$gateway->handle($actor,['jsonrpc'=>'2.0','method'=>'unknown/notification'],$now),'unsupported_notification');
gpreject(fn()=>$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>1,'method'=>'ping','params'=>'not-object'],$now),'invalid_params');

gpcheck($gateway->handle($actor,['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$now)===null,'initialized notification returned a body');
gpcheck($gateway->handle($actor,['jsonrpc'=>'2.0','method'=>'notifications/cancelled','params'=>['requestId'=>1]],$now)===null,'cancelled notification returned a body');

$unknown=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>2,'method'=>'does/not/exist'],$now);
gpcheck(($unknown['error']['code']??null)===-32601&&($unknown['error']['message']??'')==='Method not found','Unknown JSON-RPC method contract changed');

$init=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>'init','method'=>'initialize','params'=>['protocolVersion'=>'1900-01-01']],$now);
gpcheck(($init['result']['protocolVersion']??'')==='2025-11-25','Unsupported MCP version did not negotiate safe current version');
gpcheck(($init['result']['serverInfo']['name']??'')==='wp-ai-admin-cloud','Initialize server identity changed');

$tools=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/list','params'=>[]],$now);
gpcheck(count($tools['result']['tools']??[])===5,'Gateway tools/list must expose exactly five central tools');

$badName=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call','params'=>['name'=>'not_a_tool','arguments'=>[]]],$now);
gpcheck(!empty($badName['result']['isError'])&&str_contains($badName['result']['content'][0]['text']??'','unknown_gateway_tool'),'Unknown gateway tool was not returned as MCP tool error');

$missing=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>5,'method'=>'tools/call','params'=>['name'=>'cloud_site_tools_list','arguments'=>[]]],$now);
gpcheck(!empty($missing['result']['isError'])&&str_contains($missing['result']['content'][0]['text']??'','missing_argument'),'Missing tool argument was not rejected');

$unexpected=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>6,'method'=>'tools/call','params'=>['name'=>'cloud_usage','arguments'=>['extra'=>'x']]],$now);
gpcheck(!empty($unexpected['result']['isError'])&&str_contains($unexpected['result']['content'][0]['text']??'','unexpected_argument'),'Unexpected tool argument was not rejected');

$badType=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>7,'method'=>'tools/call','params'=>['name'=>'cloud_site_tools_list','arguments'=>['site_id'=>123]]],$now);
gpcheck(!empty($badType['result']['isError'])&&str_contains($badType['result']['content'][0]['text']??'','invalid_argument_type'),'Invalid argument type was not rejected');

$usage=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>8,'method'=>'tools/call','params'=>['name'=>'cloud_usage','arguments'=>[]]],$now);
gpcheck(empty($usage['result']['isError'])&&str_contains($usage['result']['content'][0]['text']??'','"plan":"free"'),'cloud_usage tool failed through gateway');
$history=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>81,'method'=>'tools/call','params'=>['name'=>'cloud_usage_history','arguments'=>[]]],$now);
gpcheck(empty($history['result']['isError']) && json_decode($history['result']['content'][0]['text']??'null',true)===[],'Empty authenticated usage history failed');
$badLimit=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>82,'method'=>'tools/call','params'=>['name'=>'cloud_usage_history','arguments'=>['limit'=>101]]],$now);
gpcheck(!empty($badLimit['result']['isError']),'Out of range history limit accepted');
$otherSite=$core->connect('protocol-owner','https://other-protocol.example.com','fixture-second-site-token-bbbbbbbb',$now);
$secondToken=$core->mint('protocol-owner',[$otherSite['id']],['mcp:read','mcp:write'],$now);
$secondActor=$core->authenticate($secondToken['access_token'],$now);
$secondCall=$core->call($secondActor,$otherSite['id'],'wp_get_site',[],'second-site-charge',$now);
gpcheck($secondCall['charged_units']===1,'Fixture second site charge failed');
$filtered=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>83,'method'=>'tools/call','params'=>['name'=>'cloud_usage_history','arguments'=>[]]],$now);
gpcheck(json_decode($filtered['result']['content'][0]['text']??'null',true)===[],'OAuth site grant leaked history for an ungranted site');
$visibleSecond=$gateway->handle($secondActor,['jsonrpc'=>'2.0','id'=>84,'method'=>'tools/call','params'=>['name'=>'cloud_usage_history','arguments'=>[]]],$now);
$items=json_decode($visibleSecond['result']['content'][0]['text']??'null',true);
gpcheck(is_array($items)&&count($items)===1&&$items[0]['site_id']===$otherSite['id']&&$items[0]['charged_units']===1,'Granted site history lacks verified charge');
gpcheck(!array_key_exists('response_sealed',$items[0])&&!array_key_exists('upstream',$items[0]),'Usage history disclosed private tool output');

$listSites=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>9,'method'=>'tools/call','params'=>['name'=>'cloud_sites_list','arguments'=>[]]],$now);
gpcheck(empty($listSites['result']['isError'])&&str_contains($listSites['result']['content'][0]['text']??'',$site['id']),'cloud_sites_list omitted allowed site');

echo 'PASS '.$checks.' gateway JSON-RPC protocol assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
