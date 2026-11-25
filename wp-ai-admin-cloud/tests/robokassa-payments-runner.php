<?php
declare(strict_types=1);

/**
 * Dedicated isolated runner for the Robokassa payment/licence lifecycle suite.
 * Uses a unique table prefix on MySQL and a disposable SQLite file locally.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/robokassa-payments.php';

use WPAICloudGateway\Core;
use WPAICloudGateway\Store;

$mysql=(string)getenv('TEST_MYSQL_DSN')!=='';
$database=$mysql?null:tempnam(sys_get_temp_dir(),'wpai-robokassa-');
if(!$mysql && $database===false) throw new RuntimeException('No disposable Robokassa database');
$prefix='rk_test_'.bin2hex(random_bytes(6)).'_';
$pdo=new PDO(
    $mysql?(string)getenv('TEST_MYSQL_DSN'):'sqlite:'.$database,
    getenv('TEST_MYSQL_USER')?:null,
    getenv('TEST_MYSQL_PASSWORD')?:null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$store=new Store($pdo,$prefix);
$tables=[
    'accounts','balances','sites','tokens','requests','batch_steps','audit','rate',
    'oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate',
    'licenses','license_activations','license_events','commerce_payments','commerce_refunds',
    'license_deliveries','sse_sessions','sse_queue',
];
try {
    $store->migrate();
    $transport=new FakeTransport();
    $core=fixtureCore($store,$transport);
    runRobokassaPaymentsTests($store,$core);
    echo 'PASS Robokassa payment/licence lifecycle; driver='.($mysql?'mysql':'sqlite')."\n";
} finally {
    foreach($tables as $table) {
        try { $store->execute('DROP TABLE IF EXISTS '.$store->table($table)); }
        catch(Throwable $ignored) {}
    }
    unset($core,$transport,$store,$pdo);
    if(!$mysql && is_string($database)) {
        foreach([$database,$database.'-wal',$database.'-shm'] as $file) if(is_file($file)) @unlink($file);
    }
}
