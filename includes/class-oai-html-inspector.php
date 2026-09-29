<?php
/** Read-only inspection of the public HTML delivered by this WordPress site. */
if (!defined('ABSPATH')) exit;

class OAI_HTML_Inspector {
 public static function inspect($req){
  $url=(string)$req->get_param('url');
  $post_id=absint($req->get_param('post_id'));
  $internal_draft_render=false;
  $post=null;
  if($post_id){
   $post=get_post($post_id);
   if(!$post || !current_user_can('read_post',$post_id)) return new WP_Error('oai_html_post','Readable post required',array('status'=>400));
   if($post->post_status!=='publish'){
    if(!$req->get_param('allow_draft')) return new WP_Error('oai_html_post','Published readable post required unless allow_draft=true is explicitly requested',array('status'=>400,'post_status'=>$post->post_status));
    if(!current_user_can('manage_options') || !current_user_can('edit_post',$post_id)) return new WP_Error('oai_html_draft_auth','Administrator edit permission is required for draft render inspection',array('status'=>403));
    if(!in_array((string)$post->post_status,array('draft','private','pending'),true)) return new WP_Error('oai_html_draft_state','Only draft, private or pending posts can use authenticated draft render inspection',array('status'=>409,'post_status'=>$post->post_status));
    $internal_draft_render=true;
   }
   $url=get_permalink($post);
  }
  if(!$url) $url=home_url('/');
  $site=wp_parse_url(home_url('/')); $target=wp_parse_url($url);
  if(!is_array($site)||!is_array($target)||!in_array(strtolower((string)($target['scheme']??'')),array('http','https'),true)
    ||strcasecmp((string)($site['host']??''),(string)($target['host']??''))!==0
    ||(int)($site['port']??(strtolower((string)$site['scheme'])==='https'?443:80)) !== (int)($target['port']??(strtolower((string)$target['scheme'])==='https'?443:80))
    ||strtolower((string)$site['scheme'])!==strtolower((string)$target['scheme'])
    ||isset($target['user'])||isset($target['pass'])) return new WP_Error('oai_html_url','Only URLs on this WordPress site are allowed',array('status'=>400));
  $path=rawurldecode((string)($target['path']??'/'));
  if(preg_match('~(?:^|/)(?:wp-admin|wp-json|wp-login\.php)(?:/|$)~i',$path)) return new WP_Error('oai_html_path','Only public frontend pages can be inspected',array('status'=>400));
  $query=array();parse_str((string)($target['query']??''),$query);
  foreach(array_keys($query) as $key){if(preg_match('/^(?:rest_route|preview|preview_id|preview_nonce|_wpnonce|action|token|key|password)$/i',(string)$key))return new WP_Error('oai_html_query','Private or API query parameters cannot be inspected',array('status'=>400));}
  $selector=trim((string)$req->get_param('selector'));
  $mode=sanitize_key((string)($req->get_param('mode')?:'inspect'));
  if(!in_array($mode,array('inspect','outline'),true))return new WP_Error('oai_html_mode','Invalid HTML inspection mode',array('status'=>400));
  if($mode==='outline'&&!OAI_Platform::content_navigation_allowed())return new WP_Error('oai_feature_disabled','Content navigation is disabled',array('status'=>403));
  $max_results=max(1,min(100,absint($req->get_param('max_results')?:20)));
  $max_chars=max(100,min(10000,absint($req->get_param('max_chars')?:5000)));
  if(strlen($selector)>120 || ($selector!=='' && !preg_match('/^(?:[a-z][a-z0-9-]*|[.#][a-zA-Z_][a-zA-Z0-9_-]*)$/i',$selector))) return new WP_Error('oai_html_selector','Use a tag, #id, or .class selector',array('status'=>400));
  if(!class_exists('DOMDocument')) return new WP_Error('oai_html_dom','PHP DOM extension is required',array('status'=>501));
  $render_mode='public_server_response';
  $body='';
  if($internal_draft_render){
   $render_mode='authenticated_draft_server_render';
   $previous_post=$GLOBALS['post']??null;
   try{
    if(defined('ELEMENTOR_VERSION') && class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->frontend) && get_post_meta($post_id,'_elementor_edit_mode',true)==='builder'){
     $frontend=\Elementor\Plugin::$instance->frontend;
     $dummy_id=absint(get_option('page_on_front'));
     if(!$dummy_id || $dummy_id===$post_id)$dummy_id=absint(get_option('page_for_posts'));
     if(!$dummy_id || $dummy_id===$post_id)$dummy_id=absint(get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>1,'exclude'=>array($post_id),'fields'=>'ids'))[0]??0);
     if($dummy_id && $dummy_id!==$post_id){$dummy=get_post($dummy_id);if($dummy){$GLOBALS['post']=$dummy;setup_postdata($dummy);}}
     if(method_exists($frontend,'get_builder_content_for_display'))$body=(string)$frontend->get_builder_content_for_display($post_id,true);
     if($body==='' && method_exists($frontend,'get_builder_content'))$body=(string)$frontend->get_builder_content($post_id,true);
     if($body===''){
      $document=\Elementor\Plugin::$instance->documents->get($post_id);
      if($document && method_exists($document,'get_content'))$body=(string)$document->get_content();
     }
    }
    if($body===''){
     $GLOBALS['post']=$post;setup_postdata($post);
     $body=(string)apply_filters('the_content',(string)$post->post_content);
    }
   }catch(Throwable $e){
    if($previous_post!==null)$GLOBALS['post']=$previous_post;else unset($GLOBALS['post']);
    wp_reset_postdata();
    return new WP_Error('oai_html_draft_render','Could not render draft HTML',array('status'=>500,'message'=>$e->getMessage()));
   }
   if($previous_post!==null)$GLOBALS['post']=$previous_post;else unset($GLOBALS['post']);
   wp_reset_postdata();
   if($body==='')return new WP_Error('oai_html_draft_empty','Draft renderer returned empty HTML',array('status'=>422,'post_id'=>$post_id));
   $body='<!doctype html><html><head><meta charset="'.esc_attr(get_bloginfo('charset')).'"><title>'.esc_html(get_the_title($post_id)).'</title></head><body class="oai-authenticated-draft-render">'.$body.'</body></html>';
  }else{
   $response=wp_safe_remote_get($url,array('timeout'=>10,'redirection'=>0,'limit_response_size'=>1048576,'headers'=>array('Accept'=>'text/html','Cache-Control'=>'no-cache')));
   if(is_wp_error($response))return $response;
   $code=wp_remote_retrieve_response_code($response);
   if($code!==200)return new WP_Error('oai_html_http','Frontend returned HTTP '.$code,array('status'=>502,'http_status'=>$code));
   $content_type=wp_remote_retrieve_header($response,'content-type');
   if(stripos((string)$content_type,'text/html')===false)return new WP_Error('oai_html_type','Frontend did not return HTML',array('status'=>415));
   $body=wp_remote_retrieve_body($response);
  }
  if(strlen($body)>=1048576)return new WP_Error('oai_html_large','HTML exceeds the 1 MiB inspection limit',array('status'=>413));
  $dom=new DOMDocument(); $previous=libxml_use_internal_errors(true);
  try { $loaded=$dom->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
  finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
  if(!$loaded)return new WP_Error('oai_html_parse','Unable to parse HTML',array('status'=>422));
  $xpath=new DOMXPath($dom);
  $title=$dom->getElementsByTagName('title')->item(0);
  $heading_total=0;foreach(array('h1','h2','h3') as $tag)$heading_total+=$dom->getElementsByTagName($tag)->length;
  $headings=array();foreach(array('h1','h2','h3') as $tag){foreach($dom->getElementsByTagName($tag) as $node){if(count($headings)>=($mode==='outline'?$max_results:60))break 2;$headings[]=array('tag'=>$tag,'text'=>mb_substr(trim(preg_replace('/\s+/u',' ',$node->textContent)),0,180));}}
  if($mode==='outline'){
   $landmark_total=0;foreach(array('main','header','nav','section','article','form','footer') as $tag)$landmark_total+=$dom->getElementsByTagName($tag)->length;
   $landmarks=array();foreach(array('main','header','nav','section','article','form','footer') as $tag){foreach($dom->getElementsByTagName($tag) as $node){if(count($landmarks)>=$max_results)break 2;$landmarks[]=array('tag'=>$tag,'id'=>mb_substr((string)$node->getAttribute('id'),0,100),'class'=>mb_substr((string)$node->getAttribute('class'),0,120));}}
   return array('url'=>$url,'mode'=>'outline','render_mode'=>$render_mode,'post_id'=>$post_id?:null,'post_status'=>$post?$post->post_status:null,'javascript_executed'=>false,'title'=>$title?trim($title->textContent):'','html_bytes'=>strlen($body),'headings'=>$headings,'headings_total'=>$heading_total,'landmarks'=>$landmarks,'landmarks_total'=>$landmark_total,'links_count'=>$dom->getElementsByTagName('a')->length,'images_count'=>$dom->getElementsByTagName('img')->length,'forms_count'=>$dom->getElementsByTagName('form')->length,'truncated'=>$landmark_total>count($landmarks)||$heading_total>count($headings));
  }
  $matches=array(); $matches_total=0; $budget=30000;
  if($selector!==''){
   if($selector[0]==='#')$query='//*[@id="'.substr($selector,1).'"]';
   elseif($selector[0]==='.')$query='//*[contains(concat(" ", normalize-space(@class), " "), " '.substr($selector,1).' ")]';
   else $query='//'.$selector;
   $found=$xpath->query($query); $matches_total=$found->length;
   foreach($found as $node){if(count($matches)>=$max_results||$budget<=0)break;$row=array();if($req->get_param('include_text')!==false){$row['text']=mb_substr(trim($node->textContent),0,min(1000,$max_chars,$budget));$budget-=mb_strlen($row['text']);}$row['html']=mb_substr($dom->saveHTML($node),0,min($max_chars,$budget));$budget-=mb_strlen($row['html']);if($req->get_param('include_attributes')){$row['attributes']=array();foreach($node->attributes??array() as $attr){if(count($row['attributes'])>=12||$budget<=0)break;if(preg_match('/^on/i',$attr->name))continue;$value=mb_substr($attr->value,0,min(200,$budget));$row['attributes'][$attr->name]=$value;$budget-=mb_strlen($value);}}$matches[]=$row;}
  }
  $result=array('url'=>$url,'render_mode'=>$render_mode,'post_id'=>$post_id?:null,'post_status'=>$post?$post->post_status:null,'javascript_executed'=>false,'title'=>$title?trim($title->textContent):'',
   'headings'=>$headings,'links_count'=>$dom->getElementsByTagName('a')->length,'images_count'=>$dom->getElementsByTagName('img')->length,
   'selector'=>$selector,'matches'=>$matches,'matches_total'=>$matches_total,'matches_truncated'=>$matches_total>count($matches),'html_bytes'=>strlen($body));
  if($req->get_param('include_html'))$result['html']=mb_substr($body,0,min(30000,max(1000,absint($req->get_param('max_length')?:12000))));
  return $result;
 }
}
