<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

if(!getenv('TEST_MYSQL_DSN')) {
    echo "SKIP suspension/token concurrency requires MySQL\n";
    exit(0);
}

$database='mysql-suspension-race';
$store=fixtureStore($database);
$store->migrate();
foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));

$core=fixtureCore($store,new FakeTransport(),100);
$owner='suspend-race';
$now=1791436800;
$core->ensureAccount($owner);
$site=$core->connect($owner,'https://suspend-race.example.com','fixture-suspend-race-site-token-aaaaaaaa',$now);

$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
foreach(['pdo','mysqlnd','pdo_mysql','sodium'] as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/suspension-token-worker.php',$database);

$workers=[];
for($i=0;$i<16;$i++){
    $mode=$i%4===0?'suspend':'mint';
    $cmd=array_merge($command,[$mode,$owner,$site['id'],(string)($now+1)]);
    $pipes=[];
    $handle=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
        'TEST_MYSQL_DSN'=>getenv('TEST_MYSQL_DSN'),
        'TEST_MYSQL_USER'=>getenv('TEST_MYSQL_USER')?:'root',
        'TEST_MYSQL_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD')?:''
    ]);
    if(!is_resource($handle))throw new RuntimeException('Unable to start suspension race worker');
    fclose($pipes[0]);$workers[]=[$handle,$pipes,$mode];
}

$minted=0;$suspended=0;$denied=0;$unexpected=[];
foreach($workers as [$handle,$pipes,$mode]){
    $out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);
    if($exit!==0){$unexpected[]='exit='.$exit.' '.$err;continue;}
    if(str_starts_with($out,'mint:')){$minted++;continue;}
    if($out==='suspend:ok'){$suspended++;continue;}
    if(in_array($out,['fault:account_unavailable','fault:site_unavailable'],true)){$denied++;continue;}
    $unexpected[]=$out.($err!==''?' stderr='.$err:'');
}
if($unexpected)throw new RuntimeException('Unexpected suspension/token race result: '.json_encode($unexpected));
if($suspended!==1)throw new RuntimeException('Exactly one suspension must win; got '.$suspended);

$account=$store->row('SELECT disabled FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
if((int)($account['disabled']??0)!==1)throw new RuntimeException('Final account is not suspended');

$activeTokens=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeTokens!==0)throw new RuntimeException('Concurrent mint left active token after suspension: '.$activeTokens);

$siteRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);
if(($siteRow['status']??'')!=='revoked'||($siteRow['token_sealed']??'')!=='')throw new RuntimeException('Suspension race left active/credentialed site');

echo 'PASS suspension/token race: minted_before_suspend='.$minted.', denied_after_suspend='.$denied.', active_tokens=0'.PHP_EOL;
