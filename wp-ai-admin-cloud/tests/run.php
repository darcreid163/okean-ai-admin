<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Fault,Security,Vault,Signer,Gateway,Core};

$checks=0;
function check(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $reason): void { try {$fn();} catch(Fault $e) { check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return; } throw new RuntimeException('Expected rejection: '.$reason); }
$longPrefixStore=new WPAICloudGateway\Store(new PDO('sqlite::memory:'),str_repeat('p',45));
check(strlen(trim($longPrefixStore->table('license_activations'),chr(96)))===64,'Maximum valid table prefix must keep MySQL table identifiers within 64 characters');
rejects(fn()=>new WPAICloudGateway\Store(new PDO('sqlite::memory:'),str_repeat('p',46)),'invalid_table_prefix');
$now=1790942400; // fixed UTC fixture, independent of WordPress/site timezone
$database=sys_get_temp_dir().'/wpai-cloud-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database); $store->migrate();
if (getenv('TEST_MYSQL_DSN')) foreach(['audit','requests','tokens','sites','balances','accounts','rate'] as $name) $store->execute('DELETE FROM '.$store->table($name));
$transport=new FakeTransport(); $core=fixtureCore($store,$transport);
$core->ensureAccount('1'); $core->ensureAccount('2');
$site=$core->connect('1','https://site-a.example.com','fixture-site-admin-token-aaaaaaaa',$now);
$site2=$core->connect('1','https://site-b.example.com','fixture-site-admin-token-bbbbbbbb',$now);
$other=$core->connect('2','https://other.example.com','fixture-site-admin-token-cccccccc',$now);
check(count($core->sites('1'))===2,'Tenant site list leaked');
$repeatSite=$core->connect('1','https://site-a.example.com','fixture-site-admin-token-aaaaaaaa',$now);
check($repeatSite['id']===$site['id']&&count($core->sites('1'))===2,'Same-account repeat connection must preserve the active registration');
$healthyCredential=$core->diagnoseSiteCredential('1',$site['id']);
check($healthyCredential['vault_binding_valid']===true && $healthyCredential['site_token_format_valid']===true && $healthyCredential['failure']===null,'Read-only credential diagnostic rejected a valid site secret');
check(!array_key_exists('token',$healthyCredential) && !array_key_exists('ciphertext',$healthyCredential),'Credential diagnostic exposed secret material');
$beforeDiagnosticCalls=$transport->executions;
$healthyConnection=$core->diagnoseSiteConnection('1',$site['id']);
check($healthyConnection['credential']['vault_binding_valid']===true && $healthyConnection['upstream']['catalog_received']===true,'Connection diagnostic did not validate the upstream catalog');
check($healthyConnection['upstream']['passed']===true && $healthyConnection['upstream']['missing_tools']===[],'Connection diagnostic did not find all seven required read tools');
check($healthyConnection['billable_actions']===0 && $healthyConnection['writes_tested']===false && $transport->executions===$beforeDiagnosticCalls,'Read-only diagnostic consumed quota or dispatched a site tool');
check(!array_key_exists('token',$healthyConnection) && !array_key_exists('ciphertext',$healthyConnection),'Connection diagnostic exposed secret material');

$wrongVaultCore=fixtureCore($store,$transport,100,str_repeat('z',32));
$wrongKeyDiagnostic=$wrongVaultCore->diagnoseSiteCredential('1',$site['id']);
check($wrongKeyDiagnostic['sealed_payload_format_valid']===true && $wrongKeyDiagnostic['vault_binding_valid']===false && $wrongKeyDiagnostic['failure']==='secret_binding_failed','Credential diagnostic did not distinguish a vault binding failure');
check($wrongKeyDiagnostic['vault_key_loaded']===true && $wrongKeyDiagnostic['decryption_attempted']===true && $wrongKeyDiagnostic['credential_readable']===false,'Vault diagnostic did not report successful key loading and failed credential decryption');
check($wrongKeyDiagnostic['status_key_present']===null && $wrongKeyDiagnostic['site_token_format_valid']===null && $wrongKeyDiagnostic['binding_failure_class']==='authentication_failed_unknown_cause','Unreadable ciphertext must not be misreported as a missing status key or malformed token');
check($wrongKeyDiagnostic['binding_context']==='site:1:'.$site['id'],'Diagnostic binding context does not match the exact owner and registration ID');
$wrongConnection=$wrongVaultCore->diagnoseSiteConnection('1',$site['id']);
check($wrongConnection['upstream']['attempted']===false && $wrongConnection['upstream']['blocked_by']==='credential_check_failed','Connection diagnostic attempted upstream access after Vault failure');

rejects(fn()=>$core->connect('2','https://site-a.example.com','fixture-site-admin-token-aaaaaaaa',$now),'site_already_registered');
$read=$core->mint('1',[$site['id']],['mcp:read'],$now);
$write=$core->mint('1',[$site['id'],$site2['id']],['mcp:read','mcp:write'],$now);
$actor=$core->authenticate($write['access_token'],$now); $readActor=$core->authenticate($read['access_token'],$now);
rejects(fn()=>$core->catalog($actor,$other['id'],$now),'scope_or_site_denied');
rejects(fn()=>$core->catalog($readActor,$site2['id'],$now),'scope_or_site_denied');
rejects(fn()=>$core->mint('2',[$site['id']],['mcp:read'],$now),'site_unavailable');
rejects(fn()=>$core->authenticate($read['access_token'],$now+3600),'invalid_or_expired_token');
rejects(fn()=>$core->mint('1',[$site['id']],['admin'],$now),'invalid_token_policy');
rejects(fn()=>$core->call($readActor,$site['id'],'operation_apply',['id'=>1],'write-denied',$now),'scope_or_site_denied');
rejects(fn()=>$core->call($readActor,$site['id'],'wordpress_execute',['mode'=>'plan'],'plan-denied',$now),'scope_or_site_denied');
check(WPAICloudGateway\Core::writes('wordpress.execute',['mode'=>'plan']),'Dot alias scope bypass');
check(!WPAICloudGateway\Core::writes('wordpress_execute',['mode'=>'read']),'Read classification');
check(WPAICloudGateway\Core::writes('new_unknown_tool',[]),'Unknown tool must require write');
$one=$core->call($actor,$site['id'],'wp_get_site',[],'action-1',$now);
check($one['charged_units']===1 && $transport->executions===1,'Success accounting');
$retry=$core->call($actor,$site['id'],'wp_get_site',[],'action-1',$now);
check(Security::canonical($one)===Security::canonical($retry) && $transport->executions===1,'Retry executed twice');
rejects(fn()=>$core->call($actor,$site2['id'],'wp_get_site',[],'action-1',$now),'idempotency_conflict');
rejects(fn()=>$core->call($actor,$site['id'],'wp_get_site',['changed'=>true],'action-1',$now),'idempotency_conflict');
$fail=$core->call($actor,$site['id'],'wp_get_site',['fail'=>true],'failed-1',$now);
check($fail['charged_units']===0 && $core->usage('1',$now)['used']===1 && $core->usage('1',$now)['reserved']===0,'Failed action charged');
$again=$core->call($actor,$site['id'],'wp_get_site',['fail'=>true],'failed-1',$now);
check(Security::canonical($again)===Security::canonical($fail) && $transport->executions===2,'Failed retry re-executed');
$unknown=$core->call($actor,$site['id'],'imaginary_tool',[],'unknown-tool',$now);
check($unknown['charged_units']===0,'Unknown tool charged');
rejects(fn()=>$core->call($actor,$site['id'],'batch_resume',['job_id'=>'x'],'batch-1',$now),'batch_receipt_accounting_not_ready');
rejects(fn()=>$core->call($actor,$site['id'],'wp_get_site',['uncertain'=>true],'timeout-1',$now),'request_outcome_pending_reconciliation');
$executions=$transport->executions;
rejects(fn()=>$core->call($actor,$site['id'],'wp_get_site',['uncertain'=>true],'timeout-1',$now),'request_outcome_pending_reconciliation');
check($transport->executions===$executions && $core->usage('1',$now)['reserved']===1,'Uncertain action retried or released');
check($core->usage('2',$now)['used']===0,'Cross-account balance leak');
check($core->usage('1',strtotime('2026-11-01T00:00:00Z'))['used']===0,'Day did not reset');
check($core->usage('1',$now)['used']===1,'Earlier balance changed');
$core->throttle('1','test_rate',$now,2); $core->throttle('1','test_rate',$now,2);
rejects(fn()=>$core->throttle('1','test_rate',$now,2),'rate_limit_exceeded');
$core->throttle('1','test_rate',$now+60,2);
check(count($core->ledger('1'))===4,'Ledger missing successful, failed or uncertain requests');
rejects(fn()=>$core->reconcile('operator','1',$one['request_key'],'succeeded',['note'=>'A checked operator receipt note.','receipt_sha256'=>str_repeat('a',64)],$now),'request_not_reconcilable');
$pendingKey=hash('sha256','1|timeout-1');
rejects(fn()=>$core->reconcile('operator','1',$pendingKey,'failed',[],$now),'operator_receipt_evidence_required');
$reconciled=$core->reconcile('operator','1',$pendingKey,'failed',['note'=>'Fixture: independent receipt confirms this action did not execute.','receipt_sha256'=>str_repeat('a',64)],$now);
check($reconciled['charged_units']===0 && $core->usage('1',$now)['reserved']===0,'Failed reconciliation charged or retained reservation');
$before=$transport->executions;
$reconciledRetry=$core->call($actor,$site['id'],'wp_get_site',['uncertain'=>true],'timeout-1',$now);
check($reconciledRetry['reconciled']===true && $transport->executions===$before,'Reconciled action redispatched');
$core->setPlan('operator','1','pro',$now);
$proUsage=$core->usage('1',$now);
check($proUsage['limit']===2000 && $proUsage['used']===1 && $proUsage['period_kind']===WPAICloudGateway\QuotaPolicy::DAY,'Upgraded Pro must share the same UTC daily quota window');
$oldBalance=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=? AND period=?',['1',gmdate('Y-m-d',$now)]);
check($oldBalance !== null && (int)$oldBalance['used']===1 && (int)$oldBalance['reserved']===0,'Upgrade modified the current Free daily balance');
$core->setPlan('operator','1','free',$now);
check($core->usage('1',$now)['used']===1 && $core->usage('1',$now)['period_kind']===WPAICloudGateway\QuotaPolicy::DAY,'Returning to Free lost current daily consumption');
$period=gmdate('Y-m-d',$now);
$store->execute('UPDATE '.$store->table('balances').' SET used=?,reserved=? WHERE owner=? AND period=?',[7,2,'1',$period]);
$manualReset=$core->resetDailyQuota('9001','1',$now);
check($manualReset['reset']===true && $manualReset['previous_used']===7 && $manualReset['used']===0,'Manual daily reset did not clear used units');
check($manualReset['reserved']===2 && $manualReset['remaining']===98,'Manual daily reset must preserve reservations in remaining quota');
check($core->usage('2',$now)['used']===0,'Manual daily reset affected another tenant');
$audit=$store->row('SELECT owner,site_id,event,request_key FROM '.$store->table('audit').' WHERE owner=? AND event=? ORDER BY created_at DESC LIMIT 1',['1','manual_daily_quota_reset']);
check($audit!==null && $audit['site_id']==='admin:9001' && $audit['request_key']===$period,'Manual daily reset audit is incomplete');
rejects(fn()=>$core->resetDailyQuota('9001','missing-account',$now),'account_unavailable');
$core->ensureAccount('legacy-reset');
$core->setPlan('operator','legacy-reset','free',$now,WPAICloudGateway\QuotaPolicy::LEGACY);
rejects(fn()=>$core->resetDailyQuota('9001','legacy-reset',$now),'daily_quota_reset_not_supported');
rejects(fn()=>$core->setPlan('operator','1','unlimited',$now),'unknown_plan');
$core->revokeToken('1',hash('sha256',$read['access_token']),$now);
rejects(fn()=>$core->authenticate($read['access_token'],$now),'invalid_or_expired_token');
rejects(fn()=>$core->catalog($readActor,$site['id'],$now),'invalid_or_expired_token');
$gateway=new Gateway($core);
$init=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-11-25']],$now);
check($init['result']['protocolVersion']==='2025-11-25','Protocol negotiation');
check($gateway->handle($actor,['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$now)===null,'Notification has body');
rejects(fn()=>$gateway->handle($actor,[['jsonrpc'=>'2.0','id'=>1,'method'=>'ping']],$now),'invalid_json_rpc');
rejects(fn()=>$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>null,'method'=>'ping'],$now),'null_request_id');
$bad=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'cloud_site_tool_call','arguments'=>['site_id'=>$site['id'],'tool'=>'wp_get_site','arguments'=>[],'idempotency_key'=>'x','home'=>'https://evil.example.com']]],$now);
check($bad['result']['isError']===true,'Unexpected routing argument accepted');
$visible=$gateway->handle($readActor,['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'cloud_sites_list','arguments'=>[]]],$now);
// Direct handle accepts a trusted actor; the HTTP adapter reauthenticates before every method.
check(count(json_decode($visible['result']['content'][0]['text'],true))===1,'Token site filter leaked');
check(array_column(Gateway::tools(),'name')===['cloud_skills_list','cloud_skill_load','cloud_media_search_images','cloud_sites_list','cloud_usage','cloud_usage_history','cloud_site_batch_call','cloud_site_batch_receipt','cloud_site_tools_list','cloud_site_tool_call'],'Gateway catalog');

foreach(['127.0.0.1','10.0.0.1','192.168.1.1','169.254.169.254','100.64.0.1','198.18.0.1','192.0.2.1','224.0.0.1','::1','fe80::1','fc00::1','::ffff:127.0.0.1','2001:db8::1','2001:20::1','2002:7f00:1::','3fff::1'] as $ip) check(!Security::publicIp($ip),'Unsafe IP accepted: '.$ip);
check(Security::publicIp('8.8.8.8') && Security::publicIp('2606:4700:4700::1111'),'Public IP rejected');
rejects(fn()=>Security::requirePublicAddresses(['8.8.8.8','127.0.0.1']),'private_site_address_blocked');
foreach(['http://example.com','https://u:p@example.com','https://example.com:8443','https://example.com/?x=y','https://example.com/#x'] as $url) rejects(fn()=>Security::home($url),'public_https_site_required');
rejects(fn()=>Security::home('https://127.0.0.1'),'invalid_site_host');
rejects(fn()=>Security::home('https://example.com/../wp-json'),'invalid_site_path');
check(Security::home('https://EXAMPLE.com/wp/')==='https://example.com/wp','Canonical home');
check(Security::canonical(['b'=>1,'a'=>['z'=>2,'x'=>3]])===Security::canonical(['a'=>['x'=>3,'z'=>2],'b'=>1]),'Fingerprint object ordering');
check(Security::canonical([2,1])!==Security::canonical([1,2]),'Array order discarded');
$vault=new Vault(str_repeat('v',32)); $secret=$vault->seal('secret','account:1');
check($vault->open($secret,'account:1')==='secret','Encryption roundtrip');
rejects(fn()=>$vault->open($secret,'account:2'),'secret_binding_failed');
$raw=base64_decode($secret); $raw[30]=chr(ord($raw[30])^1);
rejects(fn()=>$vault->open(base64_encode($raw),'account:1'),'secret_binding_failed');
$stored=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);
check(!str_contains($stored['token_sealed'],'fixture-site-admin-token'),'Plaintext site token');
check($store->row('SELECT hash FROM '.$store->table('tokens').' WHERE hash=?',[hash('sha256',$write['access_token'])])['hash']!==$write['access_token'],'Plaintext bearer token');

define('ABSPATH',__DIR__.'/'); require __DIR__.'/fixtures/client-v1-verifier.php';
$pair=sodium_crypto_sign_keypair(); $signer=new Signer(sodium_crypto_sign_secretkey($pair));
$row=$store->row('SELECT * FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);
$envelope=$signer->foundation($row,7,$now);
$verified=OAI_Control_Plane::verify($envelope,$signer->publicKey(),$row['plugin_site_id'],$row['installation_id'],$row['id'],7,$now);
check($verified['valid']===true,'Real client V1 verifier rejected server signature');
check(count($verified['claims']['features'])===15,'All features not granted');
check(OAI_Control_Plane::verify($envelope,$signer->publicKey(),'other-site',$row['installation_id'],$row['id'],7,$now)['valid']===false,'Signed cross-site replay');
check(OAI_Control_Plane::verify($envelope,$signer->publicKey(),$row['plugin_site_id'],$row['installation_id'],$row['id'],8,$now)['valid']===false,'Sequence rollback');
check(OAI_Control_Plane::verify($envelope,$signer->publicKey(),$row['plugin_site_id'],$row['installation_id'],$row['id'],7,$now+301)['valid']===false,'Expired signature');
$core->disconnect('1',$site2['id'],$now);
rejects(fn()=>$core->catalog($actor,$site2['id'],$now),'site_unavailable');
check($store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site2['id']])['token_sealed']==='','Disconnect retained credential');

// The site cap applies to active registrations only. Historical disconnected
// rows remain auditable, while a released slot can be reused immediately.
$core->ensureAccount('capacity');
$capacitySites=[];$capacityNow=$now;
for($i=0;$i<Core::MAX_SITES;$i++) {
    $capacitySites[]=$core->connect('capacity','https://capacity-'.$i.'.example.com','fixture-site-admin-token-capacity-'.$i,$capacityNow);
    $capacityNow+=60; // stay below the per-minute proof throttle while filling the fixture
}
rejects(fn()=>$core->connect('capacity','https://capacity-50.example.com','fixture-site-admin-token-capacity-50',$capacityNow),'pilot_site_count_limit');
$core->disconnect('capacity',$capacitySites[0]['id'],$capacityNow);
$capacityNow+=60;
$replacementSite=$core->connect('capacity','https://capacity-50.example.com','fixture-site-admin-token-capacity-50',$capacityNow);
rejects(fn()=>$core->connect('capacity','https://capacity-0.example.com','fixture-site-admin-token-capacity-0',$capacityNow+60),'pilot_site_count_limit');
check(count($core->sites('capacity'))===Core::MAX_SITES,'New site must fill the released active-site slot');
$core->disconnect('capacity',$replacementSite['id'],$capacityNow+60);
$capacityNow+=120;
$reactivatedSite=$core->connect('capacity','https://capacity-0.example.com','fixture-site-admin-token-capacity-0',$capacityNow);
check($reactivatedSite['id']!==$capacitySites[0]['id'],'Reconnecting a revoked site must issue a fresh registration ID');
check(count($core->sites('capacity'))===Core::MAX_SITES,'Reactivation must consume one active-site slot');

// Recovery remains available with no quota. Each call still requires a live token, owned site and appropriate scope.
$core->ensureAccount('recovery'); $recoverSite=$core->connect('recovery','https://recovery.example.com','fixture-site-admin-token-recovery',$now);
$recoverToken=$core->mint('recovery',[$recoverSite['id']],['mcp:read','mcp:write'],$now);
$recoverActor=$core->authenticate($recoverToken['access_token'],$now);
$store->execute('INSERT INTO '.$store->table('balances').' (owner,period,used,reserved) VALUES (?,?,?,?)',['recovery',gmdate('Y-m-d',$now),100,0]);
rejects(fn()=>$core->call($recoverActor,$recoverSite['id'],'wp_get_site',[],'quota-denied',$now),'daily_quota_exhausted');
foreach (['operation_verify','operation_get_receipt','operation_rollback','batch_rollback'] as $tool) {
    $result=$core->call($recoverActor,$recoverSite['id'],$tool,[],'free-'.$tool,$now);
    check($result['state']==='succeeded' && $result['charged_units']===0,'Recovery tool charged or blocked: '.$tool);
    $replayed=$core->call($recoverActor,$recoverSite['id'],$tool,[],'free-'.$tool,$now);
    check(Security::canonical($result)===Security::canonical($replayed),'Free retry changed receipt');
}
check($core->usage('recovery',$now)['used']===100 && $core->usage('recovery',$now)['reserved']===0,'Free recovery changed balance');
check(count($core->ledger('recovery'))===4,'Free recovery missing from ledger');
$recoverRead=$core->mint('recovery',[$recoverSite['id']],['mcp:read'],$now);
$recoverReadActor=$core->authenticate($recoverRead['access_token'],$now);
rejects(fn()=>$core->call($recoverReadActor,$recoverSite['id'],'operation_rollback',[],'read-cannot-rollback',$now),'scope_or_site_denied');
check(WPAICloudGateway\Core::units('operation.rollback')===0,'Dot alias charged rollback');
check(WPAICloudGateway\Core::units('operation_apply')===1,'Apply incorrectly free');
rejects(fn()=>$core->call($recoverActor,$recoverSite['id'],'operation_verify',['uncertain'=>true],'free-uncertain',$now),'request_outcome_pending_reconciliation');
$freeKey=hash('sha256','recovery|free-uncertain');
check($core->usage('recovery',$now)['reserved']===0,'Uncertain free call reserved quota');
$freeReconciled=$core->reconcile('operator','recovery',$freeKey,'succeeded',['note'=>'Fixture: independent verification receipt confirms success.','receipt_sha256'=>str_repeat('c',64)],$now);
check($freeReconciled['charged_units']===0 && $core->usage('recovery',$now)['used']===100,'Free reconciliation billed');
$core->revokeToken('recovery',hash('sha256',$recoverToken['access_token']),$now);
rejects(fn()=>$core->call($recoverActor,$recoverSite['id'],'operation_get_receipt',[],'revoked-free',$now),'invalid_or_expired_token');

// Ten independent processes compete for three slots using the same real DB and production transaction code.
$core->ensureAccount('parallel'); $pSite=$core->connect('parallel','https://parallel.example.com','fixture-site-admin-token-parallel',$now);
$pToken=$core->mint('parallel',[$pSite['id']],['mcp:read'],$now);
$extensionDir=(string)ini_get('extension_dir');
// Windows distributes php.ini with a relative extension_dir. Worker processes
// change cwd, so resolve it once against the PHP binary they are launched with.
if($extensionDir!==''&&!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#',$extensionDir))$extensionDir=dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$extensionDir;
$command=[PHP_BINARY,'-n','-d','extension_dir='.$extensionDir];
$modules=['pdo','pdo_sqlite','sodium']; if(getenv('TEST_MYSQL_DSN')) array_push($modules,'mysqlnd','pdo_mysql');
foreach($modules as $module) foreach([$module.'.'.PHP_SHLIB_SUFFIX,'php_'.$module.'.'.PHP_SHLIB_SUFFIX] as $file) if(is_file($extensionDir.'/'.$file)){array_push($command,'-d','extension='.$file);break;}
array_push($command,__DIR__.'/worker.php',$database,$pSite['id'],(string)$now);
$workers=[]; putenv('CLOUD_TEST_TOKEN='.$pToken['access_token']);
$executionLog=tempnam(sys_get_temp_dir(),'wpai-executions-'); putenv('CLOUD_TEST_EXECUTION_LOG='.$executionLog);
for($i=0;$i<10;$i++) { $pipes=[]; $handle=proc_open([...$command,'parallel-'.$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fclose($pipes[0]); $workers[]=[$handle,$pipes]; }
$ok=0; $denied=0;
foreach($workers as [$handle,$pipes]) { $out=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($handle); check($exit===0,'Worker failed: '.$err.' '.$out); if($out==='success')$ok++; elseif($out==='daily_quota_exhausted')$denied++; else throw new RuntimeException($out); }
putenv('CLOUD_TEST_TOKEN');
check($ok===3 && $denied===7,'Concurrent quota overrun');
check($core->usage('parallel',$now)['used']===3 && $core->usage('parallel',$now)['reserved']===0,'Concurrent balance wrong');
check(count(file($executionLog))===3,'Rejected quota requests executed upstream');
file_put_contents($executionLog,'');
$core->ensureAccount('duplicate'); $dupSite=$core->connect('duplicate','https://duplicate.example.com','fixture-site-admin-token-duplicate',$now);
$dupToken=$core->mint('duplicate',[$dupSite['id']],['mcp:read'],$now);
putenv('CLOUD_TEST_TOKEN='.$dupToken['access_token']);
$command[count($command)-2]=$dupSite['id'];
$workers=[];
for($i=0;$i<10;$i++) { $pipes=[]; $handle=proc_open([...$command,'same-logical-action'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fclose($pipes[0]); $workers[]=[$handle,$pipes]; }
foreach($workers as [$handle,$pipes]) { $out=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($handle); check($exit===0 && in_array($out,['success','request_outcome_pending_reconciliation'],true),'Duplicate worker failed: '.$err.' '.$out); }
check($core->usage('duplicate',$now)['used']===1 && $core->usage('duplicate',$now)['reserved']===0 && count(file($executionLog))===1,'Concurrent duplicate action executed or charged more than once');
putenv('CLOUD_TEST_TOKEN'); putenv('CLOUD_TEST_EXECUTION_LOG'); unlink($executionLog);

// OAuth tenant-binding and strict no-refresh contract.
require_once dirname(__DIR__).'/src/OAuth.php';
$oauth=new WPAICloudGateway\OAuth($store,$core,'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp');
$oauthClient=$oauth->register(['client_name'=>'Tenant isolation fixture','redirect_uris'=>['https://client.example.invalid/callback']],$now);
$verifier=str_repeat('v',64);
$challenge=WPAICloudGateway\Security::b64(hash('sha256',$verifier,true));
$authorization=$oauth->authorization([
    'client_id'=>$oauthClient['client_id'],
    'redirect_uri'=>'https://client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code',
    'scope'=>'mcp:read',
    'code_challenge'=>$challenge,
    'code_challenge_method'=>'S256',
    'state'=>'tenant-isolation-state',
]);
$oauthCode=$oauth->approve('1',$authorization,[$site['id']],$now);
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$oauthCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$verifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now,fn($owner)=>$owner==='2'),'access_denied');
$oauthToken=$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$oauthCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$verifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now,fn($owner)=>$owner==='1');
check(isset($oauthToken['access_token']) && !isset($oauthToken['refresh_token']),'OAuth issued a forbidden refresh credential');
$oauthActor=$core->authenticate($oauthToken['access_token'],$now);
$oauth->binding($oauthActor,$now);
check($oauthActor['owner']==='1' && $oauthActor['site_ids']===[$site['id']],'OAuth grant owner/site binding changed');
$legacyRefresh=['grant_type'=>'refresh_token','client_id'=>$oauthClient['client_id'],'refresh_token'=>'legacy-refresh-token','resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp'];
rejects(fn()=>$oauth->exchange($legacyRefresh,$now+60,fn($owner)=>$owner==='1'),'unsupported_grant_type');
$stillActive=$core->authenticate($oauthToken['access_token'],$now+60);
$oauth->binding($stillActive,$now+60);
rejects(fn()=>$oauth->exchange($legacyRefresh,$now+120,fn($owner)=>$owner==='1'),'unsupported_grant_type');
$otherOAuth=$oauth->register(['client_name'=>'Second tenant client','redirect_uris'=>['https://other-client.example.invalid/callback']],$now);
$otherVerifier=str_repeat('w',64);
$otherAuth=$oauth->authorization([
    'client_id'=>$otherOAuth['client_id'],
    'redirect_uri'=>'https://other-client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code','scope'=>'mcp:read',
    'code_challenge'=>WPAICloudGateway\Security::b64(hash('sha256',$otherVerifier,true)),
    'code_challenge_method'=>'S256','state'=>'second-tenant-state',
]);
$otherCode=$oauth->approve('2',$otherAuth,[$other['id']],$now);
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$otherOAuth['client_id'],
    'code'=>$otherCode,'redirect_uri'=>'https://other-client.example.invalid/callback',
    'code_verifier'=>$otherVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now,fn($owner)=>$owner==='1'),'access_denied');
$otherToken=$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$otherOAuth['client_id'],
    'code'=>$otherCode,'redirect_uri'=>'https://other-client.example.invalid/callback',
    'code_verifier'=>$otherVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now,fn($owner)=>$owner==='2');
$otherActor=$core->authenticate($otherToken['access_token'],$now);
$oauth->binding($otherActor,$now);
check($otherActor['owner']==='2' && $otherActor['site_ids']===[$other['id']],'Second tenant OAuth grant crossed owner/site boundary');
$oauth->revoke($otherToken['access_token'],$otherOAuth['client_id']);
rejects(fn()=>$oauth->binding($otherActor,$now),'invalid_or_expired_token');


// OAuth negative-path regression coverage: one-time codes, client/resource
// binding, expiry, and client-scoped revocation. All identities are synthetic.
$foreignClient=$oauth->register([
    'client_name'=>'Wrong-client fixture',
    'redirect_uris'=>['https://wrong-client.example.invalid/callback'],
],$now);
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'refresh_token','client_id'=>$foreignClient['client_id'],
    'refresh_token'=>'foreign-client-legacy-refresh-token',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now+90,fn($owner)=>true),'unsupported_grant_type');
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$oauthCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$verifier,'resource'=>'https://gateway.example.com/wp-json/other',
],$now+90,fn($owner)=>true),'invalid_grant');

// A successfully exchanged authorization code is strictly single-use.
$replayVerifier=str_repeat('r',64);
$replayAuth=$oauth->authorization([
    'client_id'=>$oauthClient['client_id'],
    'redirect_uri'=>'https://client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code','scope'=>'mcp:read',
    'code_challenge'=>WPAICloudGateway\Security::b64(hash('sha256',$replayVerifier,true)),
    'code_challenge_method'=>'S256','state'=>'single-use-code-state',
]);
$replayCode=$oauth->approve('1',$replayAuth,[$site['id']],$now);
$replayParams=[
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$replayCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$replayVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
];
$replayIssued=$oauth->exchange($replayParams,$now,fn($owner)=>$owner==='1');
rejects(fn()=>$oauth->exchange($replayParams,$now+1,fn($owner)=>true),'invalid_grant');
$replayActor=$core->authenticate($replayIssued['access_token'],$now+1);
$oauth->binding($replayActor,$now+1);

// Authorization codes expire at the five-minute boundary.
$expiredVerifier=str_repeat('e',64);
$expiredAuth=$oauth->authorization([
    'client_id'=>$oauthClient['client_id'],
    'redirect_uri'=>'https://client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code','scope'=>'mcp:read',
    'code_challenge'=>WPAICloudGateway\Security::b64(hash('sha256',$expiredVerifier,true)),
    'code_challenge_method'=>'S256','state'=>'expired-code-state',
]);
$expiredCode=$oauth->approve('1',$expiredAuth,[$site['id']],$now);
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$expiredCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$expiredVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now+300,fn($owner)=>true),'invalid_grant');

// Legacy refresh credentials are rejected regardless of age or account state.
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'refresh_token','client_id'=>$oauthClient['client_id'],
    'refresh_token'=>'expired-legacy-refresh-token',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now+2592000,fn($owner)=>true),'unsupported_grant_type');


// Account suspension must atomically invalidate outstanding OAuth codes,
// access grants and site credentials without affecting another tenant.
$ownerTwoVerifier=str_repeat('2',64);
$ownerTwoAuth=$oauth->authorization([
    'client_id'=>$otherOAuth['client_id'],
    'redirect_uri'=>'https://other-client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code','scope'=>'mcp:read',
    'code_challenge'=>WPAICloudGateway\Security::b64(hash('sha256',$ownerTwoVerifier,true)),
    'code_challenge_method'=>'S256','state'=>'suspension-control-tenant',
]);
$ownerTwoCode=$oauth->approve('2',$ownerTwoAuth,[$other['id']],$now);
$ownerTwoToken=$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$otherOAuth['client_id'],
    'code'=>$ownerTwoCode,'redirect_uri'=>'https://other-client.example.invalid/callback',
    'code_verifier'=>$ownerTwoVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now,fn($owner)=>$owner==='2');
$ownerTwoActor=$core->authenticate($ownerTwoToken['access_token'],$now);
$oauth->binding($ownerTwoActor,$now);
// A foreign OAuth client cannot revoke another client's live grant.
$oauth->revoke($ownerTwoToken['access_token'],$oauthClient['client_id']);
$stillActiveAfterWrongClientRevoke=$core->authenticate($ownerTwoToken['access_token'],$now);
$oauth->binding($stillActiveAfterWrongClientRevoke,$now);

$pendingVerifier=str_repeat('p',64);
$pendingAuth=$oauth->authorization([
    'client_id'=>$oauthClient['client_id'],
    'redirect_uri'=>'https://client.example.invalid/callback',
    'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
    'response_type'=>'code','scope'=>'mcp:read',
    'code_challenge'=>WPAICloudGateway\Security::b64(hash('sha256',$pendingVerifier,true)),
    'code_challenge_method'=>'S256','state'=>'suspension-pending-code',
]);
$pendingCode=$oauth->approve('1',$pendingAuth,[$site['id']],$now);
$suspension=$core->suspendAccount('1',$now+10);
check($suspension['suspended']===true && $suspension['access_revoked']===true,'Account suspension did not report access revocation');
rejects(fn()=>$core->authenticate($replayIssued['access_token'],$now+10),'invalid_or_expired_token');
rejects(fn()=>$oauth->exchange([
    'grant_type'=>'authorization_code','client_id'=>$oauthClient['client_id'],
    'code'=>$pendingCode,'redirect_uri'=>'https://client.example.invalid/callback',
    'code_verifier'=>$pendingVerifier,'resource'=>'https://gateway.example.com/wp-json/wpai-cloud/v1/mcp',
],$now+10,fn($owner)=>true),'invalid_grant');
$stillActiveOtherTenant=$core->authenticate($ownerTwoToken['access_token'],$now+10);
$oauth->binding($stillActiveOtherTenant,$now+10);
check($stillActiveOtherTenant['owner']==='2' && $stillActiveOtherTenant['site_ids']===[$other['id']],'Suspending one owner affected another tenant');

require __DIR__.'/robokassa-payments.php';
runRobokassaPaymentsTests($store,$core);
echo 'PASS '.$checks.' assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."; concurrency=10 workers/3 slots; client V1 verifier=accepted\n";
if (!getenv('TEST_MYSQL_DSN')) { foreach([$database,$database.'-shm',$database.'-wal'] as $f) if(file_exists($f)) unlink($f); }
