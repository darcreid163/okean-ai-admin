<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/src/OAuth.php';
use WPAICloudGateway\{Core,Fault,Transport,Security};

$checks=0;
function bcheck(bool $ok,string $m):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($m);}
function breject(callable $fn,string $reason):void{try{$fn();}catch(Fault $e){bcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Expected '.$reason);}

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-boundaries-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();

// Nested transactional primitives must compose into one outer transaction.
$nestedOwner='nested-tx-owner';
try {
    $store->transaction(function() use($store,$nestedOwner) {
        $store->transaction(function() use($store,$nestedOwner) {
            $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan) VALUES (?,?)',[$nestedOwner,'free']);
        });
        throw new RuntimeException('force outer rollback');
    });
} catch (RuntimeException $e) {
    bcheck($e->getMessage()==='force outer rollback','Unexpected nested transaction exception');
}
bcheck($store->row('SELECT owner FROM '.$store->table('accounts').' WHERE owner=?',[$nestedOwner])===null,'Nested transaction escaped outer rollback');

$store->transaction(function() use($store,$nestedOwner) {
    $store->transaction(function() use($store,$nestedOwner) {
        $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan) VALUES (?,?)',[$nestedOwner,'free']);
    });
});
bcheck(($store->row('SELECT owner FROM '.$store->table('accounts').' WHERE owner=?',[$nestedOwner])['owner']??'')===$nestedOwner,'Nested transaction did not commit with outer transaction');
$store->execute('DELETE FROM '.$store->table('accounts').' WHERE owner=?',[$nestedOwner]);

// Commercial policy must fail closed if its accounting contract is edited into an unsupported shape.
$basePolicy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
foreach([
    ['period','rolling_30d','invalid_quota_policy'],
    ['billing','attempted_tool_call','invalid_quota_policy'],
    ['batch','enabled','invalid_quota_policy']
] as [$key,$value,$reason]){
    $p=$basePolicy;$p[$key]=$value;
    breject(fn()=>new Core($store,new WPAICloudGateway\Vault(str_repeat('q',32)),$transport,$p),$reason);
}
foreach([
    ['free',-1],
    ['pro',10000001],
    ['power','2000']
] as [$plan,$value]){
    $p=$basePolicy;$p['plans'][$plan]=$value;
    breject(fn()=>new Core($store,new WPAICloudGateway\Vault(str_repeat('r',32)),$transport,$p),'invalid_plan_limit');
}

$core=fixtureCore($store,$transport,2);$core->ensureAccount('boundary');

// URL and SSRF boundaries.
bcheck(Security::home('https://EXAMPLE.COM:443/path/')==='https://example.com/path','Canonical HTTPS home normalization changed');
foreach([
    ['http://example.com','public_https_site_required'],
    ['https://user@example.com','public_https_site_required'],
    ['https://example.com?x=1','public_https_site_required'],
    ['https://example.com#frag','public_https_site_required'],
    ['https://example.com:8443','public_https_site_required'],
    ['https://localhost','invalid_site_host'],
    ['https://127.0.0.1','invalid_site_host'],
    ['https://example.com/a/../b','invalid_site_path']
] as [$url,$reason]) breject(fn()=>Security::home($url),$reason);
foreach(['10.0.0.1','127.0.0.1','169.254.169.254','100.64.0.1','192.0.2.1','198.51.100.1','203.0.113.1','::1','fc00::1','fe80::1','2001:db8::1'] as $ip)
    bcheck(Security::publicIp($ip)===false,'Reserved/private address accepted: '.$ip);
foreach(['8.8.8.8','1.1.1.1','2606:4700:4700::1111'] as $ip)
    bcheck(Security::publicIp($ip)===true,'Public address rejected: '.$ip);
breject(fn()=>Security::requirePublicAddresses(['8.8.8.8','10.0.0.1']),'private_site_address_blocked');
breject(fn()=>Security::requirePublicAddresses([]),'site_dns_unavailable');
breject(fn()=>Security::requirePublicAddresses(array_fill(0,33,'8.8.8.8')),'site_dns_unavailable');

breject(fn()=>$core->connect('boundary','http://example.com','fixture-valid-token-aaaaaaaaaaaa',$now),'public_https_site_required');
breject(fn()=>$core->connect('boundary','https://127.0.0.1','fixture-valid-token-aaaaaaaaaaaa',$now),'invalid_site_host');
breject(fn()=>$core->connect('boundary','https://example.com','short',$now),'invalid_site_token');

// Site proof must reject incompatible or malformed native manifests before creating a registration.
final class ManifestBoundaryTransport implements Transport {
    public function __construct(private array $manifest=[],private bool $isError=false){}
    public function rpc(string $home,string $token,array $request): array {
        if(($request['params']['name']??'')==='system_manifest'){
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>[
                'content'=>[['type'=>'text','text'=>json_encode($this->manifest)]],
                'isError'=>$this->isError
            ]];
        }
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>[]]];
    }
}
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
$badManifests=[
    [['plugin_name'=>'Wrong Plugin','site_id'=>'bad-plugin','installation_id'=>'bad-install','release'=>['production_defaults'=>['direct_live_writes'=>false]]],'site_manifest_incompatible'],
    [['plugin_name'=>'WP AI Admin','site_id'=>'bad-plugin','installation_id'=>'bad-install','release'=>['production_defaults'=>['direct_live_writes'=>true]]],'site_manifest_incompatible'],
    [['plugin_name'=>'WP AI Admin','release'=>['production_defaults'=>['direct_live_writes'=>false]]],'site_manifest_incompatible'],
    [['plugin_name'=>'WP AI Admin','site_id'=>'bad/site','installation_id'=>'bad-install','release'=>['production_defaults'=>['direct_live_writes'=>false]]],'invalid_identifier']
];
foreach($badManifests as $idx=>$case){
    [$manifest,$reason]=$case;
    $badCore=new Core($store,new WPAICloudGateway\Vault(str_repeat(chr(65+$idx),32)),new ManifestBoundaryTransport($manifest),$policy);
    $owner='manifest-'.$idx;$badCore->ensureAccount($owner);
    breject(fn()=>$badCore->connect($owner,'https://manifest-'.$idx.'.example.com','fixture-manifest-site-token-aaaaaaaa',$now+$idx),$reason);
    bcheck(count($badCore->sites($owner))===0,'Rejected manifest created site registration');
}
$errorCore=new Core($store,new WPAICloudGateway\Vault(str_repeat('m',32)),new ManifestBoundaryTransport([
    'plugin_name'=>'WP AI Admin','site_id'=>'error-plugin','installation_id'=>'error-install','release'=>['production_defaults'=>['direct_live_writes'=>false]]
],true),$policy);
$errorCore->ensureAccount('manifest-error');
breject(fn()=>$errorCore->connect('manifest-error','https://manifest-error.example.com','fixture-manifest-error-token-aaaaaaaa',$now+10),'site_admin_proof_failed');
bcheck(count($errorCore->sites('manifest-error'))===0,'Failed admin proof created site registration');

// Revoke-first account suspension keeps evidence but destroys active access paths.
$core->ensureAccount('closure-owner');
$closureSiteToken='fixture-closure-site-token-aaaaaaaa';
$closureSite=$core->connect('closure-owner','https://closure.example.com',$closureSiteToken,$now+20);
$closureBearer=$core->mint('closure-owner',[$closureSite['id']],['mcp:read','mcp:write'],$now+20);
$closureActor=$core->authenticate($closureBearer['access_token'],$now+20);
$closureNonce='closure-uncertain';
$closureRequestKey=hash('sha256','closure-owner|'.$closureNonce);
breject(fn()=>$core->call($closureActor,$closureSite['id'],'operation_apply',['id'=>77,'uncertain'=>true],$closureNonce,$now+20),'request_outcome_pending_reconciliation');
$closureUncertain=$store->row('SELECT state,reserved_units,period FROM '.$store->table('requests').' WHERE owner=? AND request_key=?',['closure-owner',$closureRequestKey]);
bcheck(($closureUncertain['state']??'')==='uncertain'&&(int)($closureUncertain['reserved_units']??0)===1,'Unable to seed uncertain request before suspension');

$closureGrant='grant-'.str_repeat('c',16);
$closureClient='client-closure';
$closureRedirect='https://client.example/callback';
$closureVerifier=str_repeat('v',43);
$closureRefresh='closure-refresh-'.str_repeat('r',43);
$store->execute('INSERT INTO '.$store->table('oauth_clients').' (id,name,redirects,created_at) VALUES (?,?,?,?)',[
    $closureClient,'Closure client',Security::canonical([$closureRedirect]),$now+19
]);
$store->execute('INSERT INTO '.$store->table('oauth_grants').' (id,owner,client,resource,scopes,sites,revoked,expires) VALUES (?,?,?,?,?,?,0,?)',[
    $closureGrant,'closure-owner',$closureClient,'https://wpaiadmin.ru/mcp',
    Security::canonical(['mcp:read','mcp:write']),Security::canonical([$closureSite['id']]),$now+3600
]);
$store->execute('INSERT INTO '.$store->table('oauth_codes').' (hash,owner,client,redirect,resource,scopes,sites,challenge,expires,used) VALUES (?,?,?,?,?,?,?,?,?,0)',[
    hash('sha256','closure-code'),'closure-owner',$closureClient,$closureRedirect,'https://wpaiadmin.ru/mcp',
    Security::canonical(['mcp:read']),Security::canonical([$closureSite['id']]),Security::b64(hash('sha256',$closureVerifier,true)),$now+600
]);
$store->execute('INSERT INTO '.$store->table('oauth_refresh').' (hash,grant_id,used,expires) VALUES (?,?,0,?)',[
    hash('sha256',$closureRefresh),$closureGrant,$now+3600
]);
// Legacy revoked row with a leftover sealed credential must also be scrubbed.
$legacyRevoked='reg_'.str_repeat('f',32);
$store->execute('INSERT INTO '.$store->table('sites').' (id,owner,home,url_hash,plugin_site_id,installation_id,token_sealed,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[
    $legacyRevoked,'closure-owner','https://closure-legacy.example.com',hash('sha256','https://closure-legacy.example.com'),
    'plugin-closure-legacy','install-closure-legacy','legacy-sealed-credential','revoked',$now+19
]);

$inventory=$core->closureInventory('closure-owner');
bcheck(($inventory['active_sites']??0)===1&&($inventory['active_tokens']??0)===1&&($inventory['active_oauth_grants']??0)===1&&($inventory['unused_oauth_codes']??0)===1,'Closure inventory counts wrong');
$inventoryEncoded=Security::canonical($inventory);
bcheck(!str_contains($inventoryEncoded,$closureSiteToken)&&!str_contains($inventoryEncoded,$closureBearer['access_token']),'Closure preview leaked credential material');

$closureSequenceBefore=(int)($store->row('SELECT sequence FROM '.$store->table('accounts').' WHERE owner=?',['closure-owner'])['sequence']??0);
$suspended=$core->suspendAccount('closure-owner',$now+21);
bcheck(!empty($suspended['suspended'])&&!empty($suspended['access_revoked'])&&!empty($suspended['site_credentials_cleared'])&&empty($suspended['destructive_delete_performed']),'Account suspension result contract wrong');
$closureSequenceSuspended=(int)($store->row('SELECT sequence FROM '.$store->table('accounts').' WHERE owner=?',['closure-owner'])['sequence']??0);
bcheck($closureSequenceSuspended===$closureSequenceBefore+1,'Suspension did not bump account sequence exactly once');
$suspendAudit=$store->rows('SELECT event,request_key FROM '.$store->table('audit').' WHERE owner=? AND event=?',['closure-owner','account_suspended']);
bcheck(count($suspendAudit)===1&&($suspendAudit[0]['request_key']??'')==='','Suspension audit trail missing or contains unexpected credential material');
breject(fn()=>$core->authenticate($closureBearer['access_token'],$now+22),'invalid_or_expired_token');
$closureSiteRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$closureSite['id']]);
bcheck(($closureSiteRow['status']??'')==='revoked'&&($closureSiteRow['token_sealed']??'')==='','Suspension did not revoke site and clear sealed credential');
$legacyRevokedRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$legacyRevoked]);
bcheck(($legacyRevokedRow['status']??'')==='revoked'&&($legacyRevokedRow['token_sealed']??'')==='','Suspension left credential material in legacy revoked site row');
$closureGrantRow=$store->row('SELECT revoked FROM '.$store->table('oauth_grants').' WHERE id=?',[$closureGrant]);
bcheck((int)($closureGrantRow['revoked']??0)===1,'Suspension did not revoke OAuth grant');
$closureCodeRow=$store->row('SELECT used FROM '.$store->table('oauth_codes').' WHERE hash=?',[hash('sha256','closure-code')]);
bcheck((int)($closureCodeRow['used']??0)===1,'Suspension did not invalidate unused OAuth code');
$closureOAuth=new WPAICloudGateway\OAuth($store,$core,'https://wpaiadmin.ru/mcp');

// No new authorization code may be created after suspension.
$codesBeforeSuspendApprove=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_codes').' WHERE owner=? AND used=0',['closure-owner'])['n']??0);
breject(fn()=>$closureOAuth->approve('closure-owner',[
    'client_id'=>$closureClient,
    'redirect_uri'=>$closureRedirect,
    'resource'=>'https://wpaiadmin.ru/mcp',
    'challenge'=>Security::b64(hash('sha256',$closureVerifier,true)),
    'scopes'=>['mcp:read'],
    'state'=>'closure-state'
],[$closureSite['id']],$now+22),'account_unavailable');
$codesAfterSuspendApprove=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_codes').' WHERE owner=? AND used=0',['closure-owner'])['n']??0);
bcheck($codesAfterSuspendApprove===$codesBeforeSuspendApprove,'Suspended account created a new OAuth authorization code');
breject(fn()=>$closureOAuth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$closureClient,'code'=>'closure-code',
    'redirect_uri'=>$closureRedirect,'code_verifier'=>$closureVerifier,'resource'=>'https://wpaiadmin.ru/mcp'
],$now+22,fn($owner)=>true),'invalid_grant');
breject(fn()=>$closureOAuth->exchange([
    'grant_type'=>'refresh_token','client_id'=>$closureClient,'refresh_token'=>$closureRefresh,
    'resource'=>'https://wpaiadmin.ru/mcp'
],$now+22,fn($owner)=>true),'invalid_grant');
$closureAuth=[
    'client_id'=>$closureClient,
    'redirect_uri'=>$closureRedirect,
    'resource'=>'https://wpaiadmin.ru/mcp',
    'challenge'=>Security::b64(hash('sha256',$closureVerifier,true)),
    'scopes'=>['mcp:read'],
    'state'=>'closure-state'
];
breject(fn()=>$closureOAuth->approve('closure-owner',$closureAuth,[$closureSite['id']],$now+22),'account_unavailable');
$closureRefreshRow=$store->row('SELECT used FROM '.$store->table('oauth_refresh').' WHERE hash=?',[hash('sha256',$closureRefresh)]);
bcheck((int)($closureRefreshRow['used']??0)===0,'Suspension-triggered refresh denial consumed refresh token unexpectedly');
$closureAccount=$store->row('SELECT disabled FROM '.$store->table('accounts').' WHERE owner=?',['closure-owner']);
bcheck((int)($closureAccount['disabled']??0)===1,'Suspension did not disable core account');
breject(fn()=>$core->usage('closure-owner',$now+22),'account_unavailable');
breject(fn()=>$core->setPlan('operator','closure-owner','pro',$now+22),'account_unavailable');
breject(fn()=>$core->foundation('closure-owner',$closureSite['id'],$now+22),'account_unavailable');

// Reconciliation is the sole intentional suspended-account exception: it may
// settle an already-uncertain request but must not restore access.
$closureReconciled=$core->reconcile('operator','closure-owner',$closureRequestKey,'failed',[
    'note'=>'Independent site evidence confirms the pre-suspension write did not complete.',
    'receipt_sha256'=>str_repeat('d',64)
],$now+22);
bcheck(($closureReconciled['state']??'')==='failed'&&(int)($closureReconciled['charged_units']??-1)===0,'Suspended-account reconciliation failed to settle old uncertain request');
$closureAfterReconcile=$store->row('SELECT state FROM '.$store->table('requests').' WHERE request_key=?',[$closureRequestKey]);
bcheck(($closureAfterReconcile['state']??'')==='failed','Suspended-account reconciliation did not persist terminal state');
breject(fn()=>$core->authenticate($closureBearer['access_token'],$now+22),'invalid_or_expired_token');
$core->ensureAccount('closure-owner');
breject(fn()=>$core->sites('closure-owner'),'account_unavailable');
breject(fn()=>$core->closureInventory('closure-owner'),'account_unavailable');

// Future operator recovery uses the audited Core API. Reactivation restores
// only the account row and never resurrects revoked bearer/OAuth/site access.
$reactivated=$core->reactivateAccount('operator','closure-owner',$now+23);
bcheck(!empty($reactivated['reactivated'])&&empty($reactivated['old_access_restored'])&&!empty($reactivated['reconnect_required'])&&!empty($reactivated['oauth_reauthorization_required']),'Operator reactivation contract wrong');
$closureSequenceReactivated=(int)($store->row('SELECT sequence FROM '.$store->table('accounts').' WHERE owner=?',['closure-owner'])['sequence']??0);
bcheck($closureSequenceReactivated===$closureSequenceSuspended+1,'Reactivation did not bump account sequence exactly once');
$reactivateAudit=$store->rows('SELECT event,request_key FROM '.$store->table('audit').' WHERE owner=? AND event=?',['closure-owner','operator_account_reactivated']);
bcheck(count($reactivateAudit)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)($reactivateAudit[0]['request_key']??''))===1&&!str_contains((string)$reactivateAudit[0]['request_key'],'operator'),'Reactivation audit did not hash operator identity safely');
breject(fn()=>$core->reactivateAccount('operator','closure-owner',$now+24),'account_already_active');
$reopened=$core->closureInventory('closure-owner');
bcheck(($reopened['active_sites']??-1)===0&&($reopened['active_tokens']??-1)===0&&($reopened['active_oauth_grants']??-1)===0&&($reopened['unused_oauth_codes']??-1)===0,'Re-enabled account resurrected suspended access state');
breject(fn()=>$core->authenticate($closureBearer['access_token'],$now+23),'invalid_or_expired_token');
breject(fn()=>$closureOAuth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$closureClient,'code'=>'closure-code',
    'redirect_uri'=>$closureRedirect,'code_verifier'=>$closureVerifier,'resource'=>'https://wpaiadmin.ru/mcp'
],$now+23,fn($owner)=>true),'invalid_grant');
breject(fn()=>$closureOAuth->exchange([
    'grant_type'=>'refresh_token','client_id'=>$closureClient,'refresh_token'=>$closureRefresh,
    'resource'=>'https://wpaiadmin.ru/mcp'
],$now+23,fn($owner)=>true),'invalid_grant');
breject(fn()=>$closureOAuth->approve('closure-owner',$closureAuth,[$closureSite['id']],$now+23),'invalid_site_consent');

// Recovery requires a genuinely fresh registration and fresh authorization.
$newClosureSite=$core->connect('closure-owner','https://closure.example.com','fixture-closure-site-token-fresh-bbbbbbbb',$now+24,true);
bcheck(($newClosureSite['id']??'')!==$closureSite['id'],'Reactivation reused revoked site registration id');
$oldClosureSiteRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$closureSite['id']]);
bcheck($oldClosureSiteRow===null || (($oldClosureSiteRow['status']??'')==='revoked'&&($oldClosureSiteRow['token_sealed']??'')===''),'Fresh reconnect resurrected old site registration or credential');

$newClosureBearer=$core->mint('closure-owner',[$newClosureSite['id']],['mcp:read'],$now+24);
$newClosureActor=$core->authenticate($newClosureBearer['access_token'],$now+24);
bcheck(($newClosureActor['owner']??'')==='closure-owner'&&$newClosureActor['site_ids']===[$newClosureSite['id']],'Fresh bearer after reactivation has wrong owner/site binding');
breject(fn()=>$core->authenticate($closureBearer['access_token'],$now+24),'invalid_or_expired_token');

$newClosureAuth=$closureAuth;
$newClosureAuth['state']='closure-state-fresh';
$newClosureCode=$closureOAuth->approve('closure-owner',$newClosureAuth,[$newClosureSite['id']],$now+24);
bcheck(is_string($newClosureCode)&&$newClosureCode!=='closure-code','Fresh OAuth consent did not issue a new code');
$newClosureOAuthToken=$closureOAuth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$closureClient,'code'=>$newClosureCode,
    'redirect_uri'=>$closureRedirect,'code_verifier'=>$closureVerifier,'resource'=>'https://wpaiadmin.ru/mcp'
],$now+25,fn($owner)=>$owner==='closure-owner');
bcheck(!empty($newClosureOAuthToken['access_token']),'Fresh OAuth exchange after reactivation did not issue access token');
$newOauthActor=$core->authenticate($newClosureOAuthToken['access_token'],$now+25);
bcheck($newOauthActor['site_ids']===[$newClosureSite['id']],'Fresh OAuth token after reactivation bound old registration');
breject(fn()=>$core->authenticate($closureBearer['access_token'],$now+25),'invalid_or_expired_token');

$site=$core->connect('boundary','https://boundary.example.com','fixture-boundary-admin-token-aaaaaaaa',$now);

// Token policy boundaries.
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:write'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read','mcp:read'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[],['mcp:read'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id'],$site['id']],['mcp:read'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read','admin:all'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',array_fill(0,Core::MAX_SITES+1,$site['id']),['mcp:read'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read'],$now,59),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read'],$now,3601),'invalid_token_policy');

$token=$core->mint('boundary',[$site['id']],['mcp:read','mcp:write'],$now);
$actor=$core->authenticate($token['access_token'],$now);
$readOnly=$core->mint('boundary',[$site['id']],['mcp:read'],$now);
$readOnlyActor=$core->authenticate($readOnly['access_token'],$now);
breject(fn()=>$core->call($readOnlyActor,$site['id'],'operation.apply',['id'=>1],'dotted-readonly-apply',$now),'scope_or_site_denied');
breject(fn()=>$core->call($readOnlyActor,$site['id'],'operation.rollback',['id'=>1],'dotted-readonly-rollback',$now),'scope_or_site_denied');
// Dynamic wordpress.execute scope classification: read mode is allowed to read-only actors, write mode is not.
$readExec=$core->call($readOnlyActor,$site['id'],'wordpress_execute',['mode'=>'read','command'=>'wp option get blogname'],'wordpress-read-mode',$now);
bcheck(($readExec['state']??'')==='succeeded','wordpress.execute read mode unexpectedly required write scope');
breject(fn()=>$core->call($readOnlyActor,$site['id'],'wordpress_execute',['mode'=>'write','command'=>'wp option update blogname x'],'wordpress-write-mode',$now),'scope_or_site_denied');
// Commercial truthfulness: batch mutation calls stay disabled until receipt accounting is ready, and must never reach upstream.
$beforeBatchExec=$transport->executions;
breject(fn()=>$core->call($actor,$site['id'],'batch.create',['items'=>[]],'batch-not-billable',$now),'batch_receipt_accounting_not_ready');
bcheck($transport->executions===$beforeBatchExec,'Disabled batch accounting gate still reached upstream');

// Sealed site credentials are bound to their exact tenant/site record. Ciphertext substitution must fail closed.
$site2=$core->connect('boundary','https://boundary-two.example.com','fixture-boundary-admin-token-bbbbbbbb',$now+1);
$sealed1=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']])['token_sealed'];
$sealed2=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site2['id']])['token_sealed'];
$store->execute('UPDATE '.$store->table('sites').' SET token_sealed=? WHERE id=?',[$sealed2,$site['id']]);
breject(fn()=>$core->catalog($actor,$site['id'],$now+1),'secret_binding_failed');
$store->execute('UPDATE '.$store->table('sites').' SET token_sealed=? WHERE id=?',[$sealed1,$site['id']]);
bcheck(is_array($core->catalog($actor,$site['id'],$now+1)),'Restored sealed site credential did not recover');

// An actor authenticated before account suspension must not retain access after suspension.
$store->execute('UPDATE '.$store->table('accounts').' SET disabled=1 WHERE owner=?',['boundary']);
breject(fn()=>$core->authenticate($token['access_token'],$now+1),'account_unavailable');
breject(fn()=>$core->catalog($actor,$site['id'],$now+1),'account_unavailable');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read'],$now+1),'account_unavailable');
$store->execute('UPDATE '.$store->table('accounts').' SET disabled=0 WHERE owner=?',['boundary']);
bcheck(is_array($core->catalog($actor,$site['id'],$now+1)),'Account restore fixture did not recover');

// Oversized arguments must fail before upstream execution.
$before=$transport->executions;
breject(fn()=>$core->call($actor,$site['id'],'wp_get_site',['blob'=>str_repeat('x',70000)],'oversized-args',$now),'arguments_too_large');
bcheck($transport->executions===$before,'Oversized arguments reached upstream');

// Batch mutations are blocked until receipt accounting is ready.
breject(fn()=>$core->call($actor,$site['id'],'batch_create',['items'=>[]],'blocked-batch',$now),'batch_receipt_accounting_not_ready');

// Quota charges only successful billable calls; failed upstream call consumes zero.
$failed=$core->call($actor,$site['id'],'wp_get_site',['fail'=>true],'failed-no-charge',$now);
bcheck($failed['state']==='failed'&&$failed['charged_units']===0,'Failed call charged quota');
bcheck($core->usage('boundary',$now)['used']===0,'Failed call increased used quota');
$ok1=$core->call($actor,$site['id'],'wp_get_site',[],'billable-one',$now);
$ok2=$core->call($actor,$site['id'],'wp_get_site',[],'billable-two',$now);
bcheck($ok1['charged_units']===1&&$ok2['charged_units']===1,'Successful calls not charged once each');
breject(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'over-quota',$now),'monthly_quota_exhausted');

// Same nonce with different fingerprint is rejected and never redispatched.
$core2=fixtureCore($store,$transport,100);
$nonce='fingerprint-conflict';
$first=$core2->call($actor,$site['id'],'operation_verify',['id'=>1],$nonce,$now);
$execAfter=$transport->executions;
breject(fn()=>$core2->call($actor,$site['id'],'operation_verify',['id'=>2],$nonce,$now),'idempotency_conflict');
bcheck($transport->executions===$execAfter,'Idempotency conflict reached upstream');

// Per-owner HTTP throttle must close after the configured window count.
for($i=0;$i<120;$i++) $core2->throttle('boundary','relbound-rate',$now,120);
breject(fn()=>$core2->throttle('boundary','relbound-rate',$now,120),'rate_limit_exceeded');

// Ledger API is bounded regardless of caller-supplied limit and remains owner-scoped.
$core2->ensureAccount('ledger-owner');
$ledgerSite=$core2->connect('ledger-owner','https://ledger.example.com','fixture-ledger-site-token-aaaaaaaa',$now+2000);
$ledgerToken=$core2->mint('ledger-owner',[$ledgerSite['id']],['mcp:read'],$now+2000);
$ledgerActor=$core2->authenticate($ledgerToken['access_token'],$now+2000);
for($i=0;$i<105;$i++){
    $core2->call($ledgerActor,$ledgerSite['id'],'wp_get_site',[],'ledger-'.$i,$now+2001+$i);
}
$ledgerMax=$core2->ledger('ledger-owner',1000);
bcheck(count($ledgerMax)===100,'Ledger limit was not clamped to 100');
bcheck(count(array_filter($ledgerMax,fn($r)=>($r['site_id']??'')!==$ledgerSite['id']))===0,'Ledger leaked another site/owner');
$ledgerOne=$core2->ledger('ledger-owner',0);
bcheck(count($ledgerOne)===1,'Ledger lower bound was not clamped to 1');

// Rate-limit housekeeping removes buckets older than the 24h minute window.
$core2->ensureAccount('rate-cleanup');
$oldSlot=intdiv($now-200000,60);
$store->execute('INSERT INTO '.$store->table('rate').' (owner,bucket,slot,hits) VALUES (?,?,?,?)',['rate-cleanup','old-bucket',$oldSlot,1]);
$core2->throttle('rate-cleanup','fresh-bucket',$now,5);
$oldRate=$store->row('SELECT owner FROM '.$store->table('rate').' WHERE owner=? AND slot<?',['rate-cleanup',intdiv($now,60)-1440]);
bcheck($oldRate===null,'Rate-limit housekeeping retained stale bucket');

// Site-count boundary is enforced per tenant. The 51st active registration must fail without creating a row.
$core2->ensureAccount('site-limit');
for($i=1;$i<=Core::MAX_SITES;$i++){
    $host='https://limit-'.$i.'.example.com';
    $core2->connect('site-limit',$host,'fixture-site-limit-token-'.str_pad((string)$i,24,'x'),$now+($i*60));
}
bcheck(count($core2->sites('site-limit'))===Core::MAX_SITES,'Site-limit fixture did not reach exact maximum');
breject(fn()=>$core2->connect('site-limit','https://limit-overflow.example.com','fixture-site-limit-token-overflow-xxxxxxxx',$now+((Core::MAX_SITES+1)*60)),'pilot_site_count_limit');
bcheck(count($core2->sites('site-limit'))===Core::MAX_SITES,'Rejected 51st site changed tenant registry');


// Upstream catalog must fail closed if a compromised/misbehaving site advertises more than 500 tools.
final class OversizedCatalogTransport implements Transport {
    public function rpc(string $home,string $token,array $request): array {
        if(($request['method']??'')==='tools/list'){
            $tools=[];for($i=0;$i<501;$i++)$tools[]=['name'=>'tool_'.$i,'inputSchema'=>['type'=>'object']];
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>$tools]];
        }
        if(($request['params']['name']??'')==='system_manifest'){
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode([
                'plugin_name'=>'WP AI Admin','site_id'=>'oversized-plugin','installation_id'=>'oversized-install',
                'release'=>['production_defaults'=>['direct_live_writes'=>false]]
            ])]]]];
        }
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[]]];
    }
}
$oversizedCore=new Core($store,new WPAICloudGateway\Vault(str_repeat('z',32)),new OversizedCatalogTransport(),json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true));
$oversizedCore->ensureAccount('oversized');
$oversizedSite=$oversizedCore->connect('oversized','https://oversized.example.com','fixture-oversized-site-token-aaaaaaaa',$now);
$oversizedToken=$oversizedCore->mint('oversized',[$oversizedSite['id']],['mcp:read'],$now);
$oversizedActor=$oversizedCore->authenticate($oversizedToken['access_token'],$now);
breject(fn()=>$oversizedCore->catalog($oversizedActor,$oversizedSite['id'],$now),'invalid_upstream_catalog');

// Re-running storage migration on an active database must be idempotent and preserve commercial/tenant state.
$beforeAccount=$store->row('SELECT owner,plan,sequence,disabled FROM '.$store->table('accounts').' WHERE owner=?',['boundary']);
$beforeSites=$store->rows('SELECT id,owner,home,status FROM '.$store->table('sites').' WHERE owner=? ORDER BY id',['boundary']);
$beforeBalances=$store->rows('SELECT owner,period,used,reserved FROM '.$store->table('balances').' WHERE owner=? ORDER BY period',['boundary']);
$beforeRequests=$store->rows('SELECT request_key,owner,site_id,state,reserved_units,policy_version FROM '.$store->table('requests').' WHERE owner=? ORDER BY request_key',['boundary']);
$store->migrate();
$afterAccount=$store->row('SELECT owner,plan,sequence,disabled FROM '.$store->table('accounts').' WHERE owner=?',['boundary']);
$afterSites=$store->rows('SELECT id,owner,home,status FROM '.$store->table('sites').' WHERE owner=? ORDER BY id',['boundary']);
$afterBalances=$store->rows('SELECT owner,period,used,reserved FROM '.$store->table('balances').' WHERE owner=? ORDER BY period',['boundary']);
$afterRequests=$store->rows('SELECT request_key,owner,site_id,state,reserved_units,policy_version FROM '.$store->table('requests').' WHERE owner=? ORDER BY request_key',['boundary']);
bcheck(Security::canonical($afterAccount)===Security::canonical($beforeAccount),'Repeated migrate changed account state');
bcheck(Security::canonical($afterSites)===Security::canonical($beforeSites),'Repeated migrate changed site registry');
bcheck(Security::canonical($afterBalances)===Security::canonical($beforeBalances),'Repeated migrate changed balances');
bcheck(Security::canonical($afterRequests)===Security::canonical($beforeRequests),'Repeated migrate changed request ledger');

// MySQL deployments must fail closed if a critical table loses transactional InnoDB semantics.
if(getenv('TEST_MYSQL_DSN')){
    $requestsTable=$store->table('requests');
    $store->execute('ALTER TABLE '.$requestsTable.' ENGINE=MyISAM');
    breject(fn()=>$store->migrate(),'innodb_required');
    $store->execute('ALTER TABLE '.$requestsTable.' ENGINE=InnoDB');
    $store->migrate();
    bcheck(true,'InnoDB restoration failed');
}

echo 'PASS '.$checks.' release boundary assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
