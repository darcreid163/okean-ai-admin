<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require dirname(__DIR__).'/src/OAuth.php';
$store=fixtureStore($argv[1]);$core=fixtureCore($store,new FakeTransport());
$oauth=new WPAICloudGateway\OAuth($store,$core,'https://mcp.wpaiadmin.ru/mcp');
try {$oauth->exchange(json_decode(getenv('CLOUD_OAUTH_EXCHANGE'),true),(int)$argv[2],fn($id)=>$id==='1');echo 'success';}
catch(WPAICloudGateway\Fault $e){if($e->reason!=='invalid_grant')throw $e;echo 'invalid_grant';}
