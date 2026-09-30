<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_WooCommerce {
 public static function available(){return function_exists('wc_get_product');}
 public static function validate_price($raw,$field='regular_price'){
  $normalized=is_scalar($raw)?str_replace(',','.',trim((string)$raw)):'';
  if($raw!=='' && ($normalized==='' || !is_numeric($normalized) || (float)$normalized<0 || !is_finite((float)$normalized)))return new WP_Error('oai_woo_price','Invalid WooCommerce price: '.$field,array('status'=>422,'field'=>$field));
  return true;
 }
 public static function validate_snapshot($data){
  if(!is_array($data))return new WP_Error('oai_woo_snapshot','Invalid WooCommerce product snapshot',array('status'=>422));
  foreach(array('regular_price','sale_price') as $field)if(array_key_exists($field,$data)){$valid=self::validate_price($data[$field],$field);if(is_wp_error($valid))return $valid;}
  if(isset($data['regular_price'],$data['sale_price']) && (string)$data['sale_price']!=='' && (string)$data['regular_price']!=='' && (float)$data['sale_price']>(float)$data['regular_price'])return new WP_Error('oai_woo_sale_price','Sale price exceeds regular price',array('status'=>422));
  if(isset($data['stock']) && (!is_numeric($data['stock']) || (float)$data['stock']<0))return new WP_Error('oai_woo_stock','Invalid WooCommerce stock quantity',array('status'=>422));
  if(isset($data['stock_status'])&&!in_array($data['stock_status'],array('instock','outofstock','onbackorder'),true))return new WP_Error('oai_woo_stock_status','Invalid WooCommerce stock_status',array('status'=>422));
  return true;
 }
 public static function get($id){return self::get_full($id);}
 private static function seo_snapshot($id){
  $keys=array(
   'yoast_title'=>'_yoast_wpseo_title','yoast_description'=>'_yoast_wpseo_metadesc',
   'rank_math_title'=>'rank_math_title','rank_math_description'=>'rank_math_description',
   'aioseo_title'=>'_aioseo_title','aioseo_description'=>'_aioseo_description'
  );
  $out=array();foreach($keys as $k=>$meta)$out[$k]=(string)get_post_meta($id,$meta,true);return $out;
 }
 private static function attribute_snapshot($attribute){
  if(!is_object($attribute))return array();
  return array('id'=>(int)$attribute->get_id(),'name'=>(string)$attribute->get_name(),'options'=>array_values((array)$attribute->get_options()),'position'=>(int)$attribute->get_position(),'visible'=>(bool)$attribute->get_visible(),'variation'=>(bool)$attribute->get_variation());
 }
 private static function variation_snapshot($v){
  return array('id'=>(int)$v->get_id(),'sku'=>(string)$v->get_sku(),'status'=>(string)$v->get_status(),'regular_price'=>(string)$v->get_regular_price(),'sale_price'=>(string)$v->get_sale_price(),'price'=>(string)$v->get_price(),'stock'=>$v->get_stock_quantity(),'stock_status'=>(string)$v->get_stock_status(),'manage_stock'=>(bool)$v->managing_stock(),'attributes'=>(array)$v->get_attributes());
 }
 public static function get_full($id){
  if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));
  $p=wc_get_product((int)$id);if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  $attrs=array();foreach((array)$p->get_attributes() as $a)$attrs[]=self::attribute_snapshot($a);
  $vars=array();if($p->is_type('variable'))foreach((array)$p->get_children() as $child){$v=wc_get_product($child);if($v)$vars[]=self::variation_snapshot($v);}
  return array(
   'id'=>(int)$p->get_id(),'type'=>(string)$p->get_type(),'sku'=>(string)$p->get_sku(),'name'=>(string)$p->get_name(),
   'slug'=>(string)$p->get_slug(),'description'=>(string)$p->get_description(),'short_description'=>(string)$p->get_short_description(),
   'regular_price'=>(string)$p->get_regular_price(),'sale_price'=>(string)$p->get_sale_price(),'price'=>(string)$p->get_price(),
   'stock'=>$p->get_stock_quantity(),'stock_status'=>(string)$p->get_stock_status(),'manage_stock'=>(bool)$p->managing_stock(),
   'status'=>(string)$p->get_status(),'featured'=>(bool)$p->get_featured(),'catalog_visibility'=>(string)$p->get_catalog_visibility(),
   'categories'=>array_values(array_map('intval',(array)$p->get_category_ids())),'tags'=>array_values(array_map('intval',(array)$p->get_tag_ids())),
   'image_id'=>(int)$p->get_image_id(),'gallery_ids'=>array_values(array_map('intval',(array)$p->get_gallery_image_ids())),
   'attributes'=>$attrs,'variations'=>$vars,'seo'=>self::seo_snapshot($p->get_id())
  );
 }
 private static function normalize_ids($value,$taxonomy){
  if(!is_array($value))return new WP_Error('oai_woo_terms','Term list must be an array',array('status'=>422));
  $ids=array();foreach($value as $item){
   if(is_numeric($item)){$id=absint($item);if($id)$ids[]=$id;continue;}
   $name=sanitize_text_field((string)$item);if($name==='')continue;
   $term=get_term_by('slug',sanitize_title($name),$taxonomy);if(!$term)$term=get_term_by('name',$name,$taxonomy);
   if(!$term)return new WP_Error('oai_woo_term','WooCommerce term not found: '.$name,array('status'=>422,'taxonomy'=>$taxonomy));
   $ids[]=(int)$term->term_id;
  }
  return array_values(array_unique($ids));
 }
 private static function normalize_attributes($value){
  if(!is_array($value))return new WP_Error('oai_woo_attributes','attributes must be an array',array('status'=>422));
  $out=array();foreach($value as $a){
   if(!is_array($a))return new WP_Error('oai_woo_attribute','Invalid attribute payload',array('status'=>422));
   $name=sanitize_text_field((string)($a['name']??''));if($name==='')return new WP_Error('oai_woo_attribute','Attribute name is required',array('status'=>422));
   $out[]=array('id'=>absint($a['id']??0),'name'=>$name,'options'=>array_values((array)($a['options']??array())),'position'=>absint($a['position']??0),'visible'=>array_key_exists('visible',$a)?(bool)$a['visible']:true,'variation'=>!empty($a['variation']));
  }return $out;
 }
 private static function normalize_variation_update($v){
  if(!is_array($v))return new WP_Error('oai_woo_variation','Invalid variation payload',array('status'=>422));
  $id=absint($v['id']??0);if(!$id)return new WP_Error('oai_woo_variation_id','Existing product variation updates require id',array('status'=>422));
  $out=array('id'=>$id);
  foreach(array('sku','regular_price','sale_price','stock_status','status') as $k)if(array_key_exists($k,$v))$out[$k]=sanitize_text_field((string)$v[$k]);
  if(array_key_exists('stock_quantity',$v))$out['stock']=$v['stock_quantity']===null?null:wc_stock_amount($v['stock_quantity']);
  if(array_key_exists('manage_stock',$v))$out['manage_stock']=(bool)$v['manage_stock'];
  if(array_key_exists('attributes',$v))$out['attributes']=(array)$v['attributes'];
  return $out;
 }
 private static function seo_meta_map(){return array('yoast_title'=>'_yoast_wpseo_title','yoast_description'=>'_yoast_wpseo_metadesc','rank_math_title'=>'rank_math_title','rank_math_description'=>'rank_math_description','aioseo_title'=>'_aioseo_title','aioseo_description'=>'_aioseo_description');}

 public static function search($query='', $sku='', $limit=20,$page=1,$fields='all'){
  if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));
  if(($page!=1||$fields!=='all')&&!OAI_Platform::content_navigation_allowed())return new WP_Error('oai_feature_disabled','Content navigation is disabled',array('status'=>403));
  $limit=min(100,max(1,(int)$limit)); $page=max(1,min(10000,(int)$page));
  $allowed=array('id','sku','name','regular_price','sale_price','price','stock','stock_status','manage_stock','status');
  $selected=$fields==='all'?$allowed:($fields==='summary'?array('id','sku','name','status'):array_values(array_unique(array_filter(array_map('trim',explode(',',(string)$fields))))));
  if(!$selected||array_diff($selected,$allowed))return new WP_Error('oai_woo_fields','Invalid product fields',array('status'=>400,'allowed'=>$allowed));
  $args=array('limit'=>$limit,'page'=>$page,'paginate'=>true,'return'=>'objects','status'=>array('publish','draft','private','pending'));
  if($sku!=='')$args['sku']=sanitize_text_field($sku);
  if($query!=='')$args['s']=sanitize_text_field($query);
  $result=wc_get_products($args);
  if(!is_object($result)||!isset($result->products,$result->total,$result->max_num_pages))return new WP_Error('oai_woo_page','WooCommerce did not return a paginated product result',array('status'=>500));
  $items=array(); foreach($result->products as $p){$row=array('id'=>$p->get_id(),'sku'=>$p->get_sku(),'name'=>$p->get_name(),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'price'=>$p->get_price(),'stock'=>$p->get_stock_quantity(),'stock_status'=>$p->get_stock_status(),'manage_stock'=>$p->managing_stock(),'status'=>$p->get_status());$items[]=array_intersect_key($row,array_flip($selected));}
  return array('query'=>$query,'sku'=>$sku,'page'=>$page,'limit'=>$limit,'fields'=>$selected,'count'=>count($items),'total'=>(int)$result->total,'total_pages'=>(int)$result->max_num_pages,'has_more'=>$page<(int)$result->max_num_pages,'items'=>$items);
 }
 public static function variations($product_id,$page=1,$limit=20){
  if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));
  $product=wc_get_product(absint($product_id));if(!$product)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  $page=max(1,min(10000,absint($page?:1)));$limit=max(1,min(100,absint($limit?:20)));
  $children=method_exists($product,'get_children')?(array)$product->get_children():array();$total=count($children);$total_pages=max(1,(int)ceil($total/$limit));$slice=array_slice($children,($page-1)*$limit,$limit);$items=array();
  foreach($slice as $child_id){$v=wc_get_product(absint($child_id));if(!$v)continue;$items[]=array('id'=>$v->get_id(),'parent_id'=>$product->get_id(),'sku'=>$v->get_sku(),'status'=>$v->get_status(),'regular_price'=>$v->get_regular_price(),'sale_price'=>$v->get_sale_price(),'price'=>$v->get_price(),'stock'=>$v->get_stock_quantity(),'stock_status'=>$v->get_stock_status(),'manage_stock'=>$v->managing_stock(),'attributes'=>$v->get_attributes());}
  return array('product_id'=>$product->get_id(),'product_type'=>$product->get_type(),'page'=>$page,'limit'=>$limit,'count'=>count($items),'total'=>$total,'total_pages'=>$total_pages,'has_more'=>$page<$total_pages,'items'=>$items);
 }
 public static function orders($page=1,$limit=20,$status='any'){
  if(!function_exists('wc_get_orders'))return new WP_Error('oai_woo','WooCommerce order API unavailable',array('status'=>503));
  $page=max(1,min(10000,absint($page?:1)));$limit=max(1,min(100,absint($limit?:20)));$status=sanitize_key((string)$status);if(strpos($status,'wc-')===0)$status=substr($status,3);
  if($status!==''&&$status!=='any'&&function_exists('wc_get_order_statuses')){$allowed=array_map(function($k){return strpos($k,'wc-')===0?substr($k,3):$k;},array_keys(wc_get_order_statuses()));if(!in_array($status,$allowed,true))return new WP_Error('oai_woo_order_status','Invalid WooCommerce order status',array('status'=>422,'allowed'=>$allowed));}
  $args=array('limit'=>$limit,'page'=>$page,'paginate'=>true,'return'=>'objects','orderby'=>'date','order'=>'DESC');if($status!==''&&$status!=='any')$args['status']=$status;
  $result=wc_get_orders($args);if(!is_object($result)||!isset($result->orders,$result->total,$result->max_num_pages))return new WP_Error('oai_woo_orders','WooCommerce did not return a paginated order result',array('status'=>500));
  $items=array();foreach((array)$result->orders as $o){if(!is_object($o))continue;$created=$o->get_date_created();$items[]=array('id'=>$o->get_id(),'status'=>$o->get_status(),'date_created'=>$created?$created->date('c'):null,'currency'=>$o->get_currency(),'total'=>$o->get_total(),'payment_method'=>$o->get_payment_method(),'line_items'=>count($o->get_items('line_item')),'shipping_items'=>count($o->get_items('shipping')));}
  return array('page'=>$page,'limit'=>$limit,'status'=>$status?:'any','count'=>count($items),'total'=>(int)$result->total,'total_pages'=>(int)$result->max_num_pages,'has_more'=>$page<(int)$result->max_num_pages,'privacy'=>'summary_no_customer_pii','items'=>$items);
 }
 public static function patch($id,$changes){
  $p=wc_get_product((int)$id);if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  if(!is_array($changes)||!$changes)return new WP_Error('oai_noop','No WooCommerce changes supplied',array('status'=>400));
  $allowed=array('name','slug','description','short_description','sku','regular_price','sale_price','stock_quantity','stock_status','manage_stock','categories','tags','images','attributes','variations','status','featured','catalog_visibility','seo');
  foreach($changes as $k=>$v)if(!in_array($k,$allowed,true))return new WP_Error('oai_field','WooCommerce field is not allowed: '.$k,array('status'=>400,'allowed'=>$allowed));
  $before=self::get_full($id);if(is_wp_error($before))return $before;$after=$before;
  foreach(array('regular_price','sale_price') as $k)if(array_key_exists($k,$changes)){$valid=self::validate_price($changes[$k],$k);if(is_wp_error($valid))return $valid;$after[$k]=wc_format_decimal($changes[$k]);}
  if((string)$after['sale_price']!==''&&(string)$after['regular_price']!==''&&(float)$after['sale_price']>(float)$after['regular_price'])return new WP_Error('oai_woo_sale_price','Sale price exceeds regular price',array('status'=>422));
  foreach(array('name','description','short_description') as $k)if(array_key_exists($k,$changes))$after[$k]=(string)$changes[$k];
  if(array_key_exists('slug',$changes))$after['slug']=sanitize_title((string)$changes['slug']);
  if(array_key_exists('sku',$changes))$after['sku']=wc_clean((string)$changes['sku']);
  if(array_key_exists('manage_stock',$changes))$after['manage_stock']=(bool)$changes['manage_stock'];
  if(array_key_exists('stock_quantity',$changes)){if($changes['stock_quantity']!==null&&(!is_numeric($changes['stock_quantity'])||(float)$changes['stock_quantity']<0))return new WP_Error('oai_woo_stock','Invalid WooCommerce stock quantity',array('status'=>422));$after['stock']=$changes['stock_quantity']===null?null:wc_stock_amount($changes['stock_quantity']);}
  if(array_key_exists('stock_status',$changes)){if(!in_array($changes['stock_status'],array('instock','outofstock','onbackorder'),true))return new WP_Error('oai_woo_stock_status','Invalid WooCommerce stock_status',array('status'=>422));$after['stock_status']=sanitize_key($changes['stock_status']);}
  if(array_key_exists('status',$changes)){if(!in_array($changes['status'],array('draft','publish','private','pending'),true))return new WP_Error('oai_woo_status','Invalid product status',array('status'=>422));$after['status']=sanitize_key($changes['status']);}
  if(array_key_exists('featured',$changes))$after['featured']=(bool)$changes['featured'];
  if(array_key_exists('catalog_visibility',$changes)){if(!in_array($changes['catalog_visibility'],array('visible','catalog','search','hidden'),true))return new WP_Error('oai_woo_visibility','Invalid catalog visibility',array('status'=>422));$after['catalog_visibility']=sanitize_key($changes['catalog_visibility']);}
  if(array_key_exists('categories',$changes)){ $ids=self::normalize_ids($changes['categories'],'product_cat');if(is_wp_error($ids))return $ids;$after['categories']=$ids;}
  if(array_key_exists('tags',$changes)){ $ids=self::normalize_ids($changes['tags'],'product_tag');if(is_wp_error($ids))return $ids;$after['tags']=$ids;}
  if(array_key_exists('images',$changes)){
   if(!is_array($changes['images']))return new WP_Error('oai_woo_images','images must be an array of attachment IDs',array('status'=>422));
   $ids=array_values(array_filter(array_map('absint',$changes['images'])));foreach($ids as $aid)if(get_post_type($aid)!=='attachment')return new WP_Error('oai_woo_image','Attachment not found: '.$aid,array('status'=>422));
   $after['image_id']=$ids?(int)$ids[0]:0;$after['gallery_ids']=$ids?array_slice($ids,1):array();
  }
  if(array_key_exists('attributes',$changes)){ $attrs=self::normalize_attributes($changes['attributes']);if(is_wp_error($attrs))return $attrs;$after['attributes']=$attrs;}
  if(array_key_exists('variations',$changes)){
   if($before['type']!=='variable')return new WP_Error('oai_woo_variations','Variations require a variable product',array('status'=>422));
   if(!is_array($changes['variations']))return new WP_Error('oai_woo_variations','variations must be an array',array('status'=>422));
   $map=array();foreach($after['variations'] as $i=>$v)$map[(int)$v['id']]=$i;
   foreach($changes['variations'] as $v){$n=self::normalize_variation_update($v);if(is_wp_error($n))return $n;$vid=(int)$n['id'];if(!isset($map[$vid]))return new WP_Error('oai_woo_variation_parent','Variation does not belong to product',array('status'=>422,'variation_id'=>$vid));$after['variations'][$map[$vid]]=array_merge($after['variations'][$map[$vid]],$n);}
  }
  if(array_key_exists('seo',$changes)){
   if(!is_array($changes['seo']))return new WP_Error('oai_woo_seo','seo must be an object',array('status'=>422));
   foreach($changes['seo'] as $k=>$v){if(!array_key_exists($k,self::seo_meta_map()))return new WP_Error('oai_woo_seo_field','Unsupported SEO field: '.$k,array('status'=>422));$after['seo'][$k]=sanitize_text_field((string)$v);}
  }
  $after['price']=(string)$after['sale_price']!==''?$after['sale_price']:$after['regular_price'];
  if(array_key_exists('stock_quantity',$changes)&&!array_key_exists('stock_status',$changes)&&$after['manage_stock']&&$after['stock']!==null)$after['stock_status']=(float)$after['stock']>0?'instock':($p->backorders_allowed()?'onbackorder':'outofstock');
  return array('before'=>$before,'after'=>$after,'changes'=>$changes,'id'=>(int)$id);
 }
 public static function runtime_matches_changes($actual,$changes,$before=array(),$expected_after=array()){
  if(!is_array($actual)||!is_array($changes)||!is_array($before))return new WP_Error('oai_woo_runtime','Invalid WooCommerce runtime snapshot',array('status'=>500));
  $expected=is_array($expected_after)&&$expected_after?$expected_after:$before;
  if(!$expected_after){
   foreach($changes as $change=>$value){
    if($change==='stock_quantity'){$expected['stock']=$value===null?null:wc_stock_amount($value);if(!array_key_exists('stock_status',$changes)&&!empty($expected['manage_stock'])&&$expected['stock']!==null)$expected['stock_status']=(float)$expected['stock']>0?'instock':'outofstock';continue;}
    if($change==='images'){$ids=is_array($value)?array_values(array_filter(array_map('absint',$value))):array();$expected['image_id']=$ids?(int)$ids[0]:0;$expected['gallery_ids']=$ids?array_slice($ids,1):array();continue;}
    if($change==='regular_price'||$change==='sale_price'){$expected[$change]=wc_format_decimal($value);continue;}
    if($change==='manage_stock'||$change==='featured'){$expected[$change]=(bool)$value;continue;}
    if($change==='slug'){$expected[$change]=sanitize_title((string)$value);continue;}
    if($change==='sku'){$expected[$change]=wc_clean((string)$value);continue;}
    if($change==='status'||$change==='stock_status'||$change==='catalog_visibility'){$expected[$change]=sanitize_key((string)$value);continue;}
    $expected[$change]=$value;
   }
  }
  foreach($changes as $change=>$unused){
   $fields=$change==='stock_quantity'?array('stock'):($change==='images'?array('image_id','gallery_ids'):array($change));
   if($change==='stock_quantity'&&!array_key_exists('stock_status',$changes)&&array_key_exists('stock_status',$expected))$fields[]='stock_status';
   foreach($fields as $field){
    if(!array_key_exists($field,$expected))continue;
    if(!OAI_Verify::same($actual[$field]??null,$expected[$field]))return new WP_Error('oai_woo_runtime','WooCommerce runtime did not persist requested field: '.$change,array('status'=>409,'field'=>$field,'expected'=>$expected[$field],'actual'=>$actual[$field]??null));
   }
  }
  foreach(array('type','id') as $field)if(isset($before[$field])&&!OAI_Verify::same($actual[$field]??null,$before[$field]))return new WP_Error('oai_woo_runtime','WooCommerce changed invariant field: '.$field,array('status'=>409));
  return true;
 }
 public static function created_snapshot_matches($actual,$expected){
  if(!is_array($actual)||!is_array($expected))return false;
  $a=$actual;$e=$expected;
  // Variable-product parent price and stock status are derived by WooCommerce
  // from child variations after save/sync. They are not stable create inputs
  // and must not turn a successful create into a false verification failure.
  if(($e['type']??'')==='variable'){
   unset($a['price'],$a['stock_status'],$e['price'],$e['stock_status']);
  }
  return OAI_Verify::same($a,$e);
 }
 public static function apply($id,$data){
  $valid=self::validate_snapshot($data);if(is_wp_error($valid))return $valid;
  $p=wc_get_product((int)$id);if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  if(isset($data['type'])&&(string)$data['type']!==$p->get_type())return new WP_Error('oai_woo_type','Changing product type is not supported by update_product_sandbox',array('status'=>422));
  try{
   if(array_key_exists('name',$data))$p->set_name(wp_strip_all_tags((string)$data['name']));
   if(array_key_exists('slug',$data))$p->set_slug(sanitize_title((string)$data['slug']));
   if(array_key_exists('description',$data))$p->set_description(wp_kses_post((string)$data['description']));
   if(array_key_exists('short_description',$data))$p->set_short_description(wp_kses_post((string)$data['short_description']));
   if(array_key_exists('sku',$data))$p->set_sku(wc_clean((string)$data['sku']));
   if(array_key_exists('regular_price',$data))$p->set_regular_price(wc_format_decimal($data['regular_price']));
   if(array_key_exists('sale_price',$data))$p->set_sale_price(wc_format_decimal($data['sale_price']));
   if(array_key_exists('manage_stock',$data))$p->set_manage_stock((bool)$data['manage_stock']);
   if(array_key_exists('stock',$data))$p->set_stock_quantity($data['stock']===null?null:wc_stock_amount($data['stock']));
   if(array_key_exists('stock_quantity',$data))$p->set_stock_quantity($data['stock_quantity']===null?null:wc_stock_amount($data['stock_quantity']));
   if(array_key_exists('stock_status',$data))$p->set_stock_status(sanitize_key((string)$data['stock_status']));
   if(array_key_exists('status',$data))$p->set_status(sanitize_key((string)$data['status']));
   if(array_key_exists('featured',$data))$p->set_featured((bool)$data['featured']);
   if(array_key_exists('catalog_visibility',$data))$p->set_catalog_visibility(sanitize_key((string)$data['catalog_visibility']));
   if(array_key_exists('categories',$data))$p->set_category_ids(array_values(array_map('absint',(array)$data['categories'])));
   if(array_key_exists('tags',$data))$p->set_tag_ids(array_values(array_map('absint',(array)$data['tags'])));
   if(array_key_exists('image_id',$data))$p->set_image_id(absint($data['image_id']));
   if(array_key_exists('gallery_ids',$data))$p->set_gallery_image_ids(array_values(array_map('absint',(array)$data['gallery_ids'])));
   if(array_key_exists('attributes',$data)){
    $attributes=array();foreach((array)$data['attributes'] as $a){if(!is_array($a))continue;$wa=new WC_Product_Attribute();$wa->set_id(absint($a['id']??0));$wa->set_name((string)($a['name']??''));$wa->set_options(array_values((array)($a['options']??array())));$wa->set_position(absint($a['position']??0));$wa->set_visible(!empty($a['visible']));$wa->set_variation(!empty($a['variation']));$attributes[]=$wa;}$p->set_attributes($attributes);
   }
   $p->save();
   if(isset($data['seo'])&&is_array($data['seo']))foreach(self::seo_meta_map() as $k=>$meta)if(array_key_exists($k,$data['seo']))update_post_meta($p->get_id(),$meta,sanitize_text_field((string)$data['seo'][$k]));
   if($p->is_type('variable')&&isset($data['variations'])&&is_array($data['variations'])){
    foreach($data['variations'] as $row){if(!is_array($row))continue;$vid=absint($row['id']??0);if(!$vid)continue;$v=wc_get_product($vid);if(!$v||!$v->is_type('variation')||(int)$v->get_parent_id()!==(int)$p->get_id())return new WP_Error('oai_woo_variation_parent','Variation does not belong to product',array('status'=>422,'variation_id'=>$vid));
     if(array_key_exists('sku',$row))$v->set_sku(wc_clean((string)$row['sku']));
     if(array_key_exists('regular_price',$row))$v->set_regular_price(wc_format_decimal($row['regular_price']));
     if(array_key_exists('sale_price',$row))$v->set_sale_price(wc_format_decimal($row['sale_price']));
     if(array_key_exists('manage_stock',$row))$v->set_manage_stock((bool)$row['manage_stock']);
     if(array_key_exists('stock',$row))$v->set_stock_quantity($row['stock']===null?null:wc_stock_amount($row['stock']));
     if(array_key_exists('stock_status',$row))$v->set_stock_status(sanitize_key((string)$row['stock_status']));
     if(array_key_exists('status',$row))$v->set_status(sanitize_key((string)$row['status']));
     if(array_key_exists('attributes',$row))$v->set_attributes((array)$row['attributes']);$v->save();
    }
   }
   wc_delete_product_transients((int)$id);return self::get_full($id);
  }catch(Throwable $e){return new WP_Error('oai_woo_apply',$e->getMessage(),array('status'=>422));}
 }
 public static function create_draft($changes){
  if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));
  if(!is_array($changes))$changes=array();$type=sanitize_key((string)($changes['type']??'simple'));
  if(!in_array($type,array('simple','variable'),true))return new WP_Error('oai_woo_type','P0 create supports simple or variable products',array('status'=>422));
  $p=$type==='variable'?new WC_Product_Variable():new WC_Product_Simple();$p->set_name(wp_strip_all_tags((string)($changes['name']??'AI Admin draft product')));$p->set_status('draft');$id=$p->save();
  if(!$id)return new WP_Error('oai_woo_create','Failed to create draft product',array('status'=>500));
  update_post_meta($id,'_oai_sandbox_artifact','1');
  $variations=$changes['variations']??array();unset($changes['variations'],$changes['type']);$changes['status']='draft';
  $x=self::patch($id,$changes);if(is_wp_error($x)){wp_delete_post($id,true);return $x;}$applied=self::apply($id,$x['after']);if(is_wp_error($applied)){wp_delete_post($id,true);return $applied;}
  if($type==='variable'&&is_array($variations)){
   foreach($variations as $row){if(!is_array($row))continue;$v=new WC_Product_Variation();$v->set_parent_id($id);$v->set_status('publish');if(isset($row['sku']))$v->set_sku(wc_clean((string)$row['sku']));if(isset($row['regular_price']))$v->set_regular_price(wc_format_decimal($row['regular_price']));if(isset($row['sale_price']))$v->set_sale_price(wc_format_decimal($row['sale_price']));if(isset($row['manage_stock']))$v->set_manage_stock((bool)$row['manage_stock']);if(array_key_exists('stock_quantity',$row))$v->set_stock_quantity($row['stock_quantity']===null?null:wc_stock_amount($row['stock_quantity']));if(isset($row['stock_status']))$v->set_stock_status(sanitize_key((string)$row['stock_status']));if(isset($row['attributes']))$v->set_attributes((array)$row['attributes']);$v->save();}
  }
  return self::get_full($id);
 }

}
