<?php
/** Unified read-only page QA: static DOM/accessibility/link checks + existing browser Visual QA. */
if (!defined('ABSPATH')) exit;

class OAI_Page_QA {
 const MAX_FINDINGS=20;

 public static function self_test_contract(){
  return array(
   'single_tool_two_phase'=>true,
   'static_dom_checks'=>class_exists('DOMDocument'),
   'visual_qa_reuse'=>class_exists('OAI_Visual_QA')&&method_exists('OAI_Visual_QA','start')&&method_exists('OAI_Visual_QA','result')&&method_exists('OAI_Visual_QA','render_for_qa'),
   'no_content_mutation'=>true,
   'bounded_findings'=>self::MAX_FINDINGS===20,
  );
 }

 private static function push(&$list,$item){
  if(count($list)<self::MAX_FINDINGS)$list[]=$item;
 }

 private static function text($node){
  return trim(preg_replace('/\s+/u',' ',(string)$node->textContent));
 }

 private static function selector($node){
  if(!($node instanceof DOMElement))return '';
  if($node->hasAttribute('id'))return '#'.substr($node->getAttribute('id'),0,100);
  $tag=strtolower($node->tagName);
  if($node->hasAttribute('class')){
   $classes=preg_split('/\s+/',trim($node->getAttribute('class')));
   $classes=array_values(array_filter(array_slice($classes,0,2)));
   if($classes)$tag.='.'.implode('.',$classes);
  }
  return substr($tag,0,120);
 }

 private static function has_accessible_name($el){
  if(!($el instanceof DOMElement))return true;
  if(trim($el->getAttribute('aria-label'))!=='')return true;
  if(trim($el->getAttribute('aria-labelledby'))!=='')return true;
  if(trim($el->getAttribute('title'))!=='')return true;
  if(trim($el->getAttribute('placeholder'))!=='')return true;
  if(strtolower($el->tagName)==='input'){
   $type=strtolower($el->getAttribute('type'));
   if(in_array($type,array('submit','button','reset'),true)&&trim($el->getAttribute('value'))!=='')return true;
   if(in_array($type,array('hidden'),true))return true;
  }
  $id=trim($el->getAttribute('id'));
  if($id!==''){
   $doc=$el->ownerDocument;
   foreach($doc->getElementsByTagName('label') as $label){
    if($label instanceof DOMElement && $label->getAttribute('for')===$id && self::text($label)!=='')return true;
   }
  }
  $p=$el->parentNode;
  while($p instanceof DOMElement){
   if(strtolower($p->tagName)==='label'&&self::text($p)!=='')return true;
   $p=$p->parentNode;
  }
  return false;
 }

 public static function analyze_html($html){
  $html=(string)$html;
  if($html==='')return new WP_Error('oai_page_qa_empty','Rendered HTML is empty',array('status'=>422));
  if(!class_exists('DOMDocument'))return new WP_Error('oai_page_qa_dom','PHP DOM extension is required',array('status'=>501));

  $prev=libxml_use_internal_errors(true);
  $dom=new DOMDocument();
  $ok=$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOWARNING|LIBXML_NOERROR|LIBXML_NONET);
  libxml_clear_errors();libxml_use_internal_errors($prev);
  if(!$ok)return new WP_Error('oai_page_qa_parse','Rendered HTML could not be parsed',array('status'=>422));

  $find=array(
   'heading_jumps'=>array(),'missing_alt'=>array(),'unnamed_controls'=>array(),
   'empty_links'=>array(),'unsafe_links'=>array(),'broken_fragments'=>array(),'duplicate_ids'=>array()
  );
  $heading_counts=array('h1'=>0,'h2'=>0,'h3'=>0,'h4'=>0,'h5'=>0,'h6'=>0);
  $lastLevel=0;
  for($level=1;$level<=6;$level++){
   foreach($dom->getElementsByTagName('h'.$level) as $h)$heading_counts['h'.$level]++;
  }
  $xp=new DOMXPath($dom);
  foreach($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h){
   $level=(int)substr(strtolower($h->nodeName),1);
   if($lastLevel>0&&$level>$lastLevel+1)self::push($find['heading_jumps'],array('element'=>self::selector($h),'from'=>$lastLevel,'to'=>$level,'text'=>substr(self::text($h),0,120)));
   $lastLevel=$level;
  }

  foreach($dom->getElementsByTagName('img') as $img){
   if(!$img->hasAttribute('alt'))self::push($find['missing_alt'],array('element'=>self::selector($img),'src'=>substr($img->getAttribute('src'),0,160)));
  }

  foreach(array('input','select','textarea','button') as $tag){
   foreach($dom->getElementsByTagName($tag) as $el){
    if($tag==='button'&&self::text($el)!=='')continue;
    if(!self::has_accessible_name($el))self::push($find['unnamed_controls'],array('element'=>self::selector($el),'type'=>substr($el->getAttribute('type'),0,40)));
   }
  }

  $ids=array();
  foreach($xp->query('//*[@id]') as $el){
   $id=trim($el->getAttribute('id'));if($id==='')continue;
   if(isset($ids[$id]))self::push($find['duplicate_ids'],array('id'=>substr($id,0,120),'element'=>self::selector($el)));
   $ids[$id]=true;
  }

  $link_count=0;
  foreach($dom->getElementsByTagName('a') as $a){
   $link_count++;$href=trim($a->getAttribute('href'));$label=substr(self::text($a),0,100);
   if($href===''||$href==='#')self::push($find['empty_links'],array('element'=>self::selector($a),'text'=>$label,'href'=>$href));
   if(preg_match('/^javascript:/i',$href))self::push($find['unsafe_links'],array('element'=>self::selector($a),'text'=>$label,'href'=>substr($href,0,120)));
   if(strlen($href)>1&&$href[0]==='#'){
    $target=substr($href,1);
    if(!isset($ids[$target]))self::push($find['broken_fragments'],array('element'=>self::selector($a),'href'=>substr($href,0,120),'text'=>$label));
   }
  }

  $summary=array(
   'h1_count'=>$heading_counts['h1'],
   'heading_jumps'=>count($find['heading_jumps']),
   'missing_alt'=>count($find['missing_alt']),
   'unnamed_controls'=>count($find['unnamed_controls']),
   'empty_links'=>count($find['empty_links']),
   'unsafe_links'=>count($find['unsafe_links']),
   'broken_fragments'=>count($find['broken_fragments']),
   'duplicate_ids'=>count($find['duplicate_ids']),
   'links'=>$link_count
  );
  $issue_count=($summary['h1_count']===1?0:1)+$summary['heading_jumps']+$summary['missing_alt']+$summary['unnamed_controls']+$summary['empty_links']+$summary['unsafe_links']+$summary['broken_fragments']+$summary['duplicate_ids'];
  return array(
   'status'=>$issue_count===0?'PASS':'WARN',
   'summary'=>$summary,
   'heading_counts'=>$heading_counts,
   'findings'=>$find,
   'rendered_html_bytes'=>strlen($html),
   'checks'=>array('single_h1'=>true,'heading_order'=>true,'image_alt_presence'=>true,'form_control_names'=>true,'link_integrity'=>true,'duplicate_ids'=>true)
  );
 }

 public static function run($post_id,$probe_id=''){
  $post_id=absint($post_id);$probe_id=sanitize_text_field((string)$probe_id);
  $post=$post_id?get_post($post_id):null;
  if(!$post)return new WP_Error('oai_page_qa_post','Post not found',array('status'=>404));
  if(!current_user_can('manage_options')||!current_user_can('edit_post',$post_id))return new WP_Error('oai_page_qa_auth','Administrator edit permission is required',array('status'=>403));
  $html=OAI_Visual_QA::render_for_qa($post_id);if(is_wp_error($html))return $html;
  $static=self::analyze_html($html);if(is_wp_error($static))return $static;

  if($probe_id===''){
   $visual=OAI_Visual_QA::start($post_id);if(is_wp_error($visual))return $visual;
   return array(
    'ok'=>true,'status'=>'pending_browser','post_id'=>$post_id,'post_status'=>$post->post_status,'title'=>get_the_title($post_id),
    'static'=>$static,'probe_id'=>$visual['probe_id'],'browser_url'=>$visual['url'],'viewports'=>$visual['viewports'],
    'requires_browser_open'=>true,'publishes_content'=>false,'next_call'=>array('tool'=>'system.page_qa_run','post_id'=>$post_id,'probe_id'=>$visual['probe_id'])
   );
  }

  $visual=OAI_Visual_QA::result($probe_id);if(is_wp_error($visual))return $visual;
  $meta=$visual['meta']??array();
  if(absint($meta['post_id']??0)!==$post_id)return new WP_Error('oai_page_qa_binding','Visual QA probe belongs to another post',array('status'=>409));
  if(($visual['status']??'')!=='complete'){
   return array('ok'=>true,'status'=>'pending_browser','post_id'=>$post_id,'probe_id'=>$probe_id,'static'=>$static,'visual'=>$visual,'requires_browser_open'=>true,'publishes_content'=>false);
  }
  $vreport=$visual['report']??array();
  $overall=(($static['status']??'WARN')==='PASS'&&($vreport['status']??'WARN')==='PASS')?'PASS':'WARN';
  return array(
   'ok'=>true,'status'=>'complete','qa_status'=>$overall,'post_id'=>$post_id,'post_status'=>$post->post_status,'title'=>get_the_title($post_id),
   'static'=>$static,'visual'=>$vreport,
   'summary'=>array('static_status'=>$static['status'],'visual_status'=>$vreport['status']??'unknown','desktop_tablet_mobile'=>array_keys((array)($vreport['viewports']??array()))),
   'publishes_content'=>false,'mutates_content'=>false
  );
 }
}
