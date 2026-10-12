<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Core,Fault,Transport};

$checks=0;
function richeck(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function rireject(callable $fn,string $reason): void { try{$fn();}catch(Fault $e){richeck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Expected '.$reason); }

final class IdentityTransport implements Transport {
    public string $pluginSite='plugin-original';
    public string $installation='install-original';
    public function rpc(string $home,string $token,array $request): array {
        if(($request['method']??'')==='tools/list') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>[['name'=>'wp_get_site','inputSchema'=>['type'=>'object']]]]];
        if(($request['params']['name']??'')==='system_manifest') {
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode([
                'plugin_name'=>'WP AI Admin',
                'site_id'=>$this->pluginSite,
                'installation_id'=>$this->installation,
                'release'=>['production_defaults'=>['direct_live_writes'=>false]]
            ])]]]];
        }
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>'ok']]]];
    }
}

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-reconnect-identity-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new IdentityTransport();
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
$core=new Core($store,new WPAICloudGateway\Vault(str_repeat('r',32)),$transport,$policy);
$core->ensureAccount('owner');

$first=$core->connect('owner','https://identity.example.com','fixture-identity-admin-token-aaaaaaaa',$now);
richeck($first['plugin_site_id']==='plugin-original'&&$first['installation_id']==='install-original','Initial identity not stored');

// Active same-owner replace with unchanged identity may rotate credential but must preserve registration id.
$same=$core->connect('owner','https://identity.example.com','fixture-identity-admin-token-bbbbbbbb',$now+1,true);
richeck($same['id']===$first['id'],'Same identity active reconnect changed registration id');

// A changed plugin site identity must never be accepted for the same canonical URL.
$transport->pluginSite='plugin-replaced';
rireject(fn()=>$core->connect('owner','https://identity.example.com','fixture-identity-admin-token-cccccccc',$now+2,true),'site_identity_changed');
richeck($core->sites('owner')[0]['id']===$first['id'],'Rejected identity change mutated registration');

// Installation identity is independently pinned as well.
$transport->pluginSite='plugin-original';
$transport->installation='install-replaced';
rireject(fn()=>$core->connect('owner','https://identity.example.com','fixture-identity-admin-token-dddddddd',$now+3,true),'site_identity_changed');

// After revocation, a valid same-installation reconnect gets a fresh registration id so old grants cannot revive.
$transport->installation='install-original';
$core->disconnect('owner',$first['id'],$now+4);
$reconnected=$core->connect('owner','https://identity.example.com','fixture-identity-admin-token-eeeeeeee',$now+5,true);
richeck($reconnected['id']!==$first['id'],'Revoked registration id reused after valid reconnect');
richeck(count($core->sites('owner'))===1&&$core->sites('owner')[0]['id']===$reconnected['id'],'Reconnect produced duplicate active site');

echo 'PASS '.$checks.' reconnect identity assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
