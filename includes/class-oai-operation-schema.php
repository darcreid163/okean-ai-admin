<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Canonical operation catalog shared by planner, agent and clients.
 */
class OAI_Operation_Schema {
 public static function catalog(){
  $catalog=array(
   'woodmart_theme_setting'=>array('domain'=>'woodmart','mutating'=>true,'target'=>'setting_id','value'=>'native_setting_value','approval'=>true),
   'option_update'=>array('domain'=>'options','mutating'=>true,'target'=>'option_name','value'=>'scalar|object|array','approval'=>true),
   'post_title'=>array('domain'=>'content','mutating'=>true,'target'=>'post_id:post_title','value'=>'string','approval'=>true),
   'post_excerpt'=>array('domain'=>'content','mutating'=>true,'target'=>'post_id:post_excerpt','value'=>'string','approval'=>true),
   'post_content'=>array('domain'=>'content','mutating'=>true,'target'=>'post_id:post_content','value'=>'string','approval'=>true),
   'elementor_widget_html'=>array('domain'=>'elementor','mutating'=>true,'target'=>'post_id:widget_id','value'=>'html','approval'=>true),
   'elementor_widget_settings'=>array('domain'=>'elementor','mutating'=>true,'target'=>'post_id:widget_id','value'=>'object','approval'=>true),
   'elementor_page_create'=>array('domain'=>'elementor','mutating'=>true,'target'=>'post_id:elementor_page','value'=>'elementor_tree|base64_json','approval'=>true),
   'woo_product_price'=>array('domain'=>'woocommerce','mutating'=>true,'target'=>'product_id','value'=>'price_object','approval'=>true),
   'woo_product_stock'=>array('domain'=>'woocommerce','mutating'=>true,'target'=>'product_id','value'=>'stock_object','approval'=>true),
   'file_write'=>array('domain'=>'files','mutating'=>true,'target'=>'scope:path','value'=>'text','approval'=>true),
   'content_patch'=>array('domain'=>'content','mutating'=>true,'target'=>'post_id:field','value'=>'patch','approval'=>true),
   'woocommerce_patch'=>array('domain'=>'woocommerce','mutating'=>true,'target'=>'product_id','value'=>'patch','approval'=>true),
   'workspace_write'=>array('domain'=>'workspace','mutating'=>true,'target'=>'workspace_surface','value'=>'text|json','approval'=>true),
  );
  foreach($catalog as $type=>&$schema)$schema['risk']=OAI_Operations::risk($type,'');
  unset($schema);
  return $catalog;
 }
 public static function get($type){$c=self::catalog(); return isset($c[$type])?$c[$type]:null;}
 public static function validate($type,$target,$value=null){
  $s=self::get($type); if(!$s)return OAI_Errors::make('oai_type','Unsupported operation type',400,array('type'=>$type));
  if($target===null || $target==='')return OAI_Errors::make('oai_target','Operation target is required',400,array('type'=>$type));
  if($s['value']==='object' && !is_array($value))return OAI_Errors::make('oai_value','Operation value must be an object',400,array('type'=>$type));
  return true;
 }
}

