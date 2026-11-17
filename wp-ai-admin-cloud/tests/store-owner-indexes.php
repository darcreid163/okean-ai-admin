<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$mysql=(bool)getenv('TEST_MYSQL_DSN');
$database=$mysql?'':tempnam(sys_get_temp_dir(),'wpai-owner-indexes-');
$prefix='cloud_test_';
$indexPrefix='idx_'.substr(hash('sha256',$prefix),0,10).'_';
try {
    $store=fixtureStore($database);
    $store->migrate();
    // Existing version-4 installs call this on every bootstrap; it must be repeatable.
    $store->ensureOwnerIndexes();
    $store->ensureOwnerIndexes();

    $expected=[
        $indexPrefix.'sites_owner_status_created'=>['sites',['owner','status','created_at']],
        $indexPrefix.'tokens_owner_revoked'=>['tokens',['owner','revoked']],
        $indexPrefix.'requests_owner_created_key'=>['requests',['owner','created_at','request_key']],
        $indexPrefix.'audit_owner'=>['audit',['owner']],
        $indexPrefix.'oauth_access_grant_id'=>['oauth_access',['grant_id']],
    ];
    foreach($expected as $name=>[$table,$columns]) {
        if($mysql) {
            $rows=$store->rows(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                [$prefix.$table,$name]
            );
            $actual=array_map(static fn($row)=>(string)$row['COLUMN_NAME'],$rows);
        } else {
            $rows=$store->rows('PRAGMA index_info(`'.$name.'`)');
            $actual=array_map(static fn($row)=>(string)$row['name'],$rows);
        }
        if($actual!==$columns) throw new RuntimeException($name.' columns mismatch: '.json_encode($actual));
    }

    // A second Store prefix shares the SQLite database but must get distinct index names.
    $otherPrefix='other_cloud_';
    $other=new \WPAICloudGateway\Store(new PDO($mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null),$otherPrefix);
    $other->migrate();
    $otherIndexPrefix='idx_'.substr(hash('sha256',$otherPrefix),0,10).'_';
    if($mysql) {
        $otherIndex=$other->row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',[$otherPrefix.'sites',$otherIndexPrefix.'sites_owner_status_created']);
    } else {
        $otherIndex=$other->rows('PRAGMA index_list(`'.$otherPrefix.'sites`)');
        $otherIndex=array_values(array_filter($otherIndex,static fn($row)=>($row['name']??'')===$otherIndexPrefix.'sites_owner_status_created'));
    }
    if(!$otherIndex) throw new RuntimeException('Second Store prefix did not receive its isolated index names');

    // No grant_id lookup exists for refresh rows today; do not add a speculative index.
    $refreshIndex=$indexPrefix.'oauth_refresh_grant_id';
    if($mysql) {
        $extra=$store->row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',[$prefix.'oauth_refresh',$refreshIndex]);
        if($extra) throw new RuntimeException('Unexpected speculative oauth_refresh grant_id index');
    } else {
        $extra=$store->rows('PRAGMA index_list(`'.$prefix.'oauth_refresh`)');
        foreach($extra as $index) if(($index['name']??'')===$refreshIndex) throw new RuntimeException('Unexpected speculative oauth_refresh grant_id index');
    }
    echo 'PASS: five query-backed owner/grant indexes verified; repeat migration and multi-prefix naming are safe; driver='.($mysql?'mysql':'sqlite').PHP_EOL;
} finally {
    if(!$mysql && $database!=='' && is_file($database)) @unlink($database);
}
