<?php
/** Okean AI Admin plan quota. */
if (!defined('ABSPATH')) exit;

class OAI_Quota {
 private const ENGINEERING_PLAN='__engineering__';

 public const PLANS=array(
  'free'=>array('label'=>'Free','limit'=>100,'period'=>'day'),
  'daily'=>array('label'=>'Daily','limit'=>500,'period'=>'day'),
  'pro'=>array('label'=>'Pro','limit'=>2000,'period'=>'day'),
  'business'=>array('label'=>'Business','limit'=>5000,'period'=>'day'),
 );

 public static function plans(){return self::PLANS;}

 public static function is_engineering_access(){
  $s=OAI_License::status();
  return !empty($s['active'])
   && ($s['mode']??'')===OAI_License::LOCAL_MODE
   && !empty($s['local_valid']);
 }

 public static function current_plan(){
  if(self::is_engineering_access()) return self::ENGINEERING_PLAN;
  $s=OAI_License::status();
  $p=sanitize_key((string)($s['plan']??''));
  if(isset(self::PLANS[$p])) return $p;
  $p=sanitize_key((string)get_option('oai_plan','free'));
  return isset(self::PLANS[$p])?$p:'free';
 }

 public static function period_key($plan=null){
  $plan=$plan?:self::current_plan();
  if($plan===self::ENGINEERING_PLAN) return 'unlimited';
  $period=self::PLANS[$plan]['period']??'day';
  $now=current_time('timestamp');
  return $period==='day'?wp_date('Y-m-d',$now):wp_date('Y-m',$now);
 }

 public static function status(){
  $plan=self::current_plan();
  if($plan===self::ENGINEERING_PLAN){
   return array(
    'plan'=>'engineering',
    'label'=>'Free',
    'limit'=>PHP_INT_MAX,
    'used'=>0,
    'remaining'=>PHP_INT_MAX,
    'period'=>'day',
    'period_key'=>'unlimited',
    'reset_at'=>null,
    'unlimited'=>true,
   );
  }

  global $wpdb;
  $cfg=self::PLANS[$plan];
  $period=self::period_key($plan);
  $row=$wpdb->get_row($wpdb->prepare('SELECT used FROM '.OAI_DB::quota_table().' WHERE plan=%s AND period_key=%s',$plan,$period),ARRAY_A);
  $used=$row?(int)$row['used']:0;
  return array(
   'plan'=>$plan,
   'label'=>$cfg['label'],
   'limit'=>(int)$cfg['limit'],
   'used'=>$used,
   'remaining'=>max(0,(int)$cfg['limit']-$used),
   'period'=>$cfg['period'],
   'period_key'=>$period,
   'reset_at'=>self::reset_at($cfg['period']),
   'unlimited'=>false,
  );
 }

 public static function consume($amount=1){
  if(self::is_engineering_access()) return self::status();

  global $wpdb;
  $amount=max(1,absint($amount));
  $s=self::status();
  if($s['used']+$amount>$s['limit']){
   return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array(
    'status'=>429,
    'limit'=>$s['limit'],
    'used'=>$s['used'],
    'remaining'=>$s['remaining'],
    'reset_at'=>$s['reset_at'],
   ));
  }

  $table=OAI_DB::quota_table();
  $wpdb->query($wpdb->prepare(
   'INSERT IGNORE INTO '.$table.' (plan,period_key,used,updated_at) VALUES (%s,%s,0,%s)',
   $s['plan'],$s['period_key'],current_time('mysql',true)
  ));
  $updated=$wpdb->query($wpdb->prepare(
   'UPDATE '.$table.' SET used=used+%d, updated_at=%s WHERE plan=%s AND period_key=%s AND used+%d<=%d',
   $amount,current_time('mysql',true),$s['plan'],$s['period_key'],$amount,$s['limit']
  ));
  if($updated!==1){
   $latest=self::status();
   return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array(
    'status'=>429,
    'limit'=>$latest['limit'],
    'used'=>$latest['used'],
    'remaining'=>$latest['remaining'],
    'reset_at'=>$latest['reset_at'],
   ));
  }
  return self::status();
 }

 private static function reset_at($period){
  $now=current_time('timestamp');
  return $period==='day'
   ?wp_date('c',strtotime('+1 day',strtotime(wp_date('Y-m-d 00:00:00',$now))))
   :wp_date('c',strtotime('+1 month',strtotime(wp_date('Y-m-01 00:00:00',$now))));
 }
}
