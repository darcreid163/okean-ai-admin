<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Central verification/proof state engine.
 * Verification is based on canonicalized semantic data, not raw serialized bytes.
 */
class OAI_Verify {
 public static function operation($id,$expected_override=null,$phase='after'){
  $row=OAI_DB::get((int)$id);
  if(!$row)return new WP_Error('oai_not_found','Operation not found',array('status'=>404));
  $expected=$expected_override!==null?$expected_override:json_decode((string)$row['after_data'],true);
  $meta=json_decode((string)$row['meta_data'],true);
  if(!is_array($meta))$meta=array();
  $result=array(
   'id'=>(int)$row['id'],'operation_id'=>(string)$row['operation_id'],'type'=>(string)$row['type'],
   'target'=>(string)$row['target'],'status'=>(string)$row['status'],'phase'=>$phase,
   'verified'=>false,'checks'=>array(),'expected'=>$expected,'current'=>null,
   'checked_at'=>current_time('mysql',true)
  );

  if($row['type']==='elementor_page_create'){
   $page_id=absint($meta['preview_page_id']??0);
   if(!$page_id){
    $parts=explode(':',(string)$row['target'],2); $page_id=absint($parts[1]??0);
   }
   $result['page_id']=$page_id;
   if(!$page_id){$result['checks'][]=array('name'=>'page_id','ok'=>false,'message'=>'Elementor page id is missing');return $result;}
   $result['current']=self::elementor_page($page_id);
   if(is_wp_error($result['current'])){$result['checks'][]=array('name'=>'elementor_page','ok'=>false,'message'=>$result['current']->get_error_message());return $result;}
   $current=$result['current'];
   $result['checks'][]=array('name'=>'page_exists','ok'=>true);
   $result['checks'][]=array('name'=>'elementor_data_valid','ok'=>(bool)$current['valid_json'],'bytes'=>$current['bytes']);
   $result['checks'][]=array('name'=>'node_count','ok'=>((int)$current['node_count']>0),'actual'=>(int)$current['node_count']);
   $expected_nodes=self::count_nodes($expected);
   $result['checks'][]=array('name'=>'expected_node_count','ok'=>((int)$current['node_count']===$expected_nodes),'expected'=>$expected_nodes,'actual'=>(int)$current['node_count']);
   $result['checks'][]=array('name'=>'edit_mode','ok'=>($current['edit_mode']==='builder'),'actual'=>$current['edit_mode']);
   $result['checks'][]=array('name'=>'template_type','ok'=>($current['template_type']==='wp-page'),'actual'=>$current['template_type']);
   $semantic=self::same($current['data'],$expected);
   $result['checks'][]=array('name'=>'semantic_data_match','ok'=>$semantic);
   $expected_hash=self::canonical_hash($expected);
   $result['checks'][]=array('name'=>'canonical_hash','ok'=>hash_equals((string)$current['canonical_hash'],$expected_hash),'expected'=>$expected_hash,'actual'=>$current['canonical_hash']);
   $result['verified']=self::all_checks_ok($result['checks']);
   return $result;
  }

  list($exists,$current)=OAI_REST::snapshot_for_verify($row['type'],$row['target']);
  if(is_wp_error($current)){$result['checks'][]=array('name'=>'read_current','ok'=>false,'message'=>$current->get_error_message());return $result;}
  $result['current']=$current;
  $before_exists=array_key_exists('before_exists',$meta)?(bool)$meta['before_exists']:true;
  $after_exists=array_key_exists('after_exists',$meta)?(bool)$meta['after_exists']:true;
  $expected_exists=$phase==='rollback'?$before_exists:$after_exists;
  $result['checks'][]=array('name'=>'exists','ok'=>($exists===$expected_exists),'expected'=>$expected_exists,'actual'=>$exists);

  // Authoritative verification: compare the normal WordPress read with a
  // fresh persistent-state read for post fields/meta. A same-request object
  // cache can otherwise report OLD immediately after wp_update_post(), while
  // the database already contains NEW. Never call that situation verified.
  $authoritative=self::authoritative_snapshot($row['type'],$row['target']);
  if(is_wp_error($authoritative)){
   $result['checks'][]=array('name'=>'authoritative_read','ok'=>false,'message'=>$authoritative->get_error_message());
   return $result;
  }
  if(is_array($authoritative)){
   $result['authoritative']=$authoritative;
   $auth_exists=(bool)($authoritative['exists']??false);
   $auth_current=$authoritative['value']??null;
   $result['checks'][]=array('name'=>'authoritative_exists','ok'=>($auth_exists===$expected_exists),'expected'=>$expected_exists,'actual'=>$auth_exists);
   $wp_matches_authoritative=($exists===$auth_exists) && (!$exists || self::same($current,$auth_current));
   $result['checks'][]=array('name'=>'authoritative_consistency','ok'=>$wp_matches_authoritative,'wp'=>$current,'persistent'=>$auth_current);
   $result['verification_source']=$wp_matches_authoritative?'dual_match':'conflict';
   $same=$expected_exists ? self::same($auth_current,$expected) : !$auth_exists;
   $result['checks'][]=array('name'=>'value_match','ok'=>$same);
   $result['checks'][]=array('name'=>'authoritative_value_match','ok'=>$same);
   $result['verified']=self::all_checks_ok($result['checks']);
   return $result;
  }

  $same=$expected_exists ? self::same($current,$expected) : !$exists;
  $result['checks'][]=array('name'=>'value_match','ok'=>$same);
  $result['verified']=self::all_checks_ok($result['checks']);
  return $result;
 }

 private static function all_checks_ok($checks){foreach($checks as $c)if(empty($c['ok']))return false;return true;}

 private static function authoritative_snapshot($type,$target){
  if(!class_exists('OAI_Authoritative'))return null;
  if(in_array($type,array('post_title','post_excerpt','post_content'),true)){
   $parts=explode(':',(string)$target,2);
   return OAI_Authoritative::post_field(absint($parts[0]??0),$parts[1]??'');
  }
  if(in_array($type,array('elementor_widget_html','elementor_widget_settings','elementor_page_create'),true)){
   $parts=explode(':',(string)$target,2);
   $post_id=$type==='elementor_page_create'?absint($parts[1]??0):absint($parts[0]??0);
   if($post_id)return OAI_Authoritative::post_meta($post_id,'_elementor_data');
  }
  return null;
 }

 public static function canonicalize($value){
  if(is_array($value)){
   $is_list=array_keys($value)===range(0,count($value)-1);
   if($is_list)return array_map(array(__CLASS__,'canonicalize'),$value);
   $out=array(); foreach($value as $k=>$v)$out[(string)$k]=self::canonicalize($v); ksort($out,SORT_STRING); return $out;
  }
  if(is_object($value))return self::canonicalize((array)$value);
  return $value;
 }
 public static function canonical_hash($value){return hash('sha256',wp_json_encode(self::canonicalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public static function same($a,$b){return self::canonical_hash($a)===self::canonical_hash($b);}

 private static function count_nodes($data){
  if(!is_array($data))return 0; $n=0;
  $walk=function($x)use(&$walk,&$n){
   if(!is_array($x))return;
   if(isset($x['elType']))$n++;
   if(isset($x['elements'])&&is_array($x['elements']))foreach($x['elements'] as $c)$walk($c);
   elseif(array_keys($x)===range(0,count($x)-1))foreach($x as $c)$walk($c);
  };
  $walk($data); return $n;
 }

 private static function find_nodes($data,&$out){
  if(!is_array($data))return;
  if(isset($data['id']))$out[(string)$data['id']]=$data;
  if(isset($data['elements'])&&is_array($data['elements']))foreach($data['elements'] as $c)self::find_nodes($c,$out);
  elseif(array_keys($data)===range(0,count($data)-1))foreach($data as $c)self::find_nodes($c,$out);
 }
 private static function semantic_details($expected,$current){
  $a=array();$b=array();self::find_nodes($expected,$a);self::find_nodes($current,$b);
  $missing=array();$different=array();
  foreach($a as $id=>$node){if(!isset($b[$id])){$missing[]=$id;continue;} if(!self::same($node,$b[$id]))$different[]=$id;}
  return array('missing_ids'=>$missing,'different_ids'=>$different,'unexpected_ids'=>array_values(array_diff(array_keys($b),array_keys($a))));
 }

 public static function elementor_page($page_id){
  $p=get_post($page_id); if(!$p)return new WP_Error('oai_not_found','Preview page not found',array('status'=>404));
  $raw=get_post_meta($page_id,'_elementor_data',true);
  if(!is_string($raw))$raw=wp_json_encode($raw);
  $data=json_decode((string)$raw,true); $valid=(json_last_error()===JSON_ERROR_NONE && is_array($data));
  $hash=$valid?self::canonical_hash($data):hash('sha256',(string)$raw);
  return array(
   'page_id'=>$page_id,'status'=>$p->post_status,'bytes'=>strlen((string)$raw),'valid_json'=>$valid,
   'node_count'=>$valid?self::count_nodes($data):0,'edit_mode'=>(string)get_post_meta($page_id,'_elementor_edit_mode',true),
   'template_type'=>(string)get_post_meta($page_id,'_elementor_template_type',true),'data_hash'=>hash('sha256',(string)$raw),
   'canonical_hash'=>$hash,'data'=>$valid?$data:null,'permalink'=>get_permalink($page_id),'title'=>$p->post_title
  );
 }
}
