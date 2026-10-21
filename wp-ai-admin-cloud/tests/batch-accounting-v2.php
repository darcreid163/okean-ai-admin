<?php
declare(strict_types=1);
/** Batch v2 acceptance: actual Store reservation/settlement and FakeTransport; no WordPress writes. */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,QuotaPolicy,BatchAccounting,Vault,Signer,Fault};
$checks=0;
function batchCheck(bool $ok,string $message): void { global $checks; ++$checks; if(!$ok)throw new RuntimeException($message); }
function batchReject(callable $fn,string $reason): void {
    try{$fn();}catch(Fault $e){batchCheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}
    throw new RuntimeException('Missing fault: '.$reason);
}
$now=strtotime('2026-10-21T12:00:00Z');
$database=tempnam(sys_get_temp_dir(),'wpai-batch-v2-');
if($database===false)throw new RuntimeException('No disposable database');
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new WPAICloudGateway\Store($pdo,'qa_batch_'.bin2hex(random_bytes(5)).'_');
$store->migrate();
try {
$transport=new FakeTransport();
$policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
$policy['plans']['free']=4;
$policy['batch']=BatchAccounting::POLICY;QuotaPolicy::validate($policy);
$pair=sodium_crypto_sign_keypair();
$core=new Core($store,new Vault(str_repeat('k',32)),$transport,$policy,new Signer(sodium_crypto_sign_secretkey($pair)));
$core->ensureAccount('1');$core->ensureAccount('2');
$site=$core->connect('1','https://batch-one.example.com','fixture-batch-credential-owner-1',$now);
$sameOwnerSite=$core->connect('1','https://batch-owner-second.example.com','fixture-batch-credential-owner-1-second',$now);
$other=$core->connect('2','https://batch-two.example.com','fixture-batch-credential-owner-2',$now);
$actor=$core->authenticate($core->mint('1',[$site['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
$readOnly=$core->authenticate($core->mint('1',[$site['id']],['mcp:read'],$now)['access_token'],$now);
$foreign=$core->authenticate($core->mint('2',[$other['id']],['mcp:read'],$now)['access_token'],$now);
$items=[
    ['id'=>'a','tool'=>'wp_get_site','arguments'=>['meta'=>['z'=>2,'a'=>1],'alpha'=>true]],
    ['id'=>'b','tool'=>'wp_get_site','arguments'=>['fail'=>true]],
    ['id'=>'c','tool'=>'wp_get_site','arguments'=>[]],
];
batchCheck($core->batchAvailable(),'Enabled test batch policy still disabled');
// Authorization fences are checked before reservation or upstream execution.
$preUsage=$core->usage('1',$now);
$preExecutions=$transport->executions;
batchReject(fn()=>$core->batchCall($actor,$other['id'],$items,'foreign-site-denied',false,$now),'scope_or_site_denied');
batchReject(fn()=>$core->batchCall($readOnly,$sameOwnerSite['id'],$items,'ungranted-site-denied',false,$now),'scope_or_site_denied');
batchReject(fn()=>$core->batchCall($foreign,$site['id'],$items,'foreign-actor-denied',false,$now),'scope_or_site_denied');
batchReject(fn()=>$core->batchCall($readOnly,$site['id'],[
    ['id'=>'write','tool'=>'woocommerce_update_product_sandbox','arguments'=>['product_id'=>1,'regular_price'=>'12.00']],
],'read-token-write-denied',false,$now),'scope_or_site_denied');
batchCheck($transport->executions===$preExecutions &&
    $core->usage('1',$now)['used']===$preUsage['used'] &&
    $core->usage('1',$now)['reserved']===$preUsage['reserved'],
    'Rejected batch crossed tenant/scope boundary or reserved quota');

$first=$core->batchCall($actor,$site['id'],$items,'batch-3',false,$now);
batchCheck($first['charged_units']===2&&$first['pending_units']===0,'Batch charged failed or skipped object');
batchCheck($first['summary']['succeeded']===2&&$first['summary']['failed']===1,'Batch per-step receipt incorrect');
batchCheck($core->usage('1',$now)['used']===2&&$core->usage('1',$now)['reserved']===0,'Batch settlement not atomic');
batchCheck($transport->executions===3,'Each batch object must be dispatched exactly once');
$key=hash('sha256','1|batch-3');
$receipt=$core->batchReceipt($actor,$site['id'],$key,$now);
batchCheck($receipt['charged_units']===2&&$receipt['site_id']===$site['id'],'Receipt mismatch');
$reordered=$items;
$reordered[0]['arguments']=['alpha'=>true,'meta'=>['a'=>1,'z'=>2]];
batchCheck(WPAICloudGateway\Security::canonical($items)===WPAICloudGateway\Security::canonical($reordered),'Canonical JSON changed with nested object key order');
$replay=$core->batchCall($actor,$site['id'],$reordered,'batch-3',false,$now);
batchCheck($replay['charged_units']===2&&$transport->executions===3,'Idempotency retry re-dispatched objects');
$different=$items;$different[0]['arguments']=['fail'=>true];
batchReject(fn()=>$core->batchCall($actor,$site['id'],$different,'batch-3',false,$now),'idempotency_conflict');
batchReject(fn()=>$core->batchReceipt($foreign,$site['id'],$key,$now),'scope_or_site_denied');
$stopped=$core->batchCall($actor,$site['id'],[
    ['id'=>'fail-first','tool'=>'wp_get_site','arguments'=>['fail'=>true]],
    ['id'=>'never-run','tool'=>'wp_get_site','arguments'=>[]],
],'batch-stop',true,$now);
batchCheck($stopped['charged_units']===0&&$stopped['pending_units']===0&&$stopped['summary']['cancelled']===1,'stopOnError charged undispatched step');
batchCheck($transport->executions===4&&$core->usage('1',$now)['used']===2,'Stop-on-error dispatched extra step');
$unknown=$core->batchCall($actor,$site['id'],[['id'=>'uncertain','tool'=>'wp_get_site','arguments'=>['uncertain'=>true]]],'batch-unknown',false,$now);
batchCheck($unknown['pending_units']===1&&$unknown['charged_units']===0,'Unknown outcome was charged or released');
batchCheck($core->usage('1',$now)['used']===2&&$core->usage('1',$now)['reserved']===1,'Uncertain outcome lost reservation');
batchReject(fn()=>$core->batchCall($actor,$site['id'],[['id'=>'uncertain','tool'=>'wp_get_site','arguments'=>['uncertain'=>true]]],'batch-unknown',false,$now),'batch_outcome_pending_reconciliation');
// Reserve the last unit with a genuinely uncertain request. The batch
// implementation reserves every child up front, not only successful children.
$moreUnknown=$core->batchCall($actor,$site['id'],[
    ['id'=>'another-uncertain','tool'=>'wp_get_site','arguments'=>['uncertain'=>true]],
],'batch-unknown-two',false,$now);
batchCheck($moreUnknown['pending_units']===1&&$moreUnknown['charged_units']===0,
    'Second unknown outcome lost its reservation');
batchCheck($core->usage('1',$now)['used']===2&&$core->usage('1',$now)['reserved']===2,
    'Unknown reservation must not silently settle');
batchReject(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'beyond-limit',$now),'monthly_quota_exhausted');
batchReject(fn()=>BatchAccounting::items([['id'=>'one','tool'=>'woocommerce_update_product_sandbox','arguments'=>['changes'=>['variations'=>[['id'=>3]]]]]]),'batch_multiple_targets_not_allowed');
echo 'PASS '.$checks.' Batch v2 receipt/idempotency/tenant/uncertain/quota assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
} finally {
    foreach(['audit','batch_steps','requests','balances','oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate','tokens','sites','accounts','rate'] as $name) $store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    if(!getenv('TEST_MYSQL_DSN')) foreach([$database,$database.'-wal',$database.'-shm'] as $file)if(is_file($file))unlink($file);
}
