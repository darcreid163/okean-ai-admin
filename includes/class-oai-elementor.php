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

 /** Replace one exact fragment; the caller supplies the widget hash to prevent stale edits. */
 public static function replace_html_fragment($html,$old,$replacement,$expected_sha256){
  if(!is_string($html)||!is_string($old)||$old===''||!is_string($replacement)||!is_string($expected_sha256)||!preg_match('/^[a-f0-9]{64}$/D',$expected_sha256))return new WP_Error('oai_fragment_input','A nonempty old_fragment and current widget SHA-256 are required',array('status'=>400));
  if(!hash_equals(hash('sha256',$html),$expected_sha256))return new WP_Error('oai_snapshot_mismatch','Widget HTML changed since it was read',array('status'=>409,'current_sha256'=>hash('sha256',$html)));
  if(substr_count($html,$old)!==1)return new WP_Error('oai_fragment_ambiguous','old_fragment must occur exactly once in the current widget',array('status'=>409));
  if($old===$replacement)return new WP_Error('oai_noop','Fragment is unchanged',array('status'=>409));
  $pos=strpos($html,$old);
  return substr_replace($html,$replacement,$pos,strlen($old));
 }

 /** Catch an accidental full-page append in the legacy whole-widget path. */
 public static function reject_cloned_widget($before,$after){
  if(is_string($before)&&is_string($after)&&strlen($before)>=1024&&substr_count($after,$before)>1)
   return new WP_Error('oai_elementor_duplicate','The new HTML contains multiple copies of the existing widget',array('status'=>422));
  return true;
 }

 public static function content_write_allowed($post_id){
  if(get_post_meta(absint($post_id),'_oai_elementor_single_source',true)==='1')
   return new WP_Error('oai_elementor_content_source','Edit this page through Elementor widgets; WordPress post_content is reserved for the builder',array('status'=>409));
  return true;
 }

 /** Only newly created AI Admin pages opt into one authoritative Elementor source. */
 public static function activate_single_source($post_id){
  $post_id=absint($post_id);
  if(!$post_id||!get_post($post_id))return new WP_Error('oai_post','New Elementor page not found',array('status'=>404));
  $result=self::clear_mirror_content($post_id);
  if(is_wp_error($result))return $result;
  update_post_meta($post_id,'_oai_elementor_single_source','1');
  return true;
 }

 private static function clear_mirror_content($post_id){
  if((string)get_post_field('post_content',$post_id)==='')return true;
  $updated=wp_update_post(array('ID'=>$post_id,'post_content'=>''),true);
  if(is_wp_error($updated))return $updated;
  clean_post_cache($post_id);
  if((string)get_post_field('post_content',$post_id)!=='')return new WP_Error('oai_elementor_mirror','Could not clear duplicated WordPress content',array('status'=>500));
  return true;
 }

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

 public static function container_layout_keys(){
  $base=array('content_width','width','max_width','min_height','flex_direction','flex_wrap','justify_content','align_items','align_content','gap','padding','margin','overflow','html_tag','css_classes');
  $out=$base;foreach(array('tablet','mobile') as $bp)foreach($base as $key)$out[]=$key.'_'.$bp;
  return array_values(array_unique($out));
 }
 public static function container($post_id,$node_id){
  $ref=self::find_node(absint($post_id),sanitize_text_field((string)$node_id));if(is_wp_error($ref))return $ref;
  $el=(string)($ref['node']['elType']??'');
  if(!in_array($el,array('container','e-flexbox'),true))return new WP_Error('oai_elementor_container','Elementor container/e-flexbox node required',array('status'=>422,'elType'=>$el));
  return array('post_id'=>absint($post_id),'node_id'=>(string)$node_id,'elType'=>$el,'path'=>$ref['path'],'settings'=>is_array($ref['node']['settings']??null)?$ref['node']['settings']:array(),'allowed_layout_settings'=>self::container_layout_keys());
 }
 public static function page_layout($post_id){
  $post_id=absint($post_id);$p=get_post($post_id);if(!$p||$p->post_type!=='page')return new WP_Error('oai_page','Page not found',array('status'=>404));
  $template=(string)get_post_meta($post_id,'_wp_page_template',true);if($template==='')$template='default';
  return array('post_id'=>$post_id,'page_template'=>$template);
 }
 public static function validate_page_template($template){
  $template=sanitize_key((string)$template);
  $allowed=array('default','elementor_canvas','elementor_header_footer');
  if(!in_array($template,$allowed,true))return new WP_Error('oai_page_template','Unsupported Elementor page template',array('status'=>422,'allowed'=>$allowed));
  return $template;
 }
 public static function patch_settings($target,$changes){
  $parts=explode(':',(string)$target,2); $post_id=absint($parts[0]??0); $node_id=sanitize_text_field($parts[1]??'');
  if(!$post_id||$node_id==='')return new WP_Error('oai_target','Elementor target must be POST_ID:NODE_ID',array('status'=>400));
  if(!is_array($changes))return new WP_Error('oai_changes','changes must be an object',array('status'=>400));
  $ref=self::find_node($post_id,$node_id); if(is_wp_error($ref))return $ref;
  $before=is_array($ref['node']['settings']??null)?$ref['node']['settings']:array();
  if(!$changes)return new WP_Error('oai_changes','At least one Elementor setting is required',array('status'=>400));
  $controls=array();
  if(($ref['node']['elType']??'')==='widget'){
   $schema=self::widget_schema((string)($ref['node']['widgetType']??''));
   if(is_wp_error($schema))return $schema;
   $controls=$schema['controls']??array();
  }
  $is_container=in_array((string)($ref['node']['elType']??''),array('container','e-flexbox'),true);
  $container_keys=$is_container?self::container_layout_keys():array();
  foreach($changes as $key=>$value){
   $known=$is_container?in_array((string)$key,$container_keys,true):(array_key_exists($key,$before)||isset($controls[$key]));
   if(!is_string($key) || !$known || (isset($controls[$key]) && in_array($controls[$key]['type']??'',array('section','tab','tabs'),true)))
    return new WP_Error('oai_elementor_control','Unknown or non-editable Elementor setting: '.(string)$key,array('status'=>422,'container'=>$is_container,'allowed'=>$is_container?$container_keys:null));
  }
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
  $single_source=get_post_meta($post_id,'_oai_elementor_single_source',true)==='1';
  if($single_source && (string)get_post_field('post_content',$post_id)!=='')return new WP_Error('oai_content_conflict','WordPress content was edited outside Elementor; refusing to overwrite it',array('status'=>409));
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
     if($single_source){$clear=self::clear_mirror_content($post_id);if(is_wp_error($clear))return $clear;}
     self::refresh($post_id,$document);
     return true;
    }
   }catch(Throwable $e){return new WP_Error('oai_elementor_save','Elementor document save failed',array('status'=>500,'message'=>$e->getMessage()));}
  }
  $repair=self::persist_and_verify($post_id,$data,$encoded);
  if(is_wp_error($repair))return $repair;
  if($single_source){$clear=self::clear_mirror_content($post_id);if(is_wp_error($clear))return $clear;}
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
