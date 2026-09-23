<?php
/** Okean AI Admin atomic plan quota. */
if (!defined('ABSPATH')) exit;
class OAI_Quota {
 public const PLANS=array(
  'free'=>array('label'=>'Free','limit'=>100,'period'=>'month'),
  'daily'=>array('label'=>'Daily','limit'=>500,'period'=>'day'),
  'pro'=>array('label'=>'Pro','limit'=>2000,'period'=>'month'),
  'business'=>array('label'=>'Business','limit'=>5000,'period'=>'month'),
 );
 public static function plans(){return self::PLANS;}
 public static function current_plan(){
  $s=OAI_License::status(); $p=sanitize_key((string)($s['plan']??''));
  // The local-owner license is an administrative bootstrap mode; its user-facing quota is Free.
  if($p==='local-owner') $p='free';
  if(isset(self::PLANS[$p])) return $p;
  $p=sanitize_key((string)get_option('oai_plan','free'));
  return isset(self::PLANS[$p])?$p:'free';
 }
 public static function period_key($plan=null){
  $plan=$plan?:self::current_plan(); $period=self::PLANS[$plan]['period']??'month'; $now=current_time('timestamp');
  return $period==='day'?wp_date('Y-m-d',$now):wp_date('Y-m',$now);
 }
 public static function status(){
  global $wpdb; $plan=self::current_plan(); $cfg=self::PLANS[$plan]; $period=self::period_key($plan);
  $row=$wpdb->get_row($wpdb->prepare('SELECT used FROM '.OAI_DB::quota_table().' WHERE plan=%s AND period_key=%s',$plan,$period),ARRAY_A);
  $used=$row?(int)$row['used']:0;
  return array('plan'=>$plan,'label'=>$cfg['label'],'limit'=>(int)$cfg['limit'],'used'=>$used,'remaining'=>max(0,(int)$cfg['limit']-$used),'period'=>$cfg['period'],'period_key'=>$period,'reset_at'=>self::reset_at($cfg['period']));
 }
 public static function consume($amount=1){
  global $wpdb; $amount=max(1,absint($amount)); $s=self::status();
  if($s['used']+$amount>$s['limit']) return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array('status'=>429,'limit'=>$s['limit'],'used'=>$s['used'],'remaining'=>$s['remaining'],'reset_at'=>$s['reset_at']));
  $table=OAI_DB::quota_table();
  $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$table.' (plan,period_key,used,updated_at) VALUES (%s,%s,0,%s)',$s['plan'],$s['period_key'],current_time('mysql',true)));
  $updated=$wpdb->query($wpdb->prepare('UPDATE '.$table.' SET used=used+%d, updated_at=%s WHERE plan=%s AND period_key=%s AND used+%d<=%d',$amount,current_time('mysql',true),$s['plan'],$s['period_key'],$amount,$s['limit']));
  if($updated!==1) return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array('status'=>429,'limit'=>$s['limit'],'used'=>self::status()['used'],'remaining'=>self::status()['remaining'],'reset_at'=>$s['reset_at']));
  return self::status();
 }
 private static function reset_at($period){
  $now=current_time('timestamp');
  return $period==='day'?wp_date('c',strtotime('+1 day',strtotime(wp_date('Y-m-d 00:00:00',$now)))):wp_date('c',strtotime('+1 month',strtotime(wp_date('Y-m-01 00:00:00',$now))));
 }
}
