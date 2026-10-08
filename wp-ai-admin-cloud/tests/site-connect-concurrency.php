<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

if(!getenv('TEST_MYSQL_DSN')) {
    echo "SKIP site connect concurrency requires MySQL\n";
    exit(0);
}

$database='mysql-concurrency';
$store=fixtureStore($database);
$store->migrate();
foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$core=fixtureCore($store,new FakeTransport(),100);
$core->ensureAccount('race-a');
$core->ensureAccount('race-b');

$home='https://race.example.com';
$now=1791436800;
$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
$modules=['pdo','mysqlnd','pdo_mysql','sodium'];
foreach($modules as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/site-connect-worker.php',$database);

$workers=[];
for($i=0;$i<12;$i++){
    $owner=$i%2===0?'race-a':'race-b';
    $token='fixture-race-admin-token-'.str_pad((string)$i,24,'a');
    $cmd=array_merge($command,[$owner,$home,$token,(string)$now]);
    $pipes=[];
    $handle=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
        'TEST_MYSQL_DSN'=>getenv('TEST_MYSQL_DSN'),
        'TEST_MYSQL_USER'=>getenv('TEST_MYSQL_USER')?:'root',
        'TEST_MYSQL_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD')?:''
    ]);
    if(!is_resource($handle))throw new RuntimeException('Unable to start site race worker');
    fclose($pipes[0]);$workers[]=[$handle,$pipes,$owner];
}

$success=[];$expectedDenials=0;$unexpected=[];
foreach($workers as [$handle,$pipes,$owner]){
    $out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);
    if($exit!==0)$unexpected[]='exit='.$exit.' '.$err;
    elseif(str_starts_with($out,'success:'))$success[]=[$owner,substr($out,8)];
    elseif($out==='fault:site_already_registered')$expectedDenials++;
    else $unexpected[]=$out.($err!==''?' stderr='.$err:'');
}

if($unexpected)throw new RuntimeException('Unexpected concurrent registration result: '.json_encode($unexpected));
if(count($success)!==1)throw new RuntimeException('Exactly one concurrent registration must succeed; got '.count($success));
if($expectedDenials!==11)throw new RuntimeException('All losing registrations must fail as site_already_registered; got '.$expectedDenials);

$rows=$store->rows('SELECT id,owner,home,url_hash,status FROM '.$store->table('sites'));
if(count($rows)!==1)throw new RuntimeException('Concurrent registration created duplicate site rows');
if($rows[0]['owner']!==$success[0][0]||$rows[0]['id']!==$success[0][1]||$rows[0]['status']!=='active')throw new RuntimeException('Stored site owner/id does not match winning registration');

echo 'PASS concurrent site ownership race: winner='.$rows[0]['owner'].', denied='.$expectedDenials.PHP_EOL;
