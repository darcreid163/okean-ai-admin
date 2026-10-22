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
function cloudUpgradeRows(Store $store): array {
    $rows=[];
    // Authentication legitimately increments rate counters; retained business,
    // credential and audit rows must remain byte-for-byte equivalent.
    foreach(['accounts','balances','sites','tokens','requests','batch_steps','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access'] as $name) {
        $items=array_map([Security::class,'canonical'],$store->rows('SELECT * FROM '.$store->table($name)));
        sort($items,SORT_STRING); $rows[$name]=$items;
    }
    return $rows;
}
function cloudUpgradeOptions(): array {
    global $wpdb;
    return $wpdb->get_results("SELECT option_name,option_value,autoload FROM $wpdb->options WHERE option_name IN ('wpai_gateway_enabled','wpai_gateway_wrapped_keys_v1','wpai_gateway_db_version','wpai_gateway_batch_object_v1','wpai_cloud_upgrade_sentinel') ORDER BY option_name",ARRAY_A);
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
cloudUpgradeAssert(!WordPress::operatorCore()->batchAvailable(),'Batch became enabled');
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
    $query=['client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'response_type'=>'code','resource'=>Settings::endpoint(),'scope'=>'mcp:read mcp:write offline_access','code_challenge'=>Security::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256','state'=>'synthetic-upgrade-state'];
    $auth=$oauth->authorization($query); $code=$oauth->approve('1',$auth,[$site['id']],$now);
    $exchange=cloudUpgradeRest('/wpai-cloud/v1/oauth/token',['grant_type'=>'authorization_code','client_id'=>$client['client_id'],'code'=>$code,'redirect_uri'=>$query['redirect_uri'],'code_verifier'=>$verifier,'resource'=>Settings::endpoint()],true);
    cloudUpgradeAssert($exchange->get_status()===200,'Code exchange REST handler failed');
    $token=$exchange->get_data();
    cloudUpgradeAssert(isset($token['access_token'],$token['refresh_token']),'OAuth credentials missing');
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
    cloudUpgradeAssert(cloudUpgradeSame(cloudUpgradeRows($store),$state['rows']),'Retained ledger/site/OAuth rows changed');
    cloudUpgradeAssert(cloudUpgradeSame(cloudUpgradeOptions(),$state['options']),'Gateway keys/settings or sentinel changed');
    cloudUpgradeAssert($wpdb->get_col('SHOW TABLES')===$state['tables'],'Database table set changed');
}
$transport->executions=0;
Settings::prepareKeys();
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
cloudUpgradeAssert($transport->executions===0 && cloudUpgradeSame($core->usage('1',$state['now']),$state['usage']),'Uncertain replay executed or altered quota/terms');
$health=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/health'))->get_data();
cloudUpgradeAssert($health['configured']===true && $health['oauth']===true && $health['version']===WPAI_CLOUD_VERSION,'Health REST handler failed');
$init=cloudUpgradeMcp($state['token']['access_token'],'initialize',['protocolVersion'=>'2025-06-18','capabilities'=>(object)[],'clientInfo'=>['name'=>'upgrade-fixture','version'=>'1']]);
cloudUpgradeAssert($init->get_status()===200 && ($init->get_data()['result']['protocolVersion']??'')==='2025-06-18','MCP initialize failed');
$sites=cloudUpgradeMcp($state['token']['access_token'],'tools/call',['name'=>'cloud_sites_list','arguments'=>(object)[]]);
$list=json_decode($sites->get_data()['result']['content'][0]['text']??'null',true);
cloudUpgradeAssert($sites->get_status()===200 && is_array($list) && array_column($list,'id')===[$state['site']['id']],'MCP site consent isolation failed');
if(in_array($phase,['repeat','upgraded-again'],true)) {
    $refresh=['grant_type'=>'refresh_token','client_id'=>$state['client']['client_id'],'resource'=>Settings::endpoint(),'refresh_token'=>$state['token']['refresh_token']];
    $rotated=cloudUpgradeRest('/wpai-cloud/v1/oauth/token',$refresh,true);
    $new=$rotated->get_data();
    cloudUpgradeAssert($rotated->get_status()===200 && isset($new['access_token'],$new['refresh_token']) && $new['refresh_token']!==$state['token']['refresh_token'],'Retained refresh token did not rotate');
    cloudUpgradeAssert(WordPress::authenticateHeader('Bearer '.$new['access_token'])['site_ids']===[$state['site']['id']],'Refresh widened site consent');
    cloudUpgradeReject(fn()=>WordPress::authenticateHeader('Bearer '.$state['token']['access_token']),'invalid_or_expired_token');
    $replay=cloudUpgradeRest('/wpai-cloud/v1/oauth/token',$refresh,true);
    cloudUpgradeAssert($replay->get_status()===400 && ($replay->get_data()['error']??'')==='invalid_grant','Refresh replay accepted');
    cloudUpgradeReject(fn()=>WordPress::authenticateHeader('Bearer '.$new['access_token']),'invalid_or_expired_token');
    update_option('wpai_cloud_upgrade_sentinel','after-upgrade',false);
    $store->execute('UPDATE '.$store->table('balances')." SET used=used+11 WHERE owner='1'");
}
echo 'PASS: Cloud '.$phase.' wrapped keys/signature, site/OAuth isolation, REST/MCP, legacy quota, cached receipt and uncertain replay'."\n";
