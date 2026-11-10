<?php
declare(strict_types=1);
/**
 * One real parallel billing caller for #136. Uses its own PDO connection so the
 * reservation/limit race is decided by the database, not by interleaved PHP.
 */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Signer,Fault};

$raw=base64_decode((string)($argv[1]??''),true);
if(!is_string($raw))throw new RuntimeException('Invalid worker config');
$config=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
$key=(string)($argv[2]??'');
$pdo=new PDO($config['dsn'],$config['user']?:null,$config['pass']?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new Store($pdo,$config['prefix']);
$core=new Core($store,new Vault($config['vault']),new FakeTransport(),$config['policy'],new Signer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
try {
    $actor=$core->authenticate($config['token'],$config['now']);
    $receipt=$core->call($actor,$config['site'],'wp_get_site',[],$key,$config['now']);
    echo json_encode(['ok'=>true,'charged'=>$receipt['charged_units']]);
} catch (Fault $e) {
    echo json_encode(['ok'=>false,'fault'=>$e->reason]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'fault'=>'exception','message'=>$e->getMessage()]);
}
