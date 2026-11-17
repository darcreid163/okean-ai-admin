<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\Fault;
function sseCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$database=sys_get_temp_dir().'/wpai-sse-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database);
$store->migrate();
$session=bin2hex(random_bytes(16));
$store->createSseSession($session,time()+3600);
$workers=[];
$worker=__DIR__.'/sse-queue-worker.php';
for($i=0;$i<4;$i++) {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg($database).' '.escapeshellarg($session).' '.($i*25).' 25';
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('Could not start SSE queue worker');
    fclose($pipes[0]);
    $pid=(int)proc_get_status($process)['pid'];
    $workers[]=[$process,$pipes,$pid];
}
$received=[];
$running=true;
$exitCodes=[];
while($running) {
    foreach($store->drainSseQueue($session,time()) as $payload) {
        $item=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
        $received[]=(int)$item['message_id'];
    }
    $running=false;
    foreach($workers as [$process,$pipes,$pid]) {
        $status=proc_get_status($process);
        if($status['running']) $running=true;
        elseif(!array_key_exists($pid,$exitCodes)) $exitCodes[$pid]=$status['exitcode'];
    }
    if($running) usleep(1000);
}
foreach($workers as [$process,$pipes,$pid]) {
    $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($process);
    $exit=$exitCodes[$pid]??-1;
    sseCheck($exit===0&&$stdout==='ok','SSE queue worker failed: '.$stderr);
}
foreach($store->drainSseQueue($session,time()) as $payload) {
    $item=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
    $received[]=(int)$item['message_id'];
}
sort($received);
sseCheck(count($received)===100,'SSE queue lost or duplicated messages: '.count($received).' received');
sseCheck($received===range(0,99),'SSE queue messages are missing or duplicated');
$reservation=$store->reserveSseMessage($session,time(),time()+3600,32);
sseCheck($store->drainSseQueue($session,time())===[],'Uncompleted SSE reservation became visible to the stream');
$reservedPayload=json_encode(['reservation'=>'completed'],JSON_THROW_ON_ERROR);
$store->completeSseMessage($session,$reservation,$reservedPayload,time(),time()+3600);
sseCheck($store->drainSseQueue($session,time())===[$reservedPayload],'Completed SSE reservation was not published exactly once');
$overflow=false;
for($i=0;$i<32;$i++) $store->enqueueSseMessage($session,json_encode(['cap'=>$i],JSON_THROW_ON_ERROR),time(),time()+3600,32);
try { $store->enqueueSseMessage($session,'{"cap":32}',time(),time()+3600,32); }
catch(Fault $e) { $overflow=$e->reason==='sse_queue_full'; }
sseCheck($overflow,'SSE queue limit was not enforced');
sseCheck(count($store->drainSseQueue($session,time()))===32,'SSE queue cap drain returned an unexpected count');
$store->deleteSseSession($session);
echo "PASS: 100 concurrent SSE appends drained without loss or duplication; queue cap enforced\n";
