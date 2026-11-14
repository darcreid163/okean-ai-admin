<?php
declare(strict_types=1);
/**
 * Tariff catalogue v3 contract.
 *
 * Verifies the single shipped plan source without WordPress: the frozen v2 and
 * legacy behaviour, the monthly target scheme, unapproved paid quotas that must
 * fail closed instead of becoming zero/unlimited, additive plan-version
 * merging that never invalidates every request, alias migration and the
 * billable-call gate.
 */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Fault,QuotaPolicy,Store};

$checks=0;
function v3Check(bool $ok,string $label): void { global $checks; ++$checks; if(!$ok) throw new RuntimeException($label); }
function v3Rejects(callable $fn,string $reason): void {
    try { $fn(); }
    catch (Fault $e) { v3Check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return; }
    throw new RuntimeException('Expected refusal: '.$reason);
}
function v3B64(string $value): string {
    return base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
}

$mysql=(string)getenv('TEST_MYSQL_DSN')!=='';
$database=tempnam(sys_get_temp_dir(),'wpai-plan-v3-');
if($database===false) throw new RuntimeException('No disposable DB');
$pdo=new PDO($mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,'qa_v3_'.bin2hex(random_bytes(5)).'_');
$store->migrate();
$tables=['accounts','balances','sites','tokens','requests','batch_steps','rate','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'];
try {
    $policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
    QuotaPolicy::validate($policy);
    $now=1790942400;

    // Frozen v2 default: no existing account changes scheme on upgrade.
    v3Check(($policy['default_scheme']??null)===QuotaPolicy::CURRENT,'Default scheme is not the frozen v2');
    v3Check(QuotaPolicy::scheme($policy)===QuotaPolicy::CURRENT,'Scheme resolver ignored the default');
    v3Check(($policy['automatic_money_debits']??null)===false,'Automatic money debits enabled');
    v3Check(($policy['batch']??null)==='disabled_until_receipt_accounting_contract','Production batch enabled');
    foreach(['free'=>100,'daily'=>500,'pro'=>2000,'business'=>5000] as $slug=>$limit)
        v3Check(($policy['plans'][$slug]??null)===$limit,'Frozen v2 limit changed: '.$slug);
    foreach(['free','daily','pro','business'] as $slug)
        v3Check(($policy['periods'][$slug]??null)===QuotaPolicy::DAY,'Frozen v2 period changed: '.$slug);
    foreach(['free'=>100,'pro'=>500,'power'=>2000,'agency'=>5000,'scale'=>10000] as $slug=>$limit)
        v3Check(($policy['legacy_plans'][$slug]??null)===$limit,'Legacy monthly limit changed: '.$slug);

    // Target scheme: Free is fixed and approved, paid quotas are explicitly
    // pending until the owner supplies a number.
    $v3=$policy['schemes'][QuotaPolicy::TARGET]??null;
    v3Check(is_array($v3),'Missing target scheme');
    v3Check(($v3['period']??null)===QuotaPolicy::MONTH,'Target scheme is not monthly');
    v3Check(($v3['all_tools']??null)===true,'Target scheme must expose all tools on every plan');
    v3Check(($v3['plans']['free']??null)===100,'Free target quota must be 100');
    v3Check(($v3['quota_status']['free']??null)===QuotaPolicy::QUOTA_APPROVED,'Free target quota must be approved');
    foreach(['pro'=>2000,'business'=>5000,'businesspro'=>10000] as $slug=>$price) {
        v3Check(array_key_exists($slug,$v3['plans']),'Missing target plan: '.$slug);
        v3Check($v3['plans'][$slug]===null,'Paid quota invented before owner approval: '.$slug);
        v3Check(($v3['quota_status'][$slug]??null)===QuotaPolicy::QUOTA_PENDING,'Paid quota status must be pending: '.$slug);
        v3Check(($v3['periods'][$slug]??null)===QuotaPolicy::MONTH,'Paid plan must be monthly: '.$slug);
        v3Check(($v3['prices_rub_month'][$slug]??null)===$price,'Price mismatch: '.$slug);
    }

    // Offer domain is scheme-specific.
    v3Check(QuotaPolicy::offers($policy,QuotaPolicy::TARGET,'free'),'v3 free not offered');
    v3Check(QuotaPolicy::offers($policy,QuotaPolicy::TARGET,'businesspro'),'v3 businesspro not offered');
    v3Check(!QuotaPolicy::offers($policy,QuotaPolicy::TARGET,'power'),'v3 leaked a legacy plan');
    v3Check(!QuotaPolicy::offers($policy,QuotaPolicy::CURRENT,'businesspro'),'v2 must not offer businesspro');
    v3Check(!QuotaPolicy::offers($policy,QuotaPolicy::CURRENT,'biznes'),'Retired alias is still offered on v2');
    v3Check(QuotaPolicy::offers($policy,QuotaPolicy::LEGACY,'scale'),'Legacy scale missing');

    // Terms: legacy stays monthly, v2 stays daily, v3 free is monthly/approved,
    // v3 paid is pending with a null limit (not zero, not unlimited).
    $legacy=QuotaPolicy::terms($policy,['plan'=>'pro','quota_scheme'=>QuotaPolicy::LEGACY]);
    v3Check($legacy['limit']===500&&$legacy['period_kind']===QuotaPolicy::MONTH&&$legacy['quota_scheme']===QuotaPolicy::LEGACY,'Legacy terms changed');
    $v2=QuotaPolicy::terms($policy,['plan'=>'free','quota_scheme'=>QuotaPolicy::CURRENT]);
    v3Check($v2['limit']===100&&$v2['period_kind']===QuotaPolicy::DAY&&$v2['quota_scheme']===QuotaPolicy::CURRENT,'v2 terms changed');
    $free=QuotaPolicy::terms($policy,['plan'=>'free','quota_scheme'=>QuotaPolicy::TARGET]);
    v3Check($free['limit']===100&&$free['period_kind']===QuotaPolicy::MONTH&&$free['quota_status']===QuotaPolicy::QUOTA_APPROVED,'v3 free terms wrong');
    $pro=QuotaPolicy::terms($policy,['plan'=>'pro','quota_scheme'=>QuotaPolicy::TARGET]);
    v3Check($pro['limit']===null&&$pro['quota_status']===QuotaPolicy::QUOTA_PENDING&&$pro['period_kind']===QuotaPolicy::MONTH,'v3 paid terms must stay pending');
    v3Rejects(fn()=>QuotaPolicy::terms($policy,['plan'=>'enterprise','quota_scheme'=>QuotaPolicy::TARGET]),'plan_unavailable');
    v3Rejects(fn()=>QuotaPolicy::terms($policy,['plan'=>'free','quota_scheme'=>'tariffs_v9']),'plan_unavailable');
    v3Rejects(fn()=>QuotaPolicy::terms($policy,['plan'=>'business','quota_scheme'=>QuotaPolicy::LEGACY]),'plan_unavailable');

    // Persisted plan versions merge additively. A stale/incompatible row or an
    // extra row must not invalidate the whole gateway with a 503.
    $rows=[
        ['slug'=>'free','scheme'=>QuotaPolicy::LEGACY,'quota_status'=>'approved','monthly_actions'=>111,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0],
        ['slug'=>'free','scheme'=>QuotaPolicy::CURRENT,'quota_status'=>'approved','monthly_actions'=>777,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0],
        ['slug'=>'gold','scheme'=>QuotaPolicy::LEGACY,'quota_status'=>'approved','monthly_actions'=>900,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0],
        ['slug'=>'broken','scheme'=>QuotaPolicy::LEGACY,'monthly_actions'=>1,'batch_objects_per_action'=>3,'failed_actions_billable'=>1,'rollback_billable'=>0,'verify_billable'=>0],
        ['slug'=>'pro','scheme'=>QuotaPolicy::LEGACY,'monthly_actions'=>500,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>1],
        ['slug'=>'pro','scheme'=>QuotaPolicy::TARGET,'quota_status'=>'approved','monthly_actions'=>1234,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0],
        ['slug'=>'starter','scheme'=>QuotaPolicy::TARGET,'quota_status'=>'approved','monthly_actions'=>50,'batch_objects_per_action'=>5,'failed_actions_billable'=>0,'rollback_billable'=>0,'verify_billable'=>0],
    ];
    $merged=QuotaPolicy::mergePlanVersions($policy,$rows);
    QuotaPolicy::validate($merged);
    v3Check(($merged['legacy_plans']['free']??null)===111,'Compatible legacy override was not applied');
    v3Check(($merged['legacy_plans']['gold']??null)===900,'Extra legacy plan row was dropped');
    v3Check(!array_key_exists('broken',$merged['legacy_plans']),'Incompatible plan row was applied');
    v3Check(isset($merged['incompatible_plans']['legacy_monthly_v1|broken']),'Incompatible row was not quarantined');
    v3Check(isset($merged['incompatible_plans']['legacy_monthly_v1|pro']),'Incompatible known plan was not quarantined');
    v3Rejects(fn()=>QuotaPolicy::terms($merged,['plan'=>'pro','quota_scheme'=>QuotaPolicy::LEGACY]),'plan_unavailable');
    v3Check(QuotaPolicy::terms($merged,['plan'=>'agency','quota_scheme'=>QuotaPolicy::LEGACY])['limit']===5000,'Unaffected legacy plan was quarantined');
    v3Check(($merged['plans']['free']??null)===100,'A persisted v2 row rewrote the frozen v2 catalogue');
    v3Check(($merged['schemes'][QuotaPolicy::TARGET]['plans']['pro']??null)===1234,'Approved paid quota was not applied');
    v3Check(($merged['schemes'][QuotaPolicy::TARGET]['quota_status']['pro']??null)===QuotaPolicy::QUOTA_APPROVED,'Approved status missing');
    v3Check(!isset($merged['schemes'][QuotaPolicy::TARGET]['plans']['starter']),'Unknown v3 plan leaked into the catalogue');
    $approvedPro=QuotaPolicy::terms($merged,['plan'=>'pro','quota_scheme'=>QuotaPolicy::TARGET]);
    v3Check($approvedPro['limit']===1234&&$approvedPro['quota_status']===QuotaPolicy::QUOTA_APPROVED,'Merged paid terms are not effective');

    // Invalid shipped combinations must still fail closed.
    $badApproved=$policy; $badApproved['schemes'][QuotaPolicy::TARGET]['quota_status']['pro']=QuotaPolicy::QUOTA_APPROVED;
    v3Rejects(fn()=>QuotaPolicy::validate($badApproved),'invalid_plan_limit');
    $badPending=$policy; $badPending['schemes'][QuotaPolicy::TARGET]['plans']['pro']=500;
    v3Rejects(fn()=>QuotaPolicy::validate($badPending),'invalid_plan_limit');
    $badV2=$policy; unset($badV2['plans']['daily']);
    v3Rejects(fn()=>QuotaPolicy::validate($badV2),'invalid_plan_limit');
    $badPeriod=$policy; $badPeriod['periods']['pro']=QuotaPolicy::MONTH;
    v3Rejects(fn()=>QuotaPolicy::validate($badPeriod),'invalid_quota_policy');
    $badDefault=$policy; $badDefault['default_scheme']='tariffs_v9';
    v3Rejects(fn()=>QuotaPolicy::validate($badDefault),'invalid_quota_policy');

    // Alias migration is scheme-aware, audited and idempotent. Legacy rows are
    // never rewritten: their historic monthly terms must survive.
    $aliases=[
        ['alias-v3-biznes','biznes',QuotaPolicy::TARGET],
        ['alias-v3-pro','biznespro',QuotaPolicy::TARGET],
        ['alias-v2-biznes','biznes',QuotaPolicy::CURRENT],
        ['alias-v2-pro','biznespro',QuotaPolicy::CURRENT],
        ['alias-legacy','biznespro',QuotaPolicy::LEGACY],
    ];
    foreach($aliases as [$owner,$plan,$scheme])
        $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan,quota_scheme) VALUES (?,?,?)',[$owner,$plan,$scheme]);
    v3Check(Core::migratePlanAliases($store,$now)===4,'Alias migration count is wrong');
    v3Check(Core::migratePlanAliases($store,$now)===0,'Alias migration is not idempotent');
    v3Check($store->row('SELECT plan FROM '.$store->table('accounts').' WHERE owner=?',['alias-v3-biznes'])['plan']==='business','v3 biznes alias not migrated');
    v3Check($store->row('SELECT plan FROM '.$store->table('accounts').' WHERE owner=?',['alias-v3-pro'])['plan']==='businesspro','v3 businesspro alias degraded');
    v3Check($store->row('SELECT plan FROM '.$store->table('accounts').' WHERE owner=?',['alias-v2-biznes'])['plan']==='business','v2 biznes alias not migrated');
    v3Check($store->row('SELECT plan FROM '.$store->table('accounts').' WHERE owner=?',['alias-v2-pro'])['plan']==='business','v2 businesspro alias changed');
    $legacyAlias=$store->row('SELECT plan,quota_scheme FROM '.$store->table('accounts').' WHERE owner=?',['alias-legacy']);
    v3Check($legacyAlias['plan']==='biznespro'&&$legacyAlias['quota_scheme']===QuotaPolicy::LEGACY,'Legacy alias row was rewritten');
    v3Check((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('audit').' WHERE event=?',['plan_alias_migrated'])['n']===4,'Alias migration audit trail incomplete');

    // Operator assignment validates against the target scheme, so a Native or
    // admin client can never smuggle a plan from another catalogue.
    $transport=new FakeTransport();
    $core=fixtureCore($store,$transport);
    $core->ensureAccount('assign');
    $core->setPlan('operator','assign','business',$now,QuotaPolicy::CURRENT);
    $assigned=$store->row('SELECT plan,quota_scheme FROM '.$store->table('accounts').' WHERE owner=?',['assign']);
    v3Check($assigned['plan']==='business'&&$assigned['quota_scheme']===QuotaPolicy::CURRENT,'v2 plan assignment failed');
    $core->setPlan('operator','assign','businesspro',$now,QuotaPolicy::TARGET);
    $assigned=$store->row('SELECT plan,quota_scheme FROM '.$store->table('accounts').' WHERE owner=?',['assign']);
    v3Check($assigned['plan']==='businesspro'&&$assigned['quota_scheme']===QuotaPolicy::TARGET,'v3 plan assignment failed');
    v3Check($core->usage('assign',$now)['limit']===null&&$core->usage('assign',$now)['remaining']===null,'Pending usage fabricated a balance');
    v3Rejects(fn()=>$core->setPlan('operator','assign','power',$now,QuotaPolicy::TARGET),'unknown_plan');
    v3Rejects(fn()=>$core->setPlan('operator','assign','biznes',$now,QuotaPolicy::TARGET),'unknown_plan');
    v3Rejects(fn()=>$core->setPlan('operator','assign','businesspro',$now,QuotaPolicy::CURRENT),'unknown_plan');
    $core->ensureAccount('legacy-assign');
    $store->execute('UPDATE '.$store->table('accounts').' SET plan=?,quota_scheme=? WHERE owner=?',['free',QuotaPolicy::LEGACY,'legacy-assign']);
    $core->setPlan('operator','legacy-assign','pro',$now,QuotaPolicy::LEGACY);
    v3Rejects(fn()=>$core->setPlan('operator','legacy-assign','business',$now,QuotaPolicy::LEGACY),'unknown_plan');

    // Fail-closed dispatch: an unapproved paid quota blocks only billable calls.
    $core->ensureAccount('gate');
    $home='https://plan-gate.example.com';
    $statusKey=bin2hex(random_bytes(32));
    $site=$core->connect('gate',$home,'fixture-site-admin-token-gate',$now,false,hash('sha256',$statusKey));
    $write=$core->mint('gate',[$site['id']],['mcp:read','mcp:write'],$now);
    $actor=$core->authenticate($write['access_token'],$now);
    $core->setPlan('operator','gate','businesspro',$now,QuotaPolicy::TARGET);
    v3Check($transport->executions===0,'Setup unexpectedly executed a tool');

    v3Rejects(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'pending-billable',$now),'plan_quota_not_configured');
    v3Check($transport->executions===0,'Denied billable call reached the site');
    v3Check((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('requests').' WHERE owner=?',['gate'])['n']===0,'Denied billable call left a request row');
    $balance=$store->row('SELECT used,reserved FROM '.$store->table('balances').' WHERE owner=?',['gate']);
    v3Check($balance===null||((int)$balance['used']===0&&(int)$balance['reserved']===0),'Denied billable call moved a balance');

    $verify=$core->call($actor,$site['id'],'operation_verify',['id'=>1],'pending-verify',$now);
    v3Check($verify['charged_units']===0&&$transport->executions===1,'Pending quota blocked a non-billable verification');
    $usage=$core->usage('gate',$now);
    v3Check($usage['plan']==='businesspro'&&$usage['limit']===null&&$usage['quota_status']===QuotaPolicy::QUOTA_PENDING,'Pending usage projection is wrong');
    $projection=$core->siteUsage($home,$statusKey,$now);
    $claims=json_decode(v3B64($projection['envelope']['payload']),true,16,JSON_THROW_ON_ERROR);
    v3Check($claims['quota_status']===QuotaPolicy::QUOTA_PENDING,'Site projection hid the pending quota');
    v3Check($claims['limit']===null&&$claims['remaining']===null,'Site projection invented a zero balance');
    v3Check($claims['quota_scheme']===QuotaPolicy::TARGET&&$claims['period_kind']===QuotaPolicy::MONTH,'Site projection scheme is wrong');

    $rollback=$core->call($actor,$site['id'],'operation_rollback',['id'=>1],'pending-rollback',$now);
    v3Check($rollback['charged_units']===0&&$transport->executions===2,'Pending quota blocked rollback');

    // Once the owner approves the plan, billing resumes; switching to another
    // unapproved paid plan closes it again.
    $core->setPlan('operator','gate','free',$now,QuotaPolicy::TARGET);
    $approved=$core->call($actor,$site['id'],'wp_get_site',[],'approved-billable',$now);
    v3Check($approved['charged_units']===1&&$transport->executions===3,'Approved plan did not bill');
    $usage=$core->usage('gate',$now);
    v3Check($usage['limit']===100&&$usage['used']===1&&$usage['reserved']===0&&$usage['remaining']===99,'Approved free balance is wrong');
    $projection=$core->siteUsage($home,$statusKey,$now);
    $claims=json_decode(v3B64($projection['envelope']['payload']),true,16,JSON_THROW_ON_ERROR);
    v3Check($claims['limit']===100&&$claims['remaining']===99&&$claims['quota_status']===QuotaPolicy::QUOTA_APPROVED,'Approved site projection is wrong');

    $core->setPlan('operator','gate','pro',$now,QuotaPolicy::TARGET);
    v3Rejects(fn()=>$core->call($actor,$site['id'],'wp_get_site',[],'pending-again',$now),'plan_quota_not_configured');
    v3Check($transport->executions===3,'Re-pending plan executed a tool');

    echo 'PASS '.$checks.' plan catalogue v3 assertions; driver='.($mysql?'mysql':'sqlite')."\n";
} finally {
    foreach($tables as $name) $store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    unset($core,$actor,$transport,$store,$pdo);
    if(!$mysql) foreach([$database,$database.'-wal',$database.'-shm'] as $file) if(is_file($file)) unlink($file);
}