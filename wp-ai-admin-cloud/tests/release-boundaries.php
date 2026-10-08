<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Fault,Transport};

$checks=0;
function bcheck(bool $ok,string $m):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($m);}
function breject(callable $fn,string $reason):void{try{$fn();}catch(Fault $e){bcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Expected '.$reason);}

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-boundaries-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();$core=fixtureCore($store,$transport,2);$core->ensureAccount('boundary');

// URL and token boundaries.
breject(fn()=>$core->connect('boundary','http://example.com','fixture-valid-token-aaaaaaaaaaaa',$now),'public_https_site_required');
breject(fn()=>$core->connect('boundary','https://127.0.0.1','fixture-valid-token-aaaaaaaaaaaa',$now),'invalid_site_host');
breject(fn()=>$core->connect('boundary','https://example.com','short',$now),'invalid_site_token');

$site=$core->connect('boundary','https://boundary.example.com','fixture-boundary-admin-token-aaaaaaaa',$now);

// Token policy boundaries.
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:write'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read','mcp:read'],$now),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read'],$now,59),'invalid_token_policy');
breject(fn()=>$core->mint('boundary',[$site['id']],['mcp:read'],$now,3601),'invalid_token_policy');

$token=$core->mint('boundary',[$site['id']],['mcp:read','mcp:write'],$now);
$actor=$core->authenticate($token['access_token'],$now);
$readOnly=$core->mint('boundary',[$site['id']],['mcp:read'],$now);
$readOnlyActor=$core->authenticate($readOnly['access_token'],$now);
breject(fn()=>$core->call($readOnlyActor,$site['id'],'operation.apply',['id'=>1],'dotted-readonly-apply',$now),'scope_or_site_denied');
breject(fn()=>$core->call($readOnlyActor,$site['id'],'operation.rollback',['id'=>1],'dotted-readonly-rollback',$now),'scope_or_site_denied');

// Sealed site credentials are bound to their exact tenant/site record. Ciphertext substitution must fail closed.
$site2=$core->connect('boundary','https://boundary-two.example.com','fixture-boundary-admin-token-bbbbbbbb',$now+1);
$sealed1=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']])['token_sealed'];
$sealed2=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site2['id']])['token_sealed'];
$store->execute('UPDATE '.$store->table('sites').' SET token_sealed=? WHERE id=?',[$sealed2,$site['id']]);
breject(fn()=>$core->catalog($actor,$site['id'],$now+1),'secret_binding_failed');
$store->execute('UPDATE '.$store->table('sites').' SET token_sealed=? WHERE id=?',[$sealed1,$site['id']]);
bcheck(is_array($core->catalog($actor,$site['id'],$now+1)),'Restored sealed site credential did not recover');

// An actor authenticated before account suspension must not retain access after suspension.
$store->execute('UPDATE '.$store->table('accounts').' SET disabled=1 WHERE owner=?',['boundary']);
breject(fn()=>$core->catalog($actor,$site['id'],$now+1),'account_unavailable');
$store->execute('UPDATE '.$store->table('accounts').' SET disabled=0 WHERE owner=?',['boundary']);
bcheck(is_array($core->catalog($actor,$site['id'],$now+1)),'Account restore fixture did not recover');

// Oversized arguments must fail before upstream execution.
$before=$transport->executions;
breject(fn()=>$core->call($actor,$site['id'],'wp_get_site',['blob'=>str_repeat('x',70000)],'oversized-args',$now),'arguments_too_large');
bcheck($transport->executions===$before,'Oversized arguments reached upstream');

// Batch mutations are blocked until receipt accounting is ready.
breject(fn()=>$core->call($actor,$site['id'],'batch_create',['items'=>[]],'blocked-batch',$now),'batch_receipt_accounting_not_ready');

// Quota charges only successful billable calls; failed upstream call consumes zero.
$failed=$core->call($actor,$site['id'],'wp_get_site',['fail'=>true],'failed-no-charge',$now);
bcheck($failed['state']==='failed'&&$failed['charged_units']===0,'Failed call charged quota');
bcheck($core->usage('boundary',$now)['used']===0,'Failed call increased used quota');
$ok1=$core->call($actor,$site['id'],'wp_get_site',[],'billable-one',$now);
$ok2=$core->call($actor,$site['id'],'wp_get_site',[],'billable-two',$now);
bcheck($ok1['charged_units']===1&&$ok2['charged_units']===1,'Successful calls not charged once each');
breject(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'over-quota',$now),'monthly_quota_exhausted');

// Same nonce with different fingerprint is rejected and never redispatched.
$core2=fixtureCore($store,$transport,100);
$nonce='fingerprint-conflict';
$first=$core2->call($actor,$site['id'],'operation_verify',['id'=>1],$nonce,$now);
$execAfter=$transport->executions;
breject(fn()=>$core2->call($actor,$site['id'],'operation_verify',['id'=>2],$nonce,$now),'idempotency_conflict');
bcheck($transport->executions===$execAfter,'Idempotency conflict reached upstream');

// Per-owner HTTP throttle must close after the configured window count.
for($i=0;$i<120;$i++) $core2->throttle('boundary','relbound-rate',$now,120);
breject(fn()=>$core2->throttle('boundary','relbound-rate',$now,120),'rate_limit_exceeded');

// Site-count boundary is enforced per tenant. The 51st active registration must fail without creating a row.
$core2->ensureAccount('site-limit');
for($i=1;$i<=Core::MAX_SITES;$i++){
    $host='https://limit-'.$i.'.example.com';
    $core2->connect('site-limit',$host,'fixture-site-limit-token-'.str_pad((string)$i,24,'x'),$now+($i*60));
}
bcheck(count($core2->sites('site-limit'))===Core::MAX_SITES,'Site-limit fixture did not reach exact maximum');
breject(fn()=>$core2->connect('site-limit','https://limit-overflow.example.com','fixture-site-limit-token-overflow-xxxxxxxx',$now+((Core::MAX_SITES+1)*60)),'pilot_site_count_limit');
bcheck(count($core2->sites('site-limit'))===Core::MAX_SITES,'Rejected 51st site changed tenant registry');

// MySQL deployments must fail closed if a critical table loses transactional InnoDB semantics.
if(getenv('TEST_MYSQL_DSN')){
    $requestsTable=$store->table('requests');
    $store->execute('ALTER TABLE '.$requestsTable.' ENGINE=MyISAM');
    breject(fn()=>$store->migrate(),'innodb_required');
    $store->execute('ALTER TABLE '.$requestsTable.' ENGINE=InnoDB');
    $store->migrate();
    bcheck(true,'InnoDB restoration failed');
}

echo 'PASS '.$checks.' release boundary assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
