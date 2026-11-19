<?php
// Disposable WordPress/MySQL acceptance for Native OAuth registration and retention.
if (!defined('WP_CLI') || !WP_CLI || !class_exists('OAI_MCP_OAuth')) {
    throw new RuntimeException('Run through WP-CLI on the disposable Native smoke site');
}
global $wpdb;
function oauthRegAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function oauthRegRequest(array $payload,string $ip) {
    $_SERVER['REMOTE_ADDR']=$ip;
    $request=new WP_REST_Request('POST','/okean-ai/v1/oauth/register');
    $request->set_header('content-type','application/json');
    $request->set_body(wp_json_encode($payload));
    return OAI_MCP_OAuth::register_client($request);
}
function oauthRegStatus($response): int { return $response instanceof WP_REST_Response ? (int)$response->get_status() : 0; }

// Metadata is bounded and rejects non-HTTPS public redirects.
$base=['client_name'=>'OAuth fixture','redirect_uris'=>['https://example.com/callback']];
$bad=[
    [['client_name'=>'Too many','redirect_uris'=>array_fill(0,6,'https://example.com/callback')],'198.51.100.11'],
    [['client_name'=>str_repeat('N',101),'redirect_uris'=>['https://example.com/callback']],'198.51.100.12'],
    [['client_name'=>'Long URI','redirect_uris'=>['https://example.com/'.str_repeat('a',2050)]],'198.51.100.13'],
    [['client_name'=>'FTP','redirect_uris'=>['ftp://example.com/callback']],'198.51.100.14'],
    [['client_name'=>'Credentials','redirect_uris'=>['https://user:pass@example.com/callback']],'198.51.100.15'],
    [['client_name'=>'Fragment','redirect_uris'=>['https://example.com/callback#fragment']],'198.51.100.16'],
];
foreach($bad as [$payload,$ip]) oauthRegAssert(oauthRegStatus(oauthRegRequest($payload,$ip))===400,'Invalid OAuth client metadata was accepted: '.$payload['client_name']);

// The existing per-IP limit remains active (20/minute).
$rateIp='198.51.100.30';
for($i=0;$i<20;$i++) oauthRegAssert(oauthRegStatus(oauthRegRequest(['client_name'=>'Rate fixture','redirect_uris'=>[]],$rateIp))===400,'Unexpected response before OAuth registration rate threshold');
$rateResponse=oauthRegRequest(['client_name'=>'Rate fixture','redirect_uris'=>[]],$rateIp);
oauthRegAssert(oauthRegStatus($rateResponse)===429,'OAuth registration per-IP rate limit was not enforced');

// Verify retention rules. Expired codes and fully expired credentials are pruned;
// a live refresh token and a revoked row inside its retention window survive.
$codes=OAI_MCP_OAuth::codes_table();
$tokens=OAI_MCP_OAuth::tokens_table();
$now=time();
$clientFixture='oauth-retention-fixture';
$codeRows=[
 ['old-expired-code',0,gmdate('Y-m-d H:i:s',$now-172800),gmdate('Y-m-d H:i:s',$now-172500)],
 ['old-used-code',1,gmdate('Y-m-d H:i:s',$now-172800),gmdate('Y-m-d H:i:s',$now-172500)],
 ['fresh-code',0,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+300)],
];
foreach($codeRows as [$label,$used,$created,$expires]){
 $hash=hash('sha256',$label);
 $wpdb->insert($codes,['code_hash'=>$hash,'client_id'=>$clientFixture,'user_id'=>1,'redirect_uri'=>'https://example.com/callback','scope'=>'mcp:read','resource'=>rest_url('okean-ai/mcp'),'code_challenge'=>str_repeat('a',43),'created_at'=>$created,'expires_at'=>$expires,'used'=>$used]);
 oauthRegAssert($wpdb->last_error==='','Could not insert OAuth code retention fixture');
}
$tokenRows=[
 ['old-refresh-expired',0,gmdate('Y-m-d H:i:s',$now-2678400),gmdate('Y-m-d H:i:s',$now-2678400)],
 ['old-no-refresh',0,gmdate('Y-m-d H:i:s',$now-2678400),null],
 ['live-refresh',0,gmdate('Y-m-d H:i:s',$now-7200),gmdate('Y-m-d H:i:s',$now+864000)],
 ['revoked-refresh-retained',1,gmdate('Y-m-d H:i:s',$now-7200),gmdate('Y-m-d H:i:s',$now+864000)],
];
foreach($tokenRows as [$label,$revoked,$expires,$refreshExpires]){
 $wpdb->insert($tokens,['access_hash'=>hash('sha256','access-'.$label),'refresh_hash'=>$refreshExpires===null?null:hash('sha256','refresh-'.$label),'client_id'=>$clientFixture,'user_id'=>1,'scope'=>'mcp:read','resource'=>rest_url('okean-ai/mcp'),'created_at'=>gmdate('Y-m-d H:i:s',$now-2678400),'expires_at'=>$expires,'refresh_expires_at'=>$refreshExpires,'revoked'=>$revoked]);
 oauthRegAssert($wpdb->last_error==='','Could not insert OAuth token retention fixture');
}
update_option('oai_oauth_pruned_at',0,false);
$prune=new ReflectionMethod('OAI_MCP_OAuth','prune_credentials');
$prune->setAccessible(true);
$prune->invoke(null);
$codeCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$codes.' WHERE code_hash=%s',hash('sha256','old-expired-code')));
$usedCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$codes.' WHERE code_hash=%s',hash('sha256','old-used-code')));
$freshCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$codes.' WHERE code_hash=%s',hash('sha256','fresh-code')));
oauthRegAssert($codeCount===0 && $usedCount===0 && $freshCount===1,'OAuth authorization-code retention policy mismatch');
$expiredRefresh=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tokens.' WHERE access_hash=%s',hash('sha256','access-old-refresh-expired')));
$noRefresh=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tokens.' WHERE access_hash=%s',hash('sha256','access-old-no-refresh')));
$liveRefresh=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tokens.' WHERE access_hash=%s',hash('sha256','access-live-refresh')));
$revokedRetained=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$tokens.' WHERE access_hash=%s',hash('sha256','access-revoked-refresh-retained')));
oauthRegAssert($expiredRefresh===0 && $noRefresh===0 && $liveRefresh===1 && $revokedRetained===1,'OAuth token retention removed active refresh credentials or retained expired rows');
$wpdb->query($wpdb->prepare('DELETE FROM '.$codes.' WHERE client_id=%s',$clientFixture));
$wpdb->query($wpdb->prepare('DELETE FROM '.$tokens.' WHERE client_id=%s',$clientFixture));

// Fill the registry to one slot below the ceiling, then race 24 distinct clients.
$clients=OAI_MCP_OAuth::clients_table();
$initial=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$clients);
oauthRegAssert($initial<=OAI_MCP_OAuth::CLIENT_LIMIT-1,'OAuth registry already at or above capacity before fixture');
$fixturePrefix='oauth_cap_fixture_'.bin2hex(random_bytes(6)).'_';
$needed=(OAI_MCP_OAuth::CLIENT_LIMIT-1)-$initial;
for($offset=0;$offset<$needed;$offset+=250){
 $values=[];
 $end=min($needed,$offset+250);
 for($i=$offset;$i<$end;$i++){
  $id=$fixturePrefix.str_pad((string)$i,5,'0',STR_PAD_LEFT);
  $values[]=$wpdb->prepare('(%s,%s,%s,UTC_TIMESTAMP())',$id,'Capacity fixture','["https://example.com/callback"]');
 }
 if($wpdb->query('INSERT INTO '.$clients.' (client_id,client_name,redirect_uris,created_at) VALUES '.implode(',',$values))===false) throw new RuntimeException('Could not seed OAuth capacity fixtures: '.$wpdb->last_error);
}
$workspace=(string)getenv('GITHUB_WORKSPACE');
$worker=rtrim($workspace,'/\\\\').'/tests/wordpress-oauth-register-worker.php';
oauthRegAssert($workspace!=='' && is_file($worker),'Cannot locate OAuth registration worker');
$processes=[];
for($i=1;$i<=24;$i++){
 $ip='198.51.100.'.$i;
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg(ABSPATH).' '.escapeshellarg($ip);
 $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc)) throw new RuntimeException('Cannot start OAuth registration worker '.$i);
 fclose($pipes[0]);
 $processes[]=[$proc,$pipes];
}
$created=[];$full=0;$errors=[];
foreach($processes as [$proc,$pipes]){
 $stdout=trim(stream_get_contents($pipes[1]));$stderr=trim(stream_get_contents($pipes[2]));
 fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
 if($exit!==0){$errors[]='exit='.$exit.' output='.$stdout.' stderr='.$stderr;continue;}
 if(str_starts_with($stdout,'CREATED:'))$created[]=substr($stdout,8);
 elseif($stdout==='FULL')$full++;
 else $errors[]='unexpected result='.$stdout;
}
try {
 oauthRegAssert(!$errors,'OAuth registration worker errors: '.implode('; ',$errors));
 oauthRegAssert(count($created)===1 && $full===23,'Concurrent client cap result mismatch: created='.count($created).' full='.$full);
 oauthRegAssert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.$clients)===OAI_MCP_OAuth::CLIENT_LIMIT,'OAuth client cap was exceeded or underfilled');
} finally {
 $wpdb->query($wpdb->prepare('DELETE FROM '.$clients.' WHERE client_id LIKE %s',$wpdb->esc_like($fixturePrefix).'%'));
 foreach($created as $id)$wpdb->delete($clients,['client_id'=>$id],['%s']);
}
oauthRegAssert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.$clients)===$initial,'OAuth capacity fixtures were not fully cleaned up');
echo "PASS: OAuth metadata bounds, per-IP throttle, code/token retention, and concurrent 10,000-client cap\n";
