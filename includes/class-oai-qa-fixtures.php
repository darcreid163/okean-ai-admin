<?php
/** Administrator-provisioned, owner-tagged reusable QA drafts. */
if (!defined('ABSPATH')) exit;

class OAI_QA_Fixtures {
 const OPTION='oai_qa_managed_fixtures';
 const SCHEMA_VERSION=1;
 private static $pending=array();
 private static $hooked=false;
 private static $provision_lease=null;
 public static function with_provision_lock($owner,$callback){
  $owner=absint($owner);if(!$owner||get_current_user_id()!==$owner||!current_user_can('manage_options'))return new WP_Error('oai_qa_fixture_permission','Administrator identity must match the QA session owner',array('status'=>403));
  $key='oai_qa_fixture_provision_lock';$lease=array('owner'=>$owner,'token_hash'=>hash('sha256',wp_generate_uuid4()),'expires_at'=>time()+120);
  if(!add_option($key,$lease,'',false)){
   $old=get_option($key,null);
   if(!is_array($old)||absint($old['expires_at']??0)>time())return new WP_Error('oai_qa_fixtures_busy','QA fixtures are being prepared by another request',array('status'=>409));
   self::delete_lock($key,$old);
   if(!add_option($key,$lease,'',false))return new WP_Error('oai_qa_fixtures_busy','QA fixtures are being prepared by another request',array('status'=>409));
  }
  $previous=self::$provision_lease;self::$provision_lease=$lease;
  try{return call_user_func($callback);}finally{self::delete_lock($key,$lease);self::$provision_lease=$previous;}
 }
 public static function provision_owned(){
  if(!is_array(self::$provision_lease)||absint(self::$provision_lease['expires_at']??0)<=time())return false;
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','oai_qa_fixture_provision_lock'));
  return is_string($raw)&&hash_equals(maybe_serialize(self::$provision_lease),$raw);
 }
 private static function delete_lock($key,$expected){
  global $wpdb;$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name=%s AND BINARY option_value=BINARY %s',$key,maybe_serialize($expected)));wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');
 }
 private static function specs(){return array(
  'elementor'=>array('kind'=>'elementor_regression','type'=>'page','label'=>'WP AI Admin TEST — Elementor Fixture'),
  'simple_product'=>array('kind'=>'woocommerce_simple_regression','type'=>'product','label'=>'WP AI Admin TEST — Simple Product Fixture'),
  'variable_product'=>array('kind'=>'woocommerce_variable_regression','type'=>'product','label'=>'WP AI Admin TEST — Variable Product Fixture'),
 );}
 private static function registry(){ $r=get_option(self::OPTION,array());return is_array($r)?$r:array(); }
 private static function records($owner){$r=self::registry();$rows=$r[(string)absint($owner)]??array();return is_array($rows)?$rows:array();}
 private static function save_record($owner,$key,$record){$r=self::registry();$r[(string)absint($owner)][$key]=$record;update_option(self::OPTION,$r,false);}
 private static function canonical($value){
  if(!is_array($value))return $value;
  if($value!==array()&&array_keys($value)!==range(0,count($value)-1))ksort($value);
  foreach($value as $k=>$v)$value[$k]=self::canonical($v);
  return $value;
 }
 private static function snapshot($id){
  $p=get_post($id);if(!$p)return null;
  $state=array('post_type'=>$p->post_type,'post_status'=>$p->post_status,'post_author'=>(int)$p->post_author,'post_parent'=>(int)$p->post_parent,'post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_name'=>$p->post_name,'menu_order'=>(int)$p->menu_order,'meta'=>array(),'terms'=>array(),'children'=>array());
  foreach(array('_elementor_data','_elementor_edit_mode','_elementor_page_settings','_oai_elementor_single_source','_wp_page_template','_regular_price','_sale_price','_price','_stock','_stock_status','_manage_stock','_product_attributes','_sku') as $key){
   $values=get_post_meta($id,$key,false);
   if($key==='_elementor_data')foreach($values as &$v){if(is_string($v)){$decoded=json_decode($v,true);if(is_array($decoded))$v=$decoded;}}unset($v);
   $state['meta'][$key]=$values;
  }
  if($p->post_type==='product_variation'){foreach(get_post_meta($id) as $key=>$values)if(strpos($key,'attribute_')===0)$state['meta'][$key]=$values;}
  if($p->post_type==='product'){
   foreach(array('product_type','product_visibility','product_cat','product_tag') as $taxonomy)if(taxonomy_exists($taxonomy)){
    $terms=wp_get_object_terms($id,$taxonomy,array('fields'=>'ids'));if(is_wp_error($terms))return null;
    $terms=array_map('intval',$terms);sort($terms);$state['terms'][$taxonomy]=$terms;
   }
   $ids=get_posts(array('post_type'=>'product_variation','post_parent'=>$id,'post_status'=>array('publish','private','draft','pending','trash'),'numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC'));
   foreach($ids as $child)$state['children'][(string)$child]=self::snapshot($child);
  }
  return self::canonical($state);
 }
 private static function fingerprint($id){$s=self::snapshot($id);if($s===null)return '';$json=wp_json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?hash('sha256',$json):'';}
 private static function owned($record,$key,$owner){
  $spec=self::specs()[$key]??null;$id=absint($record['id']??0);$p=$id?get_post($id):false;
  return $spec&&$p&&$p->post_type===$spec['type']&&in_array($p->post_status,array('draft','private'),true)
   &&(int)$p->post_author===absint($owner)&&(int)get_post_meta($id,'_wpai_qa_fixture_owner',true)===absint($owner)
   &&(string)get_post_meta($id,'_wpai_test_fixture',true)===$spec['kind'];
 }
 private static function baseline($record){$hash=(string)($record['baseline_sha256']??'');$now=self::fingerprint(absint($record['id']??0));return $hash!==''&&$now!==''&&hash_equals($hash,$now);}
 private static function tag($id,$key,$owner,$session){
  update_post_meta($id,'_wpai_test_fixture',self::specs()[$key]['kind']);update_post_meta($id,'_wpai_qa_fixture_owner',absint($owner));update_post_meta($id,'_wpai_test_session_id',(string)$session);
 }
 public static function elementor_data(){return array(array('id'=>'a1b2c3d','elType'=>'container','settings'=>array('content_width'=>'full'),'elements'=>array(
  array('id'=>'f1e2d3c','elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>'<section><h2>WP AI Admin QA Fixture</h2><p>Baseline paragraph for reversible Elementor tests.</p></section>'),'elements'=>array()),
  array('id'=>'h1e2d3c','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'WP AI Admin QA heading','header_size'=>'h1'),'elements'=>array()),
 )));}
 private static function create($key,$owner,$session){
  $spec=self::specs()[$key];$id=0;
  try{
   // Bind ownership before save listeners run, so a listener exception can
   // reconcile only drafts created by this exact invocation.
   $id=wp_insert_post(array('post_type'=>$spec['type'],'post_status'=>'draft','post_author'=>$owner,'post_title'=>$spec['label'],'post_content'=>'','meta_input'=>array('_wpai_test_fixture'=>$spec['kind'],'_wpai_qa_fixture_owner'=>absint($owner),'_wpai_test_session_id'=>(string)$session)),true);
   if(is_wp_error($id))return $id;
   if($key==='elementor'){
    update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode(self::elementor_data(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    update_post_meta($id,'_elementor_edit_mode','builder');update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION);
    update_post_meta($id,'_oai_elementor_single_source','1');update_post_meta($id,'_elementor_page_settings',array());
    $saved=OAI_Elementor::save_document($id,self::elementor_data());if(is_wp_error($saved))throw new RuntimeException('elementor_initialize');
   }else{
    $variable=$key==='variable_product';$product=$variable?new WC_Product_Variable($id):new WC_Product_Simple($id);
    $product->set_name($spec['label']);$product->set_status('draft');$product->set_catalog_visibility('hidden');
    if($variable){$attr=new WC_Product_Attribute();$attr->set_name('QA variant');$attr->set_options(array('One','Two'));$attr->set_visible(true);$attr->set_variation(true);$product->set_attributes(array($attr));}
    else{$product->set_regular_price('19');$product->set_manage_stock(true);$product->set_stock_quantity(3);$product->set_stock_status('instock');}
    if(!$product->save())throw new RuntimeException('product_save');
    if($variable){foreach(array('One'=>array('13',2),'Two'=>array('27',4)) as $name=>$values){$child=new WC_Product_Variation();$child->set_parent_id($id);$child->set_status('private');$child->set_attributes(array('qa-variant'=>$name));$child->set_regular_price($values[0]);$child->set_manage_stock(true);$child->set_stock_quantity($values[1]);$child->set_stock_status('instock');$child->save();}WC_Product_Variable::sync($id);}
   }
   $record=array('id'=>absint($id),'schema_version'=>self::SCHEMA_VERSION,'baseline_sha256'=>self::fingerprint($id));
   if(!self::owned($record,$key,$owner)||$record['baseline_sha256']==='')throw new RuntimeException('fixture_invalid');
   return $record;
  }catch(Throwable $e){
   // Only a newly created, still-private fixture owned by this invocation can be deleted.
   $ids=$id&&!is_wp_error($id)?array($id):get_posts(array('post_type'=>$spec['type'],'post_status'=>array('draft','private'),'author'=>$owner,'numberposts'=>-1,'fields'=>'ids','meta_query'=>array(array('key'=>'_wpai_test_fixture','value'=>$spec['kind']),array('key'=>'_wpai_test_session_id','value'=>(string)$session),array('key'=>'_wpai_qa_fixture_owner','value'=>absint($owner)))));
   foreach($ids as $created){if(self::owned(array('id'=>$created),$key,$owner)&&hash_equals((string)$session,(string)get_post_meta($created,'_wpai_test_session_id',true))){
    foreach(get_posts(array('post_type'=>'product_variation','post_parent'=>$created,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids')) as $child)wp_delete_post($child,true);
    wp_delete_post($created,true);
   }}
   return new WP_Error('oai_qa_fixture_create','Managed QA fixture could not be prepared',array('status'=>500,'fixture'=>$key));
  }
 }
 public static function prepare($owner,$session){
  $owner=absint($owner);
  if(!$owner||!user_can($owner,'manage_options')||get_current_user_id()!==$owner||!did_action('wp_loaded'))return new WP_Error('oai_qa_fixture_permission','An initialized administrator request is required to prepare QA fixtures',array('status'=>403));
  $available=array('elementor'=>defined('ELEMENTOR_VERSION')&&class_exists('Elementor\\Plugin'),'simple_product'=>class_exists('WC_Product_Simple'),'variable_product'=>class_exists('WC_Product_Variable')&&class_exists('WC_Product_Variation')&&class_exists('WC_Product_Attribute'));
  $result=array('fixtures'=>array(),'skipped'=>array());$records=self::records($owner);
  foreach(self::specs() as $key=>$spec){
   if(!self::provision_owned()){self::release($owner,$session);return new WP_Error('oai_qa_fixtures_busy','The QA provisioning lease is no longer current',array('status'=>409));}
   if(!$available[$key]){$result['skipped'][]=$key;continue;}
   $record=$records[$key]??array();$session_tag=(string)get_post_meta(absint($record['id']??0),'_wpai_test_session_id',true);
   if(!self::owned($record,$key,$owner)||($record['schema_version']??0)!==self::SCHEMA_VERSION||$session_tag!==''||!self::baseline($record)){$record=self::create($key,$owner,$session);if(is_wp_error($record)){self::release($owner,$session);return $record;}}
   else self::tag($record['id'],$key,$owner,$session);
   self::save_record($owner,$key,$record);$result['fixtures'][$key]=$record['id'];
  }
  return $result;
 }
 public static function inventory($owner){
  $out=array();foreach(self::records($owner) as $key=>$record)if(self::owned($record,$key,$owner))$out[$key]=array('id'=>absint($record['id']),'label'=>self::specs()[$key]['label'],'baseline_intact'=>self::baseline($record));return $out;
 }
 public static function regression_args($owner,$session){
  $ids=array();foreach(self::records($owner) as $key=>$record)if(self::owned($record,$key,$owner)&&hash_equals((string)$session,(string)get_post_meta($record['id'],'_wpai_test_session_id',true)))$ids[$key]=absint($record['id']);
  $args=array('include_p0'=>true,'include_file'=>false);
  if(!empty($ids['elementor'])){$args['elementor_post_id']=$ids['elementor'];$args['elementor_widget_id']='f1e2d3c';$args['elementor_container_id']='a1b2c3d';}
  if(!empty($ids['simple_product']))$args['product_id']=$ids['simple_product'];
  return $args;
 }
 public static function release($owner,$session){
  if(!$owner||$session==='')return array('released'=>array(),'retained'=>array());
  if(!did_action('wp_loaded')){self::$pending[$owner.'|'.$session]=array($owner,$session);if(!self::$hooked){add_action('wp_loaded',array(__CLASS__,'release_pending'),99);self::$hooked=true;}return array('deferred'=>true);}
  $out=array('released'=>array(),'retained'=>array());
  foreach(self::records($owner) as $key=>$record){
   $id=absint($record['id']??0);if(!self::owned($record,$key,$owner))continue;
   if(!hash_equals((string)$session,(string)get_post_meta($id,'_wpai_test_session_id',true)))continue;
   if(!self::baseline($record)){$out['retained'][]=array('id'=>$id,'reason'=>'fixture_baseline_changed');continue;}
   delete_post_meta($id,'_wpai_test_session_id');$out['released'][]=$id;
  }
  return $out;
 }
 public static function release_pending(){ $pending=self::$pending;self::$pending=array();foreach($pending as $item)self::release($item[0],$item[1]); }
}
