<?php
// Deterministically weak WordPress randomness must not shrink operation IDs.
define('ABSPATH',__DIR__.'/');
function wp_rand($min=0,$max=0){return 1000;}
require getenv('OAI_ID_TEST_IMPLEMENTATION')?:dirname(__DIR__).'/includes/class-oai-operations.php';
if(($argv[1]??'')==='worker'){$ids=array();for($i=0;$i<128;$i++)$ids[]=OAI_Operations::new_id();echo json_encode($ids);exit;}
$all=array();$workers=array();
for($i=0;$i<8;$i++){$p=proc_open(array(PHP_BINARY,'-n',__FILE__,'worker'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);if(!is_resource($p))throw new RuntimeException('ID worker failed');fclose($pipes[0]);$workers[]=array($p,$pipes);}
foreach($workers as $worker){$text=stream_get_contents($worker[1][1]);$error=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);if(proc_close($worker[0])!==0)throw new RuntimeException('ID worker failed');$ids=json_decode($text,true);if(!is_array($ids))throw new RuntimeException('ID worker returned invalid JSON');$all=array_merge($all,$ids);}
if(count($all)!==1024||count(array_unique($all))!==1024)throw new RuntimeException('Duplicate operation identifiers under concurrent generation');
foreach($all as $id)if(strpos($id,'OAI-')!==0||strlen($id)>64)throw new RuntimeException('Operation identifier exceeds existing schema or namespace');
if(strpos(OAI_Operations::new_id('ws'),'ws-')!==0)throw new RuntimeException('Workspace namespace not preserved');
try{OAI_Operations::new_id('../');throw new RuntimeException('Unsafe namespace accepted');}catch(InvalidArgumentException $e){}
echo "PASS 1024 unique operation IDs across 8 workers with fixed wp_rand; existing varchar(64) and workspace namespace preserved\n";
