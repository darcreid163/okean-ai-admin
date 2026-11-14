<?php
declare(strict_types=1);
/**
 * Hash-only licence registry and lifecycle contract.
 *
 * Verifies that Cloud Core alone owns licence state and plan derivation:
 * automatic bound issuance, manual unbound issuance, one-time key display,
 * SHA-256-at-rest storage, owner/site binding, activation limits, idempotency,
 * cross-account isolation, reissue lineage, suspend/revoke fallback to Free,
 * expiry, append-only events and refusal to let a client change its plan.
 *
 * Runs on SQLite without MySQL and uses a disposable prefix on MySQL.
 */
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Core;
use WPAICloudGateway\Fault;
use WPAICloudGateway\Licenses;
use WPAICloudGateway\QuotaPolicy;
use WPAICloudGateway\Signer;
use WPAICloudGateway\Store;
use WPAICloudGateway\Vault;

$checks=0;
function licCheck(bool $ok,string $label): void { global $checks; ++$checks; if(!$ok) throw new RuntimeException($label); }
function licRejects(callable $fn,string $reason): void {
    try { $fn(); }
    catch (Fault $e) { licCheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return; }
    throw new RuntimeException('Expected refusal: '.$reason);
}
function licFind(array $rows,string $id): array {
    foreach($rows as $row) if($row['id']===$id) return $row;
    throw new RuntimeException('Licence not found: '.$id);
}
function licContainsKey(mixed $value,string $key): bool {
    return is_string($value)&&($value===$key||str_contains($value,$key));
}

$mysql=(string)getenv('TEST_MYSQL_DSN')!=='';
$database=tempnam(sys_get_temp_dir(),'wpai-license-');
if($database===false) throw new RuntimeException('No disposable DB');
$prefix='lic_'.bin2hex(random_bytes(5)).'_';
$pdo=new PDO($mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,$prefix);
$store->migrate();
$tables=['accounts','balances','sites','tokens','requests','batch_steps','rate','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate','licenses','license_activations','license_events'];
$txStore=null;
$txDatabase=null;
$txPrefix=null;
$legacyStore=null;
try {
    $policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
    QuotaPolicy::validate($policy);
    $transport=new FakeTransport();
    $pair=sodium_crypto_sign_keypair();
    $core=new Core($store,new Vault(str_repeat('k',32)),$transport,$policy,new Signer(sodium_crypto_sign_secretkey($pair)));

    $ownerA='lic-owner-a'; $ownerB='lic-owner-b'; $operator='lic-admin';
    $core->ensureAccount($ownerA);
    $core->ensureAccount($ownerB);
    $now=time()+1;

    $siteA=$core->connect($ownerA,'https://licence-a.example.com','fixture-site-admin-token-licence-a',$now);
    $siteB=$core->connect($ownerA,'https://licence-b.example.com','fixture-site-admin-token-licence-b',$now);
    $siteC=$core->connect($ownerA,'https://licence-c.example.com','fixture-site-admin-token-licence-c',$now);
    $siteOther=$core->connect($ownerB,'https://licence-other.example.com','fixture-site-admin-token-licence-other',$now);

    /* ---------------------------------------------- automatic bound issue --- */

    $autoRows=$core->licenses($ownerA);
    licCheck(count($autoRows)===1,'New account did not receive exactly one automatic licence');
    $auto=$autoRows[0]; $autoId=(string)$auto['id'];
    licCheck($auto['source']===Licenses::SOURCE_AUTOMATIC&&$auto['status']===Licenses::ACTIVE,'Automatic licence state is wrong');
    licCheck($auto['plan']==='free'&&$auto['owner']===$ownerA,'Automatic licence plan/owner is wrong');
    licCheck($auto['activation_limit']===1&&$auto['activations']===0,'Automatic licence limits are wrong');
    licCheck(!array_key_exists('key_hash',$auto)&&!array_key_exists('license_key',$auto),'List projection leaked key material');
    $raw=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$autoId]);
    licCheck($raw!==null&&(bool)preg_match('/^[a-f0-9]{64}$/D',(string)$raw['key_hash']),'Automatic licence hash is not SHA-256');
    licCheck(strlen((string)$raw['key_prefix'])===16&&str_starts_with((string)$raw['key_prefix'],'wpai_lic_'),'Automatic licence prefix is wrong');
    foreach($raw as $value) licCheck(!(is_string($value)&&preg_match(Licenses::KEY_PATTERN,$value)),'Automatic licence row contains a plaintext key');

    // Repeated provisioning must not mint a second licence.
    $core->ensureAccount($ownerA);
    licCheck(count($core->licenses($ownerA))===1,'Repeated ensureAccount duplicated the automatic licence');
    licCheck($store->row('SELECT license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA])['license_id']===$autoId,'Automatic licence is not linked to the account');

    // Historical accounts must never be broken by automatic licence issuance.
    $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan,quota_scheme) VALUES (?,?,?)',['lic-legacy-mismatch','daily',QuotaPolicy::LEGACY]);
    $core->ensureAccount('lic-legacy-mismatch');
    licCheck($store->row('SELECT license_id FROM '.$store->table('accounts').' WHERE owner=?',['lic-legacy-mismatch'])['license_id']===null,'Mismatched legacy plan was force-licensed');
    $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan,quota_scheme) VALUES (?,?,?)',['lic-legacy-compatible','pro',QuotaPolicy::LEGACY]);
    $core->ensureAccount('lic-legacy-compatible');
    $legacyLink=$store->row('SELECT license_id FROM '.$store->table('accounts').' WHERE owner=?',['lic-legacy-compatible'])['license_id'];
    licCheck($legacyLink!==null,'Compatible legacy plan did not receive an automatic licence');
    $legacyLicence=licFind($core->allLicenses(),(string)$legacyLink);
    licCheck($legacyLicence['plan']==='pro'&&$legacyLicence['quota_scheme']===QuotaPolicy::LEGACY,'Legacy automatic licence changed the plan');
    $core->ensureAccount('lic-scheme-default');
    $core->setPlan($operator,'lic-scheme-default','free',$now,QuotaPolicy::TARGET);
    $schemeDefault=$core->issueLicense($operator,'lic-scheme-default','pro',$now);
    licCheck($schemeDefault['quota_scheme']===QuotaPolicy::TARGET,'Bound issue did not inherit the account scheme');

    /* --------------------------------------------------- manual unbound issue --- */

    $manual=$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['duration_days'=>30,'activation_limit'=>2,'comment'=>'Fixture manual licence']);
    $manualKey=(string)$manual['license_key']; $manualId=(string)$manual['id'];
    licCheck((bool)preg_match(Licenses::KEY_PATTERN,$manualKey),'Manual key format is invalid');
    licCheck(strlen($manualKey)===9+43,'Manual key length is wrong');
    licCheck($manual['status']===Licenses::UNUSED&&$manual['owner']===null,'Unbound manual licence must stay unused');
    licCheck($manual['plan']==='pro'&&$manual['quota_scheme']===QuotaPolicy::TARGET,'Manual licence plan/scheme is wrong');
    licCheck($manual['activation_limit']===2&&$manual['activations']===0,'Manual licence activation policy is wrong');
    licCheck($manual['expires_at']===$now+30*86400,'Manual licence duration was not applied');
    licCheck(!array_key_exists('key_hash',$manual),'Issue response exposed key_hash');
    $manualRaw=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$manualId]);
    licCheck($manualRaw['key_hash']===hash('sha256',$manualKey),'Manual key hash mismatch');
    licCheck($manualRaw['key_prefix']===substr($manualKey,0,16),'Manual key prefix mismatch');
    licCheck(!licContainsKey($manualRaw['key_hash'],$manualKey)&&!licContainsKey($manualRaw['key_prefix'],$manualKey),'Manual key stored in plaintext');
    foreach($manualRaw as $value) licCheck(!licContainsKey($value,$manualKey),'Manual licence row contains its plaintext key');
    licCheck(count($core->licenses($ownerA))===1,'Unbound manual licence leaked into an owner list');
    $listed=licFind($core->allLicenses(),$manualId);
    licCheck(!array_key_exists('license_key',$listed)&&!array_key_exists('key_hash',$listed),'Admin list exposed key material');

    // Input validation: no invented plans, no ambiguous dates, no unbounded limits.
    licRejects(fn()=>$core->issueLicense($operator,null,'unlimited',$now,QuotaPolicy::TARGET),'unknown_plan');
    licRejects(fn()=>$core->issueLicense($operator,null,'daily',$now,QuotaPolicy::TARGET),'unknown_plan');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['activation_limit'=>0]),'invalid_activation_limit');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['activation_limit'=>51]),'invalid_activation_limit');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['starts_at'=>-1]),'invalid_license_dates');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['expires_at'=>$now]),'invalid_license_dates');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['duration_days'=>30,'expires_at'=>$now+1000]),'invalid_license_duration');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['duration_days'=>3651]),'invalid_license_duration');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['comment'=>str_repeat('c',256)]),'invalid_license_comment');
    licRejects(fn()=>$core->issueLicense($operator,$ownerA,'pro',$now,QuotaPolicy::TARGET,['status'=>'unused']),'invalid_license_status');
    licRejects(fn()=>$core->issueLicense($operator,$ownerA,'pro',$now,QuotaPolicy::TARGET,['status'=>'suspended']),'invalid_license_status');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['status'=>'active']),'invalid_license_status');
    licRejects(fn()=>$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['status'=>'bogus']),'invalid_license_status');
    licRejects(fn()=>$core->issueLicense($operator,$ownerA,'business',$now,QuotaPolicy::CURRENT,['starts_at'=>$now+3600]),'invalid_license_dates');
    $future=$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['starts_at'=>$now+3600]);
    licCheck($future['status']===Licenses::UNUSED,'Future-dated unbound licence must stay unused');
    licRejects(fn()=>$core->activateLicense($ownerA,(string)$future['license_key'],$siteA['id'],$now+2),'license_not_started');
    $futureReissue=$core->reissueLicense($operator,(string)$future['id'],$now+3);
    licCheck($futureReissue['starts_at']===$now+3600,'Reissue lost a future start date');
    licRejects(fn()=>$core->activateLicense($ownerA,(string)$futureReissue['license_key'],$siteA['id'],$now+4),'license_not_started');
    $alias=$core->issueLicense($operator,null,'pro',$now,QuotaPolicy::TARGET,['status'=>'generated']);
    licCheck($alias['status']===Licenses::UNUSED,'generated alias was not normalized to unused');
    licCheck(Licenses::normalizeStatus('exhausted')===Licenses::EXHAUSTED,'exhausted operator state was not accepted');

    /* ------------------------------------------------------------ activation --- */

    licRejects(fn()=>$core->activateLicense($ownerA,'not-a-licence-key',$siteA['id'],$now+2),'license_invalid');
    licRejects(fn()=>$core->activateLicense($ownerA,'wpai_lic_'.str_repeat('a',43),$siteA['id'],$now+2),'license_invalid');
    $activated=$core->activateLicense($ownerA,$manualKey,$siteA['id'],$now+2);
    licCheck($activated['activated']===true&&$activated['idempotent']===false,'First activation was not reported');
    licCheck($activated['owner']===$ownerA&&$activated['status']===Licenses::ACTIVE,'Activation did not bind owner/state');
    licCheck($activated['plan']==='pro'&&$activated['quota_scheme']===QuotaPolicy::TARGET,'Activation derived the wrong plan');
    licCheck($activated['activations']===1,'Activation counter is wrong');
    $account=$store->row('SELECT plan,quota_scheme,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA]);
    licCheck($account['plan']==='pro'&&$account['quota_scheme']===QuotaPolicy::TARGET&&$account['license_id']===$manualId,'Activation did not apply the licence plan atomically');
    $usage=$core->usage($ownerA,$now+2);
    licCheck($usage['plan']==='pro'&&$usage['limit']===null&&$usage['remaining']===null,'Activated licence invented a pending balance');
    licCheck($usage['quota_status']===QuotaPolicy::QUOTA_PENDING,'Activated licence quota is not fail-closed');

    $repeat=$core->activateLicense($ownerA,$manualKey,$siteA['id'],$now+2);
    licCheck($repeat['idempotent']===true&&$repeat['activations']===1,'Same-site activation is not idempotent');
    licRejects(fn()=>$core->activateLicense($ownerB,$manualKey,$siteOther['id'],$now+2),'license_bound_to_other_account');
    licRejects(fn()=>$core->activateLicense($ownerA,$manualKey,$siteOther['id'],$now+2),'site_unavailable');

    $second=$core->activateLicense($ownerA,$manualKey,$siteB['id'],$now+3);
    licCheck($second['activations']===2&&$second['idempotent']===false,'Second activation slot was not consumed');
    licRejects(fn()=>$core->activateLicense($ownerA,$manualKey,$siteC['id'],$now+4),'license_activation_limit_reached');
    $deactivated=$core->deactivateLicense($ownerA,$manualId,$siteA['id'],$now+4);
    licCheck($deactivated['deactivated']===true&&$deactivated['activations']===1,'Deactivation did not free a slot');
    licRejects(fn()=>$core->deactivateLicense($ownerA,$manualId,$siteA['id'],$now+5),'license_activation_not_found');
    licRejects(fn()=>$core->deactivateLicense($ownerB,$manualId,$siteOther['id'],$now+5),'license_not_found');
    $third=$core->activateLicense($ownerA,$manualKey,$siteC['id'],$now+5);
    licCheck($third['activations']===2,'Reactivation after deactivation did not consume the freed slot');

    /* --------------------------------------------------------------- reissue --- */

    $reissued=$core->reissueLicense($operator,$manualId,$now+10);
    $reissuedKey=(string)$reissued['license_key']; $reissuedId=(string)$reissued['id'];
    licCheck((bool)preg_match(Licenses::KEY_PATTERN,$reissuedKey),'Reissued key format is invalid');
    licCheck($reissuedKey!==$manualKey&&$reissuedId!==$manualId,'Reissue returned the old key/id');
    licCheck($reissued['replaces']===$manualId&&$reissued['status']===Licenses::ACTIVE,'Reissue lineage/state is wrong');
    licCheck($reissued['owner']===$ownerA&&$reissued['plan']==='pro'&&$reissued['quota_scheme']===QuotaPolicy::TARGET,'Reissue changed plan ownership');
    licCheck($reissued['activation_limit']===2&&$reissued['activations']===0,'Reissue did not preserve limits/reset activations');
    licCheck($reissued['expires_at']===$manual['expires_at'],'Reissue did not preserve remaining expiry');
    $old=licFind($core->allLicenses(),$manualId);
    licCheck($old['status']===Licenses::REPLACED&&$old['replaced_by']===$reissuedId,'Old licence was not replaced');
    licCheck($old['revoked_at']!==null,'Replaced licence has no revocation timestamp');
    licCheck((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('license_activations').' WHERE license_id=? AND status=?',[$manualId,'active'])['n']===0,'Replaced licence kept active site activations');
    licCheck($store->row('SELECT license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA])['license_id']===$reissuedId,'Account was not switched to the reissued licence');
    licRejects(fn()=>$core->activateLicense($ownerA,$manualKey,$siteA['id'],$now+11),'license_revoked');
    $newActivation=$core->activateLicense($ownerA,$reissuedKey,$siteA['id'],$now+11);
    licCheck($newActivation['activated']===true&&$newActivation['activations']===1,'New key did not activate');

    /* ------------------------------------------------- plan assignment lineage --- */

    $core->setPlan($operator,$ownerA,'business',$now+20,QuotaPolicy::CURRENT);
    $account=$store->row('SELECT plan,quota_scheme,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA]);
    licCheck($account['plan']==='business'&&$account['quota_scheme']===QuotaPolicy::CURRENT,'Plan assignment did not persist');
    $business=licFind($core->allLicenses(),(string)$account['license_id']);
    $businessId=(string)$business['id'];
    licCheck($business['source']===Licenses::SOURCE_AUTOMATIC&&$business['status']===Licenses::ACTIVE,'Plan assignment did not issue an automatic licence');
    licCheck($business['plan']==='business'&&$business['quota_scheme']===QuotaPolicy::CURRENT,'Automatic licence carries the wrong plan');
    $superseded=licFind($core->allLicenses(),$reissuedId);
    licCheck($superseded['status']===Licenses::REPLACED&&$superseded['replaced_by']===$businessId,'Previous licence was not retired by plan assignment');
    $usage=$core->usage($ownerA,$now+20);
    licCheck($usage['plan']==='business'&&$usage['limit']===5000&&$usage['period_kind']===QuotaPolicy::DAY,'Approved licence quota is wrong');
    licCheck(!array_key_exists('quota_status',$usage),'Approved usage must stay byte-compatible');
    // A stale or tampered account-plan field cannot escape the licence terms.
    $store->execute('UPDATE '.$store->table('accounts').' SET plan=? WHERE owner=?',['free',$ownerA]);
    $reconciledUsage=$core->usage($ownerA,$now+21);
    licCheck($reconciledUsage['plan']==='business'&&$reconciledUsage['limit']===5000,'Licence did not reconcile a tampered account plan');

    /* ----------------------------------------- suspend, reactivate and revoke --- */

    $eventsBefore=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('license_events').' WHERE license_id=?',[$businessId])['n'];
    $suspended=$core->suspendLicense($operator,$businessId,$now+30);
    licCheck($suspended['suspended']===true&&$suspended['status']===Licenses::SUSPENDED,'Suspend state is wrong');
    $account=$store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA]);
    licCheck($account['plan']==='free'&&$account['license_id']===null,'Suspended licence did not fall back to Free');
    licCheck($core->usage($ownerA,$now+30)['limit']===100,'Suspended account kept a paid quota');
    $suspendedAgain=$core->suspendLicense($operator,$businessId,$now+31);
    licCheck($suspendedAgain['idempotent']===true&&$suspendedAgain['status']===Licenses::SUSPENDED,'Repeated suspend is not idempotent');

    $reactivated=$core->reactivateLicense($operator,$businessId,$now+40);
    licCheck($reactivated['reactivated']===true&&$reactivated['status']===Licenses::ACTIVE,'Reactivate state is wrong');
    $account=$store->row('SELECT plan,quota_scheme,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA]);
    licCheck($account['plan']==='business'&&$account['quota_scheme']===QuotaPolicy::CURRENT&&$account['license_id']===$businessId,'Reactivate did not restore the licence plan');
    licCheck($core->usage($ownerA,$now+40)['limit']===5000,'Reactivated account lost its paid quota');

    $revoked=$core->revokeLicense($operator,$businessId,$now+50);
    licCheck($revoked['revoked']===true,'Revoke was not reported');
    $account=$store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerA]);
    licCheck($account['plan']==='free'&&$account['license_id']===null,'Revoked licence did not fall back to Free');
    licCheck($core->usage($ownerA,$now+50)['limit']===100,'Revoked account kept a paid quota');
    $revokedAgain=$core->revokeLicense($operator,$businessId,$now+51);
    licCheck($revokedAgain['idempotent']===true&&$revokedAgain['status']===Licenses::REVOKED,'Repeated revoke is not idempotent');
    licRejects(fn()=>$core->reissueLicense($operator,$businessId,$now+51),'license_not_reissuable');
    licRejects(fn()=>$core->reactivateLicense($operator,$businessId,$now+51),'license_not_reactivatable');
    licRejects(fn()=>$core->suspendLicense($operator,$businessId,$now+51),'license_not_suspendable');

    // Events are append-only evidence and never contain the key or a comment.
    $events=$store->rows('SELECT * FROM '.$store->table('license_events').' WHERE license_id=? ORDER BY created_at,id',[$businessId]);
    licCheck(count($events)>$eventsBefore,'Lifecycle transitions were not recorded');
    $eventNames=array_column($events,'event');
    foreach(['issued','plan_applied','fallback_free','suspended','reactivated','revoked'] as $expected) licCheck(in_array($expected,$eventNames,true),'Missing licence event: '.$expected);
    foreach($events as $event) {
        licCheck((bool)preg_match('/^[a-f0-9]{64}$/D',(string)$event['detail_hash']),'Event detail hash is invalid');
        foreach($event as $value) {
            licCheck(!licContainsKey($value,$reissuedKey),'Event row contains a plaintext key');
            licCheck(!licContainsKey($value,'Fixture manual licence'),'Event row leaked a licence comment');
        }
    }
    licCheck((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('license_events').' WHERE license_id=?',[$businessId])['n']===count($events),'Event list was not stable');

    /* --------------------------------------------------------------- expiry --- */

    $expiring=$core->issueLicense($operator,$ownerB,'business',$now,QuotaPolicy::CURRENT,['expires_at'=>$now+120,'activation_limit'=>1]);
    $expiringId=(string)$expiring['id'];
    licCheck($store->row('SELECT plan FROM '.$store->table('accounts').' WHERE owner=?',[$ownerB])['plan']==='business','Bound manual licence did not apply its plan');
    licCheck($core->usage($ownerB,$now+60)['limit']===5000,'Bound manual licence quota is wrong');
    licRejects(fn()=>$core->activateLicense($ownerB,(string)$expiring['license_key'],$siteOther['id'],$now+121),'license_expired');
    $expiredUsage=$core->usage($ownerB,$now+121);
    licCheck($expiredUsage['plan']==='free'&&$expiredUsage['limit']===100,'Expired licence did not fall back to Free');
    $expiredRow=licFind($core->allLicenses(),$expiringId);
    licCheck($expiredRow['status']===Licenses::EXPIRED,'Expired licence state is wrong');
    licCheck($store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$ownerB])['license_id']===null,'Expired licence stayed linked to the account');
    licRejects(fn()=>$core->reactivateLicense($operator,$expiringId,$now+122),'license_not_reactivatable');

    /* ------------------------------------- historical account without licence --- */

    // A pre-upgrade database has accounts but no accounts.license_id and no
    // licence tables. Reads must keep working without warnings and must not
    // create the lazy schema as a side effect.
    $legacyPrefix='leg_'.bin2hex(random_bytes(4)).'_';
    $legacyStore=new Store($pdo,$legacyPrefix);
    $legacyStore->migrate();
    $legacyStore->execute('INSERT INTO '.$legacyStore->table('accounts').' (owner,plan,quota_scheme) VALUES (?,?,?)',['lic-legacy-nolink','pro',QuotaPolicy::LEGACY]);
    $legacyCore=new Core($legacyStore,new Vault(str_repeat('k',32)),new FakeTransport(),$policy,new Signer(sodium_crypto_sign_secretkey($pair)));
    $warnings=[];
    set_error_handler(static function(int $severity,string $message) use (&$warnings): bool { $warnings[]=$message; return true; });
    try { $legacyUsage=$legacyCore->usage('lic-legacy-nolink',$now); }
    finally { restore_error_handler(); }
    licCheck($legacyUsage['plan']==='pro'&&$legacyUsage['limit']===500,'Historical account lost its plan/quota');
    licCheck($warnings===[],'Historical account without licence schema emitted warnings: '.implode(' | ',$warnings));
    $legacyLicenceTables=(int)($mysql
        ? $legacyStore->row('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$legacyPrefix.'licenses'])['n']
        : $legacyStore->row('SELECT COUNT(*) AS n FROM sqlite_master WHERE type=? AND name=?',['table',$legacyPrefix.'licenses'])['n']);
    licCheck($legacyLicenceTables===0,'A read path created the lazy licence schema');

    /* --------------------------------------------------------- schema guard --- */

    // DDL must happen before a transaction: MySQL implicitly commits on CREATE
    // TABLE. A fresh store therefore refuses to lazily build inside one.
    $txDatabase=$mysql?null:tempnam(sys_get_temp_dir(),'wpai-license-tx-');
    $txPrefix='lict_'.bin2hex(random_bytes(4)).'_';
    $txPdo=new PDO($mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$txDatabase,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $txStore=new Store($txPdo,$txPrefix);
    $txStore->migrate();
    $schemaFault=null;
    try {
        $txStore->transaction(static function() use($txStore): void { $txStore->ensureLicenseSchema(); });
    } catch(Fault $e) { $schemaFault=$e->reason; }
    licCheck($schemaFault==='license_schema_unavailable','ensureLicenseSchema did not refuse to run inside a transaction');

    echo 'PASS '.$checks.' licence registry assertions; driver='.($mysql?'mysql':'sqlite')."\n";
} finally {
    if($txStore instanceof Store) foreach($tables as $name) $txStore->execute('DROP TABLE IF EXISTS '.$txStore->table($name));
    if($legacyStore instanceof Store) foreach($tables as $name) $legacyStore->execute('DROP TABLE IF EXISTS '.$legacyStore->table($name));
    foreach($tables as $name) $store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    unset($core,$transport,$store,$pdo,$txStore,$txPdo,$legacyStore,$legacyCore);
    foreach([$database,$database.'-wal',$database.'-shm',$txDatabase,$txDatabase.'-wal',$txDatabase.'-shm'] as $file) if(is_string($file)&&is_file($file)) @unlink($file);
}