<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Security {
 /** Read-only presentation. Never pass this projection to persistence or Apply. */
 public static function public_evidence($value){
  $changed=false;$out=self::evidence_value($value,'',$changed);
  if($changed&&((is_array($out)&&!array_is_list($out))||$out instanceof stdClass)){
   $projection=array(
   'redacted'=>true,'reason'=>'server_filesystem_paths','stored_evidence_modified'=>false,
   'hashes_attest'=>'original_evidence','canonical_payload_available'=>false
   );
   if(is_array($out))$out['_wpai_projection']=$projection;else$out->_wpai_projection=$projection;
  }
  return $out;
 }
 private static function evidence_value($value,$key,&$changed){
  if($value instanceof stdClass){
   $projected=self::evidence_value(get_object_vars($value),$key,$changed);
   return (object)$projected;
  }
  if(is_array($value)){
   $out=array();foreach($value as $name=>$item){
    $public_name=is_string($name)?self::evidence_text($name,'',$changed):$name;
    // Preserve distinct path-keyed entries after redaction.
    if($public_name!==$name)$public_name.=':'.substr(hash('sha256',$name),0,12);
    $out[$public_name]=self::evidence_value($item,(string)$name,$changed);
   }return $out;
  }
  if(!is_string($value))return $value;
  // Ledger rows still contain serialized JSON proof/receipt fields. Decode a
  // copy only: signed/stored bytes and their hashes must never be rewritten.
  $trim=ltrim($value);
  if($trim!==''&&in_array($trim[0],array('{','['),true)){
   $decoded=json_decode($value);
   if(is_array($decoded)||$decoded instanceof stdClass){
    $nested=false;$projected=self::evidence_value($decoded,$key,$nested);
    if($nested){$changed=true;return wp_json_encode($projected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
   }
  }
  return self::evidence_text($value,$key,$changed);
 }
 private static function evidence_text($text,$key,&$changed){
  $original=$text;$roots=array();
  foreach(array('OAI_DIR'=>'[plugin]','WP_CONTENT_DIR'=>'[content]','ABSPATH'=>'[wordpress]','WP_TEMP_DIR'=>'[temporary]') as $constant=>$label){
   if(defined($constant))$roots[rtrim(str_replace('\\','/',constant($constant)),'/')]=$label;
  }
  $roots[rtrim(str_replace('\\','/',sys_get_temp_dir()),'/')]='[temporary]';
  uksort($roots,function($a,$b){return strlen($b)<=>strlen($a);});
  foreach($roots as $root=>$label){
   if($root==='')continue;
   foreach(array_unique(array($root,str_replace('/','\\',$root))) as $variant){
    $pattern='~(?<![A-Za-z0-9_:/\\\\.-])'.preg_quote($variant,'~').'(?=[/\\\\]|$|[\s"\'<>),;])~';
    $text=preg_replace($pattern,$label,$text);
   }
  }
  // Historical paths may belong to another host or OS, outside current roots.
  $text=preg_replace('~\bfile://[^\s"\'<>|,;)}\]]+~i','[server-path]',$text);
  $text=preg_replace('~(?<![A-Za-z0-9_:/\\\\.-])(?:[A-Za-z]:[/\\\\]|\\\\\\\\[^\\\\\s]+\\\\)[^\s"\'<>|,;)}\]]+~','[server-path]',$text);
  $text=preg_replace('~(?<![A-Za-z0-9_:/.-])/(?:home|var|srv|opt|tmp|usr|private|mnt|workspace|Users|Applications|Library|Volumes|www|htdocs|data|sites|public_html|inetpub)(?=/|$|[\s"\'<>|,;)}\]])(?:/[^\s"\'<>|,;)}\]]*)?~','[server-path]',$text);
  if(preg_match('/(?:^|_)(?:error|message)$/i',$key))$text=preg_replace('~(?<![A-Za-z0-9_:/.-])/(?:[^/\s"\'<>|,;)}\]]+/)+[^\s"\'<>|,;)}\]]+~','[server-path]',$text);
  // Known filesystem fields cover unusual legacy mount points as well.
  if(preg_match('/(?:^|_)(?:file|path|dir|directory|root|basedir)$/i',$key)&&preg_match('~^/(?!/)~',$text))$text='[server-path]';
  if($text!==$original)$changed=true;
  return $text;
 }
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

