<?php
declare(strict_types=1);
namespace WPAICloudGateway { function time():int{return $GLOBALS['dispatch_clock'];} }
namespace {
$GLOBALS['dispatch_clock']=1000;
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Transport};
$checks=0;
function dispatchCheck(bool $ok,string $reason):void{++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($reason);}
$file=tempnam(sys_get_temp_dir(),'wpai-dispatch-');
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$file,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,'dispatch_'.bin2hex(random_bytes(5)).'_');$store->migrate();
$transport=new class implements Transport {
    public ?Closure $duringCatalog=null;
    public ?Closure $duringCall=null;
    public int $calls=0;
    public string $credential='';
    private FakeTransport $fake;
    public function __construct(){$this->fake=new FakeTransport();}
    public function rpc(string $home,string $token,array $request):array{
        if($request['method']==='tools/list'&&$this->duringCatalog){$callback=$this->duringCatalog;$this->duringCatalog=null;$callback();}
        elseif($request['method']==='tools/call'&&($request['params']['name']??'')!=='system_manifest'){
            ++$this->calls;$this->credential=$token;
            if($this->duringCall){$callback=$this->duringCall;$this->duringCall=null;$callback();}
        }
        return $this->fake->rpc($home,$token,$request);
    }
};
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
$core=new Core($store,new Vault(str_repeat('k',32)),$transport,$policy);
$now=strtotime('2026-10-22T12:00:00Z');
$setup=static function(string $owner)use($core,$now):array{
    $core->ensureAccount($owner);
    $site=$core->connect($owner,'https://'.$owner.'.example.com','fixture-old-site-credential-12345',$now);
    $actor=$core->authenticate($core->mint($owner,[$site['id']],['mcp:read'],$now,60)['access_token'],$now);
    return [$site,$actor];
};
try{
    foreach(['token','site','account','expired','elapsed'] as $case){
        $GLOBALS['dispatch_clock']=1000;
        $owner='case-'.$case;[$site,$actor]=$setup($owner);$before=$transport->calls;
        $transport->duringCatalog=static function()use($case,$core,$store,$actor,$site,$owner,$now):void{
            match($case){
                'token'=>$core->revokeToken($owner,$actor['token_hash'],$now+1),
                'site'=>$core->disconnect($owner,$site['id'],$now+1),
                'account'=>$core->suspendAccount($owner,$now+1),
                'elapsed'=>$GLOBALS['dispatch_clock']+=61,
                'expired'=>$store->execute('UPDATE '.$store->table('tokens').' SET expires_at=? WHERE hash=?',[$now,$actor['token_hash']]),
            };
        };
        $result=$core->call($actor,$site['id'],'wp_get_site',[],'revoked-during-catalog',$now);
        dispatchCheck($transport->calls===$before,'Revoked '.$case.' access still dispatched a site tool');
        dispatchCheck($result['state']==='failed'&&$result['charged_units']===0,'Revoked pre-dispatch request not settled free');
        dispatchCheck(!str_contains(json_encode($result),'fixture result'),'Revoked pre-dispatch response leaked site output');
        $balance=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=?',[$owner]);
        dispatchCheck((int)$balance['used']===0&&(int)$balance['reserved']===0,'Revoked pre-dispatch request retained quota');
    }
    [$site,$actor]=$setup('rotation');$before=$transport->calls;
    $newCredential='fixture-rotated-site-credential-67890';
    $transport->duringCatalog=static function()use($core,$site,$newCredential,$now):void{
        $core->connect('rotation',$site['home'],$newCredential,$now+1,true);
    };
    $result=$core->call($actor,$site['id'],'wp_get_site',[],'fresh-site-credential',$now);
    dispatchCheck($result['state']==='succeeded'&&$result['charged_units']===1,'Authorized rotated site could not execute');
    dispatchCheck($transport->calls===$before+1&&$transport->credential===$newCredential,'Dispatch used stale site credential');
    [$site,$actor]=$setup('inflight');$before=$transport->calls;
    $transport->duringCall=static fn()=>$core->revokeToken('inflight',$actor['token_hash'],$now+1);
    $result=$core->call($actor,$site['id'],'wp_get_site',[],'already-dispatched',$now);
    dispatchCheck($transport->calls===$before+1&&$result['state']==='succeeded','Already-dispatched confirmed reply not settled');
    $usage=$core->usage('inflight',$now+1);
    dispatchCheck($usage['used']===1&&$usage['reserved']===0,'In-flight revocation erased confirmed charge');
    echo "PASS $checks post-catalog revocation/expiry/fresh-credential/in-flight settlement assertions; driver=".$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
}finally{
    foreach(['audit','batch_steps','requests','balances','oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate','tokens','sites','accounts','rate'] as $name)$store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    if(!getenv('TEST_MYSQL_DSN'))foreach([$file,$file.'-wal',$file.'-shm'] as $path)if(is_file($path))unlink($path);
}
}
