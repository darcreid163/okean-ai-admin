<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require dirname(__DIR__).'/src/OAuth.php';
use WPAICloudGateway\{Fault,OAuth,Security};
$checks=0;
function check(bool $ok,string $message): void { global $checks;$checks++;if(!$ok)throw new RuntimeException($message); }
function rejects(callable $fn,string $reason): void { try{$fn();}catch(Fault $e){check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Missing rejection '.$reason); }
$now=1791199800;$database=tempnam(sys_get_temp_dir(),'wpai-oauth-');$store=fixtureStore($database);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate','audit','requests','tokens','sites','balances','accounts','rate'] as $table)$store->execute('DELETE FROM '.$store->table($table));
$transport=new FakeTransport();$core=fixtureCore($store,$transport);$core->ensureAccount('1');$core->ensureAccount('2');
$site=$core->connect('1','https://oauth.example.com','fixture-oauth-administrator-token',$now);
$other=$core->connect('2','https://other-oauth.example.com','fixture-oauth-administrator-token-2',$now);
$url='https://wpaiadmin.ru/mcp';$oauth=new OAuth($store,$core,$url);
$chat=$oauth->register(['client_name'=>'ChatGPT','redirect_uris'=>['https://chatgpt.com/oauth/callback']],$now);
$qwen=$oauth->register(['client_name'=>'Qwen Code','redirect_uris'=>['http://127.0.0.1:7777/oauth/callback']],$now);
check($chat['client_id']!==$qwen['client_id'] && !isset($chat['client_secret']),'Public clients not independent');
foreach(['http://evil.example.com/cb','https://u:p@example.com/cb','https://example.com/cb#x',"https://example.com/cb\n"] as $bad)rejects(fn()=>$oauth->register(['redirect_uris'=>[$bad]],$now),'invalid_redirect_uri');
rejects(fn()=>$oauth->register(['redirect_uris'=>['https://example.com/cb'],'token_endpoint_auth_method'=>'client_secret_basic'],$now),'unsupported_client_auth_method');
rejects(fn()=>$oauth->register(['redirect_uris'=>['https://example.com/cb'],'grant_types'=>'bad'],$now),'invalid_client_metadata');
rejects(fn()=>$oauth->register(['client_name'=>'','redirect_uris'=>['https://example.com/cb']],$now),'invalid_client_metadata');
rejects(fn()=>$oauth->register(['client_name'=>str_repeat('x',101),'redirect_uris'=>['https://example.com/cb']],$now),'invalid_client_metadata');
rejects(fn()=>$oauth->register(['redirect_uris'=>array_map(fn($i)=>'https://example.com/cb'.$i,range(1,6))],$now),'invalid_client_metadata');
$verifier=str_repeat('v',64);$challenge=Security::b64(hash('sha256',$verifier,true));
$query=['client_id'=>$chat['client_id'],'redirect_uri'=>$chat['redirect_uris'][0],'response_type'=>'code','resource'=>$url,'scope'=>'mcp:read mcp:write offline_access','code_challenge'=>$challenge,'code_challenge_method'=>'S256','state'=>'bound-state'];
$auth=$oauth->authorization($query);
foreach([['resource','https://other.example.com/mcp','invalid_target'],['redirect_uri','https://evil.example.com/cb','invalid_redirect_uri'],['code_challenge_method','plain','pkce_s256_required'],['scope','mcp:write','invalid_scope'],['scope','mcp:read admin:all','invalid_scope'],['state','','state_required'],['state',str_repeat('s',513),'state_required']] as [$key,$value,$reason]){ $bad=$query;$bad[$key]=$value;rejects(fn()=>$oauth->authorization($bad),$reason); }
rejects(fn()=>$oauth->approve('1',$auth,[$other['id']],$now),'invalid_site_consent');
rejects(fn()=>$oauth->approve('1',$auth,[],$now),'invalid_site_consent');
$code=$oauth->approve('1',$auth,[$site['id']],$now);
$exchange=['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$chat['client_id'],'redirect_uri'=>$query['redirect_uri'],'resource'=>$url,'code_verifier'=>$verifier];
$eligible=fn($id)=>$id==='1';
foreach([['code_verifier',str_repeat('w',64)],['resource','https://evil.example.com/mcp'],['redirect_uri','https://chatgpt.com/wrong'],['client_id',$qwen['client_id']]] as [$key,$value]){$bad=$exchange;$bad[$key]=$value;rejects(fn()=>$oauth->exchange($bad,$now,$eligible),'invalid_grant');}
rejects(fn()=>$oauth->exchange($exchange,$now,fn($id)=>false),'access_denied');
$token=$oauth->exchange($exchange,$now,$eligible);$actor=$core->authenticate($token['access_token'],$now);$oauth->binding($actor,$now);
check($actor['site_ids']===[$site['id']] && $actor['scopes']===['mcp:read','mcp:write'],'Consent scope/site widened');
check($token['resource']===$url && isset($token['refresh_token']),'Audience or refresh missing');
rejects(fn()=>$oauth->exchange($exchange,$now,$eligible),'invalid_grant');
rejects(fn()=>$core->catalog($actor,$other['id'],$now),'scope_or_site_denied');
$wrongAudience=new OAuth($store,$core,'https://other.example.com/mcp');rejects(fn()=>$wrongAudience->binding($actor,$now),'invalid_or_expired_token');
$queryQ=$query;$queryQ['client_id']=$qwen['client_id'];$queryQ['redirect_uri']=$qwen['redirect_uris'][0];
$codeQ=$oauth->approve('1',$oauth->authorization($queryQ),[$site['id']],$now);
$exchangeQ=$exchange;$exchangeQ['client_id']=$qwen['client_id'];$exchangeQ['redirect_uri']=$qwen['redirect_uris'][0];$exchangeQ['code']=$codeQ;
$tokenQ=$oauth->exchange($exchangeQ,$now,$eligible);$actorQ=$core->authenticate($tokenQ['access_token'],$now);
$first=$core->call($actor,$site['id'],'wp_get_site',[],'shared-ai-action',$now);$again=$core->call($actorQ,$site['id'],'wp_get_site',[],'shared-ai-action',$now);
check(Security::canonical($first)===Security::canonical($again) && $transport->executions===1 && $core->usage('1',$now)['used']===1,'Two AI clients duplicated one logical action');
$refresh=['grant_type'=>'refresh_token','refresh_token'=>$tokenQ['refresh_token'],'client_id'=>$qwen['client_id'],'resource'=>$url];
$bad=$refresh;$bad['client_id']=$chat['client_id'];rejects(fn()=>$oauth->exchange($bad,$now,$eligible),'invalid_grant');
$rotated=$oauth->exchange($refresh,$now+1,$eligible);
check($rotated['refresh_token']!==$tokenQ['refresh_token'],'Refresh did not rotate');
rejects(fn()=>$core->authenticate($tokenQ['access_token'],$now+1),'invalid_or_expired_token');
$core->authenticate($rotated['access_token'],$now+1);
rejects(fn()=>$oauth->exchange($refresh,$now+2,$eligible),'invalid_grant');
rejects(fn()=>$core->authenticate($rotated['access_token'],$now+2),'invalid_or_expired_token');
check($core->authenticate($token['access_token'],$now+2)['owner']==='1','One provider replay revoked another provider grant');
$oauth->revoke($token['access_token'],$chat['client_id']);rejects(fn()=>$core->authenticate($token['access_token'],$now+3),'invalid_or_expired_token');
$readQuery=$query;$readQuery['scope']='mcp:read';$readCode=$oauth->approve('1',$oauth->authorization($readQuery),[$site['id']],$now);
$readExchange=$exchange;$readExchange['code']=$readCode;$readToken=$oauth->exchange($readExchange,$now,$eligible);
check(!isset($readToken['refresh_token']),'Offline access granted without scope');
$readActor=$core->authenticate($readToken['access_token'],$now);
rejects(fn()=>$core->call($readActor,$site['id'],'operation_apply',[],'read-only-write',$now),'scope_or_site_denied');
$expired=$oauth->approve('1',$auth,[$site['id']],$now);$expiredExchange=$exchange;$expiredExchange['code']=$expired;
rejects(fn()=>$oauth->exchange($expiredExchange,$now+300,$eligible),'invalid_grant');
$oauth->rate('fixture-ip',$now,2);$oauth->rate('fixture-ip',$now,2);rejects(fn()=>$oauth->rate('fixture-ip',$now,2),'oauth_rate_limit');
check(!str_contains(Security::canonical($store->rows('SELECT * FROM '.$store->table('oauth_refresh'))),$tokenQ['refresh_token']),'Plaintext refresh token');
check(!str_contains(Security::canonical($store->rows('SELECT * FROM '.$store->table('oauth_codes'))),$codeQ),'Plaintext authorization code');
$oauthStorage=Security::canonical(array_merge(
    $store->rows('SELECT * FROM '.$store->table('oauth_codes')),
    $store->rows('SELECT * FROM '.$store->table('oauth_grants')),
    $store->rows('SELECT * FROM '.$store->table('oauth_access')),
    $store->rows('SELECT * FROM '.$store->table('oauth_refresh')),
    $store->rows('SELECT * FROM '.$store->table('audit'))
));
check(!str_contains($oauthStorage,$verifier),'Plaintext PKCE verifier persisted in OAuth/audit storage');
check(!str_contains($oauthStorage,'bound-state'),'OAuth state persisted in OAuth/audit storage');
$parallelCode=$oauth->approve('1',$auth,[$site['id']],$now);$parallel=$exchange;$parallel['code']=$parallelCode;
$before=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens'))['n'];
putenv('CLOUD_OAUTH_EXCHANGE='.json_encode($parallel));
$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
$modules=['pdo','pdo_sqlite','sodium'];if(getenv('TEST_MYSQL_DSN'))array_push($modules,'mysqlnd','pdo_mysql');
foreach($modules as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/oauth-worker.php',$database,(string)$now);$workers=[];
for($i=0;$i<10;$i++){$pipes=[];$handle=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$handle,$pipes];}
$success=0;$denied=0;
foreach($workers as [$handle,$pipes]){$out=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);check($exit===0,'Concurrent code worker failed '.$err);if($out==='success')$success++;elseif($out==='invalid_grant')$denied++;else throw new RuntimeException('Unexpected worker outcome');}
putenv('CLOUD_OAUTH_EXCHANGE');check($success===1&&$denied===9,'Authorization code exchanged more than once');
check((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens'))['n']===$before+1,'Duplicate authorization minted extra tokens');
echo 'PASS '.$checks.' OAuth assertions; PKCE/audience/site consent/refresh replay/shared AI quota; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
if(!getenv('TEST_MYSQL_DSN'))foreach([$database,$database.'-shm',$database.'-wal'] as $file)if(file_exists($file))unlink($file);
