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

 public static function usage_events($limit=100){
  global $wpdb; $limit=max(1,min(500,absint($limit))); $table=OAI_DB::quota_events_table();
  return $wpdb->get_results($wpdb->prepare('SELECT request_key,channel,units,created_at FROM '.$table.' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);
 }

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
  // Engineering access is intentionally not represented in the public quota model.
  if($plan===self::ENGINEERING_PLAN) return self::public_status('free');
  return self::public_status($plan);
 }

 private static function public_status($plan){
  global $wpdb;
  $cfg=self::PLANS[$plan]??self::PLANS['free'];
  $period=self::period_key($plan);
  $row=$wpdb->get_row($wpdb->prepare(
   'SELECT used FROM '.OAI_DB::quota_table().' WHERE plan=%s AND period_key=%s',
   $plan,$period
  ),ARRAY_A);
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
  );
 }

 public static function request_key($channel,$request_id,$fingerprint=''){
  $uid=get_current_user_id();
  $channel=sanitize_key((string)$channel);
  $request_id=(string)$request_id;
  $fingerprint=(string)$fingerprint;
  return hash('sha256',$channel.'|'.$uid.'|'.$request_id.'|'.$fingerprint);
 }

 public static function consume_request($request_key,$channel='unknown',$amount=1){
  if(self::is_engineering_access()) return self::status();
  $request_key=(string)$request_key;
  if($request_key==='') return self::consume($amount);
  global $wpdb;
  $events=OAI_DB::quota_events_table();
  $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$events.' WHERE request_key=%s LIMIT 1',$request_key));
  if($existing) return self::status();
  $result=self::consume($amount);
  if(is_wp_error($result)) return $result;
  $inserted=$wpdb->query($wpdb->prepare(
   'INSERT IGNORE INTO '.$events.' (request_key,channel,units,created_at) VALUES (%s,%s,%d,%s)',
   $request_key,sanitize_key((string)$channel),max(1,absint($amount)),current_time('mysql',true)
  ));
  if($inserted!==1){
   // A concurrent identical request already consumed this logical request.
   // Compensate the aggregate counter so retries cannot double-charge.
   $wpdb->query($wpdb->prepare(
    'UPDATE '.OAI_DB::quota_table().' SET used=GREATEST(used-%d,0), updated_at=%s WHERE plan=%s AND period_key=%s',
    max(1,absint($amount)),current_time('mysql',true),$result['plan'],$result['period_key']
   ));
   return self::status();
  }
  return $result;
 }

 public static function consume($amount=1){
  if(self::is_engineering_access()){
   // Internal engineering mode: no quota row is written and no usage is exposed.
   return self::status();
  }

  global $wpdb;
  $amount=max(1,absint($amount));
  $s=self::status();
  if($s['used']+$amount>$s['limit']){
   return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array(
    'status'=>429,'limit'=>$s['limit'],'used'=>$s['used'],
    'remaining'=>$s['remaining'],'reset_at'=>$s['reset_at']
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
    'status'=>429,'limit'=>$latest['limit'],'used'=>$latest['used'],
    'remaining'=>$latest['remaining'],'reset_at'=>$latest['reset_at']
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

