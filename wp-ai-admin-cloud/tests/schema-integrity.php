<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Store,Fault};

$checks=0;
function scheck(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function sreject(callable $fn,string $reason): void {
    try{$fn();}catch(Fault $e){scheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}
    throw new RuntimeException('Expected '.$reason);
}

$tables=['accounts','balances','sites','tokens','requests','audit','rate','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'];

if(getenv('TEST_MYSQL_DSN')){
    $pdo=new PDO(getenv('TEST_MYSQL_DSN'),getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $prefix='schema_bad_';
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$prefix.$name.'`');
    $pdo->exec('CREATE TABLE `'.$prefix.'accounts` (owner VARCHAR(64) PRIMARY KEY, plan VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $bad=new Store($pdo,$prefix);
    sreject(fn()=>$bad->migrate(),'gateway_schema_incompatible');
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$prefix.$name.'`');

    $enginePrefix='schema_engine_';
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$enginePrefix.$name.'`');
    $pdo->exec('CREATE TABLE `'.$enginePrefix.'accounts` (owner VARCHAR(64) PRIMARY KEY, plan VARCHAR(20) NOT NULL, disabled INTEGER NOT NULL DEFAULT 0, sequence INTEGER NOT NULL DEFAULT 1) ENGINE=MyISAM');
    $engine=new Store($pdo,$enginePrefix);
    sreject(fn()=>$engine->migrate(),'innodb_required');
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$enginePrefix.$name.'`');

    $goodPrefix='schema_good_';
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$goodPrefix.$name.'`');
    $good=new Store($pdo,$goodPrefix);$good->migrate();
    $good->assertSchema();
    $row=$good->row('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$goodPrefix.'requests']);
    scheck((int)($row['n']??0)>=13,'Valid MySQL gateway schema missing request columns');
    $pdo->exec('ALTER TABLE `'.$goodPrefix.'requests` DROP COLUMN reconciliation_evidence');
    sreject(fn()=>$good->assertSchema(),'gateway_schema_incompatible');
    foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS `'.$goodPrefix.$name.'`');
    echo 'PASS '.$checks." gateway schema integrity assertions; driver=mysql\n";
    exit;
}

$db=sys_get_temp_dir().'/wpai-schema-integrity-'.bin2hex(random_bytes(6)).'.sqlite';
$pdo=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE `schema_bad_accounts` (owner VARCHAR(64) PRIMARY KEY, plan VARCHAR(20) NOT NULL)');
$bad=new Store($pdo,'schema_bad_');
sreject(fn()=>$bad->migrate(),'gateway_schema_incompatible');

$goodDb=sys_get_temp_dir().'/wpai-schema-good-'.bin2hex(random_bytes(6)).'.sqlite';
$goodPdo=new PDO('sqlite:'.$goodDb,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$good=new Store($goodPdo,'schema_good_');$good->migrate();
$good->assertSchema();
$info=$good->rows('PRAGMA table_info('.$good->table('requests').')');
$names=array_map(fn($r)=>$r['name'],$info);
scheck(in_array('reconciliation_evidence',$names,true)&&in_array('policy_version',$names,true),'Valid SQLite gateway schema missing request columns');
$goodPdo->exec('ALTER TABLE '.$good->table('requests').' DROP COLUMN reconciliation_evidence');
sreject(fn()=>$good->assertSchema(),'gateway_schema_incompatible');

echo 'PASS '.$checks." gateway schema integrity assertions; driver=sqlite\n";
foreach([$db,$db.'-wal',$db.'-shm',$goodDb,$goodDb.'-wal',$goodDb.'-shm'] as $file)if(file_exists($file))unlink($file);
