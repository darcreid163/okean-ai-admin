<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Security;

if(!getenv('TEST_MYSQL_DSN')){
    echo "SKIP suspension/exchange concurrency requires MySQL\n";
    exit(0);
}

$database='mysql-suspension-exchange-race';
$store=fixtureStore($database);
$store->migrate();
foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));

$core=fixtureCore($store,new FakeTransport(),100);
$owner='exchange-race-owner';
$now=1791436800;
$core->ensureAccount($owner);
$site=$core->connect($owner,'https://exchange-race.example.com','fixture-exchange-race-site-token-aaaaaaaa',$now);

$client='client-exchange-race';
$redirect='https://client.example/callback';
$verifier=str_repeat('v',43);
$challenge=Security::b64(hash('sha256',$verifier,true));
$code='exchange-race-code-'.str_repeat('c',32);

$store->execute('INSERT INTO '.$store->table('oauth_clients').' (id,name,redirects,created_at) VALUES (?,?,?,?)',[
    $client,'Exchange race client',Security::canonical([$redirect]),$now
]);
$store->execute('INSERT INTO '.$store->table('oauth_codes').' (hash,owner,client,redirect,resource,scopes,sites,challenge,expires,used) VALUES (?,?,?,?,?,?,?,?,?,0)',[
    hash('sha256',$code),$owner,$client,$redirect,'https://wpaiadmin.ru/mcp',
    Security::canonical(['mcp:read','mcp:write']),Security::canonical([$site['id']]),$challenge,$now+600
]);

$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
foreach(['pdo','mysqlnd','pdo_mysql','sodium'] as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/suspension-exchange-worker.php',$database);

$workers=[];
for($i=0;$i<16;$i++){
    $mode=$i%4===0?'suspend':'exchange';
    $cmd=array_merge($command,[$mode,$owner,$client,$redirect,$code,$verifier,(string)($now+1)]);
    $pipes=[];
    $handle=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
        'TEST_MYSQL_DSN'=>getenv('TEST_MYSQL_DSN'),
        'TEST_MYSQL_USER'=>getenv('TEST_MYSQL_USER')?:'root',
        'TEST_MYSQL_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD')?:''
    ]);
    if(!is_resource($handle))throw new RuntimeException('Unable to start suspension/exchange worker');
    fclose($pipes[0]);$workers[]=[$handle,$pipes,$mode];
}

$exchanged=0;$suspended=0;$denied=0;$unexpected=[];
foreach($workers as [$handle,$pipes,$mode]){
    $out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);
    if($exit!==0){$unexpected[]='exit='.$exit.' '.$err;continue;}
    if(str_starts_with($out,'exchange:')){$exchanged++;continue;}
    if($out==='suspend:ok'){$suspended++;continue;}
    if($out==='fault:invalid_grant'||$out==='fault:account_unavailable'){$denied++;continue;}
    $unexpected[]=$out.($err!==''?' stderr='.$err:'');
}

if($unexpected)throw new RuntimeException('Unexpected suspension/exchange race result: '.json_encode($unexpected));
if($suspended!==1)throw new RuntimeException('Exactly one suspension must win; got '.$suspended);
if($exchanged>1)throw new RuntimeException('Authorization code exchanged more than once: '.$exchanged);

$account=$store->row('SELECT disabled FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
if((int)($account['disabled']??0)!==1)throw new RuntimeException('Final account is not suspended');

$activeTokens=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeTokens!==0)throw new RuntimeException('Exchange/suspension race left active bearer token: '.$activeTokens);

$activeGrants=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_grants').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeGrants!==0)throw new RuntimeException('Exchange/suspension race left active OAuth grant: '.$activeGrants);

$unusedCodes=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_codes').' WHERE owner=? AND used=0',[$owner])['n']??-1);
if($unusedCodes!==0)throw new RuntimeException('Exchange/suspension race left unused authorization code: '.$unusedCodes);

echo 'PASS suspension/exchange race: exchanged_before_suspend='.$exchanged.', denied='.$denied.', active_tokens=0'.PHP_EOL;
