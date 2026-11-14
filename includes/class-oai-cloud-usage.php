<?php
/**
 * Read-only, server-signed usage projection for this paired site.
 *
 * The Cloud Gateway ledger is the only billing authority. This class verifies
 * an Ed25519 projection and exposes it for display; it never counts, reserves
 * or changes usage locally and never turns a local counter into server data.
 */
if(!defined('ABSPATH'))exit;
class OAI_Cloud_Usage {
 const ENDPOINT='https://wpaiadmin.ru/wp-json/wpai-cloud/v1/site/usage';
 const CONTRACT='wpai-site-usage-v1';
 const DOMAIN="OAI-SITE-USAGE-V1\n";
 const TTL=300;
 const SKEW=60;
 const RETRY=60;
 const CACHE_PREFIX='oai_cloud_usage_';

 private static function unb64($value){
  if(!is_string($value)||$value===''||!preg_match('/^[A-Za-z0-9_-]+$/D',$value))return null;
  $padding=(4-strlen($value)%4)%4;
  $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
  return $decoded===false?null:$decoded;
 }

 /**
  * Pure verification: contract shape, envelope, Ed25519 signature, claim
  * binding to this site/account and freshness. Returns claims only when every
  * check passes; any failure is an explicit, non-fatal reason.
  */
 public static function verify($response,$context,$now){
  $bad=static function($reason){return array('valid'=>false,'reason'=>$reason,'claims'=>null);};
  if(!is_array($response)||($response['contract']??null)!==self::CONTRACT)return $bad('contract');
  if(!is_string($response['site_url']??null)||!is_string($response['site_id']??null)||$response['site_id']==='')return $bad('site');
  $envelope=$response['envelope']??null;
  if(!is_array($envelope)||($envelope['alg']??null)!=='Ed25519'||!is_string($envelope['key_id']??null)||!is_string($envelope['payload']??null)||!is_string($envelope['signature']??null))return $bad('envelope');
  if(!is_array($context)||!is_string($context['key_id']??null)||!hash_equals($context['key_id'],$envelope['key_id']))return $bad('key_id');
  if(!function_exists('sodium_crypto_sign_verify_detached'))return $bad('crypto_unavailable');
  $public=self::unb64($context['public_key']??null);
  $signature=self::unb64($envelope['signature']);
  if($public===null||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return $bad('public_key');
  if($signature===null||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)return $bad('signature');
  if(!sodium_crypto_sign_verify_detached($signature,self::DOMAIN.$envelope['payload'],$public))return $bad('signature');
  $json=self::unb64($envelope['payload']);
  if($json===null)return $bad('payload');
  try{$claims=json_decode($json,true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){return $bad('payload');}
  if(!is_array($claims)||array_is_list($claims))return $bad('payload');
  if(($claims['contract_version']??null)!==1||($claims['issuer']??null)!=='okean-cloud-gateway'||($claims['audience']??null)!=='wp-ai-admin-native')return $bad('claims');
  if(!is_string($claims['site_url']??null)||$claims['site_url']!==$response['site_url'])return $bad('site_url');
  if(!is_string($context['site_url']??null)||$context['site_url']!==$claims['site_url'])return $bad('site_url');
  if(!is_string($claims['site_id']??null)||$claims['site_id']!==$response['site_id']||$claims['site_id']==='')return $bad('site_id');
  if(!is_string($context['account_id']??null)||$context['account_id']===''||!is_string($claims['account_id']??null)||!hash_equals($context['account_id'],$claims['account_id']))return $bad('account_id');
  foreach(array('issued_at','expires_at') as $key){
   if(!is_int($claims[$key]??null)||$claims[$key]<0)return $bad('claims');
  }
  $quota_status=$claims['quota_status']??'approved';
  if(!in_array($quota_status,array('approved','requires_owner_approval'),true))return $bad('claims');
  // A paid plan whose quota the owner has not approved must be an explicit
  // state, never a fabricated zero balance.
  if($quota_status!=='approved')return $bad('quota_pending');
  foreach(array('limit','used','reserved','remaining') as $key){
   if(!is_int($claims[$key]??null)||$claims[$key]<0||$claims[$key]>10000000)return $bad('claims');
  }
  if($claims['limit']<=0||$claims['expires_at']<=$claims['issued_at'])return $bad('claims');
  if($claims['remaining']!==max(0,$claims['limit']-$claims['used']-$claims['reserved']))return $bad('remaining');
  if(!is_string($claims['plan']??null)||!preg_match('/^[a-z0-9_]{1,32}$/D',$claims['plan']))return $bad('claims');
  if(!is_string($claims['period']??null)||!preg_match('/^[0-9]{4}-[0-9]{2}(-[0-9]{2})?$/D',$claims['period']))return $bad('claims');
  if(!in_array($claims['period_kind']??null,array('calendar_day_utc','calendar_month_utc'),true))return $bad('claims');
  if(!in_array($claims['quota_scheme']??null,array('tariffs_v2','tariffs_v3','legacy_monthly_v1'),true))return $bad('claims');
  if(!is_string($claims['period_start_utc']??null)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$claims['period_start_utc']))return $bad('claims');
  if(!is_string($claims['period_end_utc']??null)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$claims['period_end_utc']))return $bad('claims');
  if(!is_string($claims['policy_version']??null)||$claims['policy_version']===''||strlen($claims['policy_version'])>80)return $bad('claims');
  if(($claims['timezone']??null)!=='UTC')return $bad('claims');
  $now=(int)$now;
  if($now<$claims['issued_at']-self::SKEW)return $bad('not_yet_valid');
  if($now>$claims['expires_at']+self::SKEW)return $bad('expired');
  if($now-$claims['issued_at']>self::TTL+self::SKEW)return $bad('stale');
  return array('valid'=>true,'reason'=>'','claims'=>$claims);
 }

 private static function public_projection($claims){
  return array(
   'plan'=>(string)$claims['plan'],
   'limit'=>(int)$claims['limit'],
   'used'=>(int)$claims['used'],
   'reserved'=>(int)$claims['reserved'],
   'remaining'=>(int)$claims['remaining'],
   'period'=>(string)$claims['period'],
   'period_kind'=>(string)$claims['period_kind'],
   'quota_scheme'=>(string)$claims['quota_scheme'],
   'quota_status'=>(string)($claims['quota_status']??'approved'),
   'period_start_utc'=>(string)$claims['period_start_utc'],
   'period_end_utc'=>(string)$claims['period_end_utc'],
   'policy_version'=>(string)$claims['policy_version'],
   'timezone'=>(string)$claims['timezone'],
   'issued_at'=>(int)$claims['issued_at'],
   'expires_at'=>(int)$claims['expires_at'],
   'site_url'=>(string)$claims['site_url'],
   'site_id'=>(string)$claims['site_id'],
   'account_id'=>(string)$claims['account_id'],
  );
 }

 private static function cache_key($hash){return self::CACHE_PREFIX.substr((string)$hash,0,32);}

 /** Credentials that negotiated the projection, newest first, own account only. */
 public static function credentials($hash=null){
  if(!is_user_logged_in()||!current_user_can('manage_options')||!class_exists('OAI_Gateway_Pairing'))return array();
  global $wpdb;$prefix=OAI_Gateway_Pairing::CREDENTIAL_PREFIX;
  $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000",$wpdb->esc_like($prefix).'%'));
  $found=array();
  foreach((array)$names as $name){
   $candidate=substr((string)$name,strlen($prefix));
   if(!preg_match('/^[a-f0-9]{64}$/D',$candidate))continue;
   if($hash!==null&&!hash_equals((string)$hash,$candidate))continue;
   $record=get_option($name,null);
   if(!is_array($record)||(int)($record['user_id']??0)!==get_current_user_id())continue;
   if(($record['usage_projection']??null)!==self::CONTRACT)continue;
   if(!is_string($record['status_key']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['status_key']))continue;
   if(!is_string($record['signing_public_key']??null)||!is_string($record['signing_key_id']??null))continue;
   $record['hash']=$candidate;
   $found[]=$record;
  }
  usort($found,static function($a,$b){return ((int)($b['created_at']??0))<=>((int)($a['created_at']??0));});
  return $found;
 }

 /** Newest usable pairing for the current administrator, or null. */
 public static function credential($hash=null){
  foreach(self::credentials($hash) as $record)return $record;
  return null;
 }

 /** One authenticated read of this site's projection. Never invents values. */
 public static function fetch($record){
  if(!is_array($record)||!isset($record['hash']))return array('valid'=>false,'reason'=>'unpaired','claims'=>null);
  $site=untrailingslashit(home_url('/'));
  $body=wp_json_encode(array('site_url'=>$site,'status_key'=>(string)$record['status_key']));
  if(!is_string($body))return array('valid'=>false,'reason'=>'request','claims'=>null);
  $response=wp_remote_post(self::ENDPOINT,array(
   'timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>16384,
   'headers'=>array('Accept'=>'application/json','Content-Type'=>'application/json'),
   'body'=>$body,
  ));
  if(is_wp_error($response))return array('valid'=>false,'reason'=>'unavailable','claims'=>null);
  if((int)wp_remote_retrieve_response_code($response)!==200)return array('valid'=>false,'reason'=>'rejected','claims'=>null);
  $decoded=json_decode(wp_remote_retrieve_body($response),true);
  $result=self::verify($decoded,array(
   'public_key'=>(string)$record['signing_public_key'],
   'key_id'=>(string)$record['signing_key_id'],
   'site_url'=>$site,
   'account_id'=>(string)($record['account_id']??''),
  ),time());
  return array('valid'=>(bool)$result['valid'],'reason'=>$result['reason'],'claims'=>$result['claims']);
 }

 /** Fetch and persist, preserving the last verified projection on failure. */
 public static function refresh($record=null){
  $record=$record?:self::credential();
  if($record===null)return null;
  $key=self::cache_key($record['hash']);
  $previous=get_option($key,null);
  $cache=is_array($previous)?$previous:array();
  $cache+=array('fetched_at'=>0,'verified_at'=>0,'valid'=>false,'reason'=>'', 'projection'=>null,'site_url'=>'','site_id'=>'','account_id'=>'','retry_at'=>0);
  $result=self::fetch($record);
  $cache['fetched_at']=time();
  if(!empty($result['valid'])){
   $claims=$result['claims'];
   $cache['valid']=true;$cache['reason']='';$cache['verified_at']=time();
   $cache['projection']=self::public_projection($claims);
   $cache['site_url']=(string)$claims['site_url'];$cache['site_id']=(string)$claims['site_id'];$cache['account_id']=(string)$claims['account_id'];
   $cache['retry_at']=0;
  }else{
   $cache['valid']=false;$cache['reason']=(string)($result['reason']??'invalid');$cache['retry_at']=time()+self::RETRY;
  }
  update_option($key,$cache,false);
  return $cache;
 }

 public static function forget($record=null){
  $record=$record?:self::credential();
  if($record===null)return false;
  return delete_option(self::cache_key($record['hash']));
 }

 /**
  * Display state: 'verified' (fresh, signed), 'stale' (last signed value older
  * than TTL), 'unknown' (no trusted projection) or 'unpaired'.
  */
 public static function status($refresh=false){
  $local=class_exists('OAI_Quota')?OAI_Quota::status():array();
  $record=self::credential();
  if($record===null)return array('state'=>'unpaired','available'=>false,'reason'=>'not_paired','projection'=>null,'checked_at'=>0,'account_label'=>'','account_id'=>'','hash'=>'','local'=>$local);
  $key=self::cache_key($record['hash']);
  $cache=get_option($key,null);
  $now=time();
  $stale=!is_array($cache)||$now-(int)($cache['verified_at']??0)>self::TTL;
  if($refresh||($stale&&(int)($cache['retry_at']??0)<=$now)){
   $updated=self::refresh($record);
   if(is_array($updated))$cache=$updated;
  }
  $projection=is_array($cache)&&is_array($cache['projection']??null)?$cache['projection']:null;
  $verified=is_array($cache)&&!empty($cache['valid'])&&$projection!==null;
  $verified_at=is_array($cache)?(int)($cache['verified_at']??0):0;
  if(!$verified)return array('state'=>'unknown','available'=>false,'reason'=>is_array($cache)?(string)($cache['reason']??'invalid'):'missing','projection'=>null,'checked_at'=>$verified_at,'account_label'=>(string)($record['account_label']??''),'account_id'=>(string)($record['account_id']??''),'hash'=>$record['hash'],'local'=>$local);
  $fresh=$now-$verified_at<=self::TTL;
  return array('state'=>$fresh?'verified':'stale','available'=>true,'reason'=>$fresh?'':'stale','projection'=>$projection,'checked_at'=>$verified_at,'account_label'=>(string)($record['account_label']??''),'account_id'=>(string)($record['account_id']??''),'hash'=>$record['hash'],'local'=>$local);
 }
}
