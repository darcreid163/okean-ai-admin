<?php
/**
 * Авторские права Андриянов Н.Г.
 *
 * Unified adapter catalog for the Native AI Gateway.
 */
if (!defined('ABSPATH')) exit;

class OAI_Adapter_Catalog {
 public static function definitions(){
  return array(
   'elementor'=>array('kind'=>'builder','status'=>'available','surface'=>'page_builder','description'=>'Elementor builder-owned document read/save pipeline.'),
   'gutenberg'=>array('kind'=>'builder','status'=>'available','surface'=>'block_editor','description'=>'Native Gutenberg/freeform post content resolver and sandbox patching.'),
   'woocommerce'=>array('kind'=>'platform','status'=>'available','surface'=>'commerce','description'=>'WooCommerce product read and sandbox field patch pipeline.'),
   'divi'=>array('kind'=>'builder','status'=>'planned','surface'=>'page_builder','description'=>'Divi-specific read/save adapter.'),
   'beaver_builder'=>array('kind'=>'builder','status'=>'planned','surface'=>'page_builder','description'=>'Beaver Builder-specific read/save adapter.'),
   'breakdance'=>array('kind'=>'builder','status'=>'planned','surface'=>'page_builder','description'=>'Breakdance-specific read/save adapter.'),
   'bricks'=>array('kind'=>'builder','status'=>'planned','surface'=>'page_builder','description'=>'Bricks-specific read/save adapter.'),
   'kadence'=>array('kind'=>'builder','status'=>'planned','surface'=>'block_editor','description'=>'Kadence block/theme-aware adapter.'),
   'generatepress'=>array('kind'=>'theme','status'=>'planned','surface'=>'theme','description'=>'GeneratePress-aware theme and block surface adapter.'),
   'editable_fields'=>array('kind'=>'surface','status'=>'partial','surface'=>'content','description'=>'Unified field resolver for supported WordPress object fields.'),
   'design'=>array('kind'=>'surface','status'=>'partial','surface'=>'design','description'=>'Design-token and style inspection/editing foundation; write adapters are still being expanded.'),
   'seo_audit'=>array('kind'=>'service','status'=>'partial','surface'=>'seo','description'=>'SEO inspection foundation; provider-specific scoring/reporting is still being expanded.'),
   'site_setup'=>array('kind'=>'service','status'=>'partial','surface'=>'site_setup','description'=>'Core site diagnostics and setup primitives; guided setup workflows are being expanded.'),
   'our_own'=>array('kind'=>'platform','status'=>'available','surface'=>'native','description'=>'Okean AI Admin native surfaces, lifecycle, verification and rollback.'),
  );
 }

 public static function catalog(){
  $items=array();
  foreach(self::definitions() as $id=>$def){
   $item=$def;
   $item['id']=$id;
   if($id==='elementor')$item['runtime_available']=defined('ELEMENTOR_VERSION');
   elseif($id==='woocommerce')$item['runtime_available']=class_exists('WooCommerce');
   elseif($id==='gutenberg')$item['runtime_available']=function_exists('parse_blocks') && function_exists('serialize_blocks');
   elseif($id==='generatepress')$item['runtime_available']=get_template()==='generatepress' || get_stylesheet()==='generatepress';
   else $item['runtime_available']=false;
   $items[]=$item;
  }
  return array('contract'=>'ai-admin/adapters/v1','items'=>$items);
 }

 public static function get($id){
  $id=sanitize_key(str_replace('.','_',(string)$id));
  $defs=self::definitions();
  if(!isset($defs[$id]))return new WP_Error('oai_adapter_not_found','Adapter not found',array('status'=>404,'adapter'=>$id));
  $item=$defs[$id];
  $item['id']=$id;
  return $item;
 }

 public static function detect($post_id){
  $post_id=absint($post_id);
  if(!$post_id)return new WP_Error('oai_adapter_context','post_id is required',array('status'=>400));
  $matches=array();
  $elementor=(defined('ELEMENTOR_VERSION') && (bool)get_post_meta($post_id,'_elementor_edit_mode',true));
  if($elementor)$matches[]=array('id'=>'elementor','reason'=>'Elementor edit mode metadata is present.');
  if(function_exists('has_blocks') && has_blocks($post_id))$matches[]=array('id'=>'gutenberg','reason'=>'Post contains native Gutenberg blocks.');
  $post_type=get_post_type($post_id);
  if($post_type==='product' && class_exists('WooCommerce'))$matches[]=array('id'=>'woocommerce','reason'=>'Post is a WooCommerce product.');
  if(!$matches)$matches[]=array('id'=>'our_own','reason'=>'No specialized builder detected; native Okean surfaces remain available.');
  return array('post_id'=>$post_id,'matches'=>$matches,'primary'=>$matches[0]['id']);
 }
}
