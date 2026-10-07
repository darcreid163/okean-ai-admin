<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Persistent-state reader used only by verification. It deliberately bypasses
 * WordPress's post/meta object cache so an in-request stale object can never
 * make Apply/Rollback look successful when the stored value differs.
 */
class OAI_Authoritative {
 public static function post_field($id,$field){
  global $wpdb;
  $id=absint($id);
  if(!$id || !in_array($field,array('post_title','post_excerpt','post_content'),true))return new WP_Error('oai_authoritative_target','Unsupported post field',array('status'=>400));
  $exists=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d LIMIT 1",$id));
  if(!$exists)return array('source'=>'database','exists'=>false,'value'=>null,'post_id'=>$id,'field'=>$field);
  $value=$wpdb->get_var($wpdb->prepare("SELECT {$field} FROM {$wpdb->posts} WHERE ID=%d LIMIT 1",$id));
  return array('source'=>'database','exists'=>true,'value'=>$value===null?'':(string)$value,'post_id'=>$id,'field'=>$field);
 }
 public static function elementor_widget($id,$widget_id,$field){
  if(!in_array($field,array('settings','html'),true))return new WP_Error('oai_authoritative_target','Unsupported Elementor field',array('status'=>400));
  $snapshot=self::post_meta($id,'_elementor_data');
  if(is_wp_error($snapshot) || empty($snapshot['exists']))return $snapshot;
  $raw=$snapshot['value']; $nodes=is_string($raw)?json_decode($raw,true):$raw;
  if(!is_array($nodes))return new WP_Error('oai_authoritative_data','Persisted Elementor document is not valid JSON',array('status'=>500));
  $found=null; $walk=function($items) use (&$walk,&$found,$widget_id){
   if(!is_array($items)||$found!==null)return;
   foreach($items as $node){
    if(!is_array($node))continue;
    if((string)($node['id']??'')===$widget_id){$found=$node;return;}
    if(isset($node['elements']))$walk($node['elements']);
    if($found!==null)return;
   }
  }; $walk($nodes);
  if($found===null)return array('source'=>'database','exists'=>false,'value'=>null,'post_id'=>$id,'widget_id'=>$widget_id,'field'=>$field);
  $settings=is_array($found['settings']??null)?$found['settings']:array();
  return array('source'=>'database','exists'=>true,'value'=>$field==='html'?(string)($settings['html']??''):$settings,'post_id'=>$id,'widget_id'=>$widget_id,'field'=>$field);
 }
 public static function post_meta($id,$key){
  global $wpdb;
  $id=absint($id); $key=(string)$key;
  if(!$id || $key==='')return new WP_Error('oai_authoritative_target','Invalid post meta target',array('status'=>400));
  $exists=$wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 1",$id,$key));
  if(!$exists)return array('source'=>'database','exists'=>false,'value'=>null,'post_id'=>$id,'meta_key'=>$key);
  $raw=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d LIMIT 1",absint($exists)));
  $value=maybe_unserialize($raw);
  return array('source'=>'database','exists'=>true,'value'=>$value,'post_id'=>$id,'meta_key'=>$key);
 }
}

