<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Elementor-aware read/patch/save layer.
 * Keeps Elementor data structured and uses Elementor's document save pipeline
 * when available instead of treating _elementor_data as a raw blob.
 */
class OAI_Elementor {
 public static function available(){ return defined('ELEMENTOR_VERSION') && class_exists('Elementor\\Plugin'); }

 public static function get_data($post_id){
  $raw=get_post_meta((int)$post_id,'_elementor_data',true);
  $data=is_string($raw)?json_decode($raw,true):$raw;
  if(!is_array($data)) return new WP_Error('oai_elementor_data','Elementor data is missing or invalid',array('status'=>404));
  return $data;
 }

 private static function walk(&$nodes,$callback,&$path=array()){
  if(!is_array($nodes)) return;
  foreach($nodes as $i=>&$node){
   if(!is_array($node)) continue;
   $p=$path; $p[]=(int)$i;
   $stop=call_user_func($callback,$node,$p);
   if($stop===true) return true;
   if(isset($node['elements']) && is_array($node['elements'])){
    $child_path=$p;
    if(self::walk($node['elements'],$callback,$child_path)) return true;
   }
  }
  return false;
 }

 public static function find_node($post_id,$node_id){
  $data=self::get_data($post_id); if(is_wp_error($data)) return $data;
  $found=null; $path=array();
  $walk=function(&$nodes,$current_path=array()) use (&$walk,&$found,&$path,$node_id){
   if(!is_array($nodes)||$found!==null)return;
   foreach($nodes as $i=>&$node){
    if(!is_array($node))continue;
    $p=$current_path; $p[]=(int)$i;
    if((string)($node['id']??'')===$node_id){$found=$node;$path=$p;return;}
    if(isset($node['elements'])&&is_array($node['elements'])){$walk($node['elements'],$p);if($found!==null)return;}
   }
  };
  $walk($data,array());
  if($found===null)return new WP_Error('oai_elementor_node','Elementor node not found',array('status'=>404));
  return array('data'=>$data,'node'=>$found,'path'=>$path,'post_id'=>(int)$post_id,'node_id'=>$node_id);
 }
 public static function widget_schema($widget_type){
  if(!self::available()) return new WP_Error('oai_elementor_unavailable','Elementor is not available',array('status'=>503));
  $widget_type=sanitize_key($widget_type); if($widget_type==='')return new WP_Error('oai_widget_type','widget_type is required',array('status'=>400));
  try {
   $widget=\Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget_type);
   if(!$widget) return new WP_Error('oai_widget_type','Elementor widget type not found',array('status'=>404));
   $controls=array();
   if(method_exists($widget,'get_stack')){
    $stack=$widget->get_stack(false);
    $raw=is_array($stack)&&isset($stack['controls'])&&is_array($stack['controls'])?$stack['controls']:array();
   } elseif(method_exists($widget,'get_controls')) {
    $raw=$widget->get_controls();
   } else {
    $raw=array();
   }
   foreach((array)$raw as $id=>$control){
    if(!is_array($control))continue;
    $controls[$id]=array(
     'type'=>$control['type']??null,
     'label'=>$control['label']??null,
     'default'=>$control['default']??null,
     'options'=>$control['options']??null,
     'responsive'=>!empty($control['responsive']),
     'frontend_available'=>isset($control['frontend_available'])?(bool)$control['frontend_available']:null,
     'selectors'=>$control['selectors']??null,
     'condition'=>$control['condition']??null
    );
   }
   return array('widget_type'=>$widget_type,'name'=>method_exists($widget,'get_name')?$widget->get_name():$widget_type,'title'=>method_exists($widget,'get_title')?$widget->get_title():'','controls'=>$controls,'control_count'=>count($controls));
  } catch(Throwable $e){
   return new WP_Error('oai_elementor_schema','Could not read Elementor widget schema',array('status'=>500,'message'=>$e->getMessage()));
  }
 }

 public static function globals(){
  if(!self::available())return new WP_Error('oai_elementor_unavailable','Elementor is not available',array('status'=>503));
  $out=array('available'=>true,'elementor_version'=>ELEMENTOR_VERSION,'kit'=>null,'global_colors'=>array(),'global_fonts'=>array());
  try{
   if(isset(\Elementor\Plugin::$instance->kits_manager)){
    $kit=\Elementor\Plugin::$instance->kits_manager->get_active_kit();
    if($kit){
     $out['kit']=array('id'=>$kit->get_id(),'settings'=>$kit->get_settings());
     $settings=$kit->get_settings();
     foreach(array('system_colors','custom_colors','system_typography','custom_typography') as $key){
      if(isset($settings[$key]))$out[$key]=$settings[$key];
     }
    }
   }
  }catch(Throwable $e){$out['warning']=$e->getMessage();}
  return $out;
 }

 public static function patch_settings($target,$changes){
  $parts=explode(':',(string)$target,2); $post_id=absint($parts[0]??0); $node_id=sanitize_text_field($parts[1]??'');
  if(!$post_id||$node_id==='')return new WP_Error('oai_target','Elementor target must be POST_ID:NODE_ID',array('status'=>400));
  if(!is_array($changes))return new WP_Error('oai_changes','changes must be an object',array('status'=>400));
  $ref=self::find_node($post_id,$node_id); if(is_wp_error($ref))return $ref;
  $before=is_array($ref['node']['settings']??null)?$ref['node']['settings']:array();
  $after=self::merge_settings($before,$changes);
  return array('post_id'=>$post_id,'node_id'=>$node_id,'node'=>$ref['node'],'before_settings'=>$before,'after_settings'=>$after,'changes'=>$changes,'path'=>$ref['path']);
 }
 private static function merge_settings($base,$changes){
  foreach($changes as $k=>$v){
   if(is_array($v)&&isset($base[$k])&&is_array($base[$k])&&!self::is_list($v)&&!self::is_list($base[$k]))$base[$k]=self::merge_settings($base[$k],$v); else $base[$k]=$v;
  }
  return $base;
 }
 private static function is_list($a){if(function_exists('array_is_list'))return array_is_list($a);$i=0;foreach($a as $k=>$v){if($k!==$i++)return false;}return true;}

 public static function save_settings($target,$settings){
  $parts=explode(':',(string)$target,2); $post_id=absint($parts[0]??0); $node_id=sanitize_text_field($parts[1]??'');
  $ref=self::find_node($post_id,$node_id); if(is_wp_error($ref))return $ref;
  $data=$ref['data']; $changed=false;
  $walk=function(&$nodes) use (&$walk,$node_id,$settings,&$changed){
   if(!is_array($nodes)||$changed)return;
   foreach($nodes as &$node){
    if(!is_array($node))continue;
    if((string)($node['id']??'')===$node_id){$node['settings']=$settings;$changed=true;return;}
    if(isset($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
   }
  };
  $walk($data); if(!$changed)return new WP_Error('oai_elementor_node','Elementor node not found',array('status'=>404));
  return self::save_document($post_id,$data);
 }

 public static function save_document($post_id,$data){
  $post_id=(int)$post_id; $encoded=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if($encoded===false)return new WP_Error('oai_elementor_encode','Could not encode Elementor data',array('status'=>500));
  if(self::available()){
   try{
    $document=\Elementor\Plugin::$instance->documents->get($post_id, false);
    if($document && method_exists($document,'save')){
     // Elementor's own save pipeline expects an envelope. Passing the raw
     // root element array can return success while leaving _elementor_data
     // empty on Elementor 4.x. WPVibe uses the canonical ['elements'=>...]
     // shape; we follow the same builder-owned save contract here.
     $result=$document->save(array('elements'=>$data));
     if(is_wp_error($result))return $result;
     $repair=self::persist_and_verify($post_id,$data,$encoded);
     if(is_wp_error($repair))return $repair;
     self::refresh($post_id,$document);
     return true;
    }
   }catch(Throwable $e){return new WP_Error('oai_elementor_save','Elementor document save failed',array('status'=>500,'message'=>$e->getMessage()));}
  }
  $repair=self::persist_and_verify($post_id,$data,$encoded);
  if(is_wp_error($repair))return $repair;
  self::refresh($post_id,null); return true;
 }

 private static function persist_and_verify($post_id,array $data,$encoded){
  $post_id=(int)$post_id;
  update_post_meta($post_id,'_elementor_data',wp_slash($encoded));
  update_post_meta($post_id,'_elementor_edit_mode','builder');
  update_post_meta($post_id,'_elementor_template_type','wp-page');
  clean_post_cache($post_id); wp_cache_delete($post_id,'post_meta');
  $raw=(string)get_post_meta($post_id,'_elementor_data',true); $decoded=$raw!==''?json_decode($raw,true):null;
  if($raw==='' || !is_array($decoded) || empty($decoded)){
   $encoded2=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   if($encoded2===false)return new WP_Error('oai_elementor_encode','Could not encode Elementor data',array('status'=>500));
   update_post_meta($post_id,'_elementor_data',wp_slash($encoded2));
   clean_post_cache($post_id); wp_cache_delete($post_id,'post_meta');
   $raw=(string)get_post_meta($post_id,'_elementor_data',true); $decoded=json_decode($raw,true);
  }
  if($raw==='' || !is_array($decoded) || empty($decoded))return new WP_Error('oai_elementor_verify','Elementor data could not be persisted or repaired',array('status'=>500));
  return true;
 }

 private static function refresh($post_id,$document=null){
  clean_post_cache($post_id);
  wp_cache_delete($post_id,'post_meta');
  // Elementor's document save pipeline already fires its save hooks.
  // Do not manually fire elementor/editor/after_save here: Elementor Pro 4.2.x
  // registers callbacks that expect the hook's full argument list.
  do_action('elementor/core/files/clear_cache');
  if($document && method_exists($document,'get_css_file')){
   try{$css=$document->get_css_file(); if($css && method_exists($css,'update'))$css->update();}catch(Throwable $e){}
  }
  if(class_exists('Elementor\\Core\\Files\\CSS\\Post')){
   try{\Elementor\Core\Files\CSS\Post::create($post_id)->update();}catch(Throwable $e){}
  }
 }
}
