<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Security {
 public static function scopes(){return array('child_theme'=>trailingslashit(get_stylesheet_directory()),'theme'=>trailingslashit(get_template_directory()));}
 public static function resolve_path($scope,$relative){
  $scopes=self::scopes(); $scope=sanitize_key($scope);
  if(!isset($scopes[$scope])) return new WP_Error('oai_path_scope','Invalid file scope',array('status'=>400));
  $relative=str_replace('\\','/',(string)$relative);
  if(strpos($relative,'/')===0 || preg_match('/^[A-Za-z]:\//',$relative)) return new WP_Error('oai_path_denied','Absolute paths are not allowed',array('status'=>403));
  if($relative==='' || strpos($relative,'..')!==false || preg_match('#(^|/)\.(?:/|$)#',$relative) || preg_match('#(^|/)\.[^/]+#',$relative))
   return new WP_Error('oai_path_denied','Path is not allowed',array('status'=>403));
  $relative=preg_replace('#/+#','/',$relative);
  $base=realpath($scopes[$scope]); if(!$base)return new WP_Error('oai_path_scope','Base path unavailable',array('status'=>500));
  $candidate=$base.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
  $real=realpath($candidate);
  if($real===false){
   $parent=realpath(dirname($candidate));
   if($parent===false || ($parent!==$base && strpos($parent,trailingslashit($base))!==0))return new WP_Error('oai_path_denied','Path escapes allowed scope',array('status'=>403));
   $real=$parent.DIRECTORY_SEPARATOR.basename($candidate);
  }
  if($real!==$base && strpos($real,trailingslashit($base))!==0)return new WP_Error('oai_path_denied','Path escapes allowed scope',array('status'=>403));
  $name=basename($real);
  if(in_array(strtolower($name),array('wp-config.php','.htaccess','.user.ini'),true))return new WP_Error('oai_path_denied','Sensitive file denied',array('status'=>403));
  return $real;
 }

 public static function resolve_path_for_write($scope,$relative){
  $scopes=self::scopes(); $scope=sanitize_key($scope);
  if(!isset($scopes[$scope])) return new WP_Error('oai_path_scope','Invalid file scope',array('status'=>400));
  $relative=str_replace('\\','/',trim((string)$relative));
  if(strpos($relative,'/')===0 || preg_match('/^[A-Za-z]:\\//',$relative)) return new WP_Error('oai_path_denied','Absolute paths are not allowed',array('status'=>403));
  if($relative==='' || strpos($relative,'..')!==false || preg_match('#(^|/)\\.(?:/|$)#',$relative) || preg_match('#(^|/)\\.[^/]+#',$relative))
   return new WP_Error('oai_path_denied','Path is not allowed',array('status'=>403));
  $relative=preg_replace('#/+#','/',$relative);
  $base=realpath($scopes[$scope]); if(!$base)return new WP_Error('oai_path_scope','Base path unavailable',array('status'=>500));
  $parts=array_values(array_filter(explode('/',$relative),function($x){return $x!=='';}));
  if(!$parts)return new WP_Error('oai_path_denied','Path is not allowed',array('status'=>403));
  $current=$base;
  foreach(array_slice($parts,0,-1) as $part){
   $current.=DIRECTORY_SEPARATOR.$part;
   if(file_exists($current)||is_link($current)){
    if(is_link($current))return new WP_Error('oai_path_denied','Symlink path segments are not allowed',array('status'=>403));
    $real=realpath($current);
    if($real===false || ($real!==$base && strpos($real,trailingslashit($base))!==0))return new WP_Error('oai_path_denied','Path escapes allowed scope',array('status'=>403));
    if(!is_dir($real))return new WP_Error('oai_path_denied','Parent path is not a directory',array('status'=>403));
    $current=$real;
   }
  }
  $candidate=$base.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
  if(is_link($candidate))return new WP_Error('oai_path_denied','Symlink file targets are not allowed',array('status'=>403));
  if(file_exists($candidate)){
   $real=realpath($candidate);
   if($real===false || ($real!==$base && strpos($real,trailingslashit($base))!==0))return new WP_Error('oai_path_denied','Path escapes allowed scope',array('status'=>403));
   $candidate=$real;
  }
  $name=basename($candidate);
  if(in_array(strtolower($name),array('wp-config.php','.htaccess','.user.ini'),true))return new WP_Error('oai_path_denied','Sensitive file denied',array('status'=>403));
  return $candidate;
 }
 public static function php_guard($path,$content){
  if(strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php')return array('ok'=>true,'checks'=>array());
  $checks=array();
  $checks['opening_tag']=(strpos($content,'<?php')===0);
  $checks['dangerous_functions']=(preg_match('/\b(eval|assert|shell_exec|passthru|system|proc_open|popen|exec)\s*\(/i',$content)!==1);
  try { @token_get_all($content,TOKEN_PARSE); $checks['php_lint']=true; } catch (Throwable $e) { $checks['php_lint']=false; $checks['php_error']=$e->getMessage(); }
  $ok=$checks['opening_tag'] && $checks['dangerous_functions'] && $checks['php_lint'];
  return array('ok'=>$ok,'checks'=>$checks);
 }
}

