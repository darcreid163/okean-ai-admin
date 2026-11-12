<?php
declare(strict_types=1);
/**
 * #136 billing proof: every failure shape, idempotent retry, batch, timeout,
 * quota boundary, UTC rollover and settlement failure is driven through the
 * real Core/Store reservation + settlement path. Prints expected vs actual
 * charge for each case.
 */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Signer,QuotaPolicy,BatchAccounting,Transport,Fault,Security};

$checks=0;
function bp(bool $ok,string $message): void { global $checks; ++$checks; if(!$ok) throw new RuntimeException($message); }
function bpRow(string $case,int $expected,int $charged,int $usedBefore,int $usedAfter,string $note=''): void {
    printf("CASE %-22s expected=%d charged=%d used %d->%d %s\n",$case,$expected,$charged,$usedBefore,$usedAfter,$note);
}
function bpReject(callable $fn,string $reason): Fault {
    try { $fn(); } catch (Fault $e) { bp($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return $e; }
    throw new RuntimeException('Expected rejection: '.$reason);
}

/** Shape-selectable site transport; `shape` is taken from tool arguments. */
final class BillingTransport implements Transport {
    public int $executions=0;
    public $onCall=null;
    private const TOOLS=['wp_get_site','wp_read_content','woocommerce_update_product_sandbox','operation_apply'];
    public function rpc(string $home,string $token,array $request): array {
        if (($request['method']??'')==='tools/list') {
            $tools=[];foreach(self::TOOLS as $name)$tools[]=['name'=>$name,'inputSchema'=>['type'=>'object']];
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>$tools]];
        }
        if (($request['params']['name']??'')==='system_manifest') {
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['plugin_name'=>'WP AI Admin','site_id'=>'plugin-'.hash('sha256',$home),'installation_id'=>'inst-'.hash('sha256',$home),'release'=>['production_defaults'=>['direct_live_writes'=>false]]])]]]];
        }
        $args=(array)($request['params']['arguments']??[]);
        if (($args['shape']??'')==='uncertain') throw new Fault('upstream_outcome_unknown',502);
        ++$this->executions;
        if (is_callable($this->onCall)) ($this->onCall)($request);
        if (($args['shape']??'')==='wp_error') return ['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32000,'message'=>'WordPress API error']];
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>$this->result((string)($args['shape']??'ok'))];
    }
    private function result(string $shape): array {
        $text=static fn(array $value): array => ['content'=>[['type'=>'text','text'=>json_encode($value,JSON_UNESCAPED_SLASHES)]]];
        return match($shape) {
            'is_error'       => $text(['ok'=>false,'code'=>'permission_denied','message'=>'Missing capability','status'=>403])+['isError'=>true],
            'ok_false'       => $text(['ok'=>false,'code'=>'validation_error','message'=>'Bad argument','status'=>400]),
            'success_false'  => $text(['success'=>false,'message'=>'Handler failed']),
            'http_500'       => $text(['http_status'=>500,'data'=>['message'=>'Upstream 500']]),
            'status_422'     => $text(['status'=>422,'message'=>'Unprocessable']),
            'error_key'      => $text(['error'=>'wp_error','message'=>'Handler threw']),
            'list_rows'      => $text([['ok'=>false,'code'=>'child_failed'],['ok'=>true,'id'=>2]]),
            'entity_status'  => $text(['ok'=>true,'status'=>'publish','id'=>7]),
            default          => $text(['ok'=>true,'value'=>'ok']),
        };
    }
}

$now=strtotime('2026-11-06T12:00:00Z');
$database=tempnam(sys_get_temp_dir(),'wpai-billing-proof-');
if($database===false)throw new RuntimeException('No disposable database');
$prefix='qa_bill_'.bin2hex(random_bytes(5)).'_';
$dsn=getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database;
$user=getenv('TEST_MYSQL_USER')?:null;
$pass=getenv('TEST_MYSQL_PASSWORD')?:null;
$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,$prefix);
$store->migrate();
$vaultKey=str_repeat('k',32);
$policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
$transport=new BillingTransport();
$core=new Core($store,new Vault($vaultKey),$transport,$policy,new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
try {
$core->ensureAccount('1');
$site=$core->connect('1','https://billing-one.example.com','fixture-billing-credential-owner-1',$now);
$actor=$core->authenticate($core->mint('1',[$site['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);

// --- 1. Failure-shape matrix: charged_units must equal the expected value. ---
$cases=[
    'ok'=>[1,'successful read charged exactly once'],
    'is_error'=>[0,'explicit isError'],
    'wp_error'=>[0,'WordPress/JSON-RPC error'],
    'ok_false'=>[0,'legacy ok:false content without isError'],
    'success_false'=>[0,'legacy success:false content'],
    'http_500'=>[0,'upstream http_status 500'],
    'status_422'=>[0,'validation status 422'],
    'error_key'=>[0,'legacy top-level error member'],
    'list_rows'=>[1,'a list of rows is one successful read'],
    'entity_status'=>[1,'entity status "publish" is not an HTTP failure'],
];
foreach($cases as $shape=>[$expected,$note]){
    $before=$core->usage('1',$now);
    $receipt=$core->call($actor,$site['id'],'wp_get_site',['shape'=>$shape],'shape-'.$shape,$now);
    $after=$core->usage('1',$now);
    bpRow($shape,$expected,(int)$receipt['charged_units'],$before['used'],$after['used'],$note);
    bp((int)$receipt['charged_units']===$expected,'Wrong charge for '.$shape);
    bp($after['used']-$before['used']===$expected,'Ledger delta mismatch for '.$shape);
    bp($after['reserved']===$before['reserved'],'Reservation leaked for '.$shape);
}

// --- 2. Duplicate delivery: cached byte-identical receipt, one charge. ---
$before=$core->usage('1',$now);$exec=$transport->executions;
$first=$core->call($actor,$site['id'],'wp_get_site',['shape'=>'ok'],'dup-key',$now);
$second=$core->call($actor,$site['id'],'wp_get_site',['shape'=>'ok'],'dup-key',$now);
$after=$core->usage('1',$now);
bpRow('duplicate',1,$after['used']-$before['used'],$before['used'],$after['used'],'cached receipt');
bp(Security::canonical($first)===Security::canonical($second),'Duplicate delivery returned a different receipt');
bp($after['used']-$before['used']===1,'Duplicate delivery double-charged');
bp($transport->executions===$exec+1,'Duplicate delivery redispatched the tool');
bpReject(fn()=>$core->call($actor,$site['id'],'wp_get_site',['shape'=>'ok','changed'=>1],'dup-key',$now),'idempotency_conflict');

// --- 3. Batch: only successfully processed objects are charged. ---
$policy['batch']=BatchAccounting::POLICY;
$batchCore=new Core($store,new Vault($vaultKey),$transport,$policy,new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
$batchCore->ensureAccount('1');
$items=[
    ['id'=>'ok-1','tool'=>'wp_get_site','arguments'=>['shape'=>'ok']],
    ['id'=>'denied','tool'=>'wp_get_site','arguments'=>['shape'=>'is_error']],
    ['id'=>'legacy-fail','tool'=>'wp_get_site','arguments'=>['shape'=>'ok_false']],
    ['id'=>'ok-2','tool'=>'wp_get_site','arguments'=>['shape'=>'ok']],
    ['id'=>'ok-3','tool'=>'wp_get_site','arguments'=>['shape'=>'entity_status']],
];
$before=$batchCore->usage('1',$now);
$batch=$batchCore->batchCall($actor,$site['id'],$items,'batch-five',false,$now);
$after=$batchCore->usage('1',$now);
bpRow('batch-5/2-fail',3,(int)$batch['charged_units'],$before['used'],$after['used'],'2 objects failed');
bp($batch['summary']['succeeded']===3&&$batch['summary']['failed']===2,'Batch summary must report 2 failed objects');
bp($after['used']-$before['used']===3,'Batch ledger delta mismatch');
$replay=$batchCore->batchCall($actor,$site['id'],$items,'batch-five',false,$now);
bp($replay['charged_units']===3&&$batchCore->usage('1',$now)['used']===$after['used'],'Batch replay double-charged');

// --- 4. Client timeout / lost response: uncertain, nothing charged, no retry. ---
$core->ensureAccount('t');
$timeoutSite=$core->connect('t','https://billing-timeout.example.com','fixture-billing-credential-owner-t',$now);
$timeoutActor=$core->authenticate($core->mint('t',[$timeoutSite['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
$before=$core->usage('t',$now);
bpReject(fn()=>$core->call($timeoutActor,$timeoutSite['id'],'wp_get_site',['shape'=>'uncertain'],'timeout-key',$now),'request_outcome_pending_reconciliation');
$pending=$core->usage('t',$now);
bpRow('timeout',0,0,$before['used'],$pending['used'],'reserved=1');
bp($pending['used']===$before['used']&&$pending['reserved']===$before['reserved']+1,'Timeout charged or lost its reservation');
$exec=$transport->executions;
bpReject(fn()=>$core->call($timeoutActor,$timeoutSite['id'],'wp_get_site',['shape'=>'uncertain'],'timeout-key',$now),'request_outcome_pending_reconciliation');
bp($transport->executions===$exec,'Uncertain request was retried automatically');
$key=hash('sha256','t|timeout-key');
$reconciled=$core->reconcile('operator','t',$key,'failed',['note'=>'Independent receipt proves the write never executed.','receipt_sha256'=>str_repeat('b',64)],$now);
$settled=$core->usage('t',$now);
bpRow('reconciled',0,(int)$reconciled['charged_units'],$pending['used'],$settled['used'],'reservation released');
bp($settled['reserved']===$before['reserved'],'Reconciliation left a reservation');
$retry=$core->call($timeoutActor,$timeoutSite['id'],'wp_get_site',['shape'=>'uncertain'],'timeout-key',$now);
bp($retry['reconciled']===true&&$transport->executions===$exec,'Reconciled request was redispatched');

// --- 5. Quota boundaries at 0, 1, limit-1, exact limit and over-limit. ---
$tight=$policy;$tight['plans']['free']=2;
$boundaryCore=new Core($store,new Vault($vaultKey),$transport,$tight,new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
$boundaryCore->ensureAccount('b');
$bSite=$boundaryCore->connect('b','https://billing-boundary.example.com','fixture-billing-credential-owner-b',$now);
$bActor=$boundaryCore->authenticate($boundaryCore->mint('b',[$bSite['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
foreach([['used'=>0,'expect'=>1],['used'=>1,'expect'=>1]] as $step){
    $before=$boundaryCore->usage('b',$now);
    bp($before['used']===$step['used'],'Boundary fixture precondition failed');
    $r=$boundaryCore->call($bActor,$bSite['id'],'wp_get_site',[],'boundary-'.$step['used'],$now);
    $after=$boundaryCore->usage('b',$now);
    bpRow('used='.$step['used'],$step['expect'],(int)$r['charged_units'],$before['used'],$after['used']);
    bp($r['charged_units']===$step['expect'],'Boundary charge mismatch at used='.$step['used']);
}
$before=$boundaryCore->usage('b',$now);
bp($before['used']===2&&$before['remaining']===0,'Boundary limit not reached');
bpReject(fn()=>$boundaryCore->call($bActor,$bSite['id'],'wp_get_site',[],'boundary-over',$now),'daily_quota_exhausted');
$after=$boundaryCore->usage('b',$now);
bpRow('over-limit',0,0,$before['used'],$after['used']);
bp($after['used']===2&&$after['used']<=$after['limit'],'Quota exceeded the plan limit');

// --- 6. UTC rollover: a new window starts at zero. ---
$rolloverNow=strtotime('2026-11-06T23:59:30Z');$nextDay=$rolloverNow+120;
$rollCore=new Core($store,new Vault($vaultKey),$transport,$tight,new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
$rollCore->ensureAccount('r');
$rSite=$rollCore->connect('r','https://billing-rollover.example.com','fixture-billing-credential-owner-r',$rolloverNow);
$rActor=$rollCore->authenticate($rollCore->mint('r',[$rSite['id']],['mcp:read','mcp:write'],$rolloverNow)['access_token'],$rolloverNow);
$rollCore->call($rActor,$rSite['id'],'wp_get_site',[],'roll-day-1',$rolloverNow);
$dayOne=$rollCore->usage('r',$rolloverNow);
$rollCore->call($rActor,$rSite['id'],'wp_get_site',[],'roll-day-2',$nextDay);
$dayTwo=$rollCore->usage('r',$nextDay);
bpRow('utc-rollover',1,1,$dayOne['used'],$dayTwo['used'],'new UTC window');
bp($dayOne['used']===1&&$dayTwo['used']===1,'UTC rollover did not reset the window');
bp($dayOne['period']!==$dayTwo['period'],'UTC period key did not advance');
bp($rollCore->usage('r',$rolloverNow)['used']===1,'Previous window balance was rewritten');

// --- 7. Settlement storage failure: no success without a recorded charge. ---
$core->ensureAccount('s');
$failSite=$core->connect('s','https://billing-settlement.example.com','fixture-billing-credential-owner-s',$now);
$failActor=$core->authenticate($core->mint('s',[$failSite['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
$period=gmdate('Y-m-d',$now);
$transport->onCall=static function() use ($store,$period): void {
    // Simulated database failure between reservation and settlement.
    $store->execute('DELETE FROM '.$store->table('balances').' WHERE owner=? AND period=?',['s',$period]);
};
$exec=$transport->executions;
$fault=bpReject(fn()=>$core->call($failActor,$failSite['id'],'wp_get_site',['shape'=>'ok'],'settle-fail',$now),'request_outcome_pending_reconciliation');
bp($fault->status===502,'Settlement failure must be reported as an uncertain outcome');
$transport->onCall=null;
$failedUsage=$core->usage('s',$now);
bpRow('settlement-db-fail',0,0,0,$failedUsage['used'],'no recorded charge');
bp($failedUsage['used']===0,'Settlement failure reported a charge');
$execAfter=$transport->executions;
bpReject(fn()=>$core->call($failActor,$failSite['id'],'wp_get_site',['shape'=>'ok'],'settle-fail',$now),'request_outcome_pending_reconciliation');
bp($transport->executions===$execAfter,'Request with an unrecorded settlement was redispatched');

// --- 8. Parallel calls at used = limit - 1 (MySQL row locks only). ---
if(getenv('TEST_MYSQL_DSN')){
    $par=$policy;$par['plans']['free']=2;
    $parCore=new Core($store,new Vault($vaultKey),$transport,$par,new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
    $parCore->ensureAccount('p');
    $pSite=$parCore->connect('p','https://billing-parallel.example.com','fixture-billing-credential-owner-p',$now);
    $parCore->call($parCore->authenticate($parCore->mint('p',[$pSite['id']],['mcp:read'],$now)['access_token'],$now),$pSite['id'],'wp_get_site',[],'parallel-warm',$now);
    $parToken=$parCore->mint('p',[$pSite['id']],['mcp:read'],$now)['access_token'];
    $config=base64_encode(json_encode(['dsn'=>getenv('TEST_MYSQL_DSN'),'user'=>getenv('TEST_MYSQL_USER'),'pass'=>getenv('TEST_MYSQL_PASSWORD'),'prefix'=>$prefix,'vault'=>$vaultKey,'policy'=>$par,'token'=>$parToken,'site'=>$pSite['id'],'now'=>$now],JSON_THROW_ON_ERROR));
    $results=[];
    foreach(['par-a','par-b'] as $key){
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/billing-parallel-worker.php').' '.escapeshellarg($config).' '.escapeshellarg($key);
        $out=shell_exec($cmd);
        $results[$key]=json_decode((string)$out,true);
    }
    $ok=array_filter($results,static fn($r)=>is_array($r)&&($r['ok']??false)===true);
    $rejected=array_filter($results,static fn($r)=>is_array($r)&&($r['fault']??'')==='daily_quota_exhausted');
    $final=$parCore->usage('p',$now);
    bpRow('parallel-limit-1',1,count($ok),1,$final['used'],'2 concurrent calls');
    bp(count($ok)===1&&count($rejected)===1,'Parallel limit race did not allow exactly one call');
    bp($final['used']===2&&$final['reserved']===0,'Parallel quota ended at '.$final['used'].' used / '.$final['reserved'].' reserved');
}else{
    echo "SKIP parallel-limit-1: deterministic row-lock race requires TEST_MYSQL_DSN\n";
}

echo 'PASS '.$checks." billing proof assertions; driver=".(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
} finally {
    $transport->onCall=null;
    foreach(['audit','batch_steps','requests','balances','accounts','rate','sites','tokens'] as $name){
        try{$store->execute('DROP TABLE IF EXISTS '.$store->table($name));}catch(Throwable $ignored){}
    }
    $pdo=null;
    if(!getenv('TEST_MYSQL_DSN'))foreach([$database,$database.'-wal',$database.'-shm'] as $file)if(is_file($file))@unlink($file);
}
