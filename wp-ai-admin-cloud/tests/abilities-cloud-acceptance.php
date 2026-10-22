<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Fault,Core};
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
echo "PASS Abilities Cloud OAuth/site/tenant/ledger/idempotency acceptance\n";
