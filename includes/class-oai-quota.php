<?php
/**
 * Okean AI Admin quota/entitlement foundation.
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Quota {
 public const PLANS = array(
  'free'=>array('label'=>'Free','limit'=>100,'period'=>'month'),
  'daily'=>array('label'=>'Daily','limit'=>500,'period'=>'day'),
  'pro'=>array('label'=>'Pro','limit'=>2000,'period'=>'month'),
  'business'=>array('label'=>'Business','limit'=>5000,'period'=>'month'),
 );

 public static function plans(){ return self::PLANS; }

 public static function current_plan(){
  $lic=OAI_License::status();
  $plan=sanitize_key((string)($lic['plan']??'free'));
  if(!isset(self::PLANS[$plan])){
   $plan=get_option('oai_plan','free');
   if(!isset(self::PLANS[$plan])) $plan='free';
  }
  return $plan;
 }

 public static function period_key($plan=null){
  $plan=$plan?:self::current_plan();
  $period=self::PLANS[$plan]['period']??'month';
  $now=current_time('timestamp');
  return $period==='day'?wp_date('Y-m-d',$now):wp_date('Y-m',$now);
 }

 private static function option_key($plan=null){ return 'oai_quota_'.self::current_plan().'_'.self::period_key($plan); }

 public static function status(){
  $plan=self::current_plan();
  $cfg=self::PLANS[$plan];
  $used=max(0,(int)get_option(self::option_key($plan),0));
  return array(
   'plan'=>$plan,
   'label'=>$cfg['label'],
   'limit'=>(int)$cfg['limit'],
   'used'=>$used,
   'remaining'=>max(0,(int)$cfg['limit']-$used),
   'period'=>$cfg['period'],
   'period_key'=>self::period_key($plan),
   'reset_at'=>self::reset_at($cfg['period']),
  );
 }

 public static function consume($amount=1){
  $amount=max(1,absint($amount));
  $s=self::status();
  if($s['used']+$amount>$s['limit']) return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array('status'=>429,'limit'=>$s['limit'],'used'=>$s['used'],'remaining'=>$s['remaining'],'reset_at'=>$s['reset_at']));
  $key=self::option_key($s['plan']);
  $new=$s['used']+$amount;
  update_option($key,$new,false);
  return self::status();
 }

 private static function reset_at($period){
  $now=current_time('timestamp');
  if($period==='day'){
   return wp_date('c',strtotime('+1 day',strtotime(wp_date('Y-m-d 00:00:00',$now))));
  }
  return wp_date('c',strtotime('+1 month',strtotime(wp_date('Y-m-01 00:00:00',$now))));
 }
}
