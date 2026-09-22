<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_WP_Post_Resolver {
 public static function resolve($id,$opts=array()){
  $p=get_post(absint($id)); if(!$p)return new WP_Error('oai_post_not_found','WordPress post not found',array('status'=>404,'post_id'=>(int)$id));
  $builder='gutenberg';
  $elementor=(string)get_post_meta($p->ID,'_elementor_data',true);
  if($elementor!=='')$builder='elementor';
  elseif($p->post_content!=='' && function_exists('has_blocks') && has_blocks($p->post_content))$builder='gutenberg';
  elseif(in_array($p->post_type,array('wp_template','wp_template_part','wp_navigation','wp_block'),true))$builder='gutenberg';
  return array('post_id'=>(int)$p->ID,'post_type'=>$p->post_type,'post_status'=>$p->post_status,'title'=>$p->post_title,'builder'=>$builder,'elementor'=>array('available'=>class_exists('Elementor\\Plugin'),'data_exists'=>$elementor!==''),'has_blocks'=>function_exists('has_blocks')?has_blocks($p->post_content):false,'permalink'=>get_permalink($p));
 }
}
