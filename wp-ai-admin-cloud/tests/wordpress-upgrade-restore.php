<?php
// WP-CLI eval-file uses eval(); no strict_types declaration here.
use WPAICloudGateway\{Core,Fault,OAuth,Security,Settings,Signer,Store,Transport,Vault,WordPress};

if (!defined('WP_CLI') || !WP_CLI || getenv('OAI_DISPOSABLE_WP_TEST')!=='1') throw new RuntimeException('Disposable WP-CLI opt-in required');
$phase=getenv('WPAI_CLOUD_UPGRADE_PHASE');
$snapshot=getenv('WPAI_CLOUD_UPGRADE_SNAPSHOT');
if (!in_array($phase,['seed','upgraded','repeat','restored','upgraded-again'],true) || !$snapshot) throw new RuntimeException('Explicit Cloud acceptance phase/snapshot required');
function cloudUpgradeAssert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function cloudUpgradeSame(mixed $a,mixed $b): bool { return Security::canonical($a)===Security::canonical($b); }
function cloudUpgradeReject(callable $fn,string $reason): void {
    try { $fn(); } catch(Fault $e) { cloudUpgradeAssert($e->reason===$reason,'Unexpected denial reason'); return; }
    throw new RuntimeException('Expected denial missing: '.$reason);
}
function cloudUpgradeRest(string $route,array $body,bool $form=false): WP_REST_Response {
    $request=new WP_REST_Request('POST',$route);
    $request->set_header('content-type',$form?'application/x-www-form-urlencoded':'application/json');
    $request->set_body($form?http_build_query($body,'','&',PHP_QUERY_RFC3986):wp_json_encode($body));
    return rest_do_request($request);
}
function cloudUpgradeMcp(string $token,string $method,array $params): WP_REST_Response {
    $request=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
    $request->set_header('content-type','application/json');
    $request->set_header('accept','application/json, text/event-stream');
    $request->set_header('authorization','Bearer '.$token);
    $request->set_body(wp_json_encode(['jsonrpc'=>'2.0','id'=>'upgrade-check','method'=>$method,'params'=>$params]));
    return rest_do_request($request);
}
final class CloudUpgradeTransport implements Transport {
    public int $executions=0;
    public function rpc(string $home,string $token,array $request): array {
        $expected=['https://upgrade-a.example.com'=>'synthetic-cloud-site-a-token','https://upgrade-b.example.com'=>'synthetic-cloud-site-b-token'];
        cloudUpgradeAssert(isset($expected[$home]) && hash_equals($expected[$home],$token),'Sealed site credential changed');
        if ($request['method']==='tools/list') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>[['name'=>'wp_get_site','inputSchema'=>['type'=>'object']],['name'=>'operation_apply','inputSchema'=>['type'=>'object']]]]];
        if (($request['params']['name']??'')==='system_manifest') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>Security::canonical(['plugin_name'=>'WP AI Admin','site_id'=>'synthetic-site-'.hash('sha256',$home),'installation_id'=>'synthetic-install-'.hash('sha256',$home),'release'=>['production_defaults'=>['direct_live_writes'=>false]]])]]]];
        $this->executions++;
        if (($request['params']['name']??'')==='operation_apply') throw new Fault('upstream_outcome_unknown',502);
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>'synthetic retained read']],'isError'=>false]];
    }
}
function cloudUpgradeRows(Store $store,array $only=[]): array {
    $rows=[];
    // Authentication legitimately increments rate counters; retained business,
    // credential and audit rows must remain byte-for-byte equivalent.
    $names=['accounts','balances','sites','tokens','requests','batch_steps','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access'];
    if($only!==[]) $names=array_values(array_intersect($names,$only));
    foreach($names as $name) {
        $items=array_map([Security::class,'canonical'],$store->rows('SELECT * FROM '.$store->table($name)));
        sort($items,SORT_STRING); $rows[$name]=$items;
    }
    return $rows;
}
function cloudUpgradeOptions(): array {
    global $wpdb;
    return $wpdb->get_results("SELECT option_name,option_value,autoload FROM $wpdb->options WHERE option_name IN ('wpai_gateway_enabled','wpai_gateway_wrapped_keys_v1','wpai_gateway_db_version','wpai_gateway_batch_object_v1','wpai_gateway_quota_cutover_v1','wpai_cloud_upgrade_sentinel') ORDER BY option_name",ARRAY_A);
}
// Real WP REST callbacks and database, with explicit fixture TLS context. This
// does not stand in for external HTTPS, browser consent or ChatGPT acceptance.
$_SERVER['HTTPS']='on';
wp_set_current_user(1);
cloudUpgradeAssert(current_user_can('manage_options'),'Disposable administrator missing');
global $wpdb;
$host=DB_HOST; $port=3306; $socket=null;
if(preg_match('/^([^:]+):(\d+)$/D',$host,$m)) { $host=$m[1]; $port=(int)$m[2]; }
elseif(preg_match('#^[^:]+:(/.*)$#D',$host,$m)) $socket=$m[1];
elseif(str_contains($host,':')) throw new RuntimeException('Unsupported disposable DB host');
$dsn=defined('WP_AI_CLOUD_DSN')?WP_AI_CLOUD_DSN:('mysql:'.($socket?'unix_socket='.$socket:'host='.$host.';port='.$port).';dbname='.DB_NAME.';charset=utf8mb4');
$store=new Store(new PDO($dsn,DB_USER,DB_PASSWORD,[PDO::ATTR_EMULATE_PREPARES=>false]),$wpdb->prefix.'wpai_gateway_');
if($phase==='seed') {
    cloudUpgradeAssert(WPAI_CLOUD_VERSION==='0.3.11','Historical Cloud source not installed');
    WordPress::enable();
} else {
    cloudUpgradeAssert(WPAI_CLOUD_VERSION===($phase==='restored'?'0.3.11':'0.3.13-dev'),'Unexpected Cloud source version');
}
cloudUpgradeAssert(WordPress::configured() && (string)get_option('wpai_gateway_db_version')==='4','Gateway normal boot failed');
// Only the 0.3.13-dev candidate owns the additive operational-index migration.
// The restored 0.3.11 phase must remain byte-for-byte historical.
if (WPAI_CLOUD_VERSION==='0.3.13-dev') {
    update_option('wpai_gateway_operational_indexes_v1','0',false);
    wp_cache_delete('wpai_gateway_operational_indexes_v1','options');
    wp_cache_delete('alloptions','options');
    wp_cache_delete('notoptions','options');
    WordPress::enable();
    cloudUpgradeAssert((string)get_option('wpai_gateway_operational_indexes_v1')==='1','Existing schema-v4 installation did not record the operational-index migration');
    $siteIndexName='wpai_'.substr(hash('sha256',$wpdb->prefix.'wpai_gateway_'),0,16).'_idx_sites_owner_status_created';
    $siteIndexes=$store->rows('SHOW INDEX FROM '.$store->table('sites'));
    cloudUpgradeAssert((bool)array_filter($siteIndexes,static fn($row)=>(string)($row['Key_name']??'')===$siteIndexName),'Existing schema-v4 installation did not receive the owner/status index');
    cloudUpgradeAssert((string)get_option('wpai_gateway_db_version')==='4','Operational-index migration changed the Gateway schema version');
}
$expectBatch=in_array($phase,['upgraded','repeat','upgraded-again'],true);
cloudUpgradeAssert(WordPress::operatorCore()->batchAvailable()===$expectBatch,'Batch state does not match the installed source');
$keys=Settings::keys(); $vault=new Vault($keys['vault']); $signer=new Signer($keys['signing']);
$transport=new CloudUpgradeTransport();
$core=new Core($store,$vault,$transport,Settings::policy(),$signer);
$oauth=WordPress::oauth();
if($phase==='seed') {
    $now=time();
    $other=wp_insert_user(['user_login'=>'synthetic-cloud-upgrade-owner','user_pass'=>wp_generate_password(40),'user_email'=>'upgrade-owner@example.invalid','role'=>'administrator']);
    cloudUpgradeAssert(!is_wp_error($other),'Cannot create second fixture owner');
    Settings::account('1'); Settings::account((string)$other);
    $core->ensureAccount('1'); $core->ensureAccount((string)$other);
    $store->execute("UPDATE ".$store->table('accounts')." SET plan='pro',quota_scheme='legacy_monthly_v1' WHERE owner='1'");
    $site=$core->connect('1','https://upgrade-a.example.com','synthetic-cloud-site-a-token',$now);
    $siteB=$core->connect((string)$other,'https://upgrade-b.example.com','synthetic-cloud-site-b-token',$now);
    $period=$core->usage('1',$now)['period'];
    $store->execute('INSERT INTO '.$store->table('balances').' (owner,period,used,reserved) VALUES (?,?,?,?)',['1',$period,7,0]);
    $registered=cloudUpgradeRest('/wpai-cloud/v1/oauth/register',['client_name'=>'Synthetic upgrade client','redirect_uris'=>['https://example.invalid/oauth/callback']]);
    cloudUpgradeAssert($registered->get_status()===201,'DCR REST handler failed');
    $client=$registered->get_data(); $verifier=str_repeat('v',64);
    $query=['client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'response_type'=>'code','resource'=>Settings::endpoint(),'scope'=>'mcp:read mcp:write','code_challenge'=>Security::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256','state'=>'synthetic-upgrade-state'];
    $auth=$oauth->authorization($query); $code=$oauth->approve('1',$auth,[$site['id']],$now);
    $exchange=cloudUpgradeRest('/wpai-cloud/v1/oauth/token',['grant_type'=>'authorization_code','client_id'=>$client['client_id'],'code'=>$code,'redirect_uri'=>$query['redirect_uri'],'code_verifier'=>$verifier,'resource'=>Settings::endpoint()],true);
    cloudUpgradeAssert($exchange->get_status()===200,'Code exchange REST handler failed');
    $token=$exchange->get_data();
    cloudUpgradeAssert(isset($token['access_token']) && !isset($token['refresh_token']),'OAuth must issue only a short-lived access token');
    $revoked=$core->mint('1',[$site['id']],['mcp:read'],$now);
    $core->revokeToken('1',hash('sha256',$revoked['access_token']),$now);
    $otherToken=$core->mint((string)$other,[$siteB['id']],['mcp:read'],$now);
    $actor=WordPress::authenticateHeader('Bearer '.$token['access_token']);
    $receipt=$core->call($actor,$site['id'],'wp_get_site',[],'synthetic-upgrade-read',$now);
    cloudUpgradeAssert($receipt['state']==='succeeded','Cannot seed retained receipt');
    cloudUpgradeReject(fn()=>$core->call($actor,$site['id'],'operation_apply',[],'synthetic-upgrade-uncertain',$now),'request_outcome_pending_reconciliation');
    $oauth->approve('1',$auth,[$site['id']],$now); // Retain an unused code alongside the used one.
    update_option('wpai_cloud_upgrade_sentinel','before-upgrade',false);
    $state=['now'=>$now,'site'=>$site,'other_site'=>$siteB,'owner_b'=>(string)$other,'token'=>$token,'other_token'=>$otherToken,'revoked_token'=>$revoked,'client'=>$client,'receipt'=>$receipt,'usage'=>$core->usage('1',$now),'foundation'=>$signer->foundation($site,1,$now),'public_key'=>$signer->publicKey(),'keys_sha256'=>hash('sha256',$keys['vault'].$keys['signing'])];
    cloudUpgradeAssert($state['usage']['used']===8 && $state['usage']['reserved']===1 && $state['usage']['legacy_terms']===true,'Legacy usage/uncertain reservation not seeded');
    $state['rows']=cloudUpgradeRows($store); $state['options']=cloudUpgradeOptions();
    $state['tables']=$wpdb->get_col('SHOW TABLES');
    // Only disposable credentials are written, in a private temporary file;
    // no values, raw HTTP bodies or database dumps are sent to CI artifacts/logs.
    cloudUpgradeAssert(file_put_contents($snapshot,Security::canonical($state))!==false,'Cannot persist private fixture snapshot');
} else {
    $state=json_decode(file_get_contents($snapshot),true,512,JSON_THROW_ON_ERROR);
    $retained=['balances','sites','tokens','requests','batch_steps','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access'];
    // The daily cutover changes the account and audit rows only; all retained
    // payment-independent ledgers, credentials, sites and OAuth rows stay exact.
    cloudUpgradeAssert(cloudUpgradeSame(cloudUpgradeRows($store,$retained),array_intersect_key($state['rows'],array_flip($retained))),'Retained ledger/site/OAuth rows changed');
    if(WPAI_CLOUD_VERSION==='0.3.11') {
        cloudUpgradeAssert(cloudUpgradeSame(cloudUpgradeRows($store),$state['rows']),'Restored historical source altered retained rows');
    }
    $actualOptions=cloudUpgradeOptions();
    if(WPAI_CLOUD_VERSION!=='0.3.11') {
        $cutoverOption=null;
        foreach($actualOptions as $option) {
            if(($option['option_name']??'')==='wpai_gateway_quota_cutover_v1') $cutoverOption=(string)($option['option_value']??'');
        }
        $actualOptions=array_values(array_filter($actualOptions,static fn($option)=>($option['option_name']??'')!=='wpai_gateway_quota_cutover_v1'));
        cloudUpgradeAssert($cutoverOption==='1','Daily quota cutover marker was not persisted exactly once');
    }
    cloudUpgradeAssert(cloudUpgradeSame($actualOptions,$state['options']),'Gateway keys/settings or sentinel changed outside the additive quota cutover marker');
    $actualTables=$wpdb->get_col('SHOW TABLES');
    $expectedTables=$state['tables'];
    if ($expectBatch) {
        $expectedTables[]=$wpdb->prefix.'wpai_gateway_sse_sessions';
        $expectedTables[]=$wpdb->prefix.'wpai_gateway_sse_queue';
    }
    $expectedTables=array_values(array_unique($expectedTables));
    sort($expectedTables,SORT_STRING);
    sort($actualTables,SORT_STRING);
    cloudUpgradeAssert($actualTables===$expectedTables,'Database table set changed or additive SSE queue schema missing');
}
$transport->executions=0;
Settings::prepareKeys();
// When this PR is stacked on the Cloud Store candidate, prove the additive SSE
// schema and queue lifecycle do not alter the existing encrypted key identity.
if (method_exists($store,'ensureSseQueueSchema')) {
    $keysBeforeSse=Settings::keys();
    $store->ensureSseQueueSchema();
    $sseSession=bin2hex(random_bytes(16));
    $ssePayload=json_encode(['key_export_coexistence'=>true],JSON_THROW_ON_ERROR);
    $store->createSseSession($sseSession,time()+3600);
    $store->enqueueSseMessage($sseSession,$ssePayload,time(),time()+3600);
    cloudUpgradeAssert($store->drainSseQueue($sseSession,time())===[$ssePayload],'SSE queue did not round-trip during key preservation acceptance');
    $store->deleteSseSession($sseSession);
    cloudUpgradeAssert(Settings::keys()===$keysBeforeSse,'SSE queue schema changed active Cloud encryption/signing keys');
}
    // Export the exact effective keys to a private file outside the WordPress root.
    $exportScript=dirname(__DIR__).'/export-keys.php';
    $exportPath=$snapshot.'.keys.php';
    $exportCommand=escapeshellarg(PHP_BINARY).' '.escapeshellarg($exportScript).' '.escapeshellarg(rtrim(ABSPATH,'/\\\\')).' '.escapeshellarg($exportPath);
    $exportProcess=proc_open($exportCommand,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$exportPipes);
    if(!is_resource($exportProcess))throw new RuntimeException('Could not start Cloud key export');
    fclose($exportPipes[0]);
    $exportStdout=stream_get_contents($exportPipes[1]);$exportStderr=stream_get_contents($exportPipes[2]);
    fclose($exportPipes[1]);fclose($exportPipes[2]);$exportStatus=proc_close($exportProcess);
    cloudUpgradeAssert($exportStatus===0 && is_file($exportPath),'Private key export failed: '.$exportStderr);
    cloudUpgradeAssert((fileperms($exportPath)&0777)===0600,'Exported key snippet mode is not 0600');
    $exportSnippet=(string)file_get_contents($exportPath);
    $vaultB64=base64_encode($keys['vault']);$signingB64=base64_encode($keys['signing']);
    cloudUpgradeAssert(str_contains($exportSnippet,"define('WP_AI_CLOUD_VAULT_KEY', '".$vaultB64."');"),'Exported vault key differs from active key');
    cloudUpgradeAssert(str_contains($exportSnippet,"define('WP_AI_CLOUD_SIGNING_KEY', '".$signingB64."');"),'Exported signing key differs from active key');
    cloudUpgradeAssert(!str_contains($exportStdout,$vaultB64) && !str_contains($exportStdout,$signingB64) && !str_contains($exportStderr,$vaultB64) && !str_contains($exportStderr,$signingB64),'Key material leaked to process output');
    $again=proc_open($exportCommand,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$againPipes);
    if(!is_resource($again))throw new RuntimeException('Could not test key export overwrite guard');
    fclose($againPipes[0]);$againOut=stream_get_contents($againPipes[1]);$againErr=stream_get_contents($againPipes[2]);fclose($againPipes[1]);fclose($againPipes[2]);
    cloudUpgradeAssert(proc_close($again)!==0 && (string)file_get_contents($exportPath)===$exportSnippet,'Key export overwrote an existing file');
    // Simulate another process winning after the initial file_exists() check:
    // a MU plugin creates the destination during wp-load.php bootstrap.
    $racePath=$snapshot.'.raced.php';
    $muDir=WPMU_PLUGIN_DIR;
    if(!is_dir($muDir) && !wp_mkdir_p($muDir))throw new RuntimeException('Could not create MU plugin fixture directory');
    $racePlugin=$muDir.'/wpai-key-export-race-'.bin2hex(random_bytes(6)).'.php';
    $raceMarker='created-by-concurrent-exporter';
    $raceCode="<?php\nfile_put_contents(".var_export($racePath,true).", ".var_export($raceMarker,true).", LOCK_EX);\n";
    if(file_put_contents($racePlugin,$raceCode)===false)throw new RuntimeException('Could not create key export race fixture');
    try {
        $raceCommand=escapeshellarg(PHP_BINARY).' '.escapeshellarg($exportScript).' '.escapeshellarg(rtrim(ABSPATH,'/\\\\')).' '.escapeshellarg($racePath);
        $race=proc_open($raceCommand,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$racePipes);
        if(!is_resource($race))throw new RuntimeException('Could not start key export race fixture');
        fclose($racePipes[0]);$raceOut=stream_get_contents($racePipes[1]);$raceErr=stream_get_contents($racePipes[2]);fclose($racePipes[1]);fclose($racePipes[2]);
        $raceStatus=proc_close($race);
        cloudUpgradeAssert($raceStatus!==0 && is_file($racePath) && (string)file_get_contents($racePath)===$raceMarker,'Exporter removed a file created by a competing process');
    } finally {
        @unlink($racePlugin);
        @unlink($racePath);
    }
    $insidePath=rtrim(ABSPATH,'/\\\\').'/wpai-key-export-rejected-'.bin2hex(random_bytes(6)).'.php';
    $insideCommand=escapeshellarg(PHP_BINARY).' '.escapeshellarg($exportScript).' '.escapeshellarg(rtrim(ABSPATH,'/\\\\')).' '.escapeshellarg($insidePath);
    $inside=proc_open($insideCommand,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$insidePipes);
    if(!is_resource($inside))throw new RuntimeException('Could not test web-root key export guard');
    fclose($insidePipes[0]);stream_get_contents($insidePipes[1]);stream_get_contents($insidePipes[2]);fclose($insidePipes[1]);fclose($insidePipes[2]);
    cloudUpgradeAssert(proc_close($inside)!==0 && !file_exists($insidePath),'Key export accepted a path inside the web root');
    @unlink($exportPath);

cloudUpgradeAssert(hash_equals($state['keys_sha256'],hash('sha256',$keys['vault'].$keys['signing'])) && Settings::keys()===$keys,'Vault/signing key changed');
cloudUpgradeAssert(cloudUpgradeSame(cloudUpgradeOptions(),$state['options']),'Repeated key preparation rewrote wrapped keys');
$keyAutoload=$wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name='wpai_gateway_wrapped_keys_v1'");
cloudUpgradeAssert(in_array($keyAutoload,['no','off','auto-off'],true),'Wrapped keys became autoloaded');
cloudUpgradeAssert($signer->publicKey()===$state['public_key'],'Signing identity changed');
cloudUpgradeAssert(sodium_crypto_sign_verify_detached(base64_decode(strtr($state['foundation']['signature'],'-_','+/')),"OAI-ENTITLEMENT-V1\n".$state['foundation']['payload'],sodium_crypto_sign_publickey_from_secretkey($keys['signing'])),'Historical signature no longer verifies');
$actor=WordPress::authenticateHeader('Bearer '.$state['token']['access_token']);
cloudUpgradeAssert($actor['owner']==='1' && $actor['site_ids']===[$state['site']['id']] && $actor['scopes']===['mcp:read','mcp:write'],'OAuth owner/site/scope changed');
cloudUpgradeReject(fn()=>WordPress::authenticateHeader('Bearer '.$state['revoked_token']['access_token']),'invalid_or_expired_token');
cloudUpgradeReject(fn()=>WordPress::authenticateHeader('Bearer wpai_cloud_'.str_repeat('z',43)),'invalid_or_expired_token');
$otherActor=WordPress::authenticateHeader('Bearer '.$state['other_token']['access_token']);
cloudUpgradeReject(fn()=>$core->catalog($actor,$state['other_site']['id'],time()),'scope_or_site_denied');
cloudUpgradeReject(fn()=>$core->catalog($otherActor,$state['site']['id'],time()),'scope_or_site_denied');
cloudUpgradeAssert(count($core->catalog($actor,$state['site']['id'],time()))===2,'Historical sealed site credential failed');
$cached=$core->call($actor,$state['site']['id'],'wp_get_site',[],'synthetic-upgrade-read',$state['now']);
cloudUpgradeAssert(cloudUpgradeSame($cached,$state['receipt']) && $transport->executions===0,'Cached receipt lost/decryption failed/re-executed');
cloudUpgradeReject(fn()=>$core->call($actor,$state['site']['id'],'operation_apply',[],'synthetic-upgrade-uncertain',$state['now']),'request_outcome_pending_reconciliation');
$currentUsage=$core->usage('1',$state['now']);
$expectedUsage=$state['usage'];
// The policy version and Batch availability are release/capability flags, not
// quota terms: the version moves to the candidate and Batch follows the
// installed catalogue (asserted above), while every quota figure stays
// byte-equivalent.
$batchAvailable=$currentUsage['batch_available']??null;
unset($currentUsage['policy_version'],$currentUsage['batch_available'],$expectedUsage['policy_version'],$expectedUsage['batch_available']);
if(WPAI_CLOUD_VERSION==='0.3.11') {
    cloudUpgradeAssert($transport->executions===0 && cloudUpgradeSame($currentUsage,$expectedUsage),'Restored historical source altered quota/terms');
} else {
    // The retired monthly account is transferred once to the final daily
    // catalogue. Historical monthly balances remain unchanged for audit.
    $historicBalance=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=? AND period=?',['1',(string)$expectedUsage['period']]);
    cloudUpgradeAssert($historicBalance!==null && (int)$historicBalance['used']===(int)$expectedUsage['used'] && (int)$historicBalance['reserved']===(int)$expectedUsage['reserved'],'Cutover rewrote the historical monthly balance');
    cloudUpgradeAssert($currentUsage['plan']==='pro' && $currentUsage['quota_scheme']==='tariffs_v3' && $currentUsage['period_kind']==='calendar_day_utc' && $currentUsage['legacy_terms']===false,'Retired account was not transferred to the final daily catalogue');
    cloudUpgradeAssert($currentUsage['limit']===2000 && $currentUsage['used']===0 && $currentUsage['reserved']===0 && $currentUsage['remaining']===2000,'Cutover did not start the final PRO daily window');
    foreach(['period','period_start_utc','period_end_utc','next_period_at','quota_scheme','period_kind','legacy_terms','limit','used','reserved','remaining'] as $key) unset($currentUsage[$key],$expectedUsage[$key]);
    cloudUpgradeAssert($transport->executions===0 && cloudUpgradeSame($currentUsage,$expectedUsage),'Cutover altered unrelated usage metadata');
}
cloudUpgradeAssert($transport->executions===0,'Uncertain replay executed');
cloudUpgradeAssert($batchAvailable===$expectBatch,'Usage Batch availability does not match the installed source');
$cutoverAccount=$store->row('SELECT plan,quota_scheme FROM '.$store->table('accounts')." WHERE owner='1'");
if(WPAI_CLOUD_VERSION==='0.3.11') cloudUpgradeAssert($cutoverAccount['plan']==='pro' && $cutoverAccount['quota_scheme']==='legacy_monthly_v1','Historical source altered the retired account');
else cloudUpgradeAssert($cutoverAccount['plan']==='pro' && $cutoverAccount['quota_scheme']==='tariffs_v3','Retired account was not transferred once');
$policyPrefix=WPAI_CLOUD_VERSION==='0.3.11'?'tariffs-v2-':'tariffs-v5-';
cloudUpgradeAssert(str_starts_with($core->usage('1',$state['now'])['policy_version'],$policyPrefix),'Quota policy version does not match the installed source');
$health=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/health'))->get_data();
cloudUpgradeAssert($health['configured']===true && $health['oauth']===true && $health['version']===WPAI_CLOUD_VERSION,'Health REST handler failed');
$init=cloudUpgradeMcp($state['token']['access_token'],'initialize',['protocolVersion'=>'2025-06-18','capabilities'=>(object)[],'clientInfo'=>['name'=>'upgrade-fixture','version'=>'1']]);
cloudUpgradeAssert($init->get_status()===200 && ($init->get_data()['result']['protocolVersion']??'')==='2025-06-18','MCP initialize failed');
$sites=cloudUpgradeMcp($state['token']['access_token'],'tools/call',['name'=>'cloud_sites_list','arguments'=>(object)[]]);
$list=json_decode($sites->get_data()['result']['content'][0]['text']??'null',true);
cloudUpgradeAssert($sites->get_status()===200 && is_array($list) && array_column($list,'id')===[$state['site']['id']],'MCP site consent isolation failed');
if(in_array($phase,['repeat','upgraded-again'],true)) {
    $refresh=['grant_type'=>'refresh_token','client_id'=>$state['client']['client_id'],'resource'=>Settings::endpoint(),'refresh_token'=>'synthetic-legacy-refresh-token'];
    $rejected=cloudUpgradeRest('/wpai-cloud/v1/oauth/token',$refresh,true);
    cloudUpgradeAssert($rejected->get_status()===400 && ($rejected->get_data()['error']??'')==='unsupported_grant_type','Legacy refresh token remained usable after strict OAuth cutover');
    cloudUpgradeAssert(WordPress::authenticateHeader('Bearer '.$state['token']['access_token'])['site_ids']===[$state['site']['id']],'Rejecting a legacy refresh token invalidated the still-live access token');
    update_option('wpai_cloud_upgrade_sentinel','after-upgrade',false);
    $store->execute('UPDATE '.$store->table('balances')." SET used=used+11 WHERE owner='1'");
}
echo 'PASS: Cloud '.$phase.' wrapped keys/signature, site/OAuth isolation, REST/MCP, daily quota cutover, cached receipt and uncertain replay'."\n";
