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
 private static function normalize_stock_amount($raw){
  if($raw===null)return null;
  $normalized=is_scalar($raw)?str_replace(',','.',trim((string)$raw)):'';
  if($normalized===''||!is_numeric($normalized)||!is_finite((float)$normalized)||(float)$normalized<0)return new WP_Error('oai_woo_stock','Invalid WooCommerce stock quantity',array('status'=>422));
  // WP AI Admin policy: site inventory is stored as whole units.
  // Round halves up so fractional 1C balances are normalized predictably
  // instead of being silently truncated by WooCommerce's default intval.
  return (int)round((float)$normalized,0,PHP_ROUND_HALF_UP);
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
  foreach(array('sku','stock_status','status') as $k)if(array_key_exists($k,$v))$out[$k]=sanitize_text_field((string)$v[$k]);
  foreach(array('regular_price','sale_price') as $k)if(array_key_exists($k,$v))$out[$k]=self::normalize_variation_price($v[$k],$k);
  if(array_key_exists('stock_quantity',$v)){ $stock=self::normalize_stock_amount($v['stock_quantity']);if(is_wp_error($stock))return $stock;$out['stock']=$stock;}
  if(array_key_exists('manage_stock',$v))$out['manage_stock']=(bool)$v['manage_stock'];
  if(array_key_exists('attributes',$v))$out['attributes']=(array)$v['attributes'];
  return $out;
 }
 // Issue #140: shared strict variation validation for both patch() and apply().
 private static function validate_variations($rows){
  if(!is_array($rows))return new WP_Error('oai_woo_variations','variations must be an array',array('status'=>422));
  $allowed=array('id','sku','regular_price','sale_price','stock_quantity','stock_status','manage_stock','status','attributes');
  $statuses=array('draft','publish','private','pending','custom');
  $stock_statuses=array('instock','outofstock','onbackorder');
  $seen=array();
  foreach($rows as $i=>$row){
   if(!is_array($row))return new WP_Error('oai_woo_variation','Invalid variation payload',array('status'=>422,'index'=>$i));
   foreach($row as $k=>$unused)if(!in_array($k,$allowed,true))return new WP_Error('oai_woo_variation_field','Unsupported variation field: '.$k,array('status'=>422,'index'=>$i,'allowed'=>$allowed));
   $regular=array_key_exists('regular_price',$row)?self::normalize_variation_price($row['regular_price'],'regular_price'):'';
   if(is_wp_error($regular))return $regular;
   $sale=array_key_exists('sale_price',$row)?self::normalize_variation_price($row['sale_price'],'sale_price'):'';
   if(is_wp_error($sale))return $sale;
   if(!is_wp_error($regular)&&!is_wp_error($sale)&&(string)$sale!==''&&(string)$regular!==''&&(float)$sale>(float)$regular)return new WP_Error('oai_woo_sale_price','Sale price exceeds regular price',array('status'=>422,'index'=>$i));
   if(array_key_exists('stock_status',$row)&&!in_array((string)$row['stock_status'],$stock_statuses,true))return new WP_Error('oai_woo_stock_status','Invalid WooCommerce stock_status',array('status'=>422,'index'=>$i));
   if(array_key_exists('status',$row)&&!in_array((string)$row['status'],$statuses,true))return new WP_Error('oai_woo_status','Invalid variation status',array('status'=>422,'index'=>$i));
   if(array_key_exists('stock_quantity',$row)){$stock=self::normalize_stock_amount($row['stock_quantity']);if(is_wp_error($stock))return $stock;}
   if(array_key_exists('attributes',$row)&&!is_array($row['attributes']))return new WP_Error('oai_woo_variation_attributes','Variation attributes must be an object',array('status'=>422,'index'=>$i));
   if(!empty($row['id'])){$key='v'.absint($row['id']);if(isset($seen[$key]))return new WP_Error('oai_woo_variation_duplicate','Duplicate variation id in one request',array('status'=>422,'variation_id'=>absint($row['id'])));$seen[$key]=true;}
   if(isset($row['sku'])&&(string)$row['sku']!==''){$key='s'.strtolower((string)$row['sku']);if(isset($seen[$key]))return new WP_Error('oai_woo_variation_duplicate_sku','Duplicate child SKU in one request',array('status'=>422,'sku'=>$row['sku']));$seen[$key]=true;}
  }
  return true;
 }
 private static function normalize_variation_price($raw,$field){
  if($raw===null||$raw==='')return '';
  $valid=self::validate_price($raw,$field);if(is_wp_error($valid))return $valid;
  return wc_format_decimal($raw);
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
  if(array_key_exists('stock_quantity',$changes)){ $stock=self::normalize_stock_amount($changes['stock_quantity']);if(is_wp_error($stock))return $stock;$after['stock']=$stock;}
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
   $valid=self::validate_variations($changes['variations']);if(is_wp_error($valid))return $valid;
   $map=array();foreach($after['variations'] as $i=>$v)$map[(int)$v['id']]=$i;
   foreach($changes['variations'] as $v){$n=self::normalize_variation_update($v);if(is_wp_error($n))return $n;$vid=(int)$n['id'];if(!isset($map[$vid]))return new WP_Error('oai_woo_variation_parent','Variation does not belong to product',array('status'=>422,'variation_id'=>$vid));$row=array_merge($after['variations'][$map[$vid]],$n);
    // The variation snapshot carries WooCommerce's derived `price`. Recompute it
    // from the merged regular/sale values exactly as the parent price below, so
    // the approved `after` matches what WooCommerce persists on apply.
    $row['price']=(string)$row['sale_price']!==''?$row['sale_price']:$row['regular_price'];
    $after['variations'][$map[$vid]]=$row;}
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
    if($change==='stock_quantity'){ $stock=self::normalize_stock_amount($value);if(is_wp_error($stock))return $stock;$expected['stock']=$stock;if(!array_key_exists('stock_status',$changes)&&!empty($expected['manage_stock'])&&$expected['stock']!==null)$expected['stock_status']=(float)$expected['stock']>0?'instock':'outofstock';continue;}
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
   if($change==='variations'){
    // Variations live in a nested array. Compare only the rows and fields the
    // caller actually requested, keyed by id, instead of the whole array. The
    // derived `price` is recomputed in patch(); never compare it unless asked.
    $requested=is_array($changes['variations'])?$changes['variations']:array();
    $before_map=array();foreach((array)($before['variations']??array()) as $row)if(isset($row['id']))$before_map[(int)$row['id']]=$row;
    $expected_map=array();foreach((array)($expected['variations']??array()) as $row)if(isset($row['id']))$expected_map[(int)$row['id']]=$row;
    $actual_map=array();foreach((array)($actual['variations']??array()) as $row)if(isset($row['id']))$actual_map[(int)$row['id']]=$row;
    foreach($requested as $req_row){
     $vid=isset($req_row['id'])?(int)$req_row['id']:0;
     if(!$vid)continue;
     foreach(array_keys((array)$req_row) as $field){
      if($field==='id')continue;
      $exp=array_key_exists($field,$expected_map[$vid]??array())?$expected_map[$vid][$field]:(array_key_exists($field,$before_map[$vid]??array())?$before_map[$vid][$field]:null);
      if(!OAI_Verify::same($actual_map[$vid][$field]??null,$exp))return new WP_Error('oai_woo_runtime','WooCommerce runtime did not persist requested variation field: '.$field,array('status'=>409,'variation_id'=>$vid,'field'=>$field,'expected'=>$exp,'actual'=>$actual_map[$vid][$field]??null));
     }
    }
    continue;
   }
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
 public static function rollback_snapshot_matches($current,$expected){
  if(!is_array($current)||!is_array($expected))return false;
  $actual=$current;$applied=$expected;
  // Rollback restores the complete snapshot written by Apply. WooCommerce
  // recalculates these parent aggregates from child variation prices/stock
  // after save, so ignore only those derived fields and compare every other
  // product, variation, taxonomy, media, attribute, description and SEO field.
  if(($applied['type']??'')==='variable')unset($actual['price'],$actual['stock_status'],$applied['price'],$applied['stock_status']);
  return OAI_Verify::same($actual,$applied);
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
   if(array_key_exists('stock',$data)){ $stock=self::normalize_stock_amount($data['stock']);if(is_wp_error($stock))return $stock;$p->set_stock_quantity($stock); }
   if(array_key_exists('stock_quantity',$data)){ $stock=self::normalize_stock_amount($data['stock_quantity']);if(is_wp_error($stock))return $stock;$p->set_stock_quantity($stock); }
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
     if(array_key_exists('stock',$row)){ $stock=self::normalize_stock_amount($row['stock']);if(is_wp_error($stock))return $stock;$v->set_stock_quantity($stock); }
     if(array_key_exists('stock_status',$row))$v->set_stock_status(sanitize_key((string)$row['stock_status']));
     if(array_key_exists('status',$row))$v->set_status(sanitize_key((string)$row['status']));
     if(array_key_exists('attributes',$row))$v->set_attributes((array)$row['attributes']);$v->save();
    }
   }
   wc_delete_product_transients((int)$id);return self::get_full($id);
  }catch(Throwable $e){return new WP_Error('oai_woo_apply',$e->getMessage(),array('status'=>422));}
 }
 public static function create_draft($changes,$request_key=''){
  if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));
  if(!is_array($changes))$changes=array();$type=sanitize_key((string)($changes['type']??'simple'));
  if(!in_array($type,array('simple','variable'),true))return new WP_Error('oai_woo_type','P0 create supports simple or variable products',array('status'=>422));
  if(!current_user_can('edit_products'))return new WP_Error('oai_woo_permission','Product edit permission required',array('status'=>403));
  // Issue #140: validate every variation row before the idempotency claim is
  // reserved, so invalid or duplicate child input returns 422 with no claim,
  // no operation row and no draft product.
  $variations=$changes['variations']??array();
  if($type==='variable'){
   $valid=self::validate_variations($variations);if(is_wp_error($valid))return $valid;
  }elseif(!empty($variations)){
   return new WP_Error('oai_woo_variations','Variations require a variable product',array('status'=>422));
  }
  if(!is_string($request_key)||($request_key!==''&&!preg_match('/^[A-Za-z0-9._~-]{16,128}$/D',$request_key)))
   return new WP_Error('oai_product_request_key','Invalid product idempotency_key',array('status'=>400));
  $ready=OAI_DB::intent_autocommit();if(is_wp_error($ready))return $ready;
  $actor=get_current_user_id();
  $payload_fingerprint=OAI_Creation_Intent::fingerprint($changes);
  if(!is_string($payload_fingerprint))return new WP_Error('oai_product_intent','Cannot encode product intent',array('status'=>503));
  $identity=wp_json_encode(array('product-create-v1',get_current_blog_id(),$actor,$request_key!==''?$request_key:$payload_fingerprint));
  if(!is_string($identity))return new WP_Error('oai_product_intent','Cannot encode product identity',array('status'=>503));
  $intent='oai_product_creation_'.hash('sha256',$identity);
  global $wpdb;
  // Read committed intent records directly; object caches are not authority
  // across workers. Unique option_name is the admission barrier.
  $existing=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$intent));
  if($existing!==null){
   $prior=maybe_unserialize($existing);
   if(OAI_Creation_Intent::classify($prior,$payload_fingerprint)==='conflict')
    return new WP_Error('oai_product_intent_conflict','Reused product creation key with different payload',array('status'=>409));
   return new WP_Error('oai_product_creation_pending','Product creation requires result reconciliation',array('status'=>409));
  }
  if($wpdb->last_error!=='')return new WP_Error('oai_product_storage','Product creation reservation unavailable',array('status'=>503));
  $admission=OAI_DB::insert_creation_claim($intent,OAI_Creation_Intent::record($payload_fingerprint,array('actor'=>$actor,'created_at'=>time(),'state'=>'reserved','claim'=>bin2hex(random_bytes(16)))));
  if(is_wp_error($admission))return $admission;
  if($admission!==true)
   return new WP_Error('oai_product_creation_pending','Product creation requires result reconciliation',array('status'=>409));
  // The child loop is wrapped so a mid-loop failure cleans up every variation
  // created by this operation plus the parent draft.
  $p=$type==='variable'?new WC_Product_Variable():new WC_Product_Simple();$p->set_name(wp_strip_all_tags((string)($changes['name']??'AI Admin draft product')));$p->set_status('draft');$id=$p->save();
  if(!$id)return new WP_Error('oai_woo_create','Failed to create draft product',array('status'=>500));
  update_post_meta($id,'_oai_sandbox_artifact','1');
  unset($changes['variations'],$changes['type']);$changes['status']='draft';
  $x=self::patch($id,$changes);if(is_wp_error($x)){wp_delete_post($id,true);return $x;}$applied=self::apply($id,$x['after']);if(is_wp_error($applied)){wp_delete_post($id,true);return $applied;}
  $created_variations=array();
  if($type==='variable'&&is_array($variations)){
   try{
    foreach($variations as $row){if(!is_array($row))continue;$v=new WC_Product_Variation();$v->set_parent_id($id);$v->set_status('publish');if(isset($row['sku']))$v->set_sku(wc_clean((string)$row['sku']));if(array_key_exists('regular_price',$row))$v->set_regular_price(self::normalize_variation_price($row['regular_price'],'regular_price'));if(array_key_exists('sale_price',$row))$v->set_sale_price(self::normalize_variation_price($row['sale_price'],'sale_price'));if(isset($row['manage_stock']))$v->set_manage_stock((bool)$row['manage_stock']);if(array_key_exists('stock_quantity',$row)){$stock=self::normalize_stock_amount($row['stock_quantity']);if(is_wp_error($stock))throw new Exception('Invalid stock quantity');$v->set_stock_quantity($stock);}if(isset($row['stock_status']))$v->set_stock_status(sanitize_key((string)$row['stock_status']));if(isset($row['attributes']))$v->set_attributes((array)$row['attributes']);$vid=$v->save();if($vid)$created_variations[]=$vid;}
   }catch(Throwable $e){
    foreach($created_variations as $vid){$cv=wc_get_product($vid);if($cv)$cv->delete(true);}
    wp_delete_post($id,true);
    return new WP_Error('oai_woo_variation_create','Variation creation failed; draft and children were cleaned up',array('status'=>422,'reason'=>$e->getMessage()));
   }
  }
  $full=self::get_full($id);
  if($type==='variable'&&is_array($variations)&&count($created_variations)!==count($variations)){foreach($created_variations as $vid){$cv=wc_get_product($vid);if($cv)$cv->delete(true);}wp_delete_post($id,true);return new WP_Error('oai_woo_variation_create','Not all variations were created; draft and children were cleaned up',array('status'=>422));}
  return $full;
 }

 public static function order_write_permission(){
  if(!current_user_can('edit_shop_orders'))return new WP_Error('oai_woo_permission','WooCommerce order edit permission required',array('status'=>403));
  return true;
 }
 private static function order_address_fields(){return array('first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone');}
 private static function normalize_order_status($status){
  $status=sanitize_key((string)$status);
  if(strpos($status,'wc-')===0)$status=substr($status,3);
  return $status;
 }
 private static function order_status_allowed($status){
  $status=self::normalize_order_status($status);
  if($status==='')return false;
  if(!function_exists('wc_get_order_statuses'))return in_array($status,array('pending','processing','on-hold','completed','cancelled','refunded','failed'),true);
  $allowed=array();
  foreach(array_keys((array)wc_get_order_statuses()) as $key){$key=(string)$key;if(strpos($key,'wc-')===0)$key=substr($key,3);$allowed[]=$key;}
  return in_array($status,$allowed,true);
 }
 private static function validate_order_address($address,$type,$strict=true){
  if(!is_array($address))return new WP_Error('oai_woo_order_address','WooCommerce '.$type.' address must be an object',array('status'=>422));
  $clean=array();
  foreach(self::order_address_fields() as $field){
   if(!array_key_exists($field,$address))continue;
   $value=is_scalar($address[$field])?trim((string)$address[$field]):'';
   if($strict&&$field==='email'&&$value!==''&&!is_email($value))return new WP_Error('oai_woo_order_email','Invalid WooCommerce billing email',array('status'=>422,'field'=>$field));
   if($field==='country')$value=strtoupper($value);
   if($field==='email')$value=sanitize_email($value);
   else $value=sanitize_text_field($value);
   $clean[$field]=$value;
  }
  if(!$clean)return new WP_Error('oai_noop','No WooCommerce order address fields supplied',array('status'=>400));
  return $clean;
 }
 private static function order_item_taxes($item){
  $taxes=$item->get_taxes();$out=array('total'=>array(),'subtotal'=>array());
  foreach(array('total','subtotal') as $kind){
   if(!isset($taxes[$kind])||!is_array($taxes[$kind]))continue;
   foreach($taxes[$kind] as $rate_id=>$amount)$out[$kind][(string)$rate_id]=(string)$amount;
  }
  return $out;
 }
 private static function order_item_snapshot($item){
  return array(
   'id'=>(int)$item->get_id(),'name'=>(string)$item->get_name(),'product_id'=>(int)$item->get_product_id(),
   'variation_id'=>(int)$item->get_variation_id(),'tax_class'=>(string)$item->get_tax_class(),
   'quantity'=>(float)$item->get_quantity(),'subtotal'=>(string)$item->get_subtotal(),
   'subtotal_tax'=>(string)$item->get_subtotal_tax(),'total'=>(string)$item->get_total(),
   'total_tax'=>(string)$item->get_total_tax(),'taxes'=>self::order_item_taxes($item)
  );
 }
 private static function order_fee_snapshot($item){
  return array('id'=>(int)$item->get_id(),'name'=>(string)$item->get_name(),'total'=>(string)$item->get_total(),'total_tax'=>(string)$item->get_total_tax(),'taxes'=>self::order_item_taxes($item));
 }
 private static function order_shipping_snapshot($item){
  return array('id'=>(int)$item->get_id(),'method_id'=>(string)$item->get_method_id(),'method_title'=>(string)$item->get_method_title(),'total'=>(string)$item->get_total(),'total_tax'=>(string)$item->get_total_tax(),'taxes'=>self::order_item_taxes($item));
 }
 private static function order_date_iso($date){return $date instanceof DateTimeInterface?$date->format('c'):null;}
 private static function order_snapshot($order){
  $billing=array();$shipping=array();
  $billing_raw=$order->get_address('billing');$shipping_raw=$order->get_address('shipping');
  foreach(self::order_address_fields() as $field){
   $billing[$field]=isset($billing_raw[$field])?(string)$billing_raw[$field]:'';
   $shipping[$field]=isset($shipping_raw[$field])?(string)$shipping_raw[$field]:'';
  }
  $line_items=array();foreach($order->get_items('line_item') as $item){$row=self::order_item_snapshot($item);$line_items[$row['id']]=$row;}ksort($line_items);
  $fees=array();foreach($order->get_items('fee') as $item){$row=self::order_fee_snapshot($item);$fees[$row['id']]=$row;}ksort($fees);
  $shipping_lines=array();foreach($order->get_items('shipping') as $item){$row=self::order_shipping_snapshot($item);$shipping_lines[$row['id']]=$row;}ksort($shipping_lines);
  return array(
   'id'=>(int)$order->get_id(),'status'=>(string)$order->get_status(),'currency'=>(string)$order->get_currency(),
   'customer_id'=>(int)$order->get_customer_id(),'customer_note'=>(string)$order->get_customer_note(),
   'billing'=>$billing,'shipping'=>$shipping,
   'line_items'=>array_values($line_items),'fee_lines'=>array_values($fees),'shipping_lines'=>array_values($shipping_lines),
   'totals'=>array(
    'discount_total'=>(string)$order->get_discount_total(),'discount_tax'=>(string)$order->get_discount_tax(),
    'shipping_total'=>(string)$order->get_shipping_total(),'shipping_tax'=>(string)$order->get_shipping_tax(),
    'cart_tax'=>(string)$order->get_cart_tax(),'total_tax'=>(string)$order->get_total_tax(),
    'total'=>(string)$order->get_total()
   ),
   'date_created'=>self::order_date_iso($order->get_date_created()),'date_modified'=>self::order_date_iso($order->get_date_modified()),
   'date_paid'=>self::order_date_iso($order->get_date_paid()),'date_completed'=>self::order_date_iso($order->get_date_completed())
  );
 }
 public static function get_order($id,$include_pii=false){
  if(!function_exists('wc_get_order'))return new WP_Error('oai_woo','WooCommerce order API unavailable',array('status'=>503));
  $order=wc_get_order(absint($id));
  if(!$order||!($order instanceof WC_Order))return new WP_Error('oai_not_found','Order not found',array('status'=>404));
  $snapshot=self::order_snapshot($order);
  if($include_pii)return $snapshot;
  foreach(array('billing','shipping') as $type)foreach($snapshot[$type] as $field=>$value)$snapshot[$type][$field]=in_array($field,array('email','phone'),true)?'***':'';
  $snapshot['customer_note']='';
  $snapshot['privacy']='redacted_no_edit_shop_orders';
  return $snapshot;
 }
 public static function order_snapshot_for_operation($id){
  $permission=self::order_write_permission();if(is_wp_error($permission))return $permission;
  return self::get_order($id,true);
 }
 public static function validate_order_snapshot($data){
  if(!is_array($data)||empty($data))return new WP_Error('oai_woo_order_snapshot','Invalid WooCommerce order snapshot',array('status'=>422));
  if(array_key_exists('id',$data)&&absint($data['id'])<=0)return new WP_Error('oai_woo_order_snapshot','Invalid WooCommerce order id',array('status'=>422));
  if(array_key_exists('status',$data)&&!self::order_status_allowed($data['status']))return new WP_Error('oai_woo_order_status','Invalid WooCommerce order status',array('status'=>422));
  foreach(array('billing','shipping') as $type){
   if(!array_key_exists($type,$data))continue;
   $valid=self::validate_order_address($data[$type],$type,false);if(is_wp_error($valid))return $valid;
  }
  if(array_key_exists('customer_note',$data)&&$data['customer_note']!==null&&!is_scalar($data['customer_note']))return new WP_Error('oai_woo_order_note','Invalid WooCommerce customer note',array('status'=>422));
  if(array_key_exists('line_items',$data)){
   if(!is_array($data['line_items']))return new WP_Error('oai_woo_order_items','WooCommerce line_items must be an array',array('status'=>422));
   $seen=array();
   foreach($data['line_items'] as $row){
    if(!is_array($row))return new WP_Error('oai_woo_order_items','Invalid WooCommerce line item',array('status'=>422));
    $item_id=absint($row['id']??0);if(!$item_id||isset($seen[$item_id]))return new WP_Error('oai_woo_order_item','Invalid or duplicate WooCommerce line item id',array('status'=>422));
    $seen[$item_id]=true;
    if(!array_key_exists('quantity',$row)||!is_numeric($row['quantity'])||!is_finite((float)$row['quantity'])||(float)$row['quantity']<=0)return new WP_Error('oai_woo_quantity','Invalid WooCommerce line item quantity',array('status'=>422));
    foreach(array('subtotal','subtotal_tax','total','total_tax') as $field)if(array_key_exists($field,$row)&&!is_numeric($row[$field]))return new WP_Error('oai_woo_order_items','Invalid WooCommerce line item amount: '.$field,array('status'=>422));
   }
  }
  if(array_key_exists('totals',$data)){
   if(!is_array($data['totals']))return new WP_Error('oai_woo_order_totals','WooCommerce totals must be an object',array('status'=>422));
   foreach(array('discount_total','discount_tax','shipping_total','shipping_tax','cart_tax','total_tax','total') as $field)if(array_key_exists($field,$data['totals'])&&!is_numeric($data['totals'][$field]))return new WP_Error('oai_woo_order_totals','Invalid WooCommerce total: '.$field,array('status'=>422));
  }
  return true;
 }
 private static function scale_order_amount($amount,$old_quantity,$new_quantity){
  if(!is_numeric($amount)||(float)$old_quantity<=0)return (string)$amount;
  return wc_format_decimal((float)$amount*((float)$new_quantity/(float)$old_quantity));
 }
 private static function order_item_after_quantity($item,$new_quantity){
  $old_quantity=(float)($item['quantity']??0);if($old_quantity<=0||$new_quantity===$old_quantity)return $item;
  $item['quantity']=$new_quantity;
  foreach(array('subtotal','total') as $field)$item[$field]=self::scale_order_amount($item[$field]??'0',$old_quantity,$new_quantity);
  foreach(array('subtotal_tax','total_tax') as $field)$item[$field]=self::scale_order_amount($item[$field]??'0',$old_quantity,$new_quantity);
  foreach(array('subtotal','total') as $kind)if(isset($item['taxes'][$kind])&&is_array($item['taxes'][$kind]))foreach($item['taxes'][$kind] as $rate_id=>$amount)$item['taxes'][$kind][$rate_id]=self::scale_order_amount($amount,$old_quantity,$new_quantity);
  return $item;
 }
 public static function patch_order($id,$changes){
  $permission=self::order_write_permission();if(is_wp_error($permission))return $permission;
  if(!is_array($changes)||!$changes)return new WP_Error('oai_noop','No WooCommerce order changes supplied',array('status'=>400));
  $allowed=array('status','billing','shipping','customer_note','line_items');
  foreach($changes as $key=>$value)if(!in_array($key,$allowed,true))return new WP_Error('oai_field','WooCommerce order field is not allowed: '.$key,array('status'=>400,'allowed'=>$allowed));
  $before=self::get_order($id,true);if(is_wp_error($before))return $before;
  $after=$before;
  if(array_key_exists('status',$changes)){
   $status=self::normalize_order_status($changes['status']);
   if(!self::order_status_allowed($status))return new WP_Error('oai_woo_order_status','Invalid WooCommerce order status',array('status'=>422));
   $after['status']=$status;
  }
  foreach(array('billing','shipping') as $type){
   if(!array_key_exists($type,$changes))continue;
   $address=self::validate_order_address($changes[$type],$type,true);if(is_wp_error($address))return $address;
   foreach($address as $field=>$value)$after[$type][$field]=$value;
  }
  if(array_key_exists('customer_note',$changes))$after['customer_note']=sanitize_textarea_field((string)$changes['customer_note']);
  if(array_key_exists('line_items',$changes)){
   if(!is_array($changes['line_items']))return new WP_Error('oai_woo_order_items','WooCommerce line_items must be an array',array('status'=>422));
   $index=array();foreach($after['line_items'] as $position=>$row)$index[(int)$row['id']]=$position;
   foreach($changes['line_items'] as $row){
    if(!is_array($row)||!array_key_exists('id',$row)||!array_key_exists('quantity',$row))return new WP_Error('oai_woo_order_item','Each line item change requires id and quantity',array('status'=>422));
    $item_id=absint($row['id']);if(!$item_id||!isset($index[$item_id]))return new WP_Error('oai_woo_order_item','Order line item does not belong to this order: '.$item_id,array('status'=>422));
    $quantity=(float)$row['quantity'];if(!is_finite($quantity)||$quantity<=0||$quantity>1000000)return new WP_Error('oai_woo_quantity','Invalid WooCommerce line item quantity',array('status'=>422));
    $after['line_items'][$index[$item_id]]=self::order_item_after_quantity($after['line_items'][$index[$item_id]],round($quantity,6));
   }
  }
  if(OAI_Verify::same($before,$after))return new WP_Error('oai_noop','Operation does not change the target',array('status'=>409));
  return array('before'=>$before,'after'=>$after,'changes'=>$changes,'id'=>(int)$id);
 }
 public static function apply_order($id,$data){
  $permission=self::order_write_permission();if(is_wp_error($permission))return $permission;
  $valid=self::validate_order_snapshot($data);if(is_wp_error($valid))return $valid;
  $order=wc_get_order(absint($id));if(!$order||!($order instanceof WC_Order))return new WP_Error('oai_not_found','Order not found',array('status'=>404));
  if(isset($data['id'])&&(int)$data['id']!==(int)$order->get_id())return new WP_Error('oai_woo_order_id','WooCommerce order id mismatch',array('status'=>422));
  try{
   if(array_key_exists('status',$data))$order->set_status(self::normalize_order_status($data['status']));
   if(array_key_exists('billing',$data)&&is_array($data['billing']))$order->set_billing_address($data['billing']);
   if(array_key_exists('shipping',$data)&&is_array($data['shipping']))$order->set_shipping_address($data['shipping']);
   if(array_key_exists('customer_note',$data))$order->set_customer_note((string)$data['customer_note']);
   if(isset($data['line_items'])&&is_array($data['line_items'])){
    $items=$order->get_items('line_item');
    foreach($data['line_items'] as $row){
     $item_id=absint($row['id']??0);$item=isset($items[$item_id])?$items[$item_id]:null;
     if(!$item)return new WP_Error('oai_woo_order_item','Order line item not found: '.$item_id,array('status'=>409));
     $new_quantity=(float)($row['quantity']??0);$old_quantity=(float)$item->get_quantity();
     if($new_quantity<=0)return new WP_Error('oai_woo_quantity','Invalid WooCommerce line item quantity',array('status'=>422));
     if($new_quantity!==$old_quantity){
      $item->set_quantity($new_quantity);
      $item->set_subtotal(self::scale_order_amount($item->get_subtotal(),$old_quantity,$new_quantity));
      $item->set_total(self::scale_order_amount($item->get_total(),$old_quantity,$new_quantity));
      $item->save();
     }
    }
   }
   $order->calculate_totals(true);
   return self::get_order((int)$order->get_id(),true);
  }catch(Throwable $e){return new WP_Error('oai_woo_order_apply',$e->getMessage(),array('status'=>422));}
 }
 public static function restore_order($id,$snapshot){
  $permission=self::order_write_permission();if(is_wp_error($permission))return $permission;
  $valid=self::validate_order_snapshot($snapshot);if(is_wp_error($valid))return $valid;
  $order=wc_get_order(absint($id));if(!$order||!($order instanceof WC_Order))return new WP_Error('oai_not_found','Order not found',array('status'=>404));
  if(isset($snapshot['id'])&&(int)$snapshot['id']!==(int)$order->get_id())return new WP_Error('oai_woo_order_id','WooCommerce order id mismatch',array('status'=>422));
  try{
   if(array_key_exists('status',$snapshot))$order->set_status(self::normalize_order_status($snapshot['status']));
   if(array_key_exists('billing',$snapshot)&&is_array($snapshot['billing']))$order->set_billing_address($snapshot['billing']);
   if(array_key_exists('shipping',$snapshot)&&is_array($snapshot['shipping']))$order->set_shipping_address($snapshot['shipping']);
   if(array_key_exists('customer_note',$snapshot))$order->set_customer_note((string)$snapshot['customer_note']);
   if(array_key_exists('date_paid',$snapshot))$order->set_date_paid($snapshot['date_paid']?:null);
   if(array_key_exists('date_completed',$snapshot))$order->set_date_completed($snapshot['date_completed']?:null);
   $items=$order->get_items('line_item');
   foreach((array)($snapshot['line_items']??array()) as $row){
    $item_id=absint($row['id']??0);$item=isset($items[$item_id])?$items[$item_id]:null;
    if(!$item)return new WP_Error('oai_woo_order_item','Order line item not found: '.$item_id,array('status'=>409));
    $item->set_quantity((float)($row['quantity']??0));
    $item->set_subtotal((string)($row['subtotal']??'0'));
    $item->set_total((string)($row['total']??'0'));
    $item->set_taxes(array('total'=>(array)($row['taxes']['total']??array()),'subtotal'=>(array)($row['taxes']['subtotal']??array())));
    $item->save();
   }
   $fees=$order->get_items('fee');
   foreach((array)($snapshot['fee_lines']??array()) as $row){
    $item_id=absint($row['id']??0);$item=isset($fees[$item_id])?$fees[$item_id]:null;
    if(!$item)return new WP_Error('oai_woo_order_fee','Order fee line not found: '.$item_id,array('status'=>409));
    $item->set_total((string)($row['total']??'0'));
    $item->set_taxes(array('total'=>(array)($row['taxes']['total']??array())));
    $item->save();
   }
   $shipping=$order->get_items('shipping');
   foreach((array)($snapshot['shipping_lines']??array()) as $row){
    $item_id=absint($row['id']??0);$item=isset($shipping[$item_id])?$shipping[$item_id]:null;
    if(!$item)return new WP_Error('oai_woo_order_shipping','Order shipping line not found: '.$item_id,array('status'=>409));
    $item->set_total((string)($row['total']??'0'));
    $item->set_taxes(array('total'=>(array)($row['taxes']['total']??array())));
    $item->save();
   }
   // set_cart_tax() derives the protected total_tax aggregate from cart+shipping
   // tax in WooCommerce; setting it directly is not part of the public API.
   $total_setters=array('discount_total'=>'set_discount_total','discount_tax'=>'set_discount_tax','shipping_total'=>'set_shipping_total','shipping_tax'=>'set_shipping_tax','cart_tax'=>'set_cart_tax','total'=>'set_total');
   foreach($total_setters as $field=>$method)if(array_key_exists($field,(array)($snapshot['totals']??array())))$order->$method((string)$snapshot['totals'][$field]);
   $order->save();
   return true;
  }catch(Throwable $e){return new WP_Error('oai_woo_order_restore',$e->getMessage(),array('status'=>422));}
 }
 public static function order_snapshot_matches($current,$expected,$ignore_volatile=false){
  if(!is_array($current)||!is_array($expected))return false;
  if($ignore_volatile)return self::order_verification_matches($current,$expected);
  return OAI_Verify::same($current,$expected);
 }
 public static function order_verification_matches($current,$expected){
  if(!is_array($current)||!is_array($expected))return false;
  $actual=$current;$wanted=$expected;
  foreach(array('date_modified','date_paid','date_completed') as $field)unset($actual[$field],$wanted[$field]);
  return OAI_Verify::same($actual,$wanted);
 }
 public static function order_runtime_matches($actual,$expected){
  if(!is_array($actual)||!is_array($expected))return new WP_Error('oai_woo_order_runtime','Invalid WooCommerce order runtime snapshot',array('status'=>500));
  foreach(array('status','customer_note') as $field)if(array_key_exists($field,$expected)&&!OAI_Verify::same($actual[$field]??null,$expected[$field]))return new WP_Error('oai_woo_order_runtime','WooCommerce order did not persist requested field: '.$field,array('status'=>409,'field'=>$field,'expected'=>$expected[$field],'actual'=>$actual[$field]??null));
  foreach(array('billing','shipping') as $type)if(isset($expected[$type])&&is_array($expected[$type]))foreach($expected[$type] as $field=>$value)if(array_key_exists($field,$actual[$type]??array())&&!OAI_Verify::same($actual[$type][$field],$value))return new WP_Error('oai_woo_order_runtime','WooCommerce order did not persist '.$type.' field: '.$field,array('status'=>409,'field'=>$field,'expected'=>$value,'actual'=>$actual[$type][$field]));
  $actual_items=array();foreach((array)($actual['line_items']??array()) as $row)$actual_items[(int)$row['id']]=(float)$row['quantity'];
  $expected_items=array();foreach((array)($expected['line_items']??array()) as $row)$expected_items[(int)$row['id']]=(float)$row['quantity'];
  if(count($actual_items)!==count($expected_items))return new WP_Error('oai_woo_order_runtime','WooCommerce order line item count changed',array('status'=>409));
  foreach($expected_items as $item_id=>$quantity)if(!array_key_exists($item_id,$actual_items)||$actual_items[$item_id]!==$quantity)return new WP_Error('oai_woo_order_runtime','WooCommerce order did not persist line item quantity: '.$item_id,array('status'=>409,'item_id'=>$item_id,'expected'=>$quantity,'actual'=>$actual_items[$item_id]??null));
  return true;
 }

}

