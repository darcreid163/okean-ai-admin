<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Files {
 public static function list_files($scope,$path=''){
  // Root listing is a valid read operation. resolve_path() intentionally
  // rejects an empty/"." relative path, so resolve the registered scope root
  // directly here and keep all non-root paths behind the normal boundary check.
  if($path===''){
   $scopes=OAI_Security::scopes(); $scope=sanitize_key($scope);
   if(!isset($scopes[$scope]))return new WP_Error('oai_path_scope','Invalid file scope',array('status'=>400));
   $base=realpath($scopes[$scope]);
   if($base===false)return new WP_Error('oai_path_scope','Base path unavailable',array('status'=>500));
  }else{
   $base=OAI_Security::resolve_path($scope,$path); if(is_wp_error($base))return $base;
  }
  if(!is_dir($base))return new WP_Error('oai_not_dir','Directory not found',array('status'=>404));
  $items=array(); foreach(scandir($base) as $name){if($name==='.'||$name==='..'||$name[0]==='.')continue; $full=$base.DIRECTORY_SEPARATOR.$name; if(is_link($full))continue; $items[]=array('name'=>$name,'type'=>is_dir($full)?'dir':'file','bytes'=>is_file($full)?filesize($full):null,'modified'=>filemtime($full));}
  return array('scope'=>$scope,'path'=>$path,'items'=>$items);
 }
 public static function read($scope,$path){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file; if(!is_file($file))return new WP_Error('oai_not_file','File not found',array('status'=>404));
  if(filesize($file)>2*1024*1024)return new WP_Error('oai_file_large','File exceeds 2 MB read limit',array('status'=>413));
  $fs=self::fs(); $data=$fs?$fs->get_contents($file):file_get_contents($file); return array('scope'=>$scope,'path'=>$path,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data),'content'=>$data);
 }
 public static function read_range($scope,$path,$start_line,$end_line,$max_chars=12000,$expected_sha256=''){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file;
  if(!is_file($file))return new WP_Error('oai_not_file','File not found',array('status'=>404));
  if(filesize($file)>2*1024*1024)return new WP_Error('oai_file_large','File exceeds 2 MB read limit',array('status'=>413));
  $start=(int)$start_line; $end=(int)$end_line;
  if($start<1||$end<$start||$end-$start+1>200)return new WP_Error('oai_line_range','Use an inclusive range of 1 to 200 lines',array('status'=>400));
  $max_chars=max(100,min(20000,(int)$max_chars));
  $sha=hash_file('sha256',$file);
  if($expected_sha256!==''&&!hash_equals($expected_sha256,$sha))return new WP_Error('oai_conflict','File changed since outline',array('status'=>409));
  $lines=file($file); if($lines===false)return new WP_Error('oai_read','Could not read file',array('status'=>500));
  $total=count($lines); if($start>$total)return new WP_Error('oai_line_range','start_line exceeds file length',array('status'=>416)); $slice=array_slice($lines,$start-1,$end-$start+1); $content=implode('',$slice);
  $truncated=mb_strlen($content)>$max_chars;
  if($truncated)$content=mb_substr($content,0,$max_chars);
  return array('scope'=>$scope,'path'=>$path,'sha256'=>$sha,'total_lines'=>$total,'start_line'=>$start,'end_line'=>min($end,$total),'content'=>$content,'truncated'=>$truncated);
 }
 public static function outline($scope,$path,$limit=120){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file;
  if(!is_file($file))return new WP_Error('oai_not_file','File not found',array('status'=>404));
  $bytes=filesize($file); if($bytes>2*1024*1024)return new WP_Error('oai_file_large','File exceeds 2 MB outline limit',array('status'=>413));
  $lines=file($file); if($lines===false)return new WP_Error('oai_read','Could not read file',array('status'=>500));
  $limit=max(1,min(200,(int)$limit)); $items=array(); $matched=0;
  foreach($lines as $i=>$line){
   $kind=''; $label=''; $trim=trim($line);
   if(preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',$line,$m)){$kind=$m[1];$label=$m[2];}
   elseif(preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/',$line,$m)){$kind='function';$label=$m[1];}
   elseif(preg_match('/\b(add_action|add_filter|register_rest_route)\s*\(\s*[\x27\x22]([^\x27\x22]{1,100})/',$line,$m)){$kind='hook';$label=$m[1].': '.$m[2];}
   elseif(preg_match('/^\s*(?:\/\*+|\/\/|#)\s*([A-Za-zА-Яа-я0-9][^*]{3,90})/u',$line,$m)){$kind='section';$label=trim($m[1]);}
   elseif(preg_match('/<\s*(h[1-6]|main|header|footer|section|article|form)\b[^>]*>/i',$line,$m)){$kind='html';$label=strtolower($m[1]).' '.mb_substr(strip_tags($trim),0,70);}
   elseif(preg_match('/^\s*([^{};]{2,100})\s*\{\s*$/',$line,$m) && preg_match('/\.(?:css|scss|less)$/i',$path)){$kind='css';$label=trim($m[1]);}
   if($kind==='')continue; $matched++;
   if(count($items)<$limit)$items[]=array('line'=>$i+1,'kind'=>$kind,'label'=>mb_substr($label,0,120));
  }
  return array('scope'=>$scope,'path'=>$path,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$file),'total_lines'=>count($lines),'matches_total'=>$matched,'truncated'=>$matched>$limit,'items'=>$items);
 }
 public static function search($scope,$q,$path='',$limit=50){
  $scope=sanitize_key((string)$scope); $q=(string)$q; $path=(string)$path;
  if($q==='')return new WP_Error('oai_query','q is required',array('status'=>400));
  if($path===''){
   $scopes=OAI_Security::scopes();
   if(!isset($scopes[$scope]))return new WP_Error('oai_path_scope','Invalid file scope',array('status'=>400));
   $base=realpath($scopes[$scope]);
   if($base===false)return new WP_Error('oai_path_scope','Base path unavailable',array('status'=>500));
  }else{
   $base=OAI_Security::resolve_path($scope,$path);
   if(is_wp_error($base))return $base;
  }
  if(!is_dir($base))return new WP_Error('oai_not_dir','Directory not found',array('status'=>404));
  $limit=max(1,min(100,(int)$limit));
  $scopes=OAI_Security::scopes(); $root=realpath($scopes[$scope]);
  $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f){
   if(count($out)>=$limit)break;
   $relative=substr($f->getPathname(),strlen(trailingslashit($root)));
   $safe=OAI_Security::resolve_path($scope,$relative);
   if(is_wp_error($safe)||!is_file($safe)||filesize($safe)>2*1024*1024)continue;
   $c=@file_get_contents($safe);
   if($c!==false && stripos($c,$q)!==false){$pos=stripos($c,$q);$out[]=array('path'=>$relative,'bytes'=>filesize($safe),'snippet'=>substr($c,max(0,$pos-180),min(500,strlen($c)-max(0,$pos-180))));}
  }
  return array('scope'=>$scope,'query'=>$q,'count'=>count($out),'items'=>$out);
 }
 private static function target_lock($file){
  // Separate lock inode survives file replacement and coordinates Native
  // write/delete across different operations targeting the same local path.
  $path=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wpai-file-'.hash('sha256',$file).'.lock';
  if(is_link($path))return new WP_Error('oai_file_lock','Unsafe target lock',array('status'=>503));
  $handle=@fopen($path,'c');
  if($handle===false)return new WP_Error('oai_file_lock','Target lock unavailable',array('status'=>503));
  @chmod($path,0600);
  if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);return new WP_Error('oai_file_busy','Another file operation is running',array('status'=>409));}
  return $handle;
 }
 private static function release_target_lock($handle){flock($handle,LOCK_UN);fclose($handle);}
 public static function write($scope,$path,$content,$expected_sha256='',$expected_exists=null){
  $file=OAI_Security::resolve_path_for_write($scope,$path); if(is_wp_error($file))return $file;
  if(strlen($content)>5*1024*1024)return new WP_Error('oai_file_large','Write limit is 5 MB',array('status'=>413));
  $guard=OAI_Security::php_guard($file,$content); if(!$guard['ok'])return new WP_Error('oai_php_guard','PHP safety check failed',$guard);
  $fs=self::fs();
  if(!$fs)return new WP_Error('oai_filesystem','WordPress Filesystem unavailable',array('status'=>500));
  if(($fs->method??'')!=='direct')return new WP_Error('oai_filesystem','Guarded file changes require the local direct filesystem',array('status'=>503));
  $lock=self::target_lock($file);if(is_wp_error($lock))return $lock;
  try{
   clearstatcache(true,$file);$exists=is_file($file);
   if($expected_exists!==null && $exists!==(bool)$expected_exists)return new WP_Error('oai_conflict','File existence changed since snapshot',array('status'=>409));
   $old=$exists?$fs->get_contents($file):'';
   if($old===false)return new WP_Error('oai_read','Could not read file before write',array('status'=>500));
   $old_hash=hash('sha256',(string)$old);
   if($expected_sha256!=='' && !hash_equals($expected_sha256,$old_hash))return new WP_Error('oai_conflict','File changed since snapshot',array('status'=>409,'current_sha256'=>$old_hash));
   $dir=dirname($file); if(!is_dir($dir) && !wp_mkdir_p($dir))return new WP_Error('oai_dir','Could not create directory',array('status'=>500));
   $backup=$exists?$old:null; $ok=$fs->put_contents($file,$content,FS_CHMOD_FILE); if(!$ok)return new WP_Error('oai_write','File write failed',array('status'=>500));
   return array('ok'=>true,'before_sha256'=>$old_hash,'after_sha256'=>hash('sha256',$content),'backup'=>$backup,'bytes'=>strlen($content),'path'=>$path);
  }finally{self::release_target_lock($lock);}
 }
 public static function delete($scope,$path,$expected_sha256=''){
  $file=OAI_Security::resolve_path($scope,$path); if(is_wp_error($file))return $file;
  $fs=self::fs(); if(!$fs)return new WP_Error('oai_filesystem','WordPress Filesystem unavailable',array('status'=>500));
  if(($fs->method??'')!=='direct')return new WP_Error('oai_filesystem','Guarded file changes require the local direct filesystem',array('status'=>503));
  $lock=self::target_lock($file);if(is_wp_error($lock))return $lock;
  try{
   clearstatcache(true,$file);
   if(!is_file($file))return new WP_Error('oai_not_file','File not found',array('status'=>404));
   $data=$fs->get_contents($file); if($data===false)return new WP_Error('oai_read','Could not read file before delete',array('status'=>500));
   $current=hash('sha256',(string)$data);
   if($expected_sha256!=='' && !hash_equals($expected_sha256,$current))return new WP_Error('oai_conflict','File changed since snapshot',array('status'=>409,'current_sha256'=>$current));
   if(!$fs->delete($file,false,'f'))return new WP_Error('oai_delete','File delete failed',array('status'=>500));
   return array('ok'=>true,'before_sha256'=>$current,'path'=>$path);
  }finally{self::release_target_lock($lock);}
 }
 private static function fs(){ global $wp_filesystem; if(!empty($wp_filesystem))return $wp_filesystem; if(!function_exists('WP_Filesystem'))require_once ABSPATH.'wp-admin/includes/file.php'; if(!WP_Filesystem())return false; return $wp_filesystem; }
}

