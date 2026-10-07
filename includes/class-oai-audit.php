<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Audit {
 public static function log($event,$row=array(),$details=array()){
  $operation_id=(string)($row['operation_id']??'');
  OAI_DB::audit_insert(array(
   'operation_id'=>$operation_id,'operation_db_id'=>isset($row['id'])?(int)$row['id']:null,
   'event'=>sanitize_key($event),'status'=>isset($row['status'])?sanitize_key($row['status']):null,
   'actor'=>get_current_user_id()?:null,'target'=>isset($row['target'])?(string)$row['target']:null,
   'details'=>wp_json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>current_time('mysql',true)
  ));
 }
 public static function list($limit=200){$rows=OAI_DB::audit_all(min(500,max(1,(int)$limit))); foreach($rows as &$r)$r['details']=json_decode($r['details'],true); return $rows;}
}

