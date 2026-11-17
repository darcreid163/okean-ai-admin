<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Fault,Store};

function indexCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function indexDefinition(Store $store,string $table,string $name,array $expected): void {
    if (getenv('TEST_MYSQL_DSN')) {
        $physical=str_replace(chr(96),'',$store->table($table));
        $rows=$store->rows('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$physical,$name]);
        $actual=array_map(static fn($row)=>(string)$row['COLUMN_NAME'],$rows);
    } else {
        $rows=$store->rows("PRAGMA index_info('".$name."')");
        $actual=array_map(static fn($row)=>(string)$row['name'],$rows);
    }
    indexCheck($actual===$expected,'Index definition mismatch: '.$table.'.'.$name);
}

$database=sys_get_temp_dir().'/wpai-cloud-index-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database);
fwrite(STDERR,"INDEX-TEST before migrate\n");
$store->migrate();
fwrite(STDERR,"INDEX-TEST after migrate\n");
$expected=[
    ['sites','idx_owner_status_created',['owner','status','created_at']],
    ['tokens','idx_owner',['owner']],
    ['requests','idx_owner_created_key',['owner','created_at','request_key']],
    ['requests','idx_owner_site_created_key',['owner','site_id','created_at','request_key']],
    ['batch_steps','idx_request_ordinal',['request_key','ordinal']],
    ['rate','idx_owner_slot',['owner','slot']],
    ['audit','idx_owner_event_created',['owner','event','created_at']],
    ['oauth_codes','idx_owner_used',['owner','used']],
    ['oauth_grants','idx_owner',['owner']],
    ['oauth_access','idx_grant_id',['grant_id']],
];
foreach($expected as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->migrate();
foreach($expected as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->ensureLicenseSchema();
$licenses=[
    ['licenses','idx_owner_issued_id',['owner','issued_at','id']],
    ['licenses','idx_issued_id',['issued_at','id']],
    ['licenses','idx_owner_status',['owner','status']],
    ['license_activations','idx_license_status',['license_id','status']],
];
foreach($licenses as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->ensureLicenseSchema();
foreach($licenses as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
if (!getenv('TEST_MYSQL_DSN')) {
    // A same-name index with a different column order/definition must fail closed.
    $store->execute('DROP INDEX idx_owner_status_created');
    $store->execute('CREATE INDEX idx_owner_status_created ON '.$store->table('sites').' (owner)');
    try { $store->migrate(); throw new RuntimeException('Mismatched index definition was accepted'); }
    catch (Fault $e) { indexCheck($e->reason==='gateway_schema_incompatible','Unexpected mismatched-index fault: '.$e->reason); }
}
$prefixStore=new Store(new PDO('sqlite::memory:'),str_repeat('p',45));
indexCheck(strlen(str_replace(chr(96),'',$prefixStore->table('license_activations')) )===64,'45-character prefix must fit 64-character MySQL identifier');
try { new Store(new PDO('sqlite::memory:'),str_repeat('p',46)); throw new RuntimeException('46-character prefix was accepted'); }
catch (Fault $e) { indexCheck($e->reason==='invalid_table_prefix','Unexpected prefix validation fault'); }
echo 'PASS Cloud Store index migration, idempotency and prefix contracts; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
if (!getenv('TEST_MYSQL_DSN')) foreach([$database,$database.'-shm',$database.'-wal'] as $file) if(file_exists($file)) unlink($file);
