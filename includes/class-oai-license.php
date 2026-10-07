<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_License {
 const MODE='subscription-ready';
 const LOCAL_MODE='local-owner';
 const LOCAL_PLAN='local-owner';
 const LOCAL_FEATURES=array('mcp','mcp_apply','mcp_rollback','agent','sandbox','preview','verify','proof','rollback','elementor','gutenberg','woocommerce','wp_cli');

 public static function status(){
  $lic=get_option('oai_license',array());
  if(!is_array($lic)) $lic=array();
  $local_valid=self::local_valid($lic);
  $active=!empty($lic['active']);
  if(($lic['mode']??'')===self::LOCAL_MODE) $active=$local_valid;
  return array(
   'mode'=>($lic['mode']??self::MODE),
   'active'=>$active,
   'plan'=>$lic['plan']??'unlicensed',
   'expires_at'=>$lic['expires_at']??null,
   'features'=>isset($lic['features'])&&is_array($lic['features'])?$lic['features']:array(),
   'site_id'=>self::site_id(),
   'license_id'=>$lic['license_id']??null,
   'source'=>$lic['source']??null,
   'owner_user_id'=>isset($lic['owner_user_id'])?(int)$lic['owner_user_id']:null,
   'local_valid'=>$local_valid,
  );
 }

 /**
  * User-facing license state. Internal local-owner identifiers and features
  * are deliberately normalized away.
  */
 public static function user_status(){
  $s=self::status();
  $plan=sanitize_key((string)($s['plan']??''));
  if($plan===self::LOCAL_PLAN) $plan='free';
  return array(
   'active'=>(bool)$s['active'],
   'plan'=>$plan ?: 'unlicensed',
   'expires_at'=>$s['expires_at'],
  );
 }

 public static function site_id(){
  $id=get_option('oai_site_id','');
  if(!$id){$id=wp_generate_uuid4();update_option('oai_site_id',$id,false);}
  return $id;
 }

 private static function local_signature($site_id,$user_id){
  return hash_hmac('sha256','AI-Admin|local-owner|'.$site_id.'|'.absint($user_id),wp_salt('auth'));
 }

 private static function local_valid(array $lic){
  if(($lic['mode']??'')!==self::LOCAL_MODE) return false;
  $uid=isset($lic['owner_user_id'])?(int)$lic['owner_user_id']:0;
  $site_id=(string)($lic['site_id']??'');
  $sig=(string)($lic['signature']??'');
  if($uid<=0||!$site_id||!$sig||!get_user_by('id',$uid)) return false;
  if(!hash_equals($site_id,self::site_id())) return false;
  return hash_equals(self::local_signature($site_id,$uid),$sig);
 }

 public static function can($feature='mcp'){
  $s=self::status();
  if(!$s['active']) return new WP_Error('oai_license_required','Active Okean AI subscription/license required for this feature',array('status'=>402,'feature'=>$feature));
  if(!empty($s['expires_at']) && strtotime($s['expires_at'])<time()) return new WP_Error('oai_license_expired','Okean AI subscription has expired',array('status'=>402));
  // Enforce the same plan matrix used by the public entitlement layer.
  // An active subscription with an empty/missing feature list must NOT become
  // an accidental allow-all license.
  if(class_exists('OAI_Entitlements') && !OAI_Entitlements::can($feature)) return new WP_Error('oai_feature_unavailable','Feature is not included in the current plan',array('status'=>403,'feature'=>$feature));
  if(!class_exists('OAI_Entitlements') && !empty($s['features']) && !in_array($feature,$s['features'],true)) return new WP_Error('oai_feature_unavailable','Feature is not included in the current plan',array('status'=>403,'feature'=>$feature));
  return true;
 }

 public static function set_local_license(array $data){
  $data['active']=!empty($data['active']);
  update_option('oai_license',$data,false);
  return self::status();
 }

 public static function activate_local_owner($user_id=0){
  $user_id=absint($user_id?:get_current_user_id());
  if($user_id<=0 || !user_can($user_id,'manage_options')) return new WP_Error('oai_license_owner_required','A WordPress administrator is required to activate the Local Owner License',array('status'=>403));
  $site_id=self::site_id();
  $license_id='LOCAL-'.strtoupper(substr(hash('sha256',$site_id.'|'.$user_id.'|AI-Admin'),0,20));
  $lic=array(
   'mode'=>self::LOCAL_MODE,
   'active'=>true,
   'plan'=>self::LOCAL_PLAN,
   'expires_at'=>null,
   'features'=>self::LOCAL_FEATURES,
   'site_id'=>$site_id,
   'license_id'=>$license_id,
   'source'=>'local-owner',
   'owner_user_id'=>$user_id,
   'issued_at'=>current_time('mysql',true),
   'signature'=>self::local_signature($site_id,$user_id),
  );
  update_option('oai_license',$lic,false);
  return self::status();
 }

 public static function deactivate_local_owner(){
  $lic=get_option('oai_license',array());
  if(!is_array($lic)) $lic=array();
  if(($lic['mode']??'')!==self::LOCAL_MODE) return self::status();
  delete_option('oai_license');
  return self::status();
 }
}

