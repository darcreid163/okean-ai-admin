<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Unified read/discovery facade. Keeps the agent independent from endpoint names.
 */
class OAI_Read_Engine {
 public static function discover($normalized,$params=array()){
  $domain=$normalized['domain']??'site'; $out=array('contract'=>'ai-admin/read/v1','domain'=>$domain,'sources'=>array());
  switch($domain){
   case 'elementor':
    $out['sources']['site']=OAI_REST::site();
    $out['sources']['post_types']=OAI_Read::post_types();
    $id=absint($params['id']??0);
    if($id){$p=get_post($id); if($p)$out['sources']['content']=OAI_REST::content(new WP_REST_Request('GET','/okean-ai/v1/read/content/'.$id));}
    $out['sources']['elementor_schema']=array('note'=>'Use /read/elementor/schema/{widget_type} after widget type discovery');
    break;
   case 'woocommerce':
    $out['sources']['site']=OAI_REST::site();
    $out['sources']['products']=OAI_REST::products(self::request($params));
    break;
   case 'files':
    $out['sources']['files']=OAI_REST::files_list(self::request($params));
    break;
   case 'content':
    $out['sources']['post_types']=OAI_Read::post_types();
    $out['sources']['content']=OAI_REST::content(self::request($params));
    break;
   default:
    $out['sources']['site']=OAI_REST::site();
    $out['sources']['plugins']=OAI_REST::plugins();
    $out['sources']['themes']=OAI_Read::themes();
    $out['sources']['health']=OAI_Read::health();
  }
  return $out;
 }
 private static function request($params){$r=new WP_REST_Request('GET','/okean-ai/v1/read'); foreach((array)$params as $k=>$v)if(is_scalar($v)||is_array($v))$r->set_param($k,$v); return $r;}
}

