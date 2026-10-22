<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Fault,Core,Gateway};
$now=strtotime('2026-10-22T08:00:00Z');
$file=tempnam(sys_get_temp_dir(),'wpai-abilities-');
$store=fixtureStore($file); $store->migrate();
$transport=new FakeTransport(); $core=fixtureCore($store,$transport);
foreach(['tenant-a','tenant-b'] as $owner) $core->ensureAccount($owner);
$a=$core->connect('tenant-a','https://abilities-a.example.com','fixture-site-admin-token-aaaaaaaa',$now);
$b=$core->connect('tenant-b','https://abilities-b.example.com','fixture-site-admin-token-bbbbbbbb',$now);
$read=$core->authenticate($core->mint('tenant-a',[$a['id']],['mcp:read'],$now)['access_token'],$now);
$foreign=$core->authenticate($core->mint('tenant-b',[$b['id']],['mcp:read'],$now)['access_token'],$now);
function assertAbility(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function denyAbility(callable $fn,string $expected):void{try{$fn();}catch(Fault $e){assertAbility($e->reason===$expected,'Wrong denial: '.$e->reason);return;}throw new RuntimeException('Missing denial '.$expected);}
assertAbility(Core::writes('ability.list',[])===false && Core::writes('ability.get',[])===false,'metadata tools must be read only');
assertAbility(Core::writes('ability.run',[])===true,'unknown execution must not be read only');
$one=$core->call($read,$a['id'],'ability.list',[],'discovery-1',$now);
assertAbility($one['charged_units']===1 && $transport->executions===1,'first metadata read not billed once');
$again=$core->call($read,$a['id'],'ability.list',[],'discovery-1',$now);
assertAbility($again['request_key']===$one['request_key'] && $transport->executions===1 && $core->usage('tenant-a',$now)['used']===1,'retry double billed');
$get=$core->call($read,$a['id'],'ability.get',['name'=>'example/read'],'discovery-2',$now);
assertAbility($get['charged_units']===1 && $core->usage('tenant-a',$now)['used']===2,'ability.get not metered');
denyAbility(fn()=>$core->call($read,$b['id'],'ability.list',[],'foreign-site',$now),'scope_or_site_denied');
denyAbility(fn()=>$core->call($foreign,$a['id'],'ability.list',[],'foreign-owner',$now),'scope_or_site_denied');
denyAbility(fn()=>$core->call($read,$a['id'],'ability.run',[],'unsafe-exec',$now),'scope_or_site_denied');
assertAbility($core->usage('tenant-a',$now)['used']===2 && $core->usage('tenant-b',$now)['used']===0,'denied operations billed');
// Exercise public Cloud JSON-RPC Gateway surface, not Core alone.
$gateway=new Gateway($core);
$rpc=static fn(array $actor,string $tool,string $key,string $site) =>
    $gateway->handle($actor,['jsonrpc'=>'2.0','id'=>$key,'method'=>'tools/call',
        'params'=>['name'=>'cloud_site_tool_call','arguments'=>['site_id'=>$site,'tool'=>$tool,'arguments'=>[],'idempotency_key'=>$key]]],$now);
$rpcList=$rpc($read,'ability.list','rpc-discovery-1',$a['id']);
assertAbility(($rpcList['result']['isError']??false)===false,'Gateway rejected valid Abilities list');
assertAbility($core->usage('tenant-a',$now)['used']===3,'Gateway failed to bill one action');
$rpcRetry=$rpc($read,'ability.list','rpc-discovery-1',$a['id']);
assertAbility(($rpcRetry['result']['isError']??false)===false && $core->usage('tenant-a',$now)['used']===3,'Gateway retry double billed');
$rpcOther=$rpc($read,'ability.list','rpc-foreign',$b['id']);
assertAbility(($rpcOther['result']['isError']??false)===true && $core->usage('tenant-a',$now)['used']===3,'Gateway site boundary or billing failed');
$rpcUnsafe=$rpc($read,'ability.run','rpc-unsafe',$a['id']);
assertAbility(($rpcUnsafe['result']['isError']??false)===true && $core->usage('tenant-a',$now)['used']===3,'Gateway unsafe Ability not denied');
// An uncertain upstream result must hold a single reservation for reconciliation,
// never charge a confirmed action and never automatically dispatch a retry.
$before=$core->usage('tenant-a',$now);
$execBefore=$transport->executions;
denyAbility(fn()=>$core->call($read,$a['id'],'ability.get',['name'=>'example/read','uncertain'=>true],'uncertain-read-1',$now),'request_outcome_pending_reconciliation');
$held=$core->usage('tenant-a',$now);
assertAbility($held['used']===$before['used'] && $held['reserved']===$before['reserved']+1,'uncertain reply charged or lost reservation');
assertAbility($transport->executions===$execBefore+1,'uncertain request not dispatched exactly once');
denyAbility(fn()=>$core->call($read,$a['id'],'ability.get',['name'=>'example/read','uncertain'=>true],'uncertain-read-1',$now+1),'request_outcome_pending_reconciliation');
assertAbility($transport->executions===$execBefore+1,'uncertain retry re-dispatched');
assertAbility($core->usage('tenant-a',$now+1)['reserved']===$held['reserved'],'uncertain retry reserved twice');
denyAbility(fn()=>$core->call($read,$a['id'],'ability.get',['name'=>'other/read'],'uncertain-read-1',$now+2),'idempotency_conflict');
assertAbility($transport->executions===$execBefore+1,'conflicting retry dispatched');
echo "PASS Abilities Cloud OAuth/site/tenant/ledger/uncertain-network-idempotency acceptance\\n";
