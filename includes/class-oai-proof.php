<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Proof {
 public static function make($id){
  $r=OAI_DB::get($id); if(!$r)return null;
  $stored=json_decode((string)($r['proof']??''),true);
  if(is_array($stored) && !empty($stored['hash']))return $stored;
  if(in_array((string)$r['status'],array('pending','approved','applying'),true))return array('proof_kind'=>'preflight','operation_id'=>$r['operation_id'],'status'=>$r['status'],'phase'=>OAI_Lifecycle::phase((string)$r['status']),'verified'=>false,'execution_proof'=>false,'generated_at'=>gmdate('c'),'plugin'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID);
  $after=json_decode((string)$r['after_data'],true); $before=json_decode((string)$r['before_data'],true); $applied=json_decode((string)($r['applied_data']??''),true); $meta=json_decode((string)$r['meta_data'],true);
  $phase=($r['status']==='rolled_back'||$r['status']==='rollback_failed')?'rollback':'after';
  // Proof must attest the canonical state that Apply actually verified. Some
  // platforms (notably WooCommerce variable products) normalize derived fields
  // after persistence, so after_data can differ legitimately from applied_data.
  if($applied===null || $r['applied_data']===null || $r['applied_data']==='')$applied=$after;
  $expected=$phase==='rollback'?$before:$applied;
  $verification=class_exists('OAI_Verify')?OAI_Verify::operation($id,$expected,$phase):null;
  $payload=array('operation_id'=>$r['operation_id'],'type'=>$r['type'],'target'=>$r['target'],'before'=>$before,'after'=>$after,'applied'=>$applied,'meta'=>$meta,'verification'=>$verification);
  $hash=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return array(
   'proof_kind'=>'execution','algorithm'=>'sha256','hash'=>$hash,
   'payload_hash'=>hash('sha256',wp_json_encode(array('before'=>$before,'after'=>$after,'applied'=>$applied,'meta'=>$meta),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'verification'=>$verification,'phase'=>$phase,'verified'=>is_array($verification)&&!empty($verification['verified']),
   'generated_at'=>gmdate('c'),'plugin'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'copyright'=>OAI_COPYRIGHT
  );
 }
}

