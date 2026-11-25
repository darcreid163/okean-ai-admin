<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Rate_Limit {
 public static function check($bucket,$limit=60,$window=60){
  $limit=(int)$limit; $window=max(1,(int)$window);
  $window_start=(int)(floor(time()/$window)*$window);
  $client_hash=self::client_key();
  $bucket_hash=hash('sha256',(string)$bucket);
  $admitted=OAI_DB::rate_limit_admit($bucket_hash,$client_hash,$window_start,$window,$limit);
  if(is_wp_error($admitted)) return $admitted;
  OAI_DB::prune_rate_limits();
  if(!$admitted){
   $retry_after=max(1,($window_start+$window)-time());
   return new WP_Error('oai_rate_limited','Rate limit exceeded',array('status'=>429,'retry_after'=>$retry_after));
  }
  return true;
 }
 private static function client_key(){
  $raw=(string)($_SERVER['REMOTE_ADDR']??'unknown'); return hash('sha256',wp_salt('auth').'|'.$raw);
 }
}

