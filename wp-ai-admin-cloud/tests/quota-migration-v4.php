<?php
declare(strict_types=1);
/** Recovery gate: real additive v3 -> v4 migration on isolated SQLite or MySQL. */
require dirname(__DIR__).'/src/Security.php';
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/BatchAccounting.php';
require dirname(__DIR__).'/src/QuotaPolicy.php';
use WPAICloudGateway\{Store,QuotaPolicy,Fault};
$checks=0;
function migrationCheck(bool $ok,string $label): void { global $checks; ++$checks; if(!$ok) throw new RuntimeException($label); }
$mysql=(string)getenv('TEST_MYSQL_DSN')!=='';
$database=tempnam(sys_get_temp_dir(),'wpai-quota-v4-');
if($database===false) throw new RuntimeException('No disposable DB');
$pdo=new PDO($mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,'qa_v4_'.bin2hex(random_bytes(5)).'_');
$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
$tables=['oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','audit','rate','batch_steps','requests','tokens','sites','balances','accounts','oauth_rate'];
try {
    $store->execute('CREATE TABLE '.$store->table('accounts').' (owner VARCHAR(64) PRIMARY KEY,plan VARCHAR(20) NOT NULL,disabled INTEGER NOT NULL DEFAULT 0,sequence INTEGER NOT NULL DEFAULT 1)'.$tail);
    $store->execute('CREATE TABLE '.$store->table('balances').' (owner VARCHAR(64) NOT NULL,period VARCHAR(7) NOT NULL,used INTEGER NOT NULL DEFAULT 0,reserved INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(owner,period))'.$tail);
    $store->execute('CREATE TABLE '.$store->table('sites').' (id VARCHAR(64) PRIMARY KEY,owner VARCHAR(64) NOT NULL,home VARCHAR(512) NOT NULL,url_hash VARCHAR(64) NOT NULL UNIQUE,plugin_site_id VARCHAR(120) NOT NULL,installation_id VARCHAR(120) NOT NULL,token_sealed TEXT NOT NULL,status VARCHAR(20) NOT NULL,created_at BIGINT NOT NULL)'.$tail);
    $store->execute('CREATE TABLE '.$store->table('oauth_grants').' (id VARCHAR(64) PRIMARY KEY,owner VARCHAR(64) NOT NULL,client VARCHAR(64) NOT NULL,resource VARCHAR(512) NOT NULL,scopes TEXT NOT NULL,sites TEXT NOT NULL,revoked INTEGER NOT NULL DEFAULT 0,expires BIGINT NOT NULL)'.$tail);
    $store->execute('INSERT INTO '.$store->table('accounts').' (owner,plan) VALUES (?,?)',['owner1','free']);
    $store->execute('INSERT INTO '.$store->table('balances').' (owner,period,used,reserved) VALUES (?,?,?,?)',['owner1','2026-10',17,3]);
    $store->execute('INSERT INTO '.$store->table('sites').' (id,owner,home,url_hash,plugin_site_id,installation_id,token_sealed,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)',['reg_one','owner1','https://wpaiadmin.ru',hash('sha256','https://wpaiadmin.ru'),'original-site','original-installation','sealed-original','active',1790000000]);
    $store->execute('INSERT INTO '.$store->table('oauth_grants').' (id,owner,client,resource,scopes,sites,expires) VALUES (?,?,?,?,?,?,?)',['grant-one','owner1','client-one','https://wpaiadmin.ru/mcp','["mcp:read"]','["reg_one"]',1900000000]);
    $store->migrate(); $store->migrate();
    $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?',['owner1']);
    $balance=$store->row('SELECT * FROM '.$store->table('balances').' WHERE owner=? AND period=?',['owner1','2026-10']);
    $site=$store->row('SELECT * FROM '.$store->table('sites').' WHERE id=?',['reg_one']);
    $grant=$store->row('SELECT * FROM '.$store->table('oauth_grants').' WHERE id=?',['grant-one']);
    migrationCheck($account!==null&&$account['quota_scheme']===QuotaPolicy::LEGACY,'Legacy account changed without consent');
    migrationCheck($balance!==null&&(int)$balance['used']===17&&(int)$balance['reserved']===3,'Historical balance reset');
    migrationCheck($site!==null&&$site['plugin_site_id']==='original-site'&&$site['installation_id']==='original-installation'&&$site['token_sealed']==='sealed-original','Site identity/token changed');
    migrationCheck($grant!==null&&$grant['owner']==='owner1'&&$grant['client']==='client-one'&&$grant['sites']==='["reg_one"]'&&(int)$grant['revoked']===0,'OAuth grant changed');
    $policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
    QuotaPolicy::validate($policy);
    $legacy=QuotaPolicy::terms($policy,$account);
    migrationCheck($legacy['limit']===100&&$legacy['period_kind']===QuotaPolicy::MONTH,'Legacy terms lost');
    $before=QuotaPolicy::window($legacy,strtotime('2026-10-31T23:59:59Z'));
    $after=QuotaPolicy::window($legacy,strtotime('2026-11-01T00:00:00Z'));
    migrationCheck($before['period']==='2026-10'&&$before['next_period_at']===strtotime('2026-11-01T00:00:00Z')&&$after['period']==='2026-11','UTC monthly rollover failed');
    foreach(['free'=>[100,QuotaPolicy::MONTH],'pro'=>[500,QuotaPolicy::DAY],'biznes'=>[2000,QuotaPolicy::DAY],'biznespro'=>[5000,QuotaPolicy::DAY]] as $plan=>[$limit,$kind]){
        $terms=QuotaPolicy::terms($policy,['plan'=>$plan,'quota_scheme'=>QuotaPolicy::CURRENT]);
        migrationCheck($terms['limit']===$limit&&$terms['period_kind']===$kind,'Bad approved limit for '.$plan);
        if($kind===QuotaPolicy::DAY){
            $day=QuotaPolicy::window($terms,strtotime('2026-03-29T23:59:59Z'));
            migrationCheck($day['period']==='2026-03-29'&&$day['next_period_at']===strtotime('2026-03-30T00:00:00Z'),'UTC/DST boundary failure for '.$plan);
        }
    }
    $store->execute('UPDATE '.$store->table('accounts').' SET plan=?,quota_scheme=? WHERE owner=?',['pro',QuotaPolicy::CURRENT,'owner1']);
    $updated=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?',['owner1']);
    migrationCheck(QuotaPolicy::terms($policy,$updated)['period_kind']===QuotaPolicy::DAY,'Explicit daily assignment failed');
    $historic=$store->row('SELECT * FROM '.$store->table('balances').' WHERE owner=? AND period=?',['owner1','2026-10']);
    migrationCheck((int)$historic['used']===17&&(int)$historic['reserved']===3,'Changing plans rewrote historic balance');
    $bad=$policy; $bad['plans']['pro']='500';
    try{QuotaPolicy::validate($bad);throw new RuntimeException('String limit incorrectly accepted');}
    catch(Fault $e){migrationCheck($e->reason==='invalid_plan_limit','Wrong quota validation fault');}
    echo 'PASS '.$checks.' v4 migration/UTC/identity assertions; driver='.($mysql?'mysql':'sqlite')."\n";
} finally {
    foreach($tables as $name) $store->execute('DROP TABLE IF EXISTS '.$store->table($name));
    if(!$mysql) foreach([$database,$database.'-wal',$database.'-shm'] as $file) if(is_file($file)) unlink($file);
}
