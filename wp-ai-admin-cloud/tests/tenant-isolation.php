<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Core, OAuth, Gateway, Fault, Security};

require dirname(__DIR__).'/src/OAuth.php';

$checks=0;
function iso_check(bool $ok,string $message): void {
    global $checks;
    $checks++;
    if(!$ok) throw new RuntimeException($message);
}
function iso_reject(callable $fn,string $reason): void {
    try { $fn(); }
    catch(Fault $e) {
        iso_check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$reason);
}
function iso_ids(array $rows): array { return array_values(array_column($rows,'id')); }

$now=1791436800;
$database=sys_get_temp_dir().'/wpai-tenant-isolation-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database);
$store->migrate();

if(getenv('TEST_MYSQL_DSN')) {
    foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $name) {
        $store->execute('DELETE FROM '.$store->table($name));
    }
}

$transport=new FakeTransport();
$core=fixtureCore($store,$transport);
$core->ensureAccount('tenant-a');
$core->ensureAccount('tenant-b');

$siteA=$core->connect('tenant-a','https://tenant-a.example.com','fixture-tenant-a-admin-token-aaaaaaaa',$now);
$siteB=$core->connect('tenant-b','https://tenant-b.example.com','fixture-tenant-b-admin-token-bbbbbbbb',$now);

iso_check(count($core->sites('tenant-a'))===1,'Tenant A site registration missing');
iso_check(count($core->sites('tenant-b'))===1,'Tenant B site registration missing');

// The same canonical site may never be silently claimed by another owner.
iso_reject(
    fn()=>$core->connect('tenant-b','https://tenant-a.example.com','fixture-cross-owner-token-cccccccc',$now+1,true),
    'site_already_registered'
);
iso_check(iso_ids($core->sites('tenant-a'))===[$siteA['id']],'Cross-owner claim changed Tenant A ownership');
iso_check(iso_ids($core->sites('tenant-b'))===[$siteB['id']],'Cross-owner claim changed Tenant B registry');

$tokenA=$core->mint('tenant-a',[$siteA['id']],['mcp:read','mcp:write'],$now);
$tokenB=$core->mint('tenant-b',[$siteB['id']],['mcp:read','mcp:write'],$now);
$actorA=$core->authenticate($tokenA['access_token'],$now);
$actorB=$core->authenticate($tokenB['access_token'],$now);

// Catalog and calls must be denied across owner/site boundaries.
iso_reject(fn()=>$core->catalog($actorA,$siteB['id'],$now),'scope_or_site_denied');
iso_reject(fn()=>$core->catalog($actorB,$siteA['id'],$now),'scope_or_site_denied');
iso_reject(fn()=>$core->call($actorA,$siteB['id'],'wp_get_site',[],'cross-a-to-b',$now),'scope_or_site_denied');
iso_reject(fn()=>$core->call($actorB,$siteA['id'],'wp_get_site',[],'cross-b-to-a',$now),'scope_or_site_denied');

// Same logical nonce used by different tenants must remain isolated by owner binding.
$a=$core->call($actorA,$siteA['id'],'wp_get_site',[],'shared-logical-nonce',$now);
$b=$core->call($actorB,$siteB['id'],'wp_get_site',[],'shared-logical-nonce',$now);
iso_check($a['request_key']!==$b['request_key'],'Idempotency key leaked across tenant boundary');
iso_check($a['site_id']===$siteA['id']&&$b['site_id']===$siteB['id'],'Same nonce crossed site routing');

$ledgerA=$core->ledger('tenant-a',100);
$ledgerB=$core->ledger('tenant-b',100);
iso_check(count(array_filter($ledgerA,fn($r)=>$r['site_id']===$siteB['id']))===0,'Tenant A ledger leaked Tenant B site');
iso_check(count(array_filter($ledgerB,fn($r)=>$r['site_id']===$siteA['id']))===0,'Tenant B ledger leaked Tenant A site');

// OAuth consent must not be able to include another owner's site.
$oauth=new OAuth($store,$core,'https://wpaiadmin.ru/mcp');
$client=$oauth->register([
    'client_name'=>'WP AI Admin Release Sandbox',
    'redirect_uris'=>['https://chatgpt.com/connector/oauth/release-sandbox']
],$now);
$verifier=str_repeat('s',64);
$query=[
    'client_id'=>$client['client_id'],
    'redirect_uri'=>$client['redirect_uris'][0],
    'response_type'=>'code',
    'resource'=>'https://wpaiadmin.ru/mcp',
    'scope'=>'mcp:read mcp:write offline_access',
    'code_challenge'=>Security::b64(hash('sha256',$verifier,true)),
    'code_challenge_method'=>'S256',
    'state'=>'tenant-isolation-state'
];
$auth=$oauth->authorization($query);
iso_reject(fn()=>$oauth->approve('tenant-a',$auth,[$siteA['id'],$siteB['id']],$now),'invalid_site_consent');

$codeA=$oauth->approve('tenant-a',$auth,[$siteA['id']],$now);
$grantA=$oauth->exchange([
    'grant_type'=>'authorization_code',
    'code'=>$codeA,
    'client_id'=>$client['client_id'],
    'redirect_uri'=>$query['redirect_uri'],
    'resource'=>$query['resource'],
    'code_verifier'=>$verifier
],$now,fn($owner)=>$owner==='tenant-a');
$oauthActorA=$core->authenticate($grantA['access_token'],$now);
$oauth->binding($oauthActorA,$now);
iso_reject(fn()=>$core->catalog($oauthActorA,$siteB['id'],$now),'scope_or_site_denied');

// Disconnect/reconnect must create a new registration ID and old grants must not expand.
$core->disconnect('tenant-a',$siteA['id'],$now+10);
iso_reject(fn()=>$core->catalog($oauthActorA,$siteA['id'],$now+11),'site_unavailable');
$siteA2=$core->connect('tenant-a','https://tenant-a.example.com','fixture-tenant-a-reconnect-dddddddd',$now+12,true);
iso_check($siteA2['id']!==$siteA['id'],'Revoked registration ID was reused');
iso_reject(fn()=>$core->catalog($oauthActorA,$siteA2['id'],$now+13),'scope_or_site_denied');
iso_reject(fn()=>$core->catalog($actorB,$siteA2['id'],$now+13),'scope_or_site_denied');

// The second tenant must remain fully intact throughout Tenant A reconnect.
iso_check(iso_ids($core->sites('tenant-b'))===[$siteB['id']],'Tenant A reconnect modified Tenant B registry');

echo 'PASS '.$checks.' tenant-isolation assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;

if(!getenv('TEST_MYSQL_DSN')) {
    foreach([$database,$database.'-wal',$database.'-shm'] as $file) if(file_exists($file)) unlink($file);
}
