<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{RpcReply,Fault,HttpsTransport};
$checks=0;
$req=['jsonrpc'=>'2.0','id'=>'abilities-transport','method'=>'tools/call','params'=>['name'=>'ability.get','arguments'=>['name'=>'example/read']]];
$valid=['jsonrpc'=>'2.0','id'=>'abilities-transport','result'=>['content'=>[['type'=>'text','text'=>'{"name":"example/read"}']]]];
RpcReply::validate($valid,$req); ++$checks;
$reject=function(array $reply)use($req,&$checks):void {
    try {RpcReply::validate($reply,$req);} catch(Fault $e) {
        if($e->reason!=='upstream_outcome_unknown')throw new RuntimeException('Wrong transport error');
        ++$checks;return;
    }
    throw new RuntimeException('Invalid upstream reply accepted');
};
$bad=$valid;$bad['id']='wrong-id';$reject($bad);
$bad=$valid;unset($bad['result']);$reject($bad);
$bad=$valid;$bad['result']['content'][0]['text']=['not'=>'string'];$reject($bad);
$bad=$valid;$bad['result']['isError']='false';$reject($bad);
$bad=$valid;$bad['result']['content'][]=['type'=>'unknown'];$reject($bad);
// HTTPS transport must not silently permit loopback or plain HTTP to bypass
// production DNS pinning / public-only protection for a disposable CI server.
$https=new HttpsTransport();
foreach(['http://127.0.0.1:8080','https://127.0.0.1','https://localhost'] as $home) {
    try {$https->rpc($home,str_repeat('t',32),$req);}
    catch(Fault $e) {++$checks;continue;}
    throw new RuntimeException('Untrusted upstream endpoint accepted: '.$home);
}
echo "PASS $checks HTTPS boundary and Ability JSON-RPC reply contracts\n";
