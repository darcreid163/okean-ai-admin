<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Proof {
 public static function make($id){
  $r=OAI_DB::get($id); if(!$r)return null;
  $after=json_decode((string)$r['after_data'],true); $before=json_decode((string)$r['before_data'],true); $meta=json_decode((string)$r['meta_data'],true);
  $phase=($r['status']==='rolled_back'||$r['status']==='rollback_failed')?'rollback':'after';
  $expected=$phase==='rollback'?$before:$after;
  $verification=class_exists('OAI_Verify')?OAI_Verify::operation($id,$expected,$phase):null;
  $payload=array('operation_id'=>$r['operation_id'],'type'=>$r['type'],'target'=>$r['target'],'before'=>$before,'after'=>$after,'meta'=>$meta,'verification'=>$verification);
  $hash=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return array(
   'algorithm'=>'sha256','hash'=>$hash,
   'payload_hash'=>hash('sha256',wp_json_encode(array('before'=>$before,'after'=>$after,'meta'=>$meta),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'verification'=>$verification,'phase'=>$phase,'verified'=>is_array($verification)&&!empty($verification['verified']),
   'generated_at'=>gmdate('c'),'plugin'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'copyright'=>OAI_COPYRIGHT
  );
 }
}
