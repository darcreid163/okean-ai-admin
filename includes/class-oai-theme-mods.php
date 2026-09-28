<?php
/** Bounded read-only inspection of the active theme's saved customizer settings. */
if (!defined('ABSPATH')) exit;
class OAI_Theme_Mods {
 public static function get($req){
  $name=trim((string)$req->get_param('name'));
  if(strlen($name)>100 || ($name!=='' && !preg_match('/^[a-zA-Z0-9_-]+$/',$name)))return new WP_Error('oai_theme_mod_name','Invalid theme setting name',array('status'=>400));
  if($name!==''){
   $sentinel=new stdClass();$value=get_theme_mod($name,$sentinel);
   $safe=$value!==$sentinel?OAI_Read::option_value($name,$value):array('redacted'=>false,'value'=>null);
   return array('theme'=>get_stylesheet(),'name'=>$name,'exists'=>$value!==$sentinel,'redacted'=>$safe['redacted'],'value'=>self::bounded($safe['value']));
  }
  $mods=get_theme_mods();if(!is_array($mods))$mods=array();
  $out=array();foreach($mods as $key=>$value){if(count($out)>=100)break;$safe=OAI_Read::option_value((string)$key,$value);$out[(string)$key]=self::bounded($safe['value']);}
  return array('theme'=>get_stylesheet(),'count'=>count($mods),'returned'=>count($out),'truncated'=>count($out)<count($mods),'mods'=>$out);
 }
 private static function bounded($value){
  $json=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($json)||strlen($json)>4096)return array('truncated'=>true,'type'=>gettype($value),'bytes'=>is_string($json)?strlen($json):null,'sha256'=>is_string($json)?hash('sha256',$json):null);
  return $value;
 }
}
