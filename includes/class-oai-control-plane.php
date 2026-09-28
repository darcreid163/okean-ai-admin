<?php
/** Verification-only foundation for a future external Control Plane. */
if (!defined('ABSPATH')) exit;

class OAI_Control_Plane {
 const CONTRACT_VERSION=1;
 const MAX_ENVELOPE_BYTES=8192;
 const MAX_TTL_SECONDS=3600;
 const CLOCK_SKEW_SECONDS=60;
 const DOMAIN="OAI-ENTITLEMENT-V1\n";

 private static function decode64($value,$max_bytes){
  if(!is_string($value)||$value===''||strlen($value)>($max_bytes*4/3+4)||!preg_match('/^[A-Za-z0-9_-]+$/D',$value))return false;
  $decoded=base64_decode(strtr($value,'-_','+/'),true);
  if($decoded===false||strlen($decoded)>$max_bytes||rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$value)return false;
  return $decoded;
 }

 /** No network access or option writes. Never use unverified fields as an entitlement. */
 public static function verify($envelope,$public_key,$site_id,$installation_id,$registration_id,$last_sequence=0,$now=null){
  $fail=function($code){return array('valid'=>false,'reason'=>$code);};
  if(!function_exists('sodium_crypto_sign_verify_detached'))return $fail('sodium_unavailable');
  if(!is_array($envelope)||strlen(json_encode($envelope)?:'')>self::MAX_ENVELOPE_BYTES)return $fail('envelope_size');
  if(($envelope['alg']??null)!=='Ed25519'||!isset($envelope['payload'],$envelope['signature'],$envelope['key_id']))return $fail('envelope_shape');
  $key=self::decode64($public_key,SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
  $signature=self::decode64($envelope['signature'],SODIUM_CRYPTO_SIGN_BYTES);
  $json=self::decode64($envelope['payload'],self::MAX_ENVELOPE_BYTES);
  if($key===false||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||$signature===false||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||$json===false)return $fail('encoding');
  if(!is_string($envelope['key_id'])||!hash_equals(substr(hash('sha256',$key),0,16),$envelope['key_id']))return $fail('key_id');
  try{$verified=sodium_crypto_sign_verify_detached($signature,self::DOMAIN.$envelope['payload'],$key);}catch(Throwable $e){$verified=false;}
  if(!$verified)return $fail('signature');
  $claims=json_decode($json,true);
  if(!is_array($claims)||json_last_error()!==JSON_ERROR_NONE)return $fail('payload');
  if(($claims['contract_version']??null)!==self::CONTRACT_VERSION||($claims['issuer']??null)!=='okean-control-plane'||($claims['audience']??null)!=='wp-ai-admin')return $fail('contract');
  foreach(array('site_id'=>$site_id,'installation_id'=>$installation_id,'registration_id'=>$registration_id) as $field=>$expected){
   if(!is_string($expected)||$expected===''||!is_string($claims[$field]??null)||!hash_equals($expected,$claims[$field]))return $fail('binding');
  }
  foreach(array('issued_at','not_before','expires_at','policy_sequence') as $field)if(!is_int($claims[$field]??null))return $fail('integer_field');
  $now=$now===null?time():$now;
  if(!is_int($now)||$claims['issued_at']>$claims['not_before']||$claims['not_before']>$claims['expires_at']||$claims['expires_at']-$claims['issued_at']>self::MAX_TTL_SECONDS||$claims['expires_at']<=$claims['issued_at'])return $fail('time_window');
  if($claims['issued_at']>$now+self::CLOCK_SKEW_SECONDS||$claims['not_before']>$now+self::CLOCK_SKEW_SECONDS||$claims['expires_at']<=$now)return $fail('time_invalid');
  if(!is_int($last_sequence)||$last_sequence<0||$claims['policy_sequence']<1||$claims['policy_sequence']<$last_sequence)return $fail('sequence');
  if(!in_array($claims['plan']??null,array('free','daily','pro','business'),true)||!is_bool($claims['active']??null)||!is_bool($claims['write_frozen']??null))return $fail('policy');
  if(!is_array($claims['features']??null)||count($claims['features'])>32||array_values($claims['features'])!==$claims['features'])return $fail('features');
  $allowed=array('read','plan','sandbox','preview','verify','proof','rollback','elementor','gutenberg','woocommerce','wp_cli','mcp','mcp_apply','mcp_rollback','agent');
  foreach($claims['features'] as $feature)if(!is_string($feature)||!in_array($feature,$allowed,true))return $fail('features');
  if(count(array_unique($claims['features']))!==count($claims['features']))return $fail('features');
  $quota=$claims['quota']??null;
  if(!is_array($quota)||!is_int($quota['limit']??null)||$quota['limit']<0||$quota['limit']>10000000||($quota['period']??null)!=='day')return $fail('quota');
  return array('valid'=>true,'claims'=>$claims);
 }
}
