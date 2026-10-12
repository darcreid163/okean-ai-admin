<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Core,Store,Transport,Vault,Signer};

final class BlockingCallTransport implements Transport {
    public function __construct(private string $signal,private string $release){}
    public function rpc(string $home,string $token,array $request): array {
        if($request['method']==='tools/list') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>[
            ['name'=>'operation_apply','inputSchema'=>['type'=>'object']]
        ]]];
        if(($request['params']['name']??'')==='system_manifest') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode([
            'plugin_name'=>'WP AI Admin','site_id'=>'plugin-blocking','installation_id'=>'install-blocking',
            'release'=>['production_defaults'=>['direct_live_writes'=>false]]
        ])]]]];
        file_put_contents($this->signal,'entered',LOCK_EX);
        $deadline=microtime(true)+10;
        while(!file_exists($this->release)){
            if(microtime(true)>$deadline)throw new RuntimeException('Timed out waiting for suspension release');
            usleep(10000);
        }
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>[
            'content'=>[['type'=>'text','text'=>'completed after suspension']],
            'isError'=>false
        ]];
    }
}

$database=$argv[1];
$owner=$argv[2];
$siteId=$argv[3];
$token=$argv[4];
$nonce=$argv[5];
$signal=$argv[6];
$release=$argv[7];
$now=(int)$argv[8];

$store=fixtureStore($database);
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
$pair=sodium_crypto_sign_keypair();
$core=new Core(
    $store,
    new Vault(str_repeat('k',32)),
    new BlockingCallTransport($signal,$release),
    $policy,
    new Signer(sodium_crypto_sign_secretkey($pair))
);

try{
    $actor=$core->authenticate($token,$now);
    $result=$core->call($actor,$siteId,'operation_apply',['id'=>501],$nonce,$now);
    echo 'success:'.json_encode($result);
}catch(\WPAICloudGateway\Fault $e){
    echo 'fault:'.$e->reason;
}catch(\Throwable $e){
    echo 'exception:'.get_class($e).':'.$e->getMessage();
}
