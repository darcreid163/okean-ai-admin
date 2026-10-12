<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/src/OAuth.php';

use WPAICloudGateway\OAuth;
use WPAICloudGateway\Security;

$store=fixtureStore($argv[1]);
$core=fixtureCore($store,new FakeTransport(),100);
$mode=$argv[2];
$owner=$argv[3];
$site=$argv[4];
$client=$argv[5];
$redirect=$argv[6];
$verifier=$argv[7];
$now=(int)$argv[8];

try {
    if($mode==='approve'){
        $oauth=new OAuth($store,$core,'https://wpaiadmin.ru/mcp');
        $auth=[
            'client_id'=>$client,
            'redirect_uri'=>$redirect,
            'resource'=>'https://wpaiadmin.ru/mcp',
            'challenge'=>Security::b64(hash('sha256',$verifier,true)),
            'scopes'=>['mcp:read'],
            'state'=>'suspend-consent-race'
        ];
        $code=$oauth->approve($owner,$auth,[$site],$now);
        echo 'approve:'.hash('sha256',$code);
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
