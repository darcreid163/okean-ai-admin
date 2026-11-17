<?php
// WP-CLI eval-file integration test; runs independent PHP workers against disposable MySQL.
if (!defined('WP_CLI') || !WP_CLI || !defined('OAI_DB_VERSION') || OAI_DB_VERSION!==25) {
    throw new RuntimeException('Disposable WP-CLI and Native DB schema 25 required');
}
global $wpdb;
function rateLimitAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }

$table=OAI_DB::rate_limits_table();
$bucket='atomic-concurrency-'.bin2hex(random_bytes(12));
$limit=3;
$workers=8;
$window=600;
$ip='198.51.100.77';
$bootstrap=ABSPATH.'wp-load.php';
$workerCode='require '.var_export($bootstrap,true).'; $_SERVER["REMOTE_ADDR"]='.var_export($ip,true).'; $result=OAI_Rate_Limit::check('.var_export($bucket,true).','.$limit.','.$window.'); if($result===true){echo "allowed";exit(0);} if(is_wp_error($result)&&$result->get_error_code()==="oai_rate_limited"){echo "limited";exit(0);} echo "storage-error";exit(2);';
$command=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($workerCode);
$processes=[];
for($i=0;$i<$workers;$i++) {
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('Unable to start rate-limit worker '.$i);
    fclose($pipes[0]);
    $processes[]=[$process,$pipes];
}
$results=[];
foreach($processes as [$process,$pipes]) {
    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    if($exit!==0) throw new RuntimeException('Rate-limit worker failed: '.$stdout.' '.$stderr);
    $results[]=trim($stdout);
}
$allowed=count(array_filter($results,static fn($value)=>$value==='allowed'));
$limited=count(array_filter($results,static fn($value)=>$value==='limited'));
rateLimitAssert($allowed===$limit&&$limited===($workers-$limit),'Concurrent requests bypassed the exact limit: '.json_encode($results));

$_SERVER['REMOTE_ADDR']=$ip;
$blocked=OAI_Rate_Limit::check($bucket,$limit,$window);
rateLimitAssert(is_wp_error($blocked)&&$blocked->get_error_code()==='oai_rate_limited','Over-limit request was not rejected');
$data=$blocked->get_error_data('oai_rate_limited');
$retry=(int)($data['retry_after']??0);
rateLimitAssert($retry>=1&&$retry<=$window,'retry_after is outside the current window remainder');

$_SERVER['REMOTE_ADDR']='198.51.100.78';
rateLimitAssert(OAI_Rate_Limit::check($bucket,$limit,$window)===true,'Different client IP shared the first client counter');
$_SERVER['REMOTE_ADDR']=$ip;
rateLimitAssert(OAI_Rate_Limit::check($bucket.'-other',$limit,$window)===true,'Different rate bucket shared the existing counter');

// Storage errors must fail closed rather than silently disabling protection.
$wpdb->query('DROP TABLE IF EXISTS `'.$table.'`');
rateLimitAssert($wpdb->last_error==='','Unable to prepare storage-failure fixture');
$failure=OAI_Rate_Limit::check('storage-failure-'.bin2hex(random_bytes(6)),10,60);
rateLimitAssert(is_wp_error($failure)&&$failure->get_error_code()==='oai_rate_limit_storage','Unavailable rate-limit storage did not fail closed');
OAI_DB::install();
$restored=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
rateLimitAssert($restored===$table,'Rate-limit table was not restored after storage failure fixture');

echo 'PASS: atomic Native rate limit allowed '.$allowed.'/'.$workers.' concurrent requests; rejected '.$limited.'; IP/bucket isolation, retry_after and fail-closed storage verified'.PHP_EOL;
