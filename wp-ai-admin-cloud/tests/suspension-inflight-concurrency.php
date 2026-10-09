<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

if(!getenv('TEST_MYSQL_DSN')){
    echo "SKIP in-flight suspension concurrency requires MySQL\n";
    exit(0);
}

$database='mysql-suspension-inflight';
$store=fixtureStore($database);
$store->migrate();
foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));

$core=fixtureCore($store,new FakeTransport(),100);
$owner='inflight-owner';
$now=1791436800;
$core->ensureAccount($owner);
$site=$core->connect($owner,'https://inflight.example.com','fixture-inflight-site-token-aaaaaaaa',$now);
$token=$core->mint($owner,[$site['id']],['mcp:read','mcp:write'],$now,3600);
$nonce='inflight-suspend-race';
$key=hash('sha256',$owner.'|'.$nonce);

$signal=sys_get_temp_dir().'/wpai-inflight-signal-'.bin2hex(random_bytes(5));
$release=sys_get_temp_dir().'/wpai-inflight-release-'.bin2hex(random_bytes(5));
@unlink($signal);@unlink($release);

$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
foreach(['pdo','mysqlnd','pdo_mysql','sodium'] as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/suspension-inflight-worker.php',$database,$owner,$site['id'],$token['access_token'],$nonce,$signal,$release,(string)($now+1));

$pipes=[];
$handle=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
    'TEST_MYSQL_DSN'=>getenv('TEST_MYSQL_DSN'),
    'TEST_MYSQL_USER'=>getenv('TEST_MYSQL_USER')?:'root',
    'TEST_MYSQL_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD')?:''
]);
if(!is_resource($handle))throw new RuntimeException('Unable to start in-flight suspension worker');
fclose($pipes[0]);

$deadline=microtime(true)+10;
while(!file_exists($signal)){
    if(microtime(true)>$deadline){
        proc_terminate($handle);
        throw new RuntimeException('Worker did not reach upstream call');
    }
    usleep(10000);
}

$dispatching=$store->row('SELECT state,reserved_units,period FROM '.$store->table('requests').' WHERE request_key=?',[$key]);
if(($dispatching['state']??'')!=='dispatching'||(int)($dispatching['reserved_units']??0)!==1)throw new RuntimeException('Call was not reserved before suspension');

$balanceBefore=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=? AND period=?',[$owner,$dispatching['period']]);
if((int)($balanceBefore['used']??-1)!==0||(int)($balanceBefore['reserved']??-1)!==1)throw new RuntimeException('Unexpected pre-suspension balance');

$suspended=$core->suspendAccount($owner,$now+2);
if(empty($suspended['suspended']))throw new RuntimeException('Suspension did not complete while request was in flight');

file_put_contents($release,'continue',LOCK_EX);
$out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);
@unlink($signal);@unlink($release);

if($exit!==0)throw new RuntimeException('In-flight worker failed: '.$err);
if(!str_starts_with($out,'success:'))throw new RuntimeException('In-flight request did not settle after suspension: '.$out.($err!==''?' stderr='.$err:''));

$request=$store->row('SELECT state,reserved_units,response_sealed FROM '.$store->table('requests').' WHERE request_key=?',[$key]);
if(($request['state']??'')!=='succeeded'||($request['response_sealed']??'')==='')throw new RuntimeException('Completed pre-suspension request did not reach terminal succeeded state');

$balance=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=? AND period=?',[$owner,$dispatching['period']]);
if((int)($balance['used']??-1)!==1||(int)($balance['reserved']??-1)!==0)throw new RuntimeException('In-flight settlement left inconsistent balance after suspension');

$account=$store->row('SELECT disabled FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
if((int)($account['disabled']??0)!==1)throw new RuntimeException('Account was resurrected by in-flight completion');

$activeTokens=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeTokens!==0)throw new RuntimeException('In-flight completion resurrected a bearer token');

$siteRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);
if(($siteRow['status']??'')!=='revoked'||($siteRow['token_sealed']??'')!=='')throw new RuntimeException('In-flight completion resurrected site access');

$successAudit=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('audit').' WHERE owner=? AND event=? AND request_key=?',[$owner,'request_succeeded',$key])['n']??0);
if($successAudit!==1)throw new RuntimeException('In-flight settlement audit missing or duplicated');

echo "PASS in-flight call settles accounting after suspension without restoring access\n";
