<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Gutenberg_Resolver {
 public static function outline($result,$matches_only=false){
  if(is_wp_error($result))return $result;
  $key=$matches_only?'matches':'blocks';
  $walk=function($nodes)use(&$walk){$out=array();foreach((array)$nodes as $node){$html=(string)($node['innerHTML']??'');$out[]=array('path'=>$node['path']??'','blockName'=>$node['blockName']??'','attrs_keys'=>array_keys((array)($node['attrs']??array())),'html_bytes'=>strlen($html),'html_sha256'=>hash('sha256',$html),'innerBlocks'=>$walk($node['innerBlocks']??array()));}return $out;};
  $result[$key]=$walk($result[$key]??array());
  if(!$matches_only){$raw=(string)($result['raw_content']??'');unset($result['raw_content']);$result['content_bytes']=strlen($raw);$result['content_sha256']=hash('sha256',$raw);}
  return $result;
 }
 public static function resolve_post($id){$r=OAI_WP_Post_Resolver::resolve($id);if(is_wp_error($r))return $r;if($r['builder']==='elementor')return new WP_Error('oai_builder_elementor','Post is owned by Elementor; use Elementor resolver',array('status'=>409,'resolver'=>'elementor','post_id'=>$r['post_id']));$p=get_post($r['post_id']);if(!$p)return new WP_Error('oai_post_not_found','Post not found',array('status'=>404));if(!function_exists('parse_blocks'))return new WP_Error('oai_blocks_unavailable','parse_blocks is unavailable',array('status'=>503));$content=(string)$p->post_content;$blocks=parse_blocks($content);$has_native_blocks=false;foreach($blocks as $b){if(is_array($b)&&!empty($b['blockName'])){$has_native_blocks=true;break;}}return array_merge($r,array('blocks'=>self::normalize($blocks),'content_mode'=>$has_native_blocks?'gutenberg':'freeform_html','has_native_blocks'=>$has_native_blocks,'raw_content'=>$content));}
 public static function normalize($blocks,$path=array()){$out=array();foreach((array)$blocks as $i=>$b){if(!is_array($b))continue;$p=$path; $p[]=$i;$children=self::normalize($b['innerBlocks']??array(),$p);$attrs=is_array($b['attrs']??null)?$b['attrs']:array();$out[]=array('index'=>(int)$i,'path'=>implode('.',$p),'blockName'=>(string)($b['blockName']??''),'attrs'=>$attrs,'innerHTML'=>(string)($b['innerHTML']??''),'innerContent'=>is_array($b['innerContent']??null)?$b['innerContent']:array(),'innerBlocks'=>$children); }return $out;}
 public static function locate($id,$selector=array()){$r=self::resolve_post($id);if(is_wp_error($r))return $r;$blocks=$r['blocks'];if(empty($r['has_native_blocks']))return self::locate_freeform($id,$r['raw_content'],$selector);$want_name=sanitize_text_field((string)($selector['block_name']??''));$path=(string)($selector['path']??'');$needle=(string)($selector['text']??'');$html=(string)($selector['html_contains']??'');$matches=array();$walk=function($nodes)use(&$walk,&$matches,$want_name,$path,$needle,$html){foreach($nodes as $n){$ok=true;if($want_name!==''&&$n['blockName']!==$want_name)$ok=false;if($path!==''&&$n['path']!==$path)$ok=false;if($needle!==''&&stripos($n['innerHTML'],$needle)===false)$ok=false;if($html!==''&&stripos($n['innerHTML'],$html)===false)$ok=false;if($ok)$matches[]=$n;if(!empty($n['innerBlocks']))$walk($n['innerBlocks']);}};$walk($blocks);return array('post_id'=>$id,'matches'=>$matches,'count'=>count($matches));}
 public static function patch($id,$selector,$new_attrs=null,$new_html=null){
  $r=self::resolve_post($id); if(is_wp_error($r))return $r;
  if(!is_array($selector))return new WP_Error('oai_block_selector','Block selector must be an object',array('status'=>400));
  $has_selector=false; foreach(array('path','block_name','text','html_contains') as $k)if(isset($selector[$k])&&(string)$selector[$k]!==''){$has_selector=true;break;}
  if(!$has_selector)return new WP_Error('oai_block_selector','Specify path, block_name, text or html_contains',array('status'=>400));
  if($new_attrs===null&&$new_html===null)return new WP_Error('oai_block_patch_empty','No block changes supplied',array('status'=>400));
  if(empty($r['has_native_blocks']))return self::patch_freeform($r,$selector,$new_attrs,$new_html);
  $p=get_post($id); $blocks=parse_blocks((string)$p->post_content); $matches=self::find_raw_matches($blocks,$selector);
  if(!$matches)return new WP_Error('oai_block_not_found','Gutenberg block not found',array('status'=>404));
  if(count($matches)>1)return new WP_Error('oai_block_ambiguous','Block selector matches multiple blocks; use an exact path',array('status'=>409,'matches'=>count($matches)));
  $path=$matches[0]['path']; $node=&$matches[0]['node']; $before=serialize_blocks($blocks);
  if($new_attrs!==null)$node['attrs']=is_array($new_attrs)?$new_attrs:$node['attrs'];
  if($new_html!==null){if(!empty($node['innerBlocks']))return new WP_Error('oai_block_html_container','Direct HTML patch is not supported for a container block; patch its inner block instead',array('status'=>422));$node['innerHTML']=(string)$new_html;$node['innerContent']=array((string)$new_html);}
  $after=serialize_blocks($blocks);$reparsed=parse_blocks($after);$roundtrip=serialize_blocks($reparsed);
  if($roundtrip!==$after)return new WP_Error('oai_block_invalid','Patched Gutenberg markup failed serialization round-trip validation',array('status'=>422));
  return array('before'=>$before,'after'=>$after,'match'=>$node,'path'=>$path,'validation'=>array('parse_blocks'=>true,'serialize_blocks'=>true,'round_trip'=>true));
 }
 private static function locate_freeform($id,$content,$selector){
  $text=trim((string)($selector['text']??''));$fragment=trim((string)($selector['html_contains']??''));$needle=$text!==''?$text:$fragment;
  if($needle==='')return new WP_Error('oai_block_selector','Freeform HTML requires text or html_contains selector',array('status'=>400));
  $count=substr_count($content,$needle);
  if($count===0)return array('post_id'=>$id,'matches'=>array(),'count'=>0,'content_mode'=>'freeform_html');
  if($count>1)return new WP_Error('oai_block_ambiguous','Freeform selector matches multiple occurrences; use a more exact selector',array('status'=>409,'matches'=>$count));
  return array('post_id'=>$id,'matches'=>array(array('index'=>0,'path'=>'html:root','blockName'=>'oai/freeform-html','attrs'=>array(),'innerHTML'=>$content,'innerContent'=>array($content),'selector'=>$needle)),'count'=>1,'content_mode'=>'freeform_html');
 }
 private static function patch_freeform($r,$selector,$new_attrs,$new_html){
  if($new_attrs!==null)return new WP_Error('oai_freeform_attrs','Attributes patch is not supported for freeform HTML; provide html replacement',array('status'=>422));
  if($new_html===null)return new WP_Error('oai_freeform_html_required','Freeform HTML patch requires html replacement',array('status'=>422));
  $content=(string)$r['raw_content'];$text=trim((string)($selector['text']??''));$fragment=trim((string)($selector['html_contains']??''));$needle=$text!==''?$text:$fragment;
  if($needle==='')return new WP_Error('oai_block_selector','Freeform HTML requires text or html_contains selector',array('status'=>400));
  $count=substr_count($content,$needle);
  if($count===0)return new WP_Error('oai_block_not_found','Freeform HTML selector not found',array('status'=>404));
  if($count>1)return new WP_Error('oai_block_ambiguous','Freeform selector matches multiple occurrences; use a more exact selector',array('status'=>409,'matches'=>$count));
  $before=$content;$after=str_replace($needle,(string)$new_html,$content,$replaced);
  if($replaced!==1)return new WP_Error('oai_block_invalid','Freeform HTML replacement was not applied exactly once',array('status'=>422));
  return array('before'=>$before,'after'=>$after,'match'=>array('path'=>'html:root','blockName'=>'oai/freeform-html','selector'=>$needle),'path'=>'html:root','validation'=>array('parse_blocks'=>true,'serialize_blocks'=>false,'round_trip'=>false,'freeform_html'=>true,'replacement_count'=>1));
 }
 private static function find_raw_matches(&$blocks,$selector,$path=array(),&$out=array()){foreach($blocks as $i=>&$b){$p=$path;$p[]=$i;$ok=true;if(isset($selector['path'])&&$selector['path']!==''&&$selector['path']!==implode('.',$p))$ok=false;if(isset($selector['block_name'])&&$selector['block_name']!==''&&(string)($b['blockName']??'')!==$selector['block_name'])$ok=false;if(isset($selector['text'])&&$selector['text']!==''&&stripos((string)($b['innerHTML']??''),(string)$selector['text'])===false)$ok=false;if(isset($selector['html_contains'])&&$selector['html_contains']!==''&&stripos((string)($b['innerHTML']??''),(string)$selector['html_contains'])===false)$ok=false;if($ok)$out[]=array('node'=>&$b,'path'=>implode('.',$p));if(!empty($b['innerBlocks']))self::find_raw_matches($b['innerBlocks'],$selector,$p,$out);}return $out;}
 private static function locate_in_raw(&$blocks,$selector,$path=array()){foreach($blocks as $i=>&$b){$p=$path;$p[]=$i;$ok=true;if(isset($selector['path'])&&$selector['path']!==''&&$selector['path']!==implode('.',$p))$ok=false;if(isset($selector['block_name'])&&$selector['block_name']!==''&&(string)($b['blockName']??'')!==$selector['block_name'])$ok=false;if(isset($selector['text'])&&$selector['text']!==''&&stripos((string)($b['innerHTML']??''),(string)$selector['text'])===false)$ok=false;if($ok)return array('node'=>&$b,'path'=>implode('.',$p));if(!empty($b['innerBlocks'])){$x=self::locate_in_raw($b['innerBlocks'],$selector,$p);if($x)return $x;}}return new WP_Error('oai_block_not_found','Gutenberg block not found',array('status'=>404));}
}

