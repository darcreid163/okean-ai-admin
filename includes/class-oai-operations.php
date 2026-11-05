<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Operations {
 public static function new_id($prefix='OAI'){
  if(!is_string($prefix)||!preg_match('/^[A-Za-z]{1,8}$/D',$prefix))throw new InvalidArgumentException('Invalid internal operation namespace');
  return $prefix.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
 }
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
 /** Classification only: never grants permission or rewrites historical evidence. */
 public static function risk($type,$target){
  // Explicit known types avoid substring aliases and unknown-low defaults.
  if(in_array($type,array('post_title','post_excerpt'),true))return 'low';
  if(in_array($type,array(
   'post_content','content_patch','workspace_write',
   'woo_product_price','woo_product_stock','woocommerce_patch','woocommerce_product','woocommerce_product_create',
   'elementor_widget_html','elementor_widget_settings','elementor_page_create','elementor_page_layout',
   'media_alt','media_title','media_featured','media_upload',
   'menu_create','menu_add_item','menu_remove_item','menu_move_item','menu_assign_location'
  ),true))return 'medium';
  // Options, theme settings and all file writes are high. Unrecognized types
  // stay high until explicitly reviewed; this does not make them supported.
  return 'high';
 }
 public static function receipt($id){return OAI_Receipts::build($id);}
}

