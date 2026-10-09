<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$store=fixtureStore($argv[1]);
$core=fixtureCore($store,new FakeTransport(),100);
$mode=$argv[2];
$owner=$argv[3];
$site=$argv[4];
$now=(int)$argv[5];

try {
    if($mode==='mint'){
        $token=$core->mint($owner,[$site],['mcp:read'],$now,3600);
        echo 'mint:'.hash('sha256',$token['access_token']);
    } elseif($mode==='suspend'){
        $r=$core->suspendAccount($owner,$now);
        echo !empty($r['suspended'])?'suspend:ok':'suspend:bad';
    } else {
        echo 'bad-mode';
    }
} catch(\WPAICloudGateway\Fault $e) {
    echo 'fault:'.$e->reason;
} catch(\Throwable $e) {
    echo 'exception:'.get_class($e).':'.$e->getMessage();
}
