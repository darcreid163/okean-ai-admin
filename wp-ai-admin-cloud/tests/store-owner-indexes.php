<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Fault;

$mysql=(bool)getenv('TEST_MYSQL_DSN');
$database=$mysql?'':tempnam(sys_get_temp_dir(),'wpai-owner-indexes-');
try {
    $store=fixtureStore($database);
    $store->migrate();
    // Existing version-4 installs call this on every bootstrap; it must be repeatable.
    $store->ensureOwnerIndexes();
    $store->ensureOwnerIndexes();

    $expected=[
        'idx_sites_owner_status_created'=>['owner','status','created_at'],
        'idx_tokens_owner_revoked'=>['owner','revoked'],
        'idx_requests_owner_created_key'=>['owner','created_at','request_key'],
        'idx_audit_owner'=>['owner'],
        'idx_oauth_access_grant_id'=>['grant_id'],
    ];
    foreach($expected as $name=>$columns) {
        if($mysql) {
            $rows=$store->rows(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                ['cloud_test_'.match($name) {
                    'idx_sites_owner_status_created'=>'sites',
                    'idx_tokens_owner_revoked'=>'tokens',
                    'idx_requests_owner_created_key'=>'requests',
                    'idx_audit_owner'=>'audit',
                    'idx_oauth_access_grant_id'=>'oauth_access',
                },$name]
            );
            $actual=array_map(static fn($row)=>(string)$row['COLUMN_NAME'],$rows);
        } else {
            $rows=$store->rows('PRAGMA index_info(`'.$name.'`)');
            $actual=array_map(static fn($row)=>(string)$row['name'],$rows);
        }
        if($actual!==$columns) throw new RuntimeException($name.' columns mismatch: '.json_encode($actual));
    }

    // No grant_id lookup exists for refresh rows today; do not add a speculative index.
    if($mysql) {
        $extra=$store->row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',['cloud_test_oauth_refresh','idx_oauth_refresh_grant_id']);
        if($extra) throw new RuntimeException('Unexpected speculative oauth_refresh grant_id index');
    } else {
        $extra=$store->rows('PRAGMA index_list(`cloud_test_oauth_refresh`)');
        foreach($extra as $index) if(($index['name']??'')==='idx_oauth_refresh_grant_id') throw new RuntimeException('Unexpected speculative oauth_refresh grant_id index');
    }
    echo 'PASS: five query-backed owner/grant indexes verified; repeat migration is idempotent; driver='.($mysql?'mysql':'sqlite').PHP_EOL;
} finally {
    if(!$mysql && $database!=='' && is_file($database)) @unlink($database);
}
