<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Surfaces {
 public static function catalog(){
  $out=OAI_Workspace::surfaces();
  $types=get_post_types(array('show_ui'=>true),'objects');
  foreach($types as $slug=>$obj)$out['surfaces'][]=array('id'=>'post_type.'.$slug,'kind'=>'post_type','editable'=>true,'description'=>$obj->label);
  return $out;
 }
 public static function resolve($surface,$context=array()){
  $surface=sanitize_key(str_replace('.','_',(string)$surface));
  switch($surface){
   case 'page_title': case 'post_title':
    $id=absint($context['post_id']??0); if(!$id)return new WP_Error('oai_surface','post_id is required',array('status'=>400)); return array('surface'=>$surface,'post_id'=>$id,'value'=>get_post_field('post_title',$id));
   case 'page_content': case 'post_content':
    $id=absint($context['post_id']??0); if(!$id)return new WP_Error('oai_surface','post_id is required',array('status'=>400)); return array('surface'=>$surface,'post_id'=>$id,'value'=>get_post_field('post_content',$id));
   case 'page_hero_heading': case 'page_hero_subheading':
    $id=absint($context['post_id']??0); if(!$id)return new WP_Error('oai_surface','post_id is required',array('status'=>400)); $key=$surface==='page_hero_heading'?'hero_heading':'hero_subheading'; return array('surface'=>$surface,'post_id'=>$id,'meta_key'=>$key,'value'=>get_post_meta($id,$key,true));
   case 'theme_tokens':
    $w=OAI_Workspace::get($context['workspace_id']??''); if(!$w)return new WP_Error('oai_surface','workspace not found',array('status'=>404)); return array('surface'=>$surface,'workspace'=>$w,'path'=>'theme.css');
  }
  return new WP_Error('oai_surface','Unsupported surface',array('status'=>400,'surface'=>$surface));
 }
}
