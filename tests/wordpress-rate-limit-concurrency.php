<?php
// Run only on disposable WordPress CI via wp-cli eval-file.
if (!defined('WP_CLI') || !WP_CLI || !defined('OAI_DB_VERSION') || OAI_DB_VERSION!==25) {
    throw new RuntimeException('Disposable WP-CLI and Native DB schema 25 required');
}
global $wpdb;
function rateLimitAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }

// Simulate an existing v24 site that does not yet have the new counter table.
$operationId='rate-limit-upgrade-'.wp_generate_uuid4();
$inserted=$wpdb->insert(OAI_DB::table(),[
    'operation_id'=>$operationId,'status'=>'pending','type'=>'content_patch',
    'target'=>'{"post_id":0}','created_at'=>gmdate('Y-m-d H:i:s')
]);
rateLimitAssert($inserted===1,'Cannot create operation migration fixture');
$table=OAI_DB::rate_limits_table();
$wpdb->query('DROP TABLE IF EXISTS `'.$table.'`');
rateLimitAssert($wpdb->last_error==='','Cannot reset disposable rate counter table');
update_option('oai_db_version',24,false);
OAI_DB::maybe_upgrade();
rateLimitAssert((int)get_option('oai_db_version')===25,'Schema did not upgrade from v24 to v25');
$found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
rateLimitAssert($found===$table,'Rate counter table missing after v24 -> v25 upgrade');
rateLimitAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.OAI_DB::table().' WHERE operation_id=%s',$operationId))===1,'Existing operation was lost during rate counter migration');
OAI_DB::maybe_upgrade();
rateLimitAssert((int)get_option('oai_db_version')===25,'Repeated v25 migration changed schema version');

// Run many independent PHP/WordPress processes against the same key/window.
$bucket='rate-limit-concurrency-'.bin2hex(random_bytes(12));
$worker=__DIR__.'/wordpress-rate-limit-worker.php';
$processes=[];
for($i=0;$i<24;$i++){
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg(ABSPATH).' '.escapeshellarg($bucket);
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc)) throw new RuntimeException('Cannot start rate-limit worker '.$i);
    fclose($pipes[0]);
    $processes[]=[$proc,$pipes];
}
$allowed=0; $blocked=0; $errors=[];
foreach($processes as [$proc,$pipes]){
    $stdout=trim(stream_get_contents($pipes[1]));
    $stderr=trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $exit=proc_close($proc);
    if($exit!==0){$errors[]='worker exit='.$exit.' stdout='.$stdout.' stderr='.$stderr;continue;}
    if($stdout==='ALLOW')$allowed++;
    elseif($stdout==='BLOCK')$blocked++;
    else $errors[]='unexpected worker result: '.$stdout;
}
rateLimitAssert(!$errors,'Rate-limit worker failures: '.implode('; ',$errors));
rateLimitAssert($allowed===10,'Expected exactly 10 admitted requests, got '.$allowed);
rateLimitAssert($blocked===14,'Expected 14 rejected requests, got '.$blocked);

// A different bucket, client, or fixed window must have an independent counter.
$client=hash('sha256',wp_salt('auth').'|198.51.100.77');
$bucketHash=hash('sha256',$bucket);
$window=(int)(floor(time()/60)*60);
rateLimitAssert(OAI_DB::rate_limit_admit(hash('sha256',$bucket.'-other-bucket'),$client,$window,10)===true,'Different bucket shared a counter');
rateLimitAssert(OAI_DB::rate_limit_admit($bucketHash,hash('sha256','different-client'),$window,10)===true,'Different client shared a counter');
rateLimitAssert(OAI_DB::rate_limit_admit($bucketHash,$client,$window+60,10)===true,'Different fixed window shared a counter');
rateLimitAssert(OAI_DB::rate_limit_admit($bucketHash,$client,$window,10)===false,'Counter admitted more requests after reaching its threshold');

// Expired records are pruned; recent/current-window records remain.
$staleBucket=hash('sha256','stale-rate-limit-fixture');
$wpdb->insert($table,[
    'bucket_hash'=>$staleBucket,'client_hash'=>$client,'window_start'=>$window-172800,
    'hits'=>1,'updated_at'=>gmdate('Y-m-d H:i:s',time()-259200)
]);
update_option('oai_rate_limits_pruned_at',0,false);
OAI_DB::prune_rate_limits();
$stale=$wpdb->get_var($wpdb->prepare("SELECT hits FROM $table WHERE bucket_hash=%s AND client_hash=%s AND window_start=%d",$staleBucket,$client,$window-172800));
rateLimitAssert($stale===null,'Expired rate counter was not pruned');

echo "PASS: v24->v25 migration is additive and idempotent; 24 concurrent workers admitted exactly 10 and rejected 14; bucket/client/window isolation and cleanup verified\n";
