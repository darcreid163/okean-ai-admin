<?php
declare(strict_types=1);
/**
 * Native ↔ Cloud Core contract: cross-account isolation.
 *
 * Complements tests/run.php (single-account correctness) with the boundary that
 * matters commercially: no account, token or idempotency key may cross tenants.
 * Runs on SQLite without MySQL and without network.
 */
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Core,Fault,Gateway};

$checks=0;
function check(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $reason): void { try {$fn();} catch(Fault $e) { check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return; } throw new RuntimeException('Expected rejection: '.$reason); }
function rpcResult(Gateway $gateway,array $actor,string $name,array $args,int $now): array {
    $reply=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>'iso','method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],$now);
    return is_array($reply['result']??null)?$reply['result']:array();
}

$now=1790942400;
$database=sys_get_temp_dir().'/wpai-isolation-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database); $store->migrate();
if (getenv('TEST_MYSQL_DSN')) foreach(['audit','requests','tokens','sites','balances','accounts','rate'] as $name) $store->execute('DELETE FROM '.$store->table($name));
$transport=new FakeTransport();
$core=fixtureCore($store,$transport);
$gateway=new \WPAICloudGateway\Gateway($core);

foreach (['alpha','beta','gamma'] as $owner) $core->ensureAccount($owner);

/* ------------------------------------------------------- 1. site ownership --- */

$a1=$core->connect('alpha','https://alpha-one.example.com','fixture-site-admin-token-11111111',$now);
$a2=$core->connect('alpha','https://alpha-two.example.com','fixture-site-admin-token-22222222',$now);
$b1=$core->connect('beta','https://beta-one.example.com','fixture-site-admin-token-33333333',$now);

check(count($core->sites('alpha'))===2,'Alpha site list incomplete');
check(count($core->sites('beta'))===1,'Beta site list wrong');
$betaHomes=array_column($core->sites('beta'),'home');
check(!in_array('https://alpha-one.example.com',$betaHomes,true),'Beta sees an alpha site');
rejects(fn()=>$core->connect('beta','https://alpha-one.example.com','fixture-site-admin-token-99999999',$now),'site_already_registered');
rejects(fn()=>$core->connect('gamma','https://beta-one.example.com','fixture-site-admin-token-99999999',$now),'site_already_registered');

/* ---------------------------------------------------------- 2. token scope --- */

$aRead=$core->mint('alpha',[$a1['id']],['mcp:read'],$now);
$aWrite=$core->mint('alpha',[$a1['id'],$a2['id']],['mcp:read','mcp:write'],$now);
$bRead=$core->mint('beta',[$b1['id']],['mcp:read'],$now);

rejects(fn()=>$core->mint('beta',[$a1['id']],['mcp:read'],$now),'site_unavailable');
rejects(fn()=>$core->mint('alpha',[$b1['id']],['mcp:read'],$now),'site_unavailable');

$aActor=$core->authenticate($aRead['access_token'],$now);
$aWriteActor=$core->authenticate($aWrite['access_token'],$now);
$bActor=$core->authenticate($bRead['access_token'],$now);

rejects(fn()=>$core->catalog($bActor,$a1['id'],$now),'scope_or_site_denied');
rejects(fn()=>$core->call($bActor,$a1['id'],'wp_get_site',[],'beta-call',$now),'scope_or_site_denied');
rejects(fn()=>$core->catalog($aActor,$a2['id'],$now),'scope_or_site_denied');
rejects(fn()=>$core->call($aActor,$a2['id'],'wp_get_site',[],'alpha-call',$now),'scope_or_site_denied');

/* ------------------------------------------------------ 3. gateway surface --- */

$listA=json_decode(rpcResult($gateway,$aActor,'cloud_sites_list',[],$now)['content'][0]['text'],true);
check(count($listA)===1 && $listA[0]['home']==='https://alpha-one.example.com','Token site filter leaked');
$listB=json_decode(rpcResult($gateway,$bActor,'cloud_sites_list',[],$now)['content'][0]['text'],true);
check(count($listB)===1 && $listB[0]['home']==='https://beta-one.example.com','Beta gateway list wrong');

$executions=$transport->executions;
$denied=rpcResult($gateway,$bActor,'cloud_site_tool_call',['site_id'=>$a1['id'],'tool'=>'wp_get_site','arguments'=>[],'idempotency_key'=>'cross-1'],$now);
check(($denied['isError']??false)===true,'Beta executed a tool on an alpha site');
check(str_contains($denied['content'][0]['text'],'scope_or_site_denied'),'Wrong denial reason');
check($transport->executions===$executions,'Upstream was contacted for a denied cross-tenant call');

$deniedList=rpcResult($gateway,$bActor,'cloud_site_tools_list',['site_id'=>$a1['id']],$now);
check(($deniedList['isError']??false)===true,'Beta read an alpha site catalog');

/* ------------------------------------------- 4. idempotency key isolation --- */

$keyA='shared-logical-action';
$keyB='shared-logical-action';
$callA=$core->call($aWriteActor,$a1['id'],'wp_get_site',[],$keyA,$now);
$callB=$core->call($bActor,$b1['id'],'wp_get_site',[],$keyB,$now);
check($callA['charged_units']===1 && $callB['charged_units']===1,'Same idempotency key collided across tenants');
$replayA=$core->call($aWriteActor,$a1['id'],'wp_get_site',[],$keyA,$now);
check(\WPAICloudGateway\Security::canonical($replayA)===\WPAICloudGateway\Security::canonical($callA),'Replay payload differs');
check($replayA['charged_units']===1,'Replay reported a different charge');

/* --------------------------------------------------------- 5. usage/ledger --- */

$usageA=$core->usage('alpha',$now); $usageB=$core->usage('beta',$now);
check($usageA['used']===1,'Alpha usage not isolated');
check($usageB['used']===1,'Beta usage not isolated');
check(count($core->ledger('alpha'))===1,'Alpha ledger leaked rows');
check(count($core->ledger('beta'))===1,'Beta ledger wrong');

/* ------------------------------------------------------------ 6. revocation --- */

$core->revokeToken('alpha',hash('sha256',$aRead['access_token']),$now);
rejects(fn()=>$core->authenticate($aRead['access_token'],$now),'invalid_or_expired_token');
check(is_array($core->authenticate($bRead['access_token'],$now)),'Beta token died with alpha revocation');
check(is_array($core->authenticate($aWrite['access_token'],$now)),'Unrelated alpha token was revoked');

/* ----------------------------------------------------------- 7. disconnect --- */

$core->disconnect('alpha',$a2['id'],$now);
try {
    $core->catalog($aWriteActor,$a2['id'],$now);
    throw new RuntimeException('Disconnected site is still readable');
} catch (Fault $e) {
    check(in_array($e->reason,['scope_or_site_denied','site_unavailable'],true),'Wrong denial reason after disconnect: '.$e->reason);
}
check(is_array($core->catalog($aWriteActor,$a1['id'],$now)),'Disconnect broke the surviving site');
check(count($core->sites('beta'))===1,'Beta sites changed by an alpha disconnect');

/* ------------------------------------------------------- 8. capacity ceiling --- */

$capNow=$now;
for ($i=0; $i<Core::MAX_SITES; $i++) {
    $capNow+=3600; // separate throttle slot per operation
    $core->connect('gamma',sprintf('https://gamma-%02d.example.com',$i),sprintf('fixture-site-admin-token-g%07d',$i),$capNow);
}
check(count($core->sites('gamma'))===Core::MAX_SITES,'Gamma did not reach the documented ceiling');
rejects(fn()=>$core->connect('gamma','https://gamma-over.example.com','fixture-site-admin-token-overflow',$capNow+3600),'pilot_site_count_limit');

/* ----------------------------------------------------------------------------
 * Tier A skill/media tools are deliberately not asserted here: they are added by
 * the Tier A branch and their tenant behaviour (global, non-tenant content) is
 * covered by tests/tier-a.php. This file must pass on main without that branch.
 * ------------------------------------------------------------------------- */

echo 'PASS '.$checks.' cross-account isolation assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').'; cap='.Core::MAX_SITES.PHP_EOL;
