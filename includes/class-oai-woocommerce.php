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
 public static function get($id){if(!self::available())return new WP_Error('oai_woo','WooCommerce unavailable',array('status'=>503));$p=wc_get_product((int)$id);if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));return array('id'=>$p->get_id(),'sku'=>$p->get_sku(),'name'=>$p->get_name(),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'price'=>$p->get_price(),'stock'=>$p->get_stock_quantity(),'stock_status'=>$p->get_stock_status(),'manage_stock'=>$p->managing_stock(),'status'=>$p->get_status());}
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
  $p=wc_get_product((int)$id);
  if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  $allowed=array('regular_price','sale_price','stock_quantity','stock_status','manage_stock');
  foreach($changes as $k=>$v)if(!in_array($k,$allowed,true))return new WP_Error('oai_field','WooCommerce field is not allowed: '.$k,array('status'=>400));
  if(!$changes)return new WP_Error('oai_noop','No WooCommerce changes supplied',array('status'=>400));
  foreach(array('regular_price','sale_price') as $price_key)if(array_key_exists($price_key,$changes)){$valid=self::validate_price($changes[$price_key],$price_key);if(is_wp_error($valid))return $valid;}
  if(array_key_exists('stock_status',$changes) && !in_array(sanitize_key($changes['stock_status']),array('instock','outofstock','onbackorder'),true))return new WP_Error('oai_woo_stock_status','Invalid WooCommerce stock_status',array('status'=>422));
  if(array_key_exists('stock_quantity',$changes) && $changes['stock_quantity']!==null && (!is_numeric($changes['stock_quantity']) || (float)$changes['stock_quantity']<0))return new WP_Error('oai_woo_stock','Invalid WooCommerce stock quantity',array('status'=>422));
  $before=self::get($id);
  $after=$before;
  if(array_key_exists('regular_price',$changes))$after['regular_price']=wc_format_decimal($changes['regular_price']);
  if(array_key_exists('sale_price',$changes))$after['sale_price']=wc_format_decimal($changes['sale_price']);
  if((string)$after['sale_price']!=='' && (string)$after['regular_price']!=='' && (float)$after['sale_price']>(float)$after['regular_price'])return new WP_Error('oai_woo_sale_price','Sale price exceeds regular price',array('status'=>422));
  if(array_key_exists('regular_price',$changes)||array_key_exists('sale_price',$changes)){
   $sale=(string)($after['sale_price']??'');
   $regular=(string)($after['regular_price']??'');
   $after['price']=$sale!==''?$sale:$regular;
  }
  if(array_key_exists('stock_quantity',$changes))$after['stock']=$changes['stock_quantity']===null?null:wc_stock_amount($changes['stock_quantity']);
  if(array_key_exists('stock_status',$changes))$after['stock_status']=sanitize_key($changes['stock_status']);
  if(array_key_exists('manage_stock',$changes))$after['manage_stock']=(bool)$changes['manage_stock'];
  // WooCommerce may derive stock_status when quantity changes. Predict the
  // runtime state so sandbox verification and rollback guards compare against
  // the same semantic product snapshot Woo will persist.
  if(array_key_exists('stock_quantity',$changes) && !array_key_exists('stock_status',$changes)){
   $manage=(bool)($after['manage_stock']??false); $stock=$after['stock'];
   if($manage && $stock!==null){
    if((float)$stock>0)$after['stock_status']='instock';
    else $after['stock_status']=(method_exists($p,'backorders_allowed')&&$p->backorders_allowed())?'onbackorder':'outofstock';
   }
  }
  return array('before'=>$before,'after'=>$after,'changes'=>$changes,'id'=>(int)$id);
}
 public static function runtime_matches_changes($actual,$changes,$before=array()){
  if(!is_array($actual)||!is_array($changes))return new WP_Error('oai_woo_runtime','Invalid WooCommerce runtime snapshot',array('status'=>500));
  foreach(array('id','sku','name','status') as $field){
   if(array_key_exists($field,$before) && !OAI_Verify::same($actual[$field]??null,$before[$field]))return new WP_Error('oai_woo_runtime','WooCommerce changed an invariant field during Apply: '.$field,array('status'=>409,'field'=>$field));
  }
  $map=array('regular_price'=>'regular_price','sale_price'=>'sale_price','stock_quantity'=>'stock','stock_status'=>'stock_status','manage_stock'=>'manage_stock');
  foreach($map as $change=>$field){
   if(!array_key_exists($change,$changes))continue;
   $expected=$changes[$change];
   if(in_array($change,array('regular_price','sale_price'),true))$expected=wc_format_decimal($expected);
   elseif($change==='stock_quantity')$expected=$expected===null?null:wc_stock_amount($expected);
   elseif($change==='stock_status')$expected=sanitize_key($expected);
   elseif($change==='manage_stock')$expected=(bool)$expected;
   if(!OAI_Verify::same($actual[$field]??null,$expected))return new WP_Error('oai_woo_runtime','WooCommerce runtime did not persist requested field: '.$change,array('status'=>409,'field'=>$change,'expected'=>$expected,'actual'=>$actual[$field]??null));
  }
  foreach(array('regular_price','sale_price','manage_stock','stock') as $field){
   $change=$field==='stock'?'stock_quantity':$field;
   if(!array_key_exists($change,$changes) && array_key_exists($field,$before) && !OAI_Verify::same($actual[$field]??null,$before[$field]))return new WP_Error('oai_woo_runtime','WooCommerce changed an unrelated field during Apply: '.$field,array('status'=>409,'field'=>$field));
  }
  if(array_key_exists('stock_quantity',$changes) && !array_key_exists('stock_status',$changes)){
   $manage=array_key_exists('manage_stock',$changes)?(bool)$changes['manage_stock']:(bool)($before['manage_stock']??false);
   $stock=$actual['stock']??null;$status=sanitize_key((string)($actual['stock_status']??''));
   if($manage && $stock!==null){
    if((float)$stock>0 && $status!=='instock')return new WP_Error('oai_woo_runtime','WooCommerce derived an unexpected stock_status for positive managed stock',array('status'=>409,'field'=>'stock_status','expected'=>'instock','actual'=>$status));
    if((float)$stock<=0 && !in_array($status,array('outofstock','onbackorder'),true))return new WP_Error('oai_woo_runtime','WooCommerce derived an unexpected stock_status for zero managed stock',array('status'=>409,'field'=>'stock_status','expected'=>array('outofstock','onbackorder'),'actual'=>$status));
   }elseif(array_key_exists('stock_status',$before) && !OAI_Verify::same($status,$before['stock_status'])){
    return new WP_Error('oai_woo_runtime','WooCommerce changed stock_status unexpectedly while stock management is disabled',array('status'=>409,'field'=>'stock_status','expected'=>$before['stock_status'],'actual'=>$status));
   }
  }elseif(!array_key_exists('stock_status',$changes) && array_key_exists('stock_status',$before) && !OAI_Verify::same($actual['stock_status']??null,$before['stock_status'])){
   return new WP_Error('oai_woo_runtime','WooCommerce changed stock_status unexpectedly during Apply',array('status'=>409,'field'=>'stock_status'));
  }
  if(!array_key_exists('regular_price',$changes)&&!array_key_exists('sale_price',$changes)&&array_key_exists('price',$before)&&!OAI_Verify::same($actual['price']??null,$before['price']))return new WP_Error('oai_woo_runtime','WooCommerce changed price unexpectedly during Apply',array('status'=>409,'field'=>'price'));
  return true;
}
 public static function apply($id,$changes){
  $valid=self::validate_snapshot($changes);if(is_wp_error($valid))return $valid;
  $p=wc_get_product((int)$id);
  if(!$p)return new WP_Error('oai_not_found','Product not found',array('status'=>404));
  if(array_key_exists('regular_price',$changes))$p->set_regular_price(wc_format_decimal($changes['regular_price']));
  if(array_key_exists('sale_price',$changes))$p->set_sale_price(wc_format_decimal($changes['sale_price']));
  if(array_key_exists('manage_stock',$changes))$p->set_manage_stock((bool)$changes['manage_stock']);
  if(array_key_exists('stock_quantity',$changes))$p->set_stock_quantity($changes['stock_quantity']===null?null:wc_stock_amount($changes['stock_quantity']));
  elseif(array_key_exists('stock',$changes))$p->set_stock_quantity($changes['stock']===null?null:wc_stock_amount($changes['stock']));
  if(array_key_exists('stock_status',$changes))$p->set_stock_status(sanitize_key($changes['stock_status']));
  $p->save();
  wc_delete_product_transients((int)$id);
  return self::get($id);
}
}
