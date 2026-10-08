<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Fault;

$checks=0;
function tlcheck(bool $ok,string $m): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($m); }
function tlreject(callable $fn,string $reason): void { try{$fn();}catch(Fault $e){tlcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Expected '.$reason); }

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-token-lifecycle-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();$core=fixtureCore($store,$transport,100);

$core->ensureAccount('owner-a');$core->ensureAccount('owner-b');
$site=$core->connect('owner-a','https://token-lifecycle.example.com','fixture-token-lifecycle-site-aaaaaaaa',$now);

// Minimum valid TTL behaves exactly at the expiry boundary.
$short=$core->mint('owner-a',[$site['id']],['mcp:read'], $now,60);
$shortActor=$core->authenticate($short['access_token'],$now+59);
tlcheck($shortActor['owner']==='owner-a','Short-lived token failed before expiry');
tlreject(fn()=>$core->authenticate($short['access_token'],$now+60),'invalid_or_expired_token');

// Wrong owner cannot revoke another owner's token.
$token=$core->mint('owner-a',[$site['id']],['mcp:read','mcp:write'],$now,3600);
$actor=$core->authenticate($token['access_token'],$now+1);
$hash=hash('sha256',$token['access_token']);
$core->revokeToken('owner-b',$hash,$now+2);
tlcheck(($core->catalog($actor,$site['id'],$now+3)!==[]),'Wrong-owner revoke affected token');

// Cached replay is still subject to token revocation because authorize runs before cache lookup.
$first=$core->call($actor,$site['id'],'operation_apply',['id'=>1],'revoke-cache',$now+4);
$exec=$transport->executions;
tlcheck($first['state']==='succeeded','Token lifecycle fixture write failed');
$core->revokeToken('owner-a',$hash,$now+5);
tlreject(fn()=>$core->call($actor,$site['id'],'operation_apply',['id'=>1],'revoke-cache',$now+6),'invalid_or_expired_token');
tlcheck($transport->executions===$exec,'Revoked cached replay reached upstream');

// Fresh token bound to an active site stops working immediately after disconnect.
$fresh=$core->mint('owner-a',[$site['id']],['mcp:read','mcp:write'],$now+7,3600);
$freshActor=$core->authenticate($fresh['access_token'],$now+8);
tlcheck(is_array($core->catalog($freshActor,$site['id'],$now+8)),'Fresh token catalog failed before disconnect');
$core->disconnect('owner-a',$site['id'],$now+9);
tlreject(fn()=>$core->catalog($freshActor,$site['id'],$now+10),'site_unavailable');
tlreject(fn()=>$core->call($freshActor,$site['id'],'operation_apply',['id'=>2],'after-disconnect',$now+10),'site_unavailable');

// Old disconnected registration cannot be reintroduced into a new token grant.
tlreject(fn()=>$core->mint('owner-a',[$site['id']],['mcp:read'],$now+11),'site_unavailable');

echo 'PASS '.$checks.' token/session lifecycle assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
