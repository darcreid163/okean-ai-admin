<?php
declare(strict_types=1);
/**
 * Tier A gateway skeleton: closed skills, server-side Unsplash, media URL policy
 * and the MCP surface that exposes them.
 *
 * Runs on SQLite without MySQL and without any network call.
 */
require __DIR__.'/bootstrap.php';

use WPAICloudGateway\Fault;
use WPAICloudGateway\Gateway;
use WPAICloudGateway\MediaPolicy;
use WPAICloudGateway\Skills;
use WPAICloudGateway\Unsplash;

$checks=0;
function check(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $reason): void {
    try { $fn(); } catch (Fault $e) { check($e->reason===$reason,'Expected '.$reason.', got '.$e->reason); return; }
    throw new RuntimeException('Expected rejection: '.$reason);
}

/* ---------------------------------------------------------------- skills --- */

$library=Skills::fromConfig(dirname(__DIR__).'/config/skills.php');
$listing=$library->listing();
check(count($listing)>=6,'Expected at least six shipped skills');
foreach ($listing as $row) {
    check(is_string($row['skill_id']) && preg_match('/^[a-z0-9-]+$/',$row['skill_id'])===1,'Skill id shape');
    check($row['tool_count']>0,'Skill must reference at least one tool');
}
$content=$library->load('content-edit');
check($content['skill_id']==='content-edit','Loaded wrong skill');
check(count($content['steps'])>=4,'Skill needs ordered steps');
check(!empty($content['guardrails']),'Skill needs guardrails');
check(str_contains($content['note'],'approval'),'Skill must state that approval is still required');

rejects(fn()=>$library->load('missing-skill'),'skill_not_found');
rejects(fn()=>$library->load('../etc/passwd'),'skill_id_invalid');
rejects(fn()=>$library->load(str_repeat('a',40)),'skill_id_invalid');

rejects(fn()=>Skills::fromArray([]),'skill_library_invalid');
rejects(fn()=>Skills::fromArray(['Bad Id'=>['title'=>'t','summary'=>'s','when'=>'w','steps'=>['x'],'tools'=>[],'guardrails'=>[]]]),'skill_id_invalid');
rejects(fn()=>Skills::fromArray(['ok'=>['title'=>'t','summary'=>'s','when'=>'w','steps'=>[],'tools'=>[],'guardrails'=>[]]]),'skill_steps_invalid');
rejects(fn()=>Skills::fromArray(['ok'=>['title'=>'t','summary'=>'s','when'=>'w','steps'=>['x'],'tools'=>['Bad Tool'],'guardrails'=>[]]]),'skill_tools_invalid');
rejects(fn()=>Skills::fromArray(['ok'=>['title'=>'t','summary'=>'s','when'=>'w','steps'=>array_fill(0,41,'x'),'tools'=>[],'guardrails'=>[]]]),'skill_steps_invalid');

/* ---------------------------------------------------------------- media --- */

check(MediaPolicy::importUrl('https://cdn.example.com/img/photo.jpg')==='https://cdn.example.com/img/photo.jpg','Valid URL normalized');
check(MediaPolicy::importUrl('https://cdn.example.com/a.png?w=1200&q=80')==='https://cdn.example.com/a.png?w=1200&q=80','Query preserved');
check(MediaPolicy::extensionAllowed('https://cdn.example.com/a.webp'),'WebP extension allowed');
check(!MediaPolicy::extensionAllowed('https://cdn.example.com/a.php'),'PHP extension refused');

rejects(fn()=>MediaPolicy::importUrl('http://cdn.example.com/a.jpg'),'media_url_insecure');
rejects(fn()=>MediaPolicy::importUrl('https://user:pass@cdn.example.com/a.jpg'),'media_url_credentials_not_allowed');
rejects(fn()=>MediaPolicy::importUrl('https://cdn.example.com:8443/a.jpg'),'media_url_port_not_allowed');
rejects(fn()=>MediaPolicy::importUrl('https://localhost/a.jpg'),'media_url_host_invalid');
rejects(fn()=>MediaPolicy::importUrl('https://127.0.0.1/a.jpg'),'media_url_ip_literal_blocked');
rejects(fn()=>MediaPolicy::importUrl('https://[::1]/a.jpg'),'media_url_ip_literal_blocked');
rejects(fn()=>MediaPolicy::importUrl('https://intranet/a.jpg'),'media_url_host_invalid');
rejects(fn()=>MediaPolicy::importUrl('https://box.local/a.jpg'),'media_url_host_blocked');
rejects(fn()=>MediaPolicy::importUrl('https://metadata.google.internal/a.jpg'),'media_url_host_blocked');
rejects(fn()=>MediaPolicy::importUrl('https://cdn.example.com/'),'media_url_path_missing');

/* ------------------------------------------------------------- unsplash --- */

$calls=[];
$response=json_encode(['results'=>[
    ['id'=>'abc','description'=>'Sea','width'=>1200,'height'=>800,
     'urls'=>['regular'=>'https://images.unsplash.com/photo-1?w=1080','small'=>'https://images.unsplash.com/photo-1?w=400'],
     'user'=>['name'=>'Ivan','links'=>['html'=>'https://unsplash.com/@ivan']],'links'=>['html'=>'https://unsplash.com/photos/abc']],
    ['id'=>'bad','urls'=>['regular'=>'https://evil.example.com/x.jpg']],
]]);
$unsplash=new Unsplash(str_repeat('k',32),function(string $url) use (&$calls,$response): array {
    $calls[]=$url;
    return ['status'=>200,'body'=>$response];
});
$found=$unsplash->search('  sea   sunset  ',5);
check(count($calls)===1,'Exactly one upstream call');
check(str_contains($calls[0],'query=sea%20%20%20sunset'),'Query is trimmed and encoded');
check(str_contains($calls[0],'per_page=5'),'per_page forwarded');
check($found['count']===1,'Off-host result must be dropped');
check($found['results'][0]['photographer']==='Ivan','Attribution captured');
check(str_contains($found['attribution'],'Unsplash'),'Attribution notice present');
check(Unsplash::imageUrl('https://images.unsplash.com/photo-1'),'Unsplash image host accepted');
check(!Unsplash::imageUrl('https://images.unsplash.com.evil.example/x.jpg'),'Lookalike host refused');
check(!Unsplash::imageUrl('http://images.unsplash.com/x.jpg'),'Plain HTTP refused');

$unsplash=new Unsplash(str_repeat('k',32),fn(string $url): array => ['status'=>200,'body'=>$response]);
check(count($unsplash->search('sea',99)['results'])===1,'per_page clamps to the maximum instead of failing');

rejects(fn()=>(new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>200,'body'=>'{}']))->search('   '),'unsplash_query_invalid');
rejects(fn()=>(new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>200,'body'=>'{}']))->search(str_repeat('a',81)),'unsplash_query_invalid');
rejects(fn()=>(new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>500,'body'=>'']))->search('sea'),'unsplash_upstream_failed');
rejects(fn()=>(new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>200,'body'=>'not json']))->search('sea'),'unsplash_response_invalid');
rejects(fn()=>(new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>200,'body'=>str_repeat('x',600000)]))->search('sea'),'unsplash_response_too_large');
rejects(fn()=>new Unsplash('short'),'unsplash_key_invalid');

/* -------------------------------------------------------------- gateway --- */

$tools=array_column(Gateway::tools(),null,'name');
foreach (['cloud_skills_list','cloud_skill_load','cloud_media_search_images'] as $name) {
    check(isset($tools[$name]),'Missing gateway tool '.$name);
    check($tools[$name]['annotations']['readOnlyHint']===true,'Tier A tools must be read-only');
    check($tools[$name]['inputSchema']['additionalProperties']===false,'Tier A schemas must be closed');
}
check(in_array('skill_id',$tools['cloud_skill_load']['inputSchema']['required'],true),'skill_id is required');
check(in_array('query',$tools['cloud_media_search_images']['inputSchema']['required'],true),'query is required');

$database=sys_get_temp_dir().'/wpai-tier-a-'.bin2hex(random_bytes(8)).'.sqlite';
$store=fixtureStore($database); $store->migrate();
$core=fixtureCore($store,new \FakeTransport(),100);
$gateway=new Gateway($core,Skills::fromConfig(dirname(__DIR__).'/config/skills.php'),new Unsplash(str_repeat('k',32),fn(string $u):array=>['status'=>200,'body'=>$response]));
$actor=['owner'=>'1','site_ids'=>[],'scopes'=>['mcp:read']];

$rpc=function(string $name,array $args) use ($gateway,$actor): array {
    $reply=$gateway->handle($actor,['jsonrpc'=>'2.0','id'=>'r1','method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],1790942400);
    return is_array($reply['result']??null)?$reply['result']:array();
};
$result=$rpc('cloud_skills_list',[]);
check(!$result['isError'],'skills list must succeed');
$payload=json_decode($result['content'][0]['text'],true);
check(count($payload['skills'])>=6,'Gateway lists the closed skills');

$result=$rpc('cloud_skill_load',['skill_id'=>'menu-management']);
check(!$result['isError'],'skill load must succeed');
check(json_decode($result['content'][0]['text'],true)['skill_id']==='menu-management','Loaded the requested skill');

$result=$rpc('cloud_skill_load',['skill_id'=>'nope']);
check($result['isError']===true,'Unknown skill must return an MCP error result');
check(str_contains($result['content'][0]['text'],'skill_not_found'),'Unknown skill reason is explicit');

$result=$rpc('cloud_media_search_images',['query'=>'sea','per_page'=>3]);
check(!$result['isError'],'media search must succeed');
check(json_decode($result['content'][0]['text'],true)['count']===1,'Media search returns normalized results');

$result=$rpc('cloud_media_search_images',['query'=>'sea','per_page'=>'3']);
check($result['isError']===true && str_contains($result['content'][0]['text'],'invalid_argument_type'),'String per_page is rejected');

$result=$gateway->handle(['owner'=>'1','site_ids'=>[],'scopes'=>[]],['jsonrpc'=>'2.0','id'=>'r2','method'=>'tools/call','params'=>['name'=>'cloud_skills_list','arguments'=>[]]],1790942400)['result'];
check($result['isError']===true && str_contains($result['content'][0]['text'],'read_scope_required'),'Missing read scope is rejected');

$noKeyGateway=new Gateway($core,Skills::fromConfig(dirname(__DIR__).'/config/skills.php'));
$result=$noKeyGateway->handle($actor,['jsonrpc'=>'2.0','id'=>'r3','method'=>'tools/call','params'=>['name'=>'cloud_media_search_images','arguments'=>['query'=>'sea']]],1790942400)['result'];
check($result['isError']===true && str_contains($result['content'][0]['text'],'unsplash_disabled'),'Unsplash without a key fails closed');

echo 'PASS '.$checks.' Tier A assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').'; skills='.count($listing).'; tools='.count($tools).PHP_EOL;
