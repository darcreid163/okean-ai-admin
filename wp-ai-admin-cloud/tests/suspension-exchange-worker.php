<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\OAuth;

$store=fixtureStore($argv[1]);
$core=fixtureCore($store,new FakeTransport(),100);
$mode=$argv[2];
$owner=$argv[3];
$client=$argv[4];
$redirect=$argv[5];
$code=$argv[6];
$verifier=$argv[7];
$now=(int)$argv[8];

try {
    if($mode==='exchange'){
        $oauth=new OAuth($store,$core,'https://wpaiadmin.ru/mcp');
        $token=$oauth->exchange([
            'grant_type'=>'authorization_code',
            'client_id'=>$client,
            'code'=>$code,
            'redirect_uri'=>$redirect,
            'code_verifier'=>$verifier,
            'resource'=>'https://wpaiadmin.ru/mcp'
        ],$now,fn($id)=>true);
        echo 'exchange:'.hash('sha256',$token['access_token']);
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
