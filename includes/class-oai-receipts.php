<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Receipts {
 public static function build($id){$r=OAI_DB::get($id);if(!$r)return null;return array('operation_id'=>$r['operation_id'],'db_id'=>(int)$r['id'],'status'=>$r['status'],'phase'=>OAI_Lifecycle::phase((string)$r['status']),'rollback_available'=>!empty(json_decode((string)$r['rollback_data'],true)),'type'=>$r['type'],'target'=>$r['target'],'risk'=>$r['risk'],'created_at'=>$r['created_at'],'approved_at'=>$r['approved_at'],'applied_at'=>$r['applied_at'],'rolled_back_at'=>$r['rolled_back_at'],'verified'=>self::proof_verified($r),'receipt'=>json_decode((string)$r['receipt'],true),'proof'=>json_decode((string)$r['proof'],true));}
 private static function proof_verified($r){ $p=json_decode((string)$r['proof'],true); return is_array($p)&&!empty($p['verified']); }
 public static function store($id,$receipt,$proof=null){OAI_DB::update($id,array('receipt'=>wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'proof'=>wp_json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}
}
