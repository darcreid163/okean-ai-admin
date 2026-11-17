<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Rate_Limit {
 public static function check($bucket,$limit=60,$window=60){
  global $wpdb;
  $bucket=(string)$bucket;$limit=max(1,(int)$limit);$window=max(1,(int)$window);
  $now=time();$window_start=intdiv($now,$window)*$window;
  $bucket_hash=hash('sha256',$bucket.'|'.self::client_key());
  $table=OAI_DB::rate_limits_table();
  $suppressed=$wpdb->suppress_errors(true);
  try{
   // One row per fixed window: an old, delayed request cannot reset a newer window.
   // The unique-key upsert increments in the database, not in a transient read/write pair.
   $inserted=$wpdb->query($wpdb->prepare(
    "INSERT INTO $table (bucket_hash,window_start,hits,expires_at) VALUES (%s,%d,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1",
    $bucket_hash,$window_start,$window_start+($window*2)
   ));
   if($inserted===false)return new WP_Error('oai_rate_limit_storage','Rate limit storage unavailable',array('status'=>503));
   $hits=$wpdb->get_var($wpdb->prepare(
    "SELECT hits FROM $table WHERE bucket_hash=%s AND window_start=%d",
    $bucket_hash,$window_start
   ));
   if($hits===null || $wpdb->last_error!=='')return new WP_Error('oai_rate_limit_storage','Rate limit storage unavailable',array('status'=>503));
  }finally{
   $wpdb->suppress_errors($suppressed);
  }
  if((int)$hits>$limit){
   $retry_after=max(1,($window_start+$window)-$now);
   return new WP_Error('oai_rate_limited','Rate limit exceeded',array('status'=>429,'retry_after'=>$retry_after));
  }
  return true;
 }
 private static function client_key(){
  $raw=(string)($_SERVER['REMOTE_ADDR']??'unknown');
  return hash('sha256',wp_salt('auth').'|'.$raw);
 }
}
