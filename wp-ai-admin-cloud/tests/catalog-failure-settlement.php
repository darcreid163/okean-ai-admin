<?php
declare(strict_types=1);
/** Never-dispatched catalog failures versus truly unknown tool outcomes. */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Transport,Fault,Gateway};
$checks=0;
function preflightCheck(bool $ok,string $reason):void{++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($reason);}
function preflightDeny(callable $fn,string $reason):void{
    try{$fn();}catch(Fault $e){preflightCheck($e->reason===$reason,'Wrong denial '.$e->reason);return;}
    throw new RuntimeException('Missing denial '.$reason);
}
$database=tempnam(sys_get_temp_dir(),'wpai-catalog-');
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,'catalog_'.bin2hex(random_bytes(5)).'_');$store->migrate();
$transport=new class implements Transport {
    public string $mode='catalog-failure';
    public int $catalogs=0;
    public int $calls=0;
    private FakeTransport $fake;
    public function __construct(){$this->fake=new FakeTransport();}
    public function rpc(string $home,string $token,array $request):array{
        if($request['method']==='tools/list'){
            ++$this->catalogs;
            if($this->mode==='catalog-failure')throw new RuntimeException('fixture-private-transport-detail');
            if($this->mode==='catalog-invalid')return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>null]];
        }elseif(($request['params']['name']??'')!=='system_manifest'){
            ++$this->calls;
            if($this->mode==='tool-uncertain')throw new Fault('upstream_outcome_unknown',502);
        }
        return $this->fake->rpc($home,$token,$request);
    }
};
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);$policy['plans']['free']=3;
$core=new Core($store,new Vault(str_repeat('k',32)),$transport,$policy);
$now=strtotime('2026-10-22T12:00:00Z');
try{
    $core->ensureAccount('owner-a');$core->ensureAccount('owner-b');
    $site=$core->connect('owner-a','https://catalog-a.example.com','fixture-catalog-site-credential-a',$now);
    $other=$core->connect('owner-b','https://catalog-b.example.com','fixture-catalog-site-credential-b',$now);
    $actor=$core->authenticate($core->mint('owner-a',[$site['id']],['mcp:read'],$now)['access_token'],$now);
    $first=$core->call($actor,$site['id'],'wp_get_site',[],'catalog-failed',$now);
    preflightCheck($first['state']==='failed'&&$first['charged_units']===0,'Catalog failure must be a confirmed zero-charge failure');
    preflightCheck($transport->calls===0,'Catalog failure dispatched a site tool');
    $usage=$core->usage('owner-a',$now);
    preflightCheck($usage['used']===0&&$usage['reserved']===0,'Catalog failure retained quota');
    preflightCheck(!str_contains(json_encode($first),'fixture-private-transport-detail'),'Transport exception leaked into receipt');
    $transport->mode='ok';$catalogs=$transport->catalogs;
    $cached=$core->call($actor,$site['id'],'wp_get_site',[],'catalog-failed',$now+1);
    preflightCheck(WPAICloudGateway\Security::canonical($cached)===WPAICloudGateway\Security::canonical($first)&&$transport->catalogs===$catalogs&&$transport->calls===0,'Failed same-key replay retried upstream');
    $success=$core->call($actor,$site['id'],'wp_get_site',[],'separate-logical-read',$now+2);
    preflightCheck($success['charged_units']===1&&$transport->calls===1,'Separate read did not recover after catalog restored');
    $transport->mode='catalog-invalid';
    $gateway=new Gateway($core);
    $reply=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>'gateway-catalog','method'=>'tools/call','params'=>['name'=>'cloud_site_tool_call','arguments'=>['site_id'=>$site['id'],'tool'=>'wp_get_site','arguments'=>[],'idempotency_key'=>'invalid-catalog']]],$now+3);
    $receipt=json_decode($reply['result']['content'][0]['text'],true);
    preflightCheck($reply['result']['isError']===true&&$receipt['state']==='failed'&&$receipt['charged_units']===0,'Gateway did not report pre-dispatch failure');
    $usage=$core->usage('owner-a',$now+3);
    preflightCheck($usage['used']===1&&$usage['reserved']===0&&$transport->calls===1,'Invalid catalog changed settled charge or dispatched');
    $catalogs=$transport->catalogs;
    preflightDeny(fn()=>$core->call($actor,$other['id'],'wp_get_site',[],'foreign-site',$now+4),'scope_or_site_denied');
    preflightDeny(fn()=>$core->call($actor,$site['id'],'operation_apply',[],'read-write-denied',$now+4),'scope_or_site_denied');
    preflightCheck($transport->catalogs===$catalogs,'Denied request fetched catalog');
    $transport->mode='tool-uncertain';
    preflightDeny(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'tool-unknown',$now+5),'request_outcome_pending_reconciliation');
    $usage=$core->usage('owner-a',$now+5);
    preflightCheck($usage['used']===1&&$usage['reserved']===1&&$transport->calls===2,'Actual unknown tool result lost reservation');
    preflightDeny(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'tool-unknown',$now+6),'request_outcome_pending_reconciliation');
    preflightCheck($transport->calls===2&&$core->usage('owner-a',$now+6)['reserved']===1,'Unknown tool retry dispatched or reserved twice');
    $ledger=$core->ledger('owner-a');$states=array_column($ledger,'charged_units','request_key');
    preflightCheck($states[$first['request_key']]===0&&$states[hash('sha256','owner-a|tool-unknown')]===null,'Ledger lost failed/unknown charge distinction');
    echo "PASS $checks catalog pre-dispatch settlement/replay/authorization/unknown-outcome assertions; driver=".$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
}finally{
    foreach(['audit','batch_steps','requests','balances','oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate','tokens','sites','accounts','rate'] as $name)$store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    if(!getenv('TEST_MYSQL_DSN'))foreach([$database,$database.'-wal',$database.'-shm'] as $file)if(is_file($file))unlink($file);
}
