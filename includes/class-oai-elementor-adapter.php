<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Elementor_Adapter implements OAI_Builder_Adapter {
 public function id(){return 'elementor';}
 public function supports($post_id){return defined('ELEMENTOR_VERSION') && (bool)get_post_meta((int)$post_id,'_elementor_edit_mode',true);}
 public function read($post_id){return OAI_Elementor::get_data((int)$post_id);}
 public function schema($name=''){
  if($name==='') return array('widgets'=>OAI_Elementor::widget_schema('heading'),'globals'=>OAI_Elementor::globals());
  return OAI_Elementor::widget_schema($name);
 }
 public function save($post_id,array $data,array $options=array()){return OAI_Elementor::save_document((int)$post_id,$data);}
 public function create(array $args){
  if(!OAI_Elementor::available())return new WP_Error('oai_elementor_unavailable','Elementor is not available',array('status'=>503));
  $title=sanitize_text_field((string)($args['title']??'')); if($title==='')return new WP_Error('oai_title','title is required',array('status'=>400));
  $status=sanitize_key((string)($args['status']??'draft')); if(!in_array($status,array('draft','publish','private'),true))$status='draft';
  $template_type=sanitize_key((string)($args['template_type']??'wp-page')); $post_type=sanitize_key((string)($args['post_type']??'page'));
  try{
   $doc=\Elementor\Plugin::$instance->documents->create($template_type,array('post_title'=>$title,'post_status'=>$status,'post_type'=>$post_type));
   if(is_wp_error($doc))return $doc; if(!$doc)return new WP_Error('oai_document_create','Elementor document creation failed',array('status'=>500));
   $id=(int)$doc->get_main_id();
   if(!empty($args['page_template'])) update_post_meta($id,'_wp_page_template',sanitize_text_field($args['page_template']));
   return array('id'=>$id,'document'=>$doc);
  }catch(Throwable $e){return new WP_Error('oai_document_create','Elementor document creation failed',array('status'=>500,'message'=>$e->getMessage()));}
 }
 public function verify($post_id,array $requested=array()){
  $post_id=(int)$post_id;
  $raw=(string)get_post_meta($post_id,'_elementor_data',true);
  $data=$raw!==''?json_decode($raw,true):null;
  $valid=is_array($data) && json_last_error()===JSON_ERROR_NONE;
  return array('ok'=>$raw!=='' && $valid && (empty($requested)?true:!empty($data)),'bytes'=>strlen($raw),'node_count'=>$this->count_nodes($data),'valid_json'=>$valid,'edit_mode'=>get_post_meta($post_id,'_elementor_edit_mode',true),'template_type'=>get_post_meta($post_id,'_elementor_template_type',true));
 }
 private function repair_empty_meta($post_id,array $data){
  $post_id=(int)$post_id;
  $raw=(string)get_post_meta($post_id,'_elementor_data',true);
  $current=$raw!==''?json_decode($raw,true):null;
  if(is_array($current) && !empty($current)) return true;
  $encoded=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if($encoded===false)return new WP_Error('oai_elementor_encode','Could not encode Elementor data',array('status'=>500));
  update_post_meta($post_id,'_elementor_data',wp_slash($encoded));
  update_post_meta($post_id,'_elementor_edit_mode','builder');
  update_post_meta($post_id,'_elementor_template_type','wp-page');
  clean_post_cache($post_id); wp_cache_delete($post_id,'post_meta');
  $check=(string)get_post_meta($post_id,'_elementor_data',true); $decoded=json_decode($check,true);
  if($check==='' || !is_array($decoded) || empty($decoded))return new WP_Error('oai_elementor_verify','Elementor data repair failed',array('status'=>500));
  return true;
 }
 private function count_nodes($node){$n=0;if(!is_array($node))return 0;if(isset($node['id']))$n++;foreach($node as $v)if(is_array($v))$n+=$this->count_nodes($v);return $n;}
 public function preview($post_id){return array('url'=>get_permalink((int)$post_id),'post_id'=>(int)$post_id);}
 public function rollback($post_id,array $snapshot){return OAI_Elementor::save_document((int)$post_id,$snapshot);}
}
