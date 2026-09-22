<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Files {
 public static function list_files($scope,$path=''){
  $base=OAI_Security::resolve_path($scope,$path===''?'.':$path); if(is_wp_error($base))return $base;
  if(!is_dir($base))return new WP_Error('oai_not_dir','Directory not found',array('status'=>404));
  $items=array(); foreach(scandir($base) as $name){if($name==='.'||$name==='..'||$name[0]==='.')continue; $full=$base.DIRECTORY_SEPARATOR.$name; $items[]=array('name'=>$name,'type'=>is_dir($full)?'dir':'file','bytes'=>is_file($full)?filesize($full):null,'modified'=>filemtime($full));}
  return array('scope'=>$scope,'path'=>$path,'items'=>$items);
 }
 public static function read($scope,$path){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file; if(!is_file($file))return new WP_Error('oai_not_file','File not found',array('status'=>404));
  if(filesize($file)>2*1024*1024)return new WP_Error('oai_file_large','File exceeds 2 MB read limit',array('status'=>413));
  $fs=self::fs(); $data=$fs?$fs->get_contents($file):file_get_contents($file); return array('scope'=>$scope,'path'=>$path,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data),'content'=>$data);
 }
 public static function search($scope,$q,$path='',$limit=50){
  $base=OAI_Security::resolve_path($scope,$path===''?'.':$path); if(is_wp_error($base))return $base; $q=(string)$q; if($q==='')return new WP_Error('oai_query','q is required',array('status'=>400));
  $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f){if(count($out)>=$limit)break; if(!$f->isFile()||$f->getSize()>2*1024*1024)continue; $name=$f->getFilename(); if($name[0]==='.')continue; $c=@file_get_contents($f->getPathname()); if($c!==false && stripos($c,$q)!==false){$pos=stripos($c,$q);$out[]=array('path'=>str_replace(OAI_Security::scopes()[$scope],'', $f->getPathname()),'bytes'=>$f->getSize(),'snippet'=>substr($c,max(0,$pos-180),min(500,strlen($c)-max(0,$pos-180))));}}
  return array('scope'=>$scope,'query'=>$q,'count'=>count($out),'items'=>$out);
 }
 public static function write($scope,$path,$content,$expected_sha256=''){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file;
  if(strlen($content)>5*1024*1024)return new WP_Error('oai_file_large','Write limit is 5 MB',array('status'=>413));
  $fs=self::fs(); $old=is_file($file)?($fs?$fs->get_contents($file):file_get_contents($file)):''; $old_hash=hash('sha256',(string)$old);
  if($expected_sha256!=='' && !hash_equals($expected_sha256,$old_hash))return new WP_Error('oai_conflict','File changed since snapshot',array('status'=>409,'current_sha256'=>$old_hash));
  $guard=OAI_Security::php_guard($file,$content); if(!$guard['ok'])return new WP_Error('oai_php_guard','PHP safety check failed',$guard);
  $dir=dirname($file); if(!is_dir($dir) && !wp_mkdir_p($dir))return new WP_Error('oai_dir','Could not create directory',array('status'=>500));
  if(!$fs)return new WP_Error('oai_filesystem','WordPress Filesystem unavailable',array('status'=>500));
  $backup=$old!==''?$old:null; $ok=$fs->put_contents($file,$content,FS_CHMOD_FILE); if(!$ok)return new WP_Error('oai_write','File write failed',array('status'=>500));
  return array('ok'=>true,'before_sha256'=>$old_hash,'after_sha256'=>hash('sha256',$content),'backup'=>$backup,'bytes'=>strlen($content),'path'=>$path);
 }
 private static function fs(){ global $wp_filesystem; if(!empty($wp_filesystem))return $wp_filesystem; if(!function_exists('WP_Filesystem'))require_once ABSPATH.'wp-admin/includes/file.php'; if(!WP_Filesystem())return false; return $wp_filesystem; }
}
