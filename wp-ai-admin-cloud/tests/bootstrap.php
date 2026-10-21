<?php
declare(strict_types=1);
foreach (['Security','Transport','Store','QuotaPolicy','BatchAccounting','Core','Skills','Unsplash','MediaPolicy','Gateway'] as $name) require_once dirname(__DIR__).'/src/'.$name.'.php';

final class FakeTransport implements \WPAICloudGateway\Transport {
    public int $executions=0;
    public function rpc(string $home,string $token,array $request): array {
        if ($request['method']==='tools/list') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['tools'=>[['name'=>'wp_get_site','inputSchema'=>['type'=>'object']],['name'=>'operation_apply','inputSchema'=>['type'=>'object']],['name'=>'wordpress_execute','inputSchema'=>['type'=>'object']],['name'=>'operation_verify','inputSchema'=>['type'=>'object']],['name'=>'operation_get_receipt','inputSchema'=>['type'=>'object']],['name'=>'operation_rollback','inputSchema'=>['type'=>'object']],['name'=>'batch_rollback','inputSchema'=>['type'=>'object']]]]];
        if (($request['params']['name']??'')==='system_manifest') return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['plugin_name'=>'WP AI Admin','site_id'=>'plugin-'.hash('sha256',$home),'installation_id'=>'inst-'.hash('sha256',$home),'release'=>['production_defaults'=>['direct_live_writes'=>false]]])]]]];
        $this->executions++;
        if (getenv('CLOUD_TEST_EXECUTION_LOG')) file_put_contents(getenv('CLOUD_TEST_EXECUTION_LOG'),"executed\n",FILE_APPEND|LOCK_EX);
        $args=(array)($request['params']['arguments']??[]);
        if (!empty($args['uncertain'])) throw new \WPAICloudGateway\Fault('upstream_outcome_unknown',502);
        return ['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>'fixture result']],'isError'=>!empty($args['fail'])]];
    }
}
function fixtureStore(string $database): \WPAICloudGateway\Store {
    $dsn=getenv('TEST_MYSQL_DSN') ?: 'sqlite:'.$database;
    $pdo=new PDO($dsn,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null);
    return new \WPAICloudGateway\Store($pdo,'cloud_test_');
}
function fixtureCore(\WPAICloudGateway\Store $store,FakeTransport $transport,int $limit=100,?string $key=null): \WPAICloudGateway\Core {
    $policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
    $policy['plans']['free']=$limit;
    $pair=sodium_crypto_sign_keypair();
    return new \WPAICloudGateway\Core($store,new \WPAICloudGateway\Vault($key??str_repeat('k',32)),$transport,$policy,new \WPAICloudGateway\Signer(sodium_crypto_sign_secretkey($pair)));
}
