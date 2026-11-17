<?php
declare(strict_types=1);
use WPAICloudGateway\{Fault,Store};
require __DIR__.'/bootstrap.php';

function indexCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function indexName(string $logical): string { return 'wpai_'.substr(hash('sha256','cloud_test_'),0,16).'_'.$logical; }
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
    ['sites',indexName('idx_sites_owner_status_created'),['owner','status','created_at']],
    ['tokens',indexName('idx_tokens_owner'),['owner']],
    ['requests',indexName('idx_requests_owner_created_key'),['owner','created_at','request_key']],
    ['requests',indexName('idx_requests_owner_site_created_key'),['owner','site_id','created_at','request_key']],
    ['batch_steps',indexName('idx_batch_steps_request_ordinal'),['request_key','ordinal']],
    ['rate',indexName('idx_rate_owner_slot'),['owner','slot']],
    ['oauth_codes',indexName('idx_oauth_codes_owner_used'),['owner','used']],
    ['oauth_grants',indexName('idx_oauth_grants_owner'),['owner']],
    ['oauth_access',indexName('idx_oauth_access_grant_id'),['grant_id']],
];
foreach($expected as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->migrate();
foreach($expected as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->ensureLicenseSchema();
$licenses=[
    ['licenses',indexName('idx_licenses_owner_issued_id'),['owner','issued_at','id']],
    ['licenses',indexName('idx_licenses_issued_id'),['issued_at','id']],
    ['licenses',indexName('idx_licenses_owner_status'),['owner','status']],
    ['license_activations',indexName('idx_license_activations_license_status'),['license_id','status']],
];
foreach($licenses as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
$store->ensureLicenseSchema();
foreach($licenses as [$table,$name,$columns]) indexDefinition($store,$table,$name,$columns);
if (!getenv('TEST_MYSQL_DSN')) {
    // SQLite index names are global: a name already owned by another table must fail closed, not recurse.
    $collisionDb=sys_get_temp_dir().'/wpai-cloud-index-collision-'.bin2hex(random_bytes(8)).'.sqlite';
    $collisionPdo=new PDO('sqlite:'.$collisionDb);
    $collisionPdo->exec('CREATE TABLE collision (owner TEXT)');
    $collisionPdo->exec('CREATE INDEX '.indexName('idx_tokens_owner').' ON collision (owner)');
    $collisionStore=new Store($collisionPdo,'cloud_test_');
    try { $collisionStore->migrate(); throw new RuntimeException('Cross-table SQLite index name collision was accepted'); }
    catch (Fault $e) { indexCheck($e->reason==='gateway_schema_incompatible','Unexpected cross-table index collision fault: '.$e->reason); }
    unset($collisionStore,$collisionPdo);
    foreach([$collisionDb,$collisionDb.'-shm',$collisionDb.'-wal'] as $file) if(file_exists($file)) unlink($file);
    // A same-name index with a different column order/definition must fail closed.
    $store->execute('DROP INDEX '.indexName('idx_sites_owner_status_created').'');
    $store->execute('CREATE INDEX '.indexName('idx_sites_owner_status_created').' ON '.$store->table('sites').' (owner)');
    try { $store->migrate(); throw new RuntimeException('Mismatched index definition was accepted'); }
    catch (Fault $e) { indexCheck($e->reason==='gateway_schema_incompatible','Unexpected mismatched-index fault: '.$e->reason); }
}
echo 'PASS Cloud Store index migration and idempotency contracts; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite')."\n";
if (!getenv('TEST_MYSQL_DSN')) foreach([$database,$database.'-shm',$database.'-wal'] as $file) if(file_exists($file)) unlink($file);
