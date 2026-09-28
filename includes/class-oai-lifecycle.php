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
  if($type==='file_write'||$type==='theme_file_write'||$type==='workspace_file_write')return array('action'=>$exists?'restore_file':'delete_created_file','target'=>$target,'before_exists'=>$exists,'before_sha256'=>hash('sha256',(string)$before));
  if($type==='option_update'&&!$exists)return array('action'=>'delete_option','target'=>$target);
  return array('action'=>'restore_snapshot','type'=>$type,'target'=>$target);
 }
 public static function decorate($row){
  if(!$row)return $row; $meta=$row['meta_data']??array(); if(is_string($meta)){ $meta=json_decode($meta,true); } if(!is_array($meta))$meta=array();
  $row['phase']=self::phase((string)$row['status']);
  $row['rollback_available']=!empty($meta['rollback_descriptor']);
  $row['rollback_descriptor']=$meta['rollback_descriptor']??null;
  $row['proof_hash']=null; $proof=json_decode((string)($row['proof']??''),true); if(is_array($proof))$row['proof_hash']=$proof['hash']??null;
  return $row;
 }
}
