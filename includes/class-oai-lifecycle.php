<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Lifecycle {
 public static function phase($status){
  $map=array('pending'=>'awaiting_approval','approved'=>'approved','applying'=>'applying','applied'=>'applied','proved'=>'proved','rejected'=>'rejected','apply_failed'=>'apply_failed','rollback_pending'=>'rollback_pending','rolling_back'=>'rolling_back','rolled_back'=>'rolled_back','rollback_failed'=>'rollback_failed','expired'=>'expired');
  return $map[$status]??$status;
 }
 public static function descriptor($type,$target,$before,$meta=array()){
  $exists=array_key_exists('before_exists',$meta)?(bool)$meta['before_exists']:true;
  if($type==='elementor_page_create')return array('action'=>'delete_created_object','post_id'=>absint($meta['preview_page_id']??0),'post_type'=>'page','created_by_ai_admin'=>true);
  if($type==='media_upload')return array('action'=>'delete_created_attachment','attachment_id'=>absint($meta['attachment_id']??$target),'created_by_ai_admin'=>true);
  if($type==='menu_create')return array('action'=>'delete_created_menu','menu_id'=>absint($meta['menu_id']??$target),'created_by_ai_admin'=>true);
  if($type==='menu_add_item')return array('action'=>'delete_created_menu_item','item_id'=>absint($meta['item_id']??$target),'created_by_ai_admin'=>true);
  if($type==='woocommerce_product_create')return array('action'=>'delete_created_product','product_id'=>absint($meta['product_id']??$target),'variation_ids'=>array_values(array_filter(array_map('absint',(array)($meta['variation_ids']??array())))),'created_by_ai_admin'=>true);
  if($type==='file_write'||$type==='theme_file_write'||$type==='workspace_file_write')return array('action'=>$exists?'restore_file':'delete_created_file','target'=>$target,'before_exists'=>$exists,'before_sha256'=>hash('sha256',(string)$before));
  if($type==='option_update'&&!$exists)return array('action'=>'delete_option','target'=>$target);
  return array('action'=>'restore_snapshot','type'=>$type,'target'=>$target);
 }
 public static function looks_like_filesystem_path($value){
  if(!is_string($value)||$value==='')return false;
  $v=str_replace('\\','/',$value);
  if(preg_match('/^[A-Za-z]:\\//',$v))return true;
  return (bool)preg_match('#^/(?:home|var|srv|opt|usr|mnt|private|Users|www)(?:/|$)#',$v);
 }
 public static function redact_public($value,$key=''){
  if(is_array($value)){
   $out=array();
   foreach($value as $k=>$v)$out[$k]=self::redact_public($v,(string)$k);
   return $out;
  }
  if(is_string($value)){
   $sensitive_keys=array('file','absolute_path','filesystem_path','plugin_dir','uploads_path','stylesheet_directory');
   if(in_array($key,$sensitive_keys,true)&&self::looks_like_filesystem_path($value))return '[redacted filesystem path]';
   if(in_array($key,array('receipt','proof'),true)){
    $decoded=json_decode($value,true);
    if(json_last_error()===JSON_ERROR_NONE&&is_array($decoded)){
     $redacted=self::redact_public($decoded);
     return wp_json_encode($redacted);
    }
   }
  }
  return $value;
 }
 public static function decorate($row){
  if(!$row)return $row; $meta=$row['meta_data']??array(); if(is_string($meta)){ $meta=json_decode($meta,true); } if(!is_array($meta))$meta=array();
  $row['phase']=self::phase((string)$row['status']);
  $row['rollback_available']=!empty($meta['rollback_descriptor']);
  $row['rollback_descriptor']=$meta['rollback_descriptor']??null;
  $proof_hash=null; $proof=json_decode((string)($row['proof']??''),true); if(is_array($proof))$proof_hash=$proof['hash']??null;
  $row=self::redact_public($row);
  $row['proof_hash']=$proof_hash;
  return $row;
 }
}
