<?php
declare(strict_types=1);
/** Storage-free recovery guard for Cloud Core 0.3.11. */
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
$ok(($p['batch']??'')==='disabled_until_receipt_accounting_contract','Production batch activated');
foreach(['free'=>[100,Policy::MONTH],'pro'=>[500,Policy::DAY],'biznes'=>[2000,Policy::DAY],'biznespro'=>[5000,Policy::DAY]] as $plan=>$expected){
    $t=Policy::terms($p,['quota_scheme'=>Policy::CURRENT,'plan'=>$plan]);
    $ok($t['limit']===$expected[0]&&$t['period_kind']===$expected[1],'Bad tariff '.$plan);
    $w=Policy::window($t,strtotime('2026-10-21T23:59:59Z'));
    $next=Policy::window($t,strtotime('2026-10-22T00:00:00Z'));
    $ok($plan==='free'?$w['period']==='2026-10'&&$next['period']==='2026-10':$w['period']==='2026-10-21'&&$next['period']==='2026-10-22','UTC boundary '.$plan);
}
$legacy=Policy::terms($p,['plan'=>'free','quota_scheme'=>Policy::LEGACY]);
$ok($legacy['quota_scheme']===Policy::LEGACY&&$legacy['period_kind']===Policy::MONTH,'Legacy free plan not preserved');
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
echo "PASS $checks standalone release-guard assertions (no database required)\n";
