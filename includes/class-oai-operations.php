<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Operations {
 public static function snapshot($type,$target){
  $r=OAI_REST::current($type,$target); if(is_array($r)) return $r;
  if(is_wp_error($r)) return $r;
  return array(false,null);
 }
 public static function diff($before,$after){
  if($before===$after)return array('changed'=>false,'added'=>array(),'removed'=>array(),'changed'=>array());
  $out=array('changed'=>true,'added'=>array(),'removed'=>array(),'changed'=>array());
  if(is_array($before)&&is_array($after)){
   foreach($after as $k=>$v){if(!array_key_exists($k,$before))$out['added'][$k]=$v;elseif($before[$k]!==$v)$out['changed'][$k]=array('before'=>$before[$k],'after'=>$v);}
   foreach($before as $k=>$v)if(!array_key_exists($k,$after))$out['removed'][$k]=$v;
  } else {$out['changed']['value']=array('before'=>$before,'after'=>$after);}
  return $out;
 }
 public static function risk($type,$target){
  if(strpos($type,'user')!==false||strpos($type,'option')!==false)return 'high';
  if(strpos($type,'file')!==false)return 'high';
  if(strpos($type,'woocommerce')!==false)return 'medium';
  if(strpos($type,'media')!==false||strpos($type,'menu')!==false)return 'medium';
  if(strpos($type,'elementor')!==false||strpos($type,'gutenberg')!==false)return 'medium';
  return 'low';
 }
 public static function receipt($id){return OAI_Receipts::build($id);}
}
