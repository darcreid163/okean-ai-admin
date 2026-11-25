<?php
declare(strict_types=1);
/** Storage-free guard for the final daily tariff catalogue and retired-scheme compatibility. */
$root=dirname(__DIR__);
foreach(['Security','BatchAccounting','QuotaPolicy','Core'] as $class) require_once $root.'/src/'.$class.'.php';
use WPAICloudGateway\{BatchAccounting as Batch,QuotaPolicy as Policy,Core,Fault};
$checks=0;
$ok=static function(bool $true,string $name) use (&$checks):void {++$checks;if(!$true)throw new RuntimeException($name);};
$refuse=static function(callable $f,string $reason) use ($ok):void {
    try{$f();}catch(Fault $e){$ok($e->reason===$reason,'Unexpected failure: '.$e->reason);return;}
    throw new RuntimeException('Expected refusal: '.$reason);
};
$p=json_decode(file_get_contents($root.'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
Policy::validate($p);
$ok(($p['automatic_money_debits']??null)===false,'Automatic money debit unexpectedly enabled');
$ok(($p['batch']??'')===Batch::POLICY,'Production batch must be enabled');
foreach(['free'=>[50,Policy::DAY],'daily'=>[500,Policy::DAY],'pro'=>[2000,Policy::DAY],'business'=>[5000,Policy::DAY],'businesspro'=>[10000,Policy::DAY]] as $plan=>$expected){
    $t=Policy::terms($p,['quota_scheme'=>Policy::CURRENT,'plan'=>$plan]);
    $ok($t['limit']===$expected[0]&&$t['period_kind']===$expected[1]&&$t['quota_scheme']===Policy::TARGET,'Bad final daily tariff '.$plan);
    $w=Policy::window($t,strtotime('2026-10-21T23:59:59Z'));
    $next=Policy::window($t,strtotime('2026-10-22T00:00:00Z'));
    $ok($w['period']==='2026-10-21'&&$next['period']==='2026-10-22','UTC boundary '.$plan);
}
$alias=Policy::terms($p,['quota_scheme'=>Policy::CURRENT,'plan'=>'biznes']);
$ok($alias['limit']===5000&&$alias['quota_scheme']===Policy::TARGET,'Retired v2 alias did not resolve to the final PRO+ daily quota');
$legacy=Policy::terms($p,['plan'=>'free','quota_scheme'=>Policy::LEGACY]);
$ok($legacy['limit']===50&&$legacy['quota_scheme']===Policy::TARGET&&$legacy['period_kind']===Policy::DAY,'Retired monthly free plan did not resolve to final daily terms');
$items=Batch::items([['id'=>'1','tool'=>'wp.get_site','arguments'=>[]],['id'=>'2','tool'=>'woocommerce.update_product_sandbox','arguments'=>['id'=>42,'changes'=>['regular_price'=>'10']]]]);
$ok($items[0]['tool']==='wp_get_site'&&$items[1]['tool']==='woocommerce_update_product_sandbox','Batch normalization wrong');
$refuse(fn()=>Batch::items([]),'batch_item_limit');
$refuse(fn()=>Batch::items(array_fill(0,21,['id'=>'x','tool'=>'wp_get_site','arguments'=>[]])),'batch_item_limit');
$refuse(fn()=>Batch::items([['id'=>'same','tool'=>'wp_get_site','arguments'=>[]],['id'=>'same','tool'=>'wp_get_site','arguments'=>[]]]),'duplicate_batch_step');
$refuse(fn()=>Batch::items([['id'=>'x','tool'=>'operation.apply','arguments'=>['id'=>1]]]),'batch_tool_not_allowed');
$refuse(fn()=>Batch::items([['id'=>'x','tool'=>'woocommerce_patch','arguments'=>['changes'=>['variations'=>[['id'=>1]]]]]]),'batch_multiple_targets_not_allowed');
$refuse(fn()=>Batch::items([['id'=>'x','tool'=>'wp_get_site','arguments'=>['a']]]),'invalid_batch_arguments');
$bad=$p;$bad['plans']['pro']='500';$refuse(fn()=>Policy::validate($bad),'invalid_plan_limit');
$bad=$p;$bad['periods']['pro']=Policy::MONTH;$refuse(fn()=>Policy::validate($bad),'invalid_quota_policy');
$ok(Core::units('wp_get_site')===1&&Core::units('operation.get_receipt')===0,'Billing units drift');
$ok(!Core::writes('wp_get_site',[])&&Core::writes('woocommerce_update_product_sandbox',[]),'Read/write classification drift');
$admin=file_get_contents($root.'/src/Admin.php');
$coreSource=file_get_contents($root.'/src/Core.php');
$gateway=file_get_contents($root.'/src/Gateway.php');
$rest=file_get_contents($root.'/includes/class-wpai-cloud-rest.php');
$ok(str_contains($admin,"if (!current_user_can('manage_options')) return;"),'Quota admin page lost manage_options gate');
$ok(str_contains($admin,'check_admin_referer(\'wpai_gateway_\'.$action,\'wpai_gateway_nonce\');')&&str_contains($admin,"if (!is_ssl()) throw new Fault('https_required',403);"),'Quota admin action lost HTTPS/nonce protection');
$ok(str_contains($admin,'$action===\'quota_reset\'')&&str_contains($admin,'$core->resetDailyQuota('),'Quota reset action is not wired to Cloud Core');
$ok(str_contains($coreSource,"'manual_daily_quota_reset'")&&str_contains($coreSource,"'admin:'.$operator"),'Quota reset audit evidence missing');
$ok(!str_contains($gateway,'quota_reset')&&!str_contains($rest,'quota_reset'),'Manual quota reset leaked into public MCP/REST surface');
echo "PASS $checks standalone release-guard assertions (no database required)\n";
