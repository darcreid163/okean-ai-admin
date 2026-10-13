<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$store=fixtureStore($argv[1]);
$core=fixtureCore($store,new FakeTransport(),100);
try {
    $site=$core->connect($argv[2],$argv[3],$argv[4],(int)$argv[5]);
    echo 'success:'.$site['id'];
} catch(\WPAICloudGateway\Fault $e) {
    echo 'fault:'.$e->reason;
} catch(\Throwable $e) {
    echo 'exception:'.get_class($e).':'.$e->getMessage();
}
