<?php
declare(strict_types=1);
/**
 * #124 acceptance: one logical creation request key must behave identically
 * through the single-tool transport (the path used by the MCP adapter) and the
 * Batch transport. A Native 409 is a conflict/failed object, never a refund or
 * a successful object. Uncertain outcomes stay reserved until evidence-based
 * reconciliation. The Gateway ledger remains the only balance authority.
 *
 * Synthetic fixtures only: FakeTransport, disposable SQLite/MySQL store, no
 * WordPress writes and no production billing.
 */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Signer,BatchAccounting,Transport,Fault,Security};

$checks=0;
function ct(bool $ok,string $message): void { global $checks; ++$checks; if(!$ok) throw new RuntimeException($message); }
function ctReject(callable $fn,string $reason): Fault {
    try { $fn(); } catch(Fault $e) { ct($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return $e; }
    throw new RuntimeException('Expected rejection: '.$reason);
}
function ctCase(string $case,int $expected,int $actual): void {
    printf("CASE %-30s expected=%d actual=%d\n",$case,$expected,$actual);
    ct($expected===$actual,$case.' mismatch');
}

/** Creation-shaped site transport: the same logical key can succeed, conflict or become unknown. */
final class CreationTransport implements Transport {
    public int $executions=0;
    public array $log=[];
    private const TOOLS=['wp_get_site','elementor_create_draft','woocommerce_update_product_sandbox'];
    public function rpc(string $home,string $token,array $request): array {
        if(($request['method']??'')==='tools/list'){
            $tools=[];foreach(self::TOOLS as $name)$tools[]=['name'=>$name,'inputSchema'=>['type'=>'object']];
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>$tools]];
        }
        if(($request['params']['name']??'')==='system_manifest'){
            return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['plugin_name'=>'WP AI Admin','site_id'=>'plugin-'.hash('sha256',$home),'installation_id'=>'inst-'.hash('sha256',$home),'release'=>['production_defaults'=>['direct_live_writes'=>false]]])]]]];
        }
        $args=(array)($request['params']['arguments']??[]);
        $shape=(string)($args['shape']??'creation_ok');
        ++$this->executions;
        $this->log[]=['tool'=>(string)($request['params']['name']??''),'logical'=>(string)($args['logical_key']??''),'shape'=>$shape];
        if($shape==='uncertain')throw new Fault('upstream_outcome_unknown',502);
        // The Native MCP adapter returns a JSON-RPC error for a durable creation
        // claim that is already pending/conflicting, with the HTTP-like status
        // inside error.data.status. It must never look like a successful object.
        if($shape==='native_409')return ['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32000,'message'=>'Page creation requires result reconciliation','data'=>['status'=>409]]];
        if($shape==='failed')return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['ok'=>false,'code'=>'validation_error','status'=>400])]]]];
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['ok'=>true,'id'=>501,'status'=>'pending','logical_key'=>$args['logical_key']??''])]]]];
    }
}

$now=strtotime('2026-11-06T12:00:00Z');
$database=tempnam(sys_get_temp_dir(),'wpai-creation-transport-');
if($database===false)throw new RuntimeException('No disposable database');
$prefix='qa_creation_'.bin2hex(random_bytes(5)).'_';
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,$prefix);
$store->migrate();
$policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
$transport=new CreationTransport();
$vault=new Vault(str_repeat('k',32));
$signer=new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
$core=new Core($store,$vault,$transport,$policy,$signer);
try {
$owner='ct124';
$core->ensureAccount($owner);
$site=$core->connect($owner,'https://creation-124.example.com','fixture-creation-credential-124',$now);
$actor=$core->authenticate($core->mint($owner,[$site['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);

// --- 1. Single-tool transport: one reservation, one charge, cached retry. ---
$before=$core->usage($owner,$now);
$first=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-A','title'=>'A'],'create-A',$now);
ct($first['state']==='succeeded'&&$first['charged_units']===1,'Successful creation was not charged exactly once');
$after=$core->usage($owner,$now);
ctCase('single/charge',$before['used']+1,$after['used']);
ctCase('single/dispatch',1,$transport->executions);
ctCase('single/reserved',$before['reserved'],$after['reserved']);
$replay=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-A','title'=>'A'],'create-A',$now);
ct(Security::canonical($first)===Security::canonical($replay),'Cached retry returned a different receipt');
ctCase('single/replay-dispatch',1,$transport->executions);
ctCase('single/replay-used',$after['used'],$core->usage($owner,$now)['used']);
// Changed payload under the same request key is an idempotency conflict.
ctReject(fn()=>$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-A','title'=>'CHANGED'],'create-A',$now),'idempotency_conflict');
ctCase('single/conflict-dispatch',1,$transport->executions);
ctCase('single/conflict-used',$after['used'],$core->usage($owner,$now)['used']);

// --- 2. Native 409: conflict/failed object, never a refund or a charge. ---
$before=$core->usage($owner,$now);
$conflict=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-A','shape'=>'native_409'],'create-A-retry',$now);
ct($conflict['state']==='failed'&&$conflict['charged_units']===0,'Native 409 settled as a successful/charged object');
$after=$core->usage($owner,$now);
ctCase('native-409/used',$before['used'],$after['used']);
ctCase('native-409/reserved',$before['reserved'],$after['reserved']);
ctCase('native-409/dispatch',2,$transport->executions);
$conflictReplay=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-A','shape'=>'native_409'],'create-A-retry',$now);
ct(Security::canonical($conflict)===Security::canonical($conflictReplay),'Native 409 retry returned a different receipt');
ctCase('native-409/replay-dispatch',2,$transport->executions);
// The earlier successful charge is untouched: a conflict is not a refund.
ctCase('native-409/no-refund',$after['used'],$core->usage($owner,$now)['used']);
// A genuinely different logical creation after the conflict is still allowed.
$fresh=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'create-B'],'create-B',$now);
ct($fresh['state']==='succeeded'&&$fresh['charged_units']===1,'Fresh creation after a conflict was not charged once');
ctCase('after-conflict/used',$after['used']+1,$core->usage($owner,$now)['used']);

// --- 3. Batch transport: per-object settlement, replay, conflict. ---
$policy['batch']=BatchAccounting::POLICY;
$batchCore=new Core($store,$vault,$transport,$policy,$signer);
$batchCore->ensureAccount($owner);
$items=[
    ['id'=>'step-create','tool'=>'elementor_create_draft','arguments'=>['logical_key'=>'batch-A']],
    ['id'=>'step-conflict','tool'=>'elementor_create_draft','arguments'=>['logical_key'=>'batch-A','shape'=>'native_409']],
    ['id'=>'step-failed','tool'=>'wp_get_site','arguments'=>['shape'=>'failed']],
];
$before=$batchCore->usage($owner,$now);$exec=$transport->executions;
$batch=$batchCore->batchCall($actor,$site['id'],$items,'batch-A',false,$now);
$after=$batchCore->usage($owner,$now);
ctCase('batch/charged',$before['used']+1,$after['used']);
ct($batch['charged_units']===1&&$batch['pending_units']===0,'Batch charged a failed/conflicting object');
ct($batch['summary']['succeeded']===1&&$batch['summary']['failed']===2,'Batch per-step summary is wrong');
ctCase('batch/dispatch',$exec+3,$transport->executions);
ctCase('batch/reserved',$before['reserved'],$after['reserved']);
$batchReplay=$batchCore->batchCall($actor,$site['id'],$items,'batch-A',false,$now);
ct($batchReplay['charged_units']===1,'Batch replay changed the charge');
ctCase('batch/replay-dispatch',$exec+3,$transport->executions);
$changed=$items;$changed[0]['arguments']=['logical_key'=>'batch-A','title'=>'CHANGED'];
ctReject(fn()=>$batchCore->batchCall($actor,$site['id'],$changed,'batch-A',false,$now),'idempotency_conflict');
ctCase('batch/conflict-used',$after['used'],$batchCore->usage($owner,$now)['used']);
$receipt=$batchCore->batchReceipt($actor,$site['id'],hash('sha256',$owner.'|batch-A'),$now);
ct($receipt['charged_units']===1&&$receipt['summary']['failed']===2,'Batch receipt does not reconcile');
// The same Native creation key arriving later through the single-tool
// transport is a Native 409, not a second successful object.
$before=$batchCore->usage($owner,$now);
$cross=$batchCore->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'batch-A','shape'=>'native_409'],'batch-A-single-retry',$now);
ct($cross['state']==='failed'&&$cross['charged_units']===0,'Cross-transport retry of a batch creation was charged');
ctCase('cross-transport/used',$before['used'],$batchCore->usage($owner,$now)['used']);

// --- 4. Uncertain outcome: reserved, no blind retry, evidence-based settle. ---
$items=[['id'=>'unknown','tool'=>'elementor_create_draft','arguments'=>['logical_key'=>'uncertain-A','shape'=>'uncertain']]];
$before=$batchCore->usage($owner,$now);
$pending=$batchCore->batchCall($actor,$site['id'],$items,'uncertain-batch',false,$now);
ct($pending['charged_units']===0&&$pending['pending_units']===1,'Uncertain batch object was charged or released');
ctCase('uncertain-batch/reserved',$before['reserved']+1,$batchCore->usage($owner,$now)['reserved']);
$exec=$transport->executions;
ctReject(fn()=>$batchCore->batchCall($actor,$site['id'],$items,'uncertain-batch',false,$now),'batch_outcome_pending_reconciliation');
ctCase('uncertain-batch/no-retry',$exec,$transport->executions);
$batchKey=hash('sha256',$owner.'|uncertain-batch');
$reconciled=$batchCore->reconcile('operator',$owner,$batchKey,'failed',['note'=>'Independent site receipt shows the creation was rejected before writing.','receipt_sha256'=>str_repeat('d',64)],$now);
ct($reconciled['charged_units']===0&&$reconciled['pending_units']===0,'Reconciled uncertain batch object was charged');
ctCase('uncertain-batch/released',$before['reserved'],$batchCore->usage($owner,$now)['reserved']);
$settledReplay=$batchCore->batchCall($actor,$site['id'],$items,'uncertain-batch',false,$now);
ct($settledReplay['charged_units']===0,'Reconciled batch replay redispatched a charge');
ctCase('uncertain-batch/replay-dispatch',$exec,$transport->executions);

$before=$core->usage($owner,$now);
ctReject(fn()=>$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'uncertain-single','shape'=>'uncertain'],'uncertain-single',$now),'request_outcome_pending_reconciliation');
ctCase('uncertain-single/reserved',$before['reserved']+1,$core->usage($owner,$now)['reserved']);
$exec=$transport->executions;
ctReject(fn()=>$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'uncertain-single','shape'=>'uncertain'],'uncertain-single',$now),'request_outcome_pending_reconciliation');
ctCase('uncertain-single/no-retry',$exec,$transport->executions);
$singleKey=hash('sha256',$owner.'|uncertain-single');
$settled=$core->reconcile('operator',$owner,$singleKey,'succeeded',['note'=>'Independent site receipt confirms the artifact was created exactly once.','receipt_sha256'=>str_repeat('e',64)],$now);
ct($settled['charged_units']===1,'Successful reconciliation did not charge the reserved unit');
ctCase('uncertain-single/used',$before['used']+1,$core->usage($owner,$now)['used']);
ctCase('uncertain-single/released',$before['reserved'],$core->usage($owner,$now)['reserved']);
$settledRetry=$core->call($actor,$site['id'],'elementor_create_draft',['logical_key'=>'uncertain-single','shape'=>'uncertain'],'uncertain-single',$now);
ct($settledRetry['reconciled']===true,'Reconciled request did not return the settled receipt');
ctCase('uncertain-single/replay-dispatch',$exec,$transport->executions);

// --- 5. The Gateway ledger is the only balance authority. ---
$usage=$core->usage($owner,$now);
ctCase('ledger/used',$before['used']+1,$usage['used']);
ctCase('ledger/reserved',0,$usage['reserved']);
ct($usage['used']<=$usage['limit']&&$usage['remaining']>=0,'Ledger exceeded the plan limit');
$ledger=$core->ledger($owner);
$sum=0;
foreach($ledger as $row){
    ct(in_array($row['state'],['succeeded','failed'],true),'Ledger contains a non-terminal request after settlement');
    ct(is_int($row['charged_units'])&&$row['charged_units']>=0,'Ledger charge is missing or negative');
    $sum+=$row['charged_units'];
}
ctCase('ledger/rows',7,count($ledger));
ctCase('ledger/sum', $usage['used'], $sum);
$core->ensureAccount('ct124-other');
ct($core->usage('ct124-other',$now)['used']===0,'Cross-account ledger leak');

echo 'PASS '.$checks.' creation transport/accounting assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
} finally {
    foreach(['audit','batch_steps','requests','balances','accounts','rate','sites','tokens','oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate'] as $name){
        try{$store->execute('DROP TABLE IF EXISTS '.$store->table($name));}catch(Throwable $ignored){}
    }
    $pdo=null;
    if(!getenv('TEST_MYSQL_DSN'))foreach([$database,$database.'-wal',$database.'-shm'] as $file)if(is_file($file))@unlink($file);
}
