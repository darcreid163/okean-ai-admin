<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Security;

if(!getenv('TEST_MYSQL_DSN')){
    echo "SKIP suspension/consent concurrency requires MySQL\n";
    exit(0);
}

$database='mysql-suspension-consent-race';
$store=fixtureStore($database);
$store->migrate();
foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));

$core=fixtureCore($store,new FakeTransport(),100);
$owner='consent-race-owner';
$now=1791436800;
$core->ensureAccount($owner);
$site=$core->connect($owner,'https://consent-race.example.com','fixture-consent-race-site-token-aaaaaaaa',$now);

$client='client-consent-race';
$redirect='https://client.example/callback';
$verifier=str_repeat('v',43);
$store->execute('INSERT INTO '.$store->table('oauth_clients').' (id,name,redirects,created_at) VALUES (?,?,?,?)',[
    $client,'Consent race client',Security::canonical([$redirect]),$now
]);

$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
foreach(['pdo','mysqlnd','pdo_mysql','sodium'] as $module)if(is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX))array_push($command,'-d','extension='.$module);
array_push($command,__DIR__.'/suspension-consent-worker.php',$database);

$workers=[];
for($i=0;$i<16;$i++){
    $mode=$i%4===0?'suspend':'approve';
    $cmd=array_merge($command,[$mode,$owner,$site['id'],$client,$redirect,$verifier,(string)($now+1)]);
    $pipes=[];
    $handle=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
        'TEST_MYSQL_DSN'=>getenv('TEST_MYSQL_DSN'),
        'TEST_MYSQL_USER'=>getenv('TEST_MYSQL_USER')?:'root',
        'TEST_MYSQL_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD')?:''
    ]);
    if(!is_resource($handle))throw new RuntimeException('Unable to start suspension/consent worker');
    fclose($pipes[0]);$workers[]=[$handle,$pipes,$mode];
}

$approved=0;$suspended=0;$denied=0;$unexpected=[];
foreach($workers as [$handle,$pipes,$mode]){
    $out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($handle);
    if($exit!==0){$unexpected[]='exit='.$exit.' '.$err;continue;}
    if(str_starts_with($out,'approve:')){$approved++;continue;}
    if($out==='suspend:ok'){$suspended++;continue;}
    if(in_array($out,['fault:account_unavailable','fault:invalid_site_consent'],true)){$denied++;continue;}
    $unexpected[]=$out.($err!==''?' stderr='.$err:'');
}
if($unexpected)throw new RuntimeException('Unexpected suspension/consent race result: '.json_encode($unexpected));
if($suspended!==1)throw new RuntimeException('Exactly one suspension must win; got '.$suspended);

$account=$store->row('SELECT disabled FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
if((int)($account['disabled']??0)!==1)throw new RuntimeException('Final account is not suspended');

$unusedCodes=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_codes').' WHERE owner=? AND used=0',[$owner])['n']??-1);
if($unusedCodes!==0)throw new RuntimeException('Concurrent OAuth approval left unused code after suspension: '.$unusedCodes);

$activeGrants=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('oauth_grants').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeGrants!==0)throw new RuntimeException('Suspension/consent race left active OAuth grant: '.$activeGrants);

$activeTokens=(int)($store->row('SELECT COUNT(*) AS n FROM '.$store->table('tokens').' WHERE owner=? AND revoked=0',[$owner])['n']??-1);
if($activeTokens!==0)throw new RuntimeException('Suspension/consent race left active bearer token: '.$activeTokens);

$siteRow=$store->row('SELECT status,token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);
if(($siteRow['status']??'')!=='revoked'||($siteRow['token_sealed']??'')!=='')throw new RuntimeException('Suspension/consent race left site credential active');

echo 'PASS suspension/consent race: approved_before_suspend='.$approved.', denied_after_suspend='.$denied.', unused_codes=0'.PHP_EOL;
