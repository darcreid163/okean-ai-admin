<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Fault};

$checks=0;
function qcheck(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function qreject(callable $fn,string $reason): void {
    try{$fn();}catch(Fault $e){qcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}
    throw new RuntimeException('Expected '.$reason);
}

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-quota-idempotency-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();
$core=fixtureCore($store,$transport,2);
$core->ensureAccount('quota-owner');
$site=$core->connect('quota-owner','https://quota.example.com','fixture-quota-site-token-aaaaaaaa',$now);
$token=$core->mint('quota-owner',[$site['id']],['mcp:read','mcp:write'],$now);
$actor=$core->authenticate($token['access_token'],$now);

$before=$core->usage('quota-owner',$now);
qcheck($before['used']===0&&$before['reserved']===0&&$before['remaining']===2,'Initial quota state wrong');

$first=$core->call($actor,$site['id'],'operation_apply',['id'=>1],'same-nonce',$now);
qcheck($first['state']==='succeeded'&&$first['charged_units']===1,'Successful write was not charged exactly once');
qcheck($transport->executions===1,'Unexpected upstream execution count after first write');

$cached=$core->call($actor,$site['id'],'operation_apply',['id'=>1],'same-nonce',$now+1);
qcheck($cached['request_key']===$first['request_key'],'Idempotent replay changed request key');
qcheck($transport->executions===1,'Idempotent replay re-executed upstream write');

qreject(fn()=>$core->call($actor,$site['id'],'operation_apply',['id'=>2],'same-nonce',$now+2),'idempotency_conflict');
qcheck($transport->executions===1,'Idempotency conflict reached upstream');

$failed=$core->call($actor,$site['id'],'operation_apply',['id'=>3,'fail'=>true],'failed-write',$now+3);
qcheck($failed['state']==='failed'&&$failed['charged_units']===0,'Failed upstream operation consumed quota');
$afterFailed=$core->usage('quota-owner',$now+3);
qcheck($afterFailed['used']===1&&$afterFailed['reserved']===0&&$afterFailed['remaining']===1,'Failed request changed quota incorrectly');

$second=$core->call($actor,$site['id'],'operation_apply',['id'=>4],'second-success',$now+4);
qcheck($second['state']==='succeeded'&&$second['charged_units']===1,'Second successful write charge wrong');
$full=$core->usage('quota-owner',$now+4);
qcheck($full['used']===2&&$full['remaining']===0,'Quota did not reach configured limit exactly');
qreject(fn()=>$core->call($actor,$site['id'],'operation_apply',['id'=>5],'over-limit',$now+5),'monthly_quota_exhausted');

$transportExecutions=$transport->executions;
qreject(fn()=>$core->call($actor,$site['id'],'operation_apply',['id'=>6,'uncertain'=>true],'uncertain-write',$now+6),'monthly_quota_exhausted');
qcheck($transport->executions===$transportExecutions,'Quota rejection reached upstream');

// Separate account for uncertain-outcome reconciliation so quota capacity is available.
$core->ensureAccount('reconcile-owner');
$site2=$core->connect('reconcile-owner','https://reconcile.example.com','fixture-reconcile-site-token-bbbbbbbb',$now);
$token2=$core->mint('reconcile-owner',[$site2['id']],['mcp:read','mcp:write'],$now);
$actor2=$core->authenticate($token2['access_token'],$now);
qreject(fn()=>$core->call($actor2,$site2['id'],'operation_apply',['id'=>7,'uncertain'=>true],'uncertain-write',$now+10),'request_outcome_pending_reconciliation');
$uncertainUsage=$core->usage('reconcile-owner',$now+10);
qcheck($uncertainUsage['used']===0&&$uncertainUsage['reserved']===1,'Uncertain write did not retain reservation');
qreject(fn()=>$core->call($actor2,$site2['id'],'operation_apply',['id'=>7,'uncertain'=>true],'uncertain-write',$now+11),'request_outcome_pending_reconciliation');
$ledger=$core->ledger('reconcile-owner',10);
$key=$ledger[0]['request_key']??'';
qcheck(preg_match('/^[a-f0-9]{64}$/',$key)===1&&($ledger[0]['state']??'')==='uncertain','Uncertain request not visible in owner ledger');

qreject(fn()=>$core->reconcile('operator','reconcile-owner',$key,'failed',['note'=>'too short','receipt_sha256'=>str_repeat('a',64)],$now+12),'operator_receipt_evidence_required');
$evidenceNote='Independent site receipt confirms no mutation occurred; evidence-marker-7f3c.';
$evidenceHash=str_repeat('b',64);
$reconciled=$core->reconcile('operator','reconcile-owner',$key,'failed',[
    'note'=>$evidenceNote,
    'receipt_sha256'=>$evidenceHash
],$now+13);
qcheck($reconciled['state']==='failed'&&$reconciled['charged_units']===0&&!empty($reconciled['reconciled']),'Failed reconciliation result wrong');
$reconciledRow=$store->row('SELECT evidence_sealed,response_sealed FROM '.$store->table('requests').' WHERE owner=? AND request_key=?',['reconcile-owner',$key]);
qcheck(is_array($reconciledRow)&&strlen((string)($reconciledRow['evidence_sealed']??''))>40,'Reconciliation evidence was not sealed');
$rawReconcileStorage=Security::canonical(array_merge([$reconciledRow],$store->rows('SELECT event,request_key FROM '.$store->table('audit').' WHERE owner=?',['reconcile-owner'])));
qcheck(!str_contains($rawReconcileStorage,$evidenceNote)&&!str_contains($rawReconcileStorage,$evidenceHash),'Plaintext reconciliation evidence leaked into request/audit storage');
$afterReconcile=$core->usage('reconcile-owner',$now+13);
qcheck($afterReconcile['used']===0&&$afterReconcile['reserved']===0&&$afterReconcile['remaining']===2,'Failed reconciliation did not release reservation');
qreject(fn()=>$core->reconcile('operator','reconcile-owner',$key,'failed',[
    'note'=>$evidenceNote,
    'receipt_sha256'=>$evidenceHash
],$now+14),'request_not_reconcilable');

// Cached idempotent receipts are sealed. Storage tampering must fail closed and must never re-dispatch the write.
$core->ensureAccount('tamper-owner');
$site3=$core->connect('tamper-owner','https://tamper.example.com','fixture-tamper-site-token-cccccccc',$now);
$token3=$core->mint('tamper-owner',[$site3['id']],['mcp:read','mcp:write'],$now);
$actor3=$core->authenticate($token3['access_token'],$now);
$tamperedFirst=$core->call($actor3,$site3['id'],'operation_apply',['id'=>8],'tamper-cache',$now+20);
$tamperExecutions=$transport->executions;
$tamperKey=$tamperedFirst['request_key'];
$row=$store->row('SELECT response_sealed FROM '.$store->table('requests').' WHERE request_key=?',[$tamperKey]);
qcheck(is_array($row)&&strlen((string)$row['response_sealed'])>20,'Cached sealed receipt missing');
$sealed=(string)$row['response_sealed'];
$sealed[5]=$sealed[5]==='A'?'B':'A';
$store->execute('UPDATE '.$store->table('requests').' SET response_sealed=? WHERE request_key=?',[$sealed,$tamperKey]);
qreject(fn()=>$core->call($actor3,$site3['id'],'operation_apply',['id'=>8],'tamper-cache',$now+21),'secret_binding_failed');
qcheck($transport->executions===$tamperExecutions,'Tampered cached receipt caused write redispatch');

// Plan changes must preserve already-used units and only change the ceiling.
$core->ensureAccount('plan-owner');
$planSite=$core->connect('plan-owner','https://plan.example.com','fixture-plan-site-token-dddddddd',$now);
$planToken=$core->mint('plan-owner',[$planSite['id']],['mcp:read','mcp:write'],$now);
$planActor=$core->authenticate($planToken['access_token'],$now);
$core->call($planActor,$planSite['id'],'operation_apply',['id'=>9],'plan-charge',$now+30);
$freeUsage=$core->usage('plan-owner',$now+30);
qcheck($freeUsage['plan']==='free'&&$freeUsage['used']===1,'Plan fixture free usage wrong');
$core->setPlan('operator','plan-owner','pro',$now+31);
$proUsage=$core->usage('plan-owner',$now+31);
qcheck($proUsage['plan']==='pro'&&$proUsage['used']===1&&$proUsage['limit']===500&&$proUsage['remaining']===499,'Plan upgrade reset or corrupted usage');
qreject(fn()=>$core->setPlan('operator','plan-owner','does-not-exist',$now+32),'unknown_plan');
$afterBadPlan=$core->usage('plan-owner',$now+32);
qcheck($afterBadPlan['plan']==='pro'&&$afterBadPlan['used']===1,'Rejected plan change mutated account state');

// Billing periods are calendar-month UTC and must not carry used/reserved balances into the next month.
$nextMonth=strtotime('2026-11-01 00:00:05 UTC');
$nextUsage=$core->usage('plan-owner',$nextMonth);
qcheck($nextUsage['period']==='2026-11'&&$nextUsage['used']===0&&$nextUsage['reserved']===0&&$nextUsage['remaining']===500,'New UTC month inherited previous balance');
$core->call($planActor,$planSite['id'],'operation_apply',['id'=>10],'november-charge',$nextMonth);
$nextUsage2=$core->usage('plan-owner',$nextMonth);
qcheck($nextUsage2['used']===1&&$nextUsage2['remaining']===499,'New-period charge accounting wrong');
$oldUsage=$core->usage('plan-owner',$now+33);
qcheck($oldUsage['period']==='2026-10'&&$oldUsage['used']===1,'New-period activity mutated prior-period balance');

echo 'PASS '.$checks.' quota/idempotency/reconciliation assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
