<?php
declare(strict_types=1);
/** Actual Core/Store/Vault/Gateway with synthetic Native replies; no network. */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Store,Vault,Transport,Gateway,Security,BatchAccounting};
$checks=0;
function viewCheck(bool $ok,string $why):void{++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($why);}
function viewReply(Gateway $gateway,array $actor,string $name,array $args,int $now):array{
    return $gateway->handle($actor,['jsonrpc'=>'2.0','id'=>7,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],$now);
}
function viewValue(array $reply):array{return json_decode($reply['result']['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);}
function viewClean(array $value):void{
    $json=Security::canonical($value);
    foreach(['private-tenant','PrivateTenant','private-server','legacy-mount'] as $private)viewCheck(!str_contains($json,$private),'Cached metadata disclosed '.$private);
}
$database=tempnam(sys_get_temp_dir(),'wpai-projection-');
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix='view_'.bin2hex(random_bytes(5)).'_';$store=new Store($pdo,$prefix);$store->migrate();
$fixture=['file'=>'/legacy-mount/private-tenant/image.png','windows'=>'C:\\PrivateTenant\\image.png','unc'=>'\\\\private-server\\share\\image.png',
    'file_uri'=>'file:///legacy-mount/private-tenant/image.png','malformed'=>['proof'=>'{"file":"\\/legacy-mount\\/private-tenant"'],
    'path_keys'=>['/srv/private-tenant/a'=>1,'/srv/private-tenant/b'=>2,'[server-path]:'.substr(hash('sha256','/srv/private-tenant/a'),0,12)=>3],
    'proof'=>['hash'=>str_repeat('e',64),'payload_hash'=>str_repeat('f',64),'verified'=>true,'phase'=>'after'],
    'serialized'=>'{"file":"/srv/private-tenant/image.png","empty":{},"list":[],"number":1.0}',
    'url'=>'https://public.example.com/srv/images/a.png','relative'=>'includes/file.php','route'=>'/wp-json/api/v1'];
$transport=new class($fixture) implements Transport {
    public int $calls=0;public bool $updated=false;public array $replies=[];private FakeTransport $fake;
    public function __construct(public array $fixture){$this->fake=new FakeTransport();}
    public function rpc(string $home,string $token,array $request):array{
        if($request['method']==='tools/list'){
            $reply=$this->fake->rpc($home,$token,$request);
            foreach(['wp.get_site','file.read','wp.read_content','custom_content','media.upload_sandbox'] as $name)$reply['result']['tools'][]=['name'=>$name,'inputSchema'=>['type'=>'object']];
            return $reply;
        }
        if(($request['params']['name']??'')==='system_manifest')return $this->fake->rpc($home,$token,$request);
        ++$this->calls;$tool=str_replace('.','_',$request['params']['name']);$args=(array)$request['params']['arguments'];
        if(!empty($args['uncertain']))throw new WPAICloudGateway\Fault('upstream_outcome_unknown',502);
        if(!empty($args['fail']))$reply=['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32009,'message'=>'Cannot read /legacy-mount/private-tenant/image.png']];
        elseif(in_array($tool,['file_read','wp_read_content','custom_content'],true)){
            $text="<?php // literal /srv/private-tenant/source.php C:\\PrivateTenant\\source.php\n";
            $raw=['content'=>$text,'sha256'=>hash('sha256',$text)];
            $reply=['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>Security::canonical($raw)]],'isError'=>false]];
        }else{
            $payload=$this->fixture;
            if($this->updated)$payload=['file'=>'[server-path]','proof'=>$payload['proof']];
            $reply=['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode($payload,JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$payload,'isError'=>false]];
        }
        $this->replies[$request['id']]=$reply;return $reply;
    }
};
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);$policy['plans']['free']=50;$policy['batch']=BatchAccounting::POLICY;
$vault=new Vault(str_repeat('v',32));$core=new Core($store,$vault,$transport,$policy);$gateway=new Gateway($core);$now=strtotime('2026-10-23T12:00:00Z');
try{
    $core->ensureAccount('owner-a');$core->ensureAccount('owner-b');
    $site=$core->connect('owner-a','https://view-a.example.com','synthetic-credential-owner-a',$now);
    $unselected=$core->connect('owner-a','https://view-unselected.example.com','synthetic-credential-unselected',$now);
    $other=$core->connect('owner-b','https://view-b.example.com','synthetic-credential-owner-b',$now);
    $actor=$core->authenticate($core->mint('owner-a',[$site['id'], $unselected['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
    $selected=$core->authenticate($core->mint('owner-a',[$site['id']],['mcp:read'],$now)['access_token'],$now);
    $foreign=$core->authenticate($core->mint('owner-b',[$other['id']],['mcp:read'],$now)['access_token'],$now);
    $args=['site_id'=>$site['id'],'tool'=>'wp_get_site','arguments'=>[],'idempotency_key'=>'old-metadata'];
    // Seed through original Core boundary: same unprojected encrypted receipt
    // that a historical deployment already holds; no DB backfill or re-signing.
    $raw=$core->call($actor,$site['id'],'wp_get_site',[],'old-metadata',$now);
    $row=$store->row('SELECT * FROM '.$store->table('requests').' WHERE request_key=?',[$raw['request_key']]);
    $usage=$core->usage('owner-a',$now);$calls=$transport->calls;$transport->updated=true;
    $reply=viewReply($gateway,$actor,'cloud_site_tool_call',$args,$now+1);$view=viewValue($reply);
    viewClean($view);
    viewCheck(!$reply['result']['isError']&&$view['state']==='succeeded'&&$view['charged_units']===1,'Original outcome/charge changed');
    viewCheck($transport->calls===$calls&&$core->usage('owner-a',$now)===$usage,'Replay dispatched or changed quota');
    viewCheck($store->row('SELECT * FROM '.$store->table('requests').' WHERE request_key=?',[$raw['request_key']])===$row,'Replay rewrote original encrypted evidence');
    viewCheck(Security::canonical($core->call($actor,$site['id'],'wp_get_site',[],'old-metadata',$now+1))===Security::canonical($raw),'Core canonical replay changed');
    viewCheck($view['_wpai_projection']['hashes_attest']==='original_evidence'&&!$view['_wpai_projection']['canonical_payload_available'],'Projection/hash contract missing');
    foreach(['text','structured'] as $kind){
        $data=$kind==='text'?json_decode($view['upstream']['result']['content'][0]['text'],true):$view['upstream']['result']['structuredContent'];
        viewCheck(Security::canonical($data['proof'])===Security::canonical($fixture['proof']),'Original proof hashes/verified flags changed');
        viewCheck($data['url']===$fixture['url']&&$data['route']===$fixture['route']&&$data['relative']===$fixture['relative'],'URL or relative identifier changed');
        $values=array_values($data['path_keys']);sort($values);viewCheck($values===[1,2,3],'Redacted display-key collisions hid records');
        viewCheck($data['malformed']['proof']==='[invalid serialized metadata]','Malformed proof body exposed');
        $nested=json_decode($data['serialized']);
        viewCheck($nested->empty instanceof stdClass&&$nested->list===[]&&is_float($nested->number),'Nested serialized JSON contract changed');
    }
    viewCheck(Security::canonical(viewValue(viewReply($gateway,$actor,'cloud_site_tool_call',$args,$now+2)))===Security::canonical($view),'Public replay is unstable');
    $transport->updated=false;
    $freshArgs=array_replace($args,['tool'=>'wp.get_site','idempotency_key'=>'fresh-metadata']);
    $fresh=viewValue(viewReply($gateway,$actor,'cloud_site_tool_call',$freshArgs,$now+3));viewClean($fresh);
    viewCheck($fresh['upstream']['result']===$view['upstream']['result'],'Fresh/cached and dotted/underscore metadata views disagree');
    foreach(['file.read','wp.read_content','custom_content'] as $tool){
        $raw=$core->call($actor,$site['id'],$tool,[],'raw-'.$tool,$now+4);
        $out=viewValue(viewReply($gateway,$actor,'cloud_site_tool_call',array_replace($args,['tool'=>$tool,'idempotency_key'=>'raw-'.$tool]),$now+5));
        viewCheck(Security::canonical($out)===Security::canonical($raw),'Raw editable or arbitrary tool content changed: '.$tool);
        $source=json_decode($out['upstream']['result']['content'][0]['text'],true);
        viewCheck($source['sha256']===hash('sha256',$source['content']),'Editable content hash no longer matches bytes');
    }
    $failedArgs=array_replace($args,['tool'=>'file.read','arguments'=>['fail'=>true],'idempotency_key'=>'failed-read']);
    $failedRaw=$core->call($actor,$site['id'],'file.read',['fail'=>true],'failed-read',$now+6);
    $failedBefore=$core->usage('owner-a',$now);$failedCalls=$transport->calls;
    $failedReply=viewReply($gateway,$actor,'cloud_site_tool_call',$failedArgs,$now+7);$failed=viewValue($failedReply);viewClean($failed);
    viewCheck($failedReply['result']['isError']&&$failed['state']==='failed'&&$failed['charged_units']===0&&$failed['upstream']['error']['code']===-32009,'Error code/outcome changed');
    viewCheck($failedBefore===$core->usage('owner-a',$now)&&$failedCalls===$transport->calls,'Failed replay retried or charged');
    // Original per-child digest commits the raw reply, not its outward view.
    $items=[['id'=>'metadata','tool'=>'wp_get_site','arguments'=>[]],['id'=>'raw','tool'=>'file_read','arguments'=>[]]];
    $batch=viewValue(viewReply($gateway,$actor,'cloud_site_batch_call',['site_id'=>$site['id'],'items'=>$items,'idempotency_key'=>'mixed-batch'],$now+8));
    viewClean($batch['results'][0]);
    $rawReply=$transport->replies[$batch['results'][1]['upstream']['id']];
    viewCheck(Security::canonical($batch['results'][1]['upstream'])===Security::canonical($rawReply),'Batch raw content changed');
    foreach($batch['steps'] as $step){
        $output=$batch['results'][$step['step_id']==='metadata'?0:1];
        viewCheck($step['result_sha256']===hash('sha256',Security::canonical($transport->replies[$output['upstream']['id']])),'Original batch digest changed');
    }
    viewCheck($batch['charged_units']===2,'Mixed batch charge changed');
    $batchUsage=$core->usage('owner-a',$now);$batchCalls=$transport->calls;
    $batchReceipt=viewValue(viewReply($gateway,$actor,'cloud_site_batch_receipt',['site_id'=>$site['id'],'request_key'=>$batch['request_key']],$now+9));
    viewCheck($batchReceipt['steps']===$batch['steps']&&$batchUsage===$core->usage('owner-a',$now)&&$batchCalls===$transport->calls,'Batch receipt changed evidence/charges or dispatched');
    $unknownArgs=array_replace($args,['arguments'=>['uncertain'=>true],'idempotency_key'=>'unknown-outcome']);
    $unknownUsage=$core->usage('owner-a',$now);
    $unknown=viewValue(viewReply($gateway,$actor,'cloud_site_tool_call',$unknownArgs,$now+9));
    $unknownCalls=$transport->calls;
    $again=viewValue(viewReply($gateway,$actor,'cloud_site_tool_call',$unknownArgs,$now+9));
    $held=$core->usage('owner-a',$now);
    viewCheck($unknown['error']==='request_outcome_pending_reconciliation'&&$again['error']===$unknown['error'],'Uncertain outcome was treated as completed');
    viewCheck($held['used']===$unknownUsage['used']&&$held['reserved']===$unknownUsage['reserved']+1&&$transport->calls===$unknownCalls,'Uncertain retry replayed or lost its reservation');

    $write=$core->call($actor,$site['id'],'media.upload_sandbox',[],'old-write',$now+10);
    $outside=$core->call($actor,$unselected['id'],'wp_get_site',[],'unselected-cache',$now+10);
    $denials=[[$foreign,$args,'scope_or_site_denied',$now+11],[$selected,array_replace($args,['site_id'=>$unselected['id'],'idempotency_key'=>'unselected-cache']),'scope_or_site_denied',$now+11],
        [$selected,array_replace($args,['tool'=>'media.upload_sandbox','idempotency_key'=>'old-write']),'scope_or_site_denied',$now+11],[$actor,$args,'invalid_or_expired_token',$now+4000]];
    $preCalls=$transport->calls;$preUsage=$core->usage('owner-a',$now);
    $deny=function(array $who,array $input,string $reason,int $at)use($gateway):void{
        $denied=viewReply($gateway,$who,'cloud_site_tool_call',$input,$at);$error=viewValue($denied);
        viewCheck($denied['result']['isError']&&($error['error']??'')===$reason,'Cached replay authorization changed: '.($error['error']??'none'));
        viewClean($error);
    };
    foreach($denials as [$who,$input,$reason,$at])$deny($who,$input,$reason,$at);
    $core->revokeToken('owner-a',$selected['token_hash'],$now+11);
    $deny($selected,$args,'invalid_or_expired_token',$now+12);
    $core->disconnect('owner-a',$unselected['id'],$now+11);
    $deny($actor,array_replace($args,['site_id'=>$unselected['id'],'idempotency_key'=>'unselected-cache']),'site_unavailable',$now+12);
    viewCheck($preCalls===$transport->calls&&$preUsage===$core->usage('owner-a',$now),'Denied replay dispatched or changed quota');
    echo "PASS $checks cached/fresh metadata projection, original sealed evidence/proof/batch digests, editable bytes, replay quota neutrality and authorization fences\n";
}finally{
    if(getenv('TEST_MYSQL_DSN'))foreach(['batch_steps','requests','balances','rate','audit','oauth_access','oauth_refresh','oauth_grants','oauth_codes','oauth_clients','oauth_rate','tokens','sites','accounts'] as $table)$store->execute('DROP TABLE IF EXISTS '.$store->table($table));
    unset($core,$store,$pdo);if(is_file($database))unlink($database);
}
