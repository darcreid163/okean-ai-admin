<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Unified machine-readable error contract for AI Admin agent clients.
 */
class OAI_Errors {
 public static function make($code,$message,$status=400,$details=array()){
  return new WP_Error(sanitize_key($code),$message,array_merge(array('status'=>(int)$status,'contract'=>'ai-admin/v1'),is_array($details)?$details:array()));
 }
 public static function normalize($error,$fallback_code='oai_error',$fallback_status=500){
  if($error instanceof WP_Error){
   $data=$error->get_error_data();
   $status=is_array($data)&&isset($data['status'])?(int)$data['status']:$fallback_status;
   return array('ok'=>false,'code'=>$error->get_error_code()?:$fallback_code,'message'=>$error->get_error_message()?:'AI Admin error','status'=>$status,'details'=>is_array($data)?$data:array(),'contract'=>'ai-admin/v1');
  }
  return array('ok'=>false,'code'=>$fallback_code,'message'=>(string)$error,'status'=>$fallback_status,'details'=>array(),'contract'=>'ai-admin/v1');
 }
 public static function response($error){
  $n=self::normalize($error);
  return new WP_REST_Response($n,$n['status']);
 }
}
