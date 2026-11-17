<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$database=$argv[1]??'';
$session=$argv[2]??'';
$start=(int)($argv[3]??0);
$count=(int)($argv[4]??0);
$store=fixtureStore($database);
for($i=0;$i<$count;$i++) {
    $id=$start+$i;
    $payload=json_encode(['message_id'=>$id],JSON_THROW_ON_ERROR);
    $deadline=microtime(true)+20;
    while(true) {
        try {
            $now=time();
            $store->enqueueSseMessage($session,$payload,$now,$now+3600,32);
            break;
        } catch(\\WPAICloudGateway\\Fault $e) {
            if($e->reason!=='sse_queue_full'||microtime(true)>$deadline) throw $e;
            usleep(1000);
        }
    }
}
echo 'ok';
