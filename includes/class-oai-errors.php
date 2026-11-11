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

 /**
  * Enumerated business-failure markers shared by the MCP transport contract and
  * the local quota meter (#136). A tool result is a failure when any of these
  * is present at the top level:
  *  - WP_Error
  *  - WP_REST_Response with HTTP status >= 400
  *  - JSON-RPC envelope with an `error` member or `result.isError === true`
  *  - isError === true
  *  - ok === false
  *  - success === false
  *  - http_status >= 400
  *  - integer status >= 400
  *  - non-empty top-level error
  * Lists and scalars are never interpreted as a failure envelope: a list of
  * rows may legitimately contain failed children while the request succeeded.
  * Returns null for success, otherwise an auditable failure descriptor.
  */
 public static function failure($value){
  if($value instanceof WP_Error){
   $data=$value->get_error_data();
   return array('ok'=>false,'code'=>$value->get_error_code()?:'oai_error','message'=>$value->get_error_message()?:'AI Admin error',
    'status'=>is_array($data)&&isset($data['status'])?(int)$data['status']:500,'marker'=>'wp_error');
  }
  if($value instanceof WP_REST_Response){
   $status=(int)$value->get_status();
   if($status>=400)return array('ok'=>false,'code'=>'rest_http_'.$status,'message'=>'REST response failed','status'=>$status,'marker'=>'http_status');
   return self::failure($value->get_data());
  }
  if(!is_array($value)||array_is_list($value))return null;
  if(array_key_exists('jsonrpc',$value)){
   if(array_key_exists('error',$value)&&!empty($value['error']))
    return array('ok'=>false,'code'=>'jsonrpc_error','message'=>'JSON-RPC error','status'=>500,'marker'=>'jsonrpc_error');
   if(!is_array($value['result']??null)||!is_array($value['result']['content']??null))
    return array('ok'=>false,'code'=>'jsonrpc_result','message'=>'JSON-RPC result is missing content','status'=>500,'marker'=>'jsonrpc_result');
   return (($value['result']['isError']??null)===true)
    ? array('ok'=>false,'code'=>'jsonrpc_is_error','message'=>'JSON-RPC result is an error','status'=>500,'marker'=>'isError')
    : null;
  }
  if(($value['isError']??null)===true)return array('ok'=>false,'code'=>'result_is_error','message'=>'Tool result is an error','status'=>500,'marker'=>'isError');
  if(($value['ok']??null)===false)return self::marker_failure($value,'ok');
  if(($value['success']??null)===false)return self::marker_failure($value,'success');
  if(isset($value['http_status'])&&is_numeric($value['http_status'])&&(int)$value['http_status']>=400)
   return self::marker_failure($value,'http_status',(int)$value['http_status']);
  if(isset($value['status'])&&is_int($value['status'])&&$value['status']>=400)
   return self::marker_failure($value,'status',$value['status']);
  if(array_key_exists('error',$value)&&!empty($value['error'])){
   $error=$value['error'];
   return array('ok'=>false,'code'=>is_string($error)?sanitize_key($error):'tool_error','message'=>'Tool reported an error','status'=>500,'marker'=>'error');
  }
  return null;
 }

 private static function marker_failure(array $value,string $marker,int $fallback=500){
  $code=is_string($value['code']??null)&&$value['code']!==''?sanitize_key($value['code']):'result_'.$marker;
  $message=is_string($value['message']??null)&&$value['message']!==''?$value['message']:'Tool reported a failure';
  $status=isset($value['status'])&&is_int($value['status'])?$value['status']:$fallback;
  return array('ok'=>false,'code'=>$code,'message'=>$message,'status'=>$status,'marker'=>$marker);
 }

 public static function failed($value){return self::failure($value)!==null;}
}

