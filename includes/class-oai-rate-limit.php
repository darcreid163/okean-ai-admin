<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Rate_Limit {
 public static function check($bucket,$limit=60,$window=60){
  $ip=self::client_key(); $key='oai_rl_'.md5($bucket.'|'.$ip); $n=(int)get_transient($key); if($n >= $limit)return new WP_Error('oai_rate_limited','Rate limit exceeded',array('status'=>429,'retry_after'=>$window)); set_transient($key,$n+1,$window); return true;
 }
 private static function client_key(){
  $raw=(string)($_SERVER['REMOTE_ADDR']??'unknown'); return hash('sha256',wp_salt('auth').'|'.$raw);
 }
}

