<?php
/**
 * AI Admin workspace / draft-theme engine.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Workspace {
 public static function root(){
  $u=wp_upload_dir();
  return trailingslashit($u['basedir']).'ai-admin-workspaces';
 }
 public static function url_root(){
  $u=wp_upload_dir();
  return trailingslashit($u['baseurl']).'ai-admin-workspaces';
 }
 private static function ensure_root(){
  $root=self::root();
  if(!is_dir($root)) wp_mkdir_p($root);
  return is_dir($root)?$root:false;
 }
 private static function id(){return 'ws-'.gmdate('Ymd-His').'-'.wp_rand(1000,9999);}
 public static function list_workspaces(){
  $root=self::ensure_root(); if(!$root)return array('items'=>array());
  $items=array();
  foreach(scandir($root) as $name){
   if($name==='.'||$name==='..'||strpos($name,'ws-')!==0)continue;
   $meta=$root.'/'.$name.'/workspace.json';
   $data=is_file($meta)?json_decode((string)file_get_contents($meta),true):array();
   $items[]=array('id'=>$name,'created_at'=>$data['created_at']??null,'active'=>$data['active']??false,'theme'=>$data['theme']??null,'path'=>$root.'/'.$name);
  }
  usort($items,function($a,$b){return strcmp((string)$b['id'],(string)$a['id']);});
  return array('root'=>$root,'items'=>$items);
 }
 public static function create($clone_theme=true){
  $root=self::ensure_root(); if(!$root)return new WP_Error('oai_workspace_root','Could not create workspace root',array('status'=>500));
  $id=self::id(); $base=$root.'/'.$id; wp_mkdir_p($base);
  $theme=array('stylesheet'=>get_stylesheet(),'template'=>get_template(),'name'=>wp_get_theme()->get('Name'),'version'=>wp_get_theme()->get('Version'));
  $meta=array('id'=>$id,'created_at'=>gmdate('c'),'active'=>true,'theme'=>$theme,'clone_theme'=>(bool)$clone_theme);
  if($clone_theme){
   $theme_dir=$base.'/theme'; wp_mkdir_p($theme_dir);
   $source=trailingslashit(get_stylesheet_directory());
   $ok=self::copy_tree($source,$theme_dir);
   if(is_wp_error($ok)){ self::remove_tree($base); return $ok; }
   $meta['theme_path']=$theme_dir;
  }
  file_put_contents($base.'/workspace.json',wp_json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
  update_option('oai_active_workspace',$id,false);
  return $meta;
 }
 private static function copy_tree($src,$dst){
  if(!is_dir($src))return new WP_Error('oai_workspace_source','Theme source directory not found',array('status'=>404));
  if(!wp_mkdir_p($dst))return new WP_Error('oai_workspace_dir','Could not create workspace directory',array('status'=>500));
  $fs= self::fs();
  if($fs){
   foreach(self::tree($src) as $file){
    $rel=ltrim(str_replace('\\','/',substr($file,strlen(rtrim($src,'/\\')))),'/');
    $target=trailingslashit($dst).$rel; $dir=dirname($target); if(!is_dir($dir)){ if(!wp_mkdir_p($dir)) return new WP_Error('oai_workspace_dir','Could not create workspace directory',array('status'=>500,'file'=>$rel)); }
    $data=$fs->get_contents($file); if($data===false)return new WP_Error('oai_workspace_read','Could not read theme file',array('status'=>500,'file'=>$rel));
    if(!$fs->put_contents($target,$data,FS_CHMOD_FILE))return new WP_Error('oai_workspace_write','Could not copy theme file',array('status'=>500,'file'=>$rel));
   }
   return true;
  }
  return new WP_Error('oai_workspace_fs','WordPress Filesystem could not initialize',array('status'=>500));
 }
 private static function remove_tree($dir){
  if(!is_dir($dir))return;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f){ if($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
  @rmdir($dir);
 }
 private static function tree($dir){
  $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f)if($f->isFile())$out[]=$f->getPathname();
  return $out;
 }
 private static function fs(){
  global $wp_filesystem;
  if(!empty($wp_filesystem))return $wp_filesystem;
  if(!function_exists('WP_Filesystem'))require_once ABSPATH.'wp-admin/includes/file.php';
  if(!WP_Filesystem())return false;
  return $wp_filesystem;
 }
 public static function get($id=''){
  $id=$id?:get_option('oai_active_workspace',''); if(!$id)return null;
  $file=self::root().'/'.sanitize_file_name($id).'/workspace.json';
  if(!is_file($file))return null; $x=json_decode((string)file_get_contents($file),true); return is_array($x)?$x:null;
 }
 private static function base($id=''){
  $w=self::get($id); if(!$w||empty($w['id']))return new WP_Error('oai_workspace','Workspace not found',array('status'=>404));
  return self::root().'/'.$w['id'];
 }
 public static function files($id='',$path=''){
  $base=self::base($id); if(is_wp_error($base))return $base; $root=$base.'/theme';
  $path=ltrim(str_replace('\\','/',(string)$path),'/');
  if(strpos($path,'..')!==false)return new WP_Error('oai_path_denied','Invalid workspace path',array('status'=>403));
  $target=$root.($path!==''?'/'.$path:''); $real=realpath($target); $root_real=realpath($root);
  if(!$root_real)return new WP_Error('oai_path_denied','Workspace root unavailable',array('status'=>403));
  if($real===false){
   $parent=realpath(dirname($target));
   if(!$parent || ($parent!==$root_real && strpos($parent,trailingslashit($root_real))!==0))return new WP_Error('oai_path_denied','Workspace path denied',array('status'=>403));
   return new WP_Error('oai_not_file','Workspace file not found',array('status'=>404));
  }
  if($real!==$root_real && strpos($real,trailingslashit($root_real))!==0)return new WP_Error('oai_path_denied','Workspace path denied',array('status'=>403));
  if(is_file($real))return array('workspace'=>self::get($id),'path'=>$path,'type'=>'file','bytes'=>filesize($real),'sha256'=>hash_file('sha256',$real));
  $items=array(); foreach(scandir($real) as $name){if($name==='.'||$name==='..'||$name[0]==='.')continue;$f=$real.'/'.$name;$items[]=array('name'=>$name,'type'=>is_dir($f)?'dir':'file','bytes'=>is_file($f)?filesize($f):null,'sha256'=>is_file($f)?hash_file('sha256',$f):null);}
  return array('workspace'=>self::get($id),'path'=>$path,'type'=>'dir','items'=>$items);
 }
 public static function read($id,$path){
  $x=self::files($id,$path); if(is_wp_error($x))return $x; if(($x['type']??'')!=='file')return new WP_Error('oai_not_file','Workspace path is not a file',array('status'=>400));
  $base=self::base($id); $file=$base.'/theme/'.ltrim(str_replace('\\','/',(string)$path),'/'); $fs=self::fs(); $content=$fs?$fs->get_contents($file):@file_get_contents($file); if($content===false)return new WP_Error('oai_read','Could not read workspace file',array('status'=>500));
  return array('workspace'=>$id,'path'=>$path,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content'=>$content);
 }
 public static function write($id,$path,$content){
  $base=self::base($id); if(is_wp_error($base))return $base; $path=ltrim(str_replace('\\','/',(string)$path),'/');
  if($path===''||strpos($path,'..')!==false||preg_match('#(^|/)\.\.?(/|$)#',$path))return new WP_Error('oai_path_denied','Invalid workspace path',array('status'=>403));
  $file=$base.'/theme/'.$path; $root=realpath($base.'/theme'); $parent=realpath(dirname($file)); if($parent===false){$parent=dirname($file);wp_mkdir_p($parent);$parent=realpath($parent);} if(!$root||($parent!==$root && strpos($parent,trailingslashit($root))!==0))return new WP_Error('oai_path_denied','Workspace path denied',array('status'=>403));
  $fs=self::fs(); $old_exists=is_file($file); $old=$old_exists?($fs?$fs->get_contents($file):@file_get_contents($file)):''; if($old===false)$old='';
  $guard=OAI_Security::php_guard($file,$content); if(empty($guard['ok']))return new WP_Error('oai_php_guard','PHP safety check failed',$guard);
  if($fs){if(!$fs->put_contents($file,$content,FS_CHMOD_FILE))return new WP_Error('oai_write','Workspace write failed',array('status'=>500));}else return new WP_Error('oai_workspace_fs','WordPress Filesystem unavailable',array('status'=>500));
  return array('before_exists'=>$old_exists,'before'=>$old,'after'=>$content,'before_sha256'=>hash('sha256',$old),'after_sha256'=>hash('sha256',$content),'path'=>$path,'bytes'=>strlen($content));
 }
 public static function restore($id,$path,$exists,$content=''){
  $base=self::base($id); if(is_wp_error($base))return $base;
  $path=ltrim(str_replace('\\','/',(string)$path),'/');
  if($path===''||strpos($path,'..')!==false||preg_match('#(^|/)\.\.?(/|$)#',$path))return new WP_Error('oai_path_denied','Invalid workspace path',array('status'=>403));
  $root=realpath($base.'/theme'); $file=$base.'/theme/'.$path; $parent=realpath(dirname($file));
  if(!$root||!$parent||($parent!==$root && strpos($parent,trailingslashit($root))!==0))return new WP_Error('oai_path_denied','Workspace path denied',array('status'=>403));
  $fs=self::fs(); if(!$fs)return new WP_Error('oai_workspace_fs','WordPress Filesystem unavailable',array('status'=>500));
  if($exists){if(!$fs->put_contents($file,(string)$content,FS_CHMOD_FILE))return new WP_Error('oai_write','Workspace restore failed',array('status'=>500));}
  elseif(is_file($file) && !$fs->delete($file,false,'f'))return new WP_Error('oai_write','Workspace restore delete failed',array('status'=>500));
  return true;
 }

 public static function surfaces($id=''){
  $w=self::get($id); $theme=$w['theme']??array();
  return array('workspace'=>$w,'surfaces'=>array(
   array('id'=>'theme.files','kind'=>'theme','scope'=>'workspace','editable'=>true,'description'=>'Draft theme files'),
   array('id'=>'theme.tokens','kind'=>'theme','editable'=>true,'description'=>'theme.css @theme design tokens'),
   array('id'=>'page.title','kind'=>'page','editable'=>true,'description'=>'WordPress page title'),
   array('id'=>'page.content','kind'=>'page','editable'=>true,'description'=>'WordPress page content'),
   array('id'=>'page.hero_heading','kind'=>'page_meta','editable'=>true,'description'=>'Starter-theme hero heading'),
   array('id'=>'page.hero_subheading','kind'=>'page_meta','editable'=>true,'description'=>'Starter-theme hero subheading'),
   array('id'=>'elementor.widget','kind'=>'elementor','editable'=>true,'description'=>'Elementor widget settings/content'),
   array('id'=>'woocommerce.product','kind'=>'woocommerce','editable'=>true,'description'=>'WooCommerce product fields'),
   array('id'=>'gutenberg.blocks','kind'=>'gutenberg','editable'=>true,'description'=>'Parsed Gutenberg block tree'),
   array('id'=>'menu.primary','kind'=>'menu','editable'=>true,'description'=>'Primary navigation menu'),
   array('id'=>'site.options','kind'=>'option','editable'=>true,'description'=>'Approved site options')
  ));
 }
}

