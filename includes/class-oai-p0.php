<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;

/**
 * P0 write surfaces for Media and Navigation.
 * Every public mutator creates a normal OAI sandbox operation.
 */
class OAI_P0 {
 private static function op($type,$target,$before,$after,$meta=array(),$before_exists=true){
  $diff=OAI_Operations::diff($before,$after);
  if(empty($diff['changed']))return new WP_Error('oai_noop','Operation does not change the target',array('status'=>409));
  $op=OAI_Operations::new_id();
  $meta=array_merge(array('before_exists'=>(bool)$before_exists,'after_exists'=>true),$meta);
  $descriptor=OAI_Lifecycle::descriptor($type,$target,$before,$meta);
  $meta['rollback_descriptor']=$descriptor;
  $row=array(
   'operation_id'=>$op,'status'=>'pending','type'=>$type,'target'=>(string)$target,
   'before_data'=>wp_json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
   'after_data'=>wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
   'applied_data'=>null,'meta_data'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
   'diff_data'=>wp_json_encode($diff,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
   'rollback_data'=>wp_json_encode($descriptor,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
   'phase'=>'awaiting_approval','risk'=>OAI_Operations::risk($type,$target),
   'created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS)
  );
  $id=OAI_DB::insert($row);if(is_wp_error($id))return $id;
  OAI_Audit::log('created',array_merge($row,array('id'=>$id)));
  $r=OAI_DB::get($id);
  foreach(array('before_data','after_data','applied_data','meta_data','diff_data','rollback_data') as $k)if(array_key_exists($k,$r))$r[$k]=json_decode($r[$k],true);
  return OAI_Lifecycle::decorate($r);
 }

 public static function media_snapshot($id){
  $p=get_post(absint($id));
  if(!$p||$p->post_type!=='attachment')return new WP_Error('oai_media_not_found','Attachment not found',array('status'=>404));
  return array(
   'id'=>(int)$p->ID,'status'=>(string)$p->post_status,'title'=>(string)$p->post_title,
   'alt'=>(string)get_post_meta($p->ID,'_wp_attachment_image_alt',true),
   'caption'=>(string)$p->post_excerpt,'description'=>(string)$p->post_content,
   'mime'=>(string)$p->post_mime_type,'url'=>(string)wp_get_attachment_url($p->ID)
  );
 }
 public static function media_update_alt($a){
  $id=absint($a['attachment_id']??0);$snap=self::media_snapshot($id);if(is_wp_error($snap))return $snap;
  $alt=sanitize_text_field((string)($a['alt']??''));return self::op('media_alt',(string)$id,$snap['alt'],$alt,array('attachment_id'=>$id),true);
 }
 public static function media_update_title($a){
  $id=absint($a['attachment_id']??0);$snap=self::media_snapshot($id);if(is_wp_error($snap))return $snap;
  $title=sanitize_text_field((string)($a['title']??''));if($title==='')return new WP_Error('oai_media_title','Media title cannot be empty',array('status'=>422));
  return self::op('media_title',(string)$id,$snap['title'],$title,array('attachment_id'=>$id),true);
 }
 public static function media_set_featured($a){
  $post_id=absint($a['post_id']??0);$attachment_id=absint($a['attachment_id']??0);
  if(!$post_id||!get_post($post_id))return new WP_Error('oai_post_not_found','Post not found',array('status'=>404));
  if($attachment_id){$m=self::media_snapshot($attachment_id);if(is_wp_error($m))return $m;}
  $before=(int)get_post_thumbnail_id($post_id);
  return self::op('media_featured',(string)$post_id,$before,$attachment_id,array('post_id'=>$post_id),true);
 }
 /** Validate in a private temporary file before creating any Media Library artifact. */
 private static function validate_media_image($filename,$bytes,$declared_mime){
  $allowed=array('image/jpeg','image/png','image/webp','image/gif');
  $check=wp_check_filetype($filename);$mime=(string)($check['type']??'');
  if(!in_array($mime,$allowed,true)||($declared_mime!==null&&$declared_mime!==$mime))return new WP_Error('oai_media_mime','Filename and declared MIME must identify the same supported image',array('status'=>422,'allowed'=>$allowed));
  $image=@getimagesizefromstring($bytes);
  if(!is_array($image)||empty($image[0])||empty($image[1]))return new WP_Error('oai_media_content','Media payload is not a supported image',array('status'=>422));
  if(($image['mime']??'')!==$mime)return new WP_Error('oai_media_mime','Image bytes do not match filename and declared MIME',array('status'=>422));
  // Bound decoded memory before invoking GD/Imagick, including compressed bombs.
  if($image[0]>20000000/$image[1])return new WP_Error('oai_media_dimensions','Image exceeds 20 megapixels',array('status'=>413));
  if(!wp_image_editor_supports(array('mime_type'=>$mime)))return new WP_Error('oai_media_decoder','Server cannot decode this image format',array('status'=>503));
  $temporary=wp_tempnam('oai-media-validate');
  if(!$temporary)return new WP_Error('oai_media_temporary','Cannot validate image in temporary storage',array('status'=>503));
  try{
   if(!@chmod($temporary,0600)||@file_put_contents($temporary,$bytes)!==strlen($bytes))return new WP_Error('oai_media_temporary','Cannot validate image in temporary storage',array('status'=>503));
   $verified_type=wp_check_filetype_and_ext($temporary,$filename);
   if(empty($verified_type['ext'])||($verified_type['type']??'')!==$mime||!empty($verified_type['proper_filename']))return new WP_Error('oai_media_mime','WordPress rejected the image type or filename',array('status'=>422));
   $editor=wp_get_image_editor($temporary,array('mime_type'=>$mime));
   if(is_wp_error($editor))return new WP_Error('oai_media_decode','Image data could not be decoded',array('status'=>422));
   $size=$editor->get_size();
   if(!is_array($size)||(int)($size['width']??0)!==(int)$image[0]||(int)($size['height']??0)!==(int)$image[1])return new WP_Error('oai_media_decode','Decoded image dimensions are inconsistent',array('status'=>422));
   return $mime;
  }catch(Throwable $error){
   return new WP_Error('oai_media_decode','Image data could not be decoded',array('status'=>422));
  }finally{
   unset($editor);
   @unlink($temporary);
  }
 }

 public static function media_upload($a){
  if(!current_user_can('upload_files'))return new WP_Error('oai_media_permission','upload_files capability required',array('status'=>403));
  if(!is_array($a))return new WP_Error('oai_media_payload','Media payload must be an object',array('status'=>400));
  foreach(array('filename','content_base64','mime_type','title','alt') as $field)if(array_key_exists($field,$a)&&!is_string($a[$field]))return new WP_Error('oai_media_payload','Media fields must be strings',array('status'=>400));
  $filename=sanitize_file_name((string)($a['filename']??''));$encoded=(string)($a['content_base64']??'');
  if($filename===''||$encoded==='')return new WP_Error('oai_media_payload','filename and content_base64 are required',array('status'=>400));
  if(strlen($encoded)>4*(int)ceil((10*1024*1024)/3))return new WP_Error('oai_media_size','Encoded media payload exceeds 10 MB image limit',array('status'=>413));
  $bytes=base64_decode($encoded,true);if($bytes===false||$bytes==='')return new WP_Error('oai_media_base64','Invalid base64 media payload',array('status'=>422));
  if(strlen($bytes)>10*1024*1024)return new WP_Error('oai_media_size','Media payload exceeds 10 MB',array('status'=>413));
  $mime=self::validate_media_image($filename,$bytes,$a['mime_type']??null);if(is_wp_error($mime))return $mime;
  $title=sanitize_text_field((string)($a['title']??pathinfo($filename,PATHINFO_FILENAME)));
  $intent=array('filename'=>$filename,'bytes_sha256'=>hash('sha256',$bytes),'mime'=>$mime,'title'=>$title,'alt_present'=>array_key_exists('alt',$a),'alt'=>sanitize_text_field((string)($a['alt']??'')));
  return OAI_DB::with_media_creation($intent,static function()use($filename,$bytes,$mime,$title,$a){
   $up=wp_upload_bits($filename,null,$bytes);if(!empty($up['error']))return new WP_Error('oai_media_upload',$up['error'],array('status'=>500));
   $id=wp_insert_attachment(array('post_mime_type'=>$mime,'post_title'=>$title,'post_status'=>'private'),$up['file'],0,true);
   if(is_wp_error($id)){@unlink($up['file']);return $id;}
   update_post_meta($id,'_oai_sandbox_artifact','1');
   OAI_DB::record_media_artifact($id);
   if(!function_exists('wp_generate_attachment_metadata'))require_once ABSPATH.'wp-admin/includes/image.php';
   $meta=wp_generate_attachment_metadata($id,$up['file']);if(is_array($meta))wp_update_attachment_metadata($id,$meta);
   if(array_key_exists('alt',$a))update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field((string)$a['alt']));
   $after=self::media_snapshot($id);if(is_wp_error($after)){wp_delete_attachment($id,true);return $after;}
   $after['status']='inherit';
   $op=self::op('media_upload',(string)$id,null,$after,array('attachment_id'=>$id,'sandbox_artifact'=>true,'created_by_ai_admin'=>true),false);if(is_wp_error($op)){wp_delete_attachment($id,true);return $op;}return $op;
  });
 }

 public static function media_import_url($a){
  if(!current_user_can('upload_files'))return new WP_Error('oai_media_permission','upload_files capability required',array('status'=>403));
  $url=esc_url_raw((string)($a['url']??''));
  if($url===''||!wp_http_validate_url($url))return new WP_Error('oai_media_url','A valid public HTTP(S) image URL is required',array('status'=>400));
  $parts=wp_parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));
  if(!in_array($scheme,array('http','https'),true))return new WP_Error('oai_media_scheme','Only HTTP and HTTPS URLs are supported',array('status'=>422));
  $limit=10*1024*1024;
  $response=wp_safe_remote_get($url,array('timeout'=>20,'redirection'=>3,'limit_response_size'=>$limit+1,'user-agent'=>'WP AI Admin/'.(defined('OAI_VERSION')?OAI_VERSION:'unknown').' (media.import_url_sandbox)'));
  if(is_wp_error($response))return new WP_Error('oai_media_remote',$response->get_error_message(),array('status'=>502));
  $code=(int)wp_remote_retrieve_response_code($response);
  if($code<200||$code>=300)return new WP_Error('oai_media_remote_status','Remote image server returned HTTP '.$code,array('status'=>502,'remote_status'=>$code));
  $bytes=(string)wp_remote_retrieve_body($response);
  if($bytes==='')return new WP_Error('oai_media_remote_empty','Remote image response is empty',array('status'=>422));
  if(strlen($bytes)>$limit)return new WP_Error('oai_media_size','Remote image exceeds 10 MB',array('status'=>413));
  $image=@getimagesizefromstring($bytes);
  $mime=is_array($image)?strtolower((string)($image['mime']??'')):'';
  $allowed=array('image/jpeg','image/png','image/webp','image/gif');
  if(!in_array($mime,$allowed,true))return new WP_Error('oai_media_mime','Remote content is not a supported JPEG, PNG, WEBP or GIF image',array('status'=>422,'allowed'=>$allowed));
  $filename=sanitize_file_name((string)($a['filename']??''));
  if($filename===''){
   $path=(string)($parts['path']??'');$filename=sanitize_file_name(wp_basename($path));
  }
  $ext_map=array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif');$ext=$ext_map[$mime];
  if($filename===''||strtolower((string)pathinfo($filename,PATHINFO_EXTENSION))!==$ext){
   $base=sanitize_file_name((string)pathinfo($filename,PATHINFO_FILENAME));if($base==='')$base='imported-image';
   $filename=$base.'.'.$ext;
  }
  $upload=array('filename'=>$filename,'content_base64'=>base64_encode($bytes),'mime_type'=>$mime);
  if(array_key_exists('title',$a))$upload['title']=$a['title'];
  if(array_key_exists('alt',$a))$upload['alt']=$a['alt'];
  $op=self::media_upload($upload);
  if(is_array($op)){
   $op['source_url']=$url;
   $op['imported_via']='media.import_url_sandbox';
  }
  return $op;
 }

 public static function menu_snapshot($menu_id){
  $menu=wp_get_nav_menu_object(absint($menu_id));if(!$menu)return new WP_Error('oai_menu_not_found','Menu not found',array('status'=>404));
  $items=wp_get_nav_menu_items($menu->term_id,array('post_status'=>'any'));$rows=array();
  foreach((array)$items as $i)$rows[]=self::menu_item_snapshot($i->ID);
  return array('id'=>(int)$menu->term_id,'name'=>(string)$menu->name,'slug'=>(string)$menu->slug,'items'=>$rows);
 }
 public static function menu_item_snapshot($item_id){
  $p=get_post(absint($item_id));if(!$p||$p->post_type!=='nav_menu_item')return new WP_Error('oai_menu_item_not_found','Menu item not found',array('status'=>404));
  return array(
   'id'=>(int)$p->ID,'status'=>(string)$p->post_status,'title'=>(string)(wp_setup_nav_menu_item($p)->title??$p->post_title),
   'url'=>(string)get_post_meta($p->ID,'_menu_item_url',true),'type'=>(string)get_post_meta($p->ID,'_menu_item_type',true),
   'object'=>(string)get_post_meta($p->ID,'_menu_item_object',true),'object_id'=>(int)get_post_meta($p->ID,'_menu_item_object_id',true),
   'parent'=>(int)get_post_meta($p->ID,'_menu_item_menu_item_parent',true),'order'=>(int)$p->menu_order,
   'classes'=>array_values(array_filter((array)get_post_meta($p->ID,'_menu_item_classes',true)))
  );
 }
 public static function menu_create($a){
  if(!current_user_can('edit_theme_options'))return new WP_Error('oai_menu_permission','edit_theme_options capability required',array('status'=>403));
  $name=sanitize_text_field((string)($a['name']??''));if($name==='')return new WP_Error('oai_menu_name','Menu name is required',array('status'=>400));
  $key=$a['idempotency_key']??'';
  if(!is_string($key)||($key!==''&&!preg_match('/^[A-Za-z0-9._~-]{16,128}$/D',$key)))
   return new WP_Error('oai_menu_request_key','Invalid menu idempotency_key',array('status'=>400));
  $actor=get_current_user_id();
  if($actor<1)return new WP_Error('oai_menu_permission','Authenticated editor required',array('status'=>403));
  $ready=OAI_DB::intent_autocommit();if(is_wp_error($ready))return $ready;
  $normal_name=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
  $payload_hash=hash('sha256',$normal_name);
  // Legacy keyless creation retains v1 identity. Explicit key now defines one
  // creation request across changed names instead of becoming a new intent.
  $fingerprint=wp_json_encode($key!==''?array('menu-creation-v2',get_current_blog_id(),$actor,$key):array('menu-creation-v1',get_current_blog_id(),$actor,$normal_name,''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($fingerprint))return new WP_Error('oai_menu_intent','Unable to encode menu creation intent',array('status'=>503));
  $intent='oai_menu_creation_'.hash('sha256',$fingerprint);
  global $wpdb;
  if($key!==''){
   $legacy=wp_json_encode(array('menu-creation-v1',get_current_blog_id(),$actor,$normal_name,$key),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   if(!is_string($legacy))return new WP_Error('oai_menu_intent','Cannot encode legacy menu creation',array('status'=>503));
   $legacy_name='oai_menu_creation_'.hash('sha256',$legacy);
   $legacy_claim=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$legacy_name));
   if($legacy_claim!==null)return new WP_Error('oai_menu_creation_pending','Legacy menu claim requires reconciliation',array('status'=>409));
   if($wpdb->last_error!=='')return new WP_Error('oai_menu_storage','Legacy menu reservation lookup unavailable',array('status'=>503));
  }
  $existing=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$intent));
  if($existing!==null){
   $prior=maybe_unserialize($existing);
   if(is_array($prior)&&isset($prior['payload_hash'])&&!hash_equals((string)$prior['payload_hash'],$payload_hash))
    return new WP_Error('oai_menu_intent_conflict','Menu creation key reused with another name',array('status'=>409));
   return new WP_Error('oai_menu_creation_pending','Menu creation requires reconciliation',array('status'=>409));
  }
  if($wpdb->last_error!=='')return new WP_Error('oai_menu_storage','Menu reservation unavailable',array('status'=>503));
  // Unique option_name is the atomic admission point. Crash/unknown outcome
  // claims remain held: a timeout or old timestamp cannot prove absence.
  $admission=OAI_DB::insert_creation_claim($intent,array('actor'=>$actor,'payload_hash'=>$payload_hash,'created_at'=>time(),'state'=>'reserved','claim'=>bin2hex(random_bytes(16))));
  if(is_wp_error($admission))return $admission;
  if($admission!==true)
   return new WP_Error('oai_menu_creation_pending','Menu creation intent already reserved; verify before retry',array('status'=>409));
  $id=wp_create_nav_menu($name);if(is_wp_error($id))return $id;update_term_meta($id,'_oai_sandbox_artifact','1');
  $after=self::menu_snapshot($id);if(is_wp_error($after)){wp_delete_nav_menu($id);return $after;}
  $op=self::op('menu_create',(string)$id,null,$after,array('menu_id'=>(int)$id,'sandbox_artifact'=>true,'created_by_ai_admin'=>true),false);if(is_wp_error($op)){wp_delete_nav_menu($id);return $op;}return $op;
 }
 private static function menu_item_args($a){
  $type=sanitize_key((string)($a['type']??'custom'));if(!in_array($type,array('custom','post_type','taxonomy'),true))$type='custom';
  return array(
   'menu-item-title'=>sanitize_text_field((string)($a['title']??'')),
   'menu-item-url'=>esc_url_raw((string)($a['url']??'')),
   'menu-item-type'=>$type,'menu-item-object'=>sanitize_key((string)($a['object']??'custom')),
   'menu-item-object-id'=>absint($a['object_id']??0),'menu-item-parent-id'=>absint($a['parent']??0),
   'menu-item-position'=>absint($a['position']??0),'menu-item-status'=>'publish'
  );
 }
 public static function menu_add_item($a){
  if(!current_user_can('edit_theme_options'))return new WP_Error('oai_menu_permission','Menu edit permission required',array('status'=>403));
  $menu_id=absint($a['menu_id']??0);$menu=wp_get_nav_menu_object($menu_id);if(!$menu)return new WP_Error('oai_menu_not_found','Menu not found',array('status'=>404));
  $args=self::menu_item_args($a);if($args['menu-item-title']===''&&$args['menu-item-object-id']===0)return new WP_Error('oai_menu_item','Menu item title or object_id is required',array('status'=>422));
  $key=$a['idempotency_key']??'';
  if(!is_string($key)||($key!==''&&!preg_match('/^[A-Za-z0-9._~-]{16,128}$/D',$key)))
   return new WP_Error('oai_menu_item_request_key','Invalid menu item idempotency_key',array('status'=>400));
  $ready=OAI_DB::intent_autocommit();if(is_wp_error($ready))return $ready;
  $actor=get_current_user_id();
  $canonical=$args;ksort($canonical,SORT_STRING);
  $encoded=wp_json_encode(array('menu-item-v1',$menu_id,$canonical),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
  if(!is_string($encoded))return new WP_Error('oai_menu_item_intent','Cannot encode menu item creation intent',array('status'=>503));
  $payload_hash=hash('sha256',$encoded);
  // A nonempty retry key is bound to one payload, even when menu target
  // changes. Keyless legacy calls remain scoped to menu and canonical item.
  $identity=wp_json_encode($key!==''?array('menu-item-v2',get_current_blog_id(),$actor,$key):array('menu-item-v1',get_current_blog_id(),$actor,$menu_id,$payload_hash));
  if(!is_string($identity))return new WP_Error('oai_menu_item_intent','Cannot encode menu item request identity',array('status'=>503));
  $intent='oai_menu_item_creation_'.hash('sha256',$identity);
  global $wpdb;
  if($key!==''){
   $legacy_identity=wp_json_encode(array('menu-item-v1',get_current_blog_id(),$actor,$menu_id,$key));
   if(!is_string($legacy_identity))return new WP_Error('oai_menu_item_intent','Cannot encode legacy menu item creation',array('status'=>503));
   $legacy_name='oai_menu_item_creation_'.hash('sha256',$legacy_identity);
   $legacy_value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$legacy_name));
   if($legacy_value!==null){
    $prior=maybe_unserialize($legacy_value);
    if(is_array($prior)&&isset($prior['payload_hash'])&&!hash_equals((string)$prior['payload_hash'],$payload_hash))
     return new WP_Error('oai_menu_item_intent_conflict','Reused prior menu item request key with changed content',array('status'=>409));
    return new WP_Error('oai_menu_item_creation_pending','Existing item claim requires reconciliation',array('status'=>409));
   }
   if($wpdb->last_error!=='')return new WP_Error('oai_menu_item_storage','Legacy item reservation lookup unavailable',array('status'=>503));
  }
  $existing=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$intent));
  if($existing!==null){
   $prior=maybe_unserialize($existing);
   if(is_array($prior)&&isset($prior['payload_hash'])&&!hash_equals((string)$prior['payload_hash'],$payload_hash))
    return new WP_Error('oai_menu_item_intent_conflict','Reused item creation key with different content',array('status'=>409));
   return new WP_Error('oai_menu_item_creation_pending','Menu item creation requires reconciliation',array('status'=>409));
  }
  if($wpdb->last_error!=='')return new WP_Error('oai_menu_item_storage','Item creation reservation unavailable',array('status'=>503));
  // Persist the claim before wp_update_nav_menu_item can write a new post.
  $admission=OAI_DB::insert_creation_claim($intent,array('actor'=>$actor,'payload_hash'=>$payload_hash,'created_at'=>time(),'state'=>'reserved','claim'=>bin2hex(random_bytes(16))));
  if(is_wp_error($admission))return $admission;
  if($admission!==true)
   return new WP_Error('oai_menu_item_creation_pending','Menu item creation requires reconciliation',array('status'=>409));
  $item_id=wp_update_nav_menu_item($menu_id,0,$args);if(is_wp_error($item_id))return $item_id;
  wp_update_post(array('ID'=>$item_id,'post_status'=>'draft'));update_post_meta($item_id,'_oai_sandbox_artifact','1');
  $after=self::menu_item_snapshot($item_id);if(is_wp_error($after)){wp_delete_post($item_id,true);return $after;}$after['status']='publish';
  $op=self::op('menu_add_item',(string)$item_id,null,$after,array('menu_id'=>$menu_id,'item_id'=>(int)$item_id,'sandbox_artifact'=>true,'created_by_ai_admin'=>true),false);if(is_wp_error($op)){wp_delete_post($item_id,true);return $op;}return $op;
 }
 public static function menu_remove_item($a){
  $item_id=absint($a['item_id']??0);$before=self::menu_item_snapshot($item_id);if(is_wp_error($before))return $before;
  $after=$before;$after['status']='draft';return self::op('menu_remove_item',(string)$item_id,$before,$after,array('item_id'=>$item_id),true);
 }
 public static function menu_move_item($a){
  $item_id=absint($a['item_id']??0);$before=self::menu_item_snapshot($item_id);if(is_wp_error($before))return $before;
  $after=$before;if(array_key_exists('parent',$a))$after['parent']=absint($a['parent']);if(array_key_exists('position',$a))$after['order']=absint($a['position']);
  return self::op('menu_move_item',(string)$item_id,$before,$after,array('item_id'=>$item_id),true);
 }
 public static function menu_assign_location($a){
  $location=sanitize_key((string)($a['location']??''));$menu_id=absint($a['menu_id']??0);
  if($location===''||!isset(get_registered_nav_menus()[$location]))return new WP_Error('oai_menu_location','Unknown menu location',array('status'=>422,'locations'=>array_keys(get_registered_nav_menus())));
  if($menu_id&&!wp_get_nav_menu_object($menu_id))return new WP_Error('oai_menu_not_found','Menu not found',array('status'=>404));
  $locations=(array)get_theme_mod('nav_menu_locations',array());$exists=array_key_exists($location,$locations);$before=$exists?(int)$locations[$location]:0;
  return self::op('menu_assign_location',$location,$before,$menu_id,array('location'=>$location),$exists);
 }

 public static function op_for_created_product($snapshot){
  if(!is_array($snapshot)||empty($snapshot['id']))return new WP_Error('oai_product_snapshot','Invalid created product snapshot',array('status'=>500));
  $id=absint($snapshot['id']);
  $op=self::op('woocommerce_product_create',(string)$id,null,$snapshot,array('product_id'=>$id,'sandbox_artifact'=>true,'created_by_ai_admin'=>true),false);if(is_wp_error($op)){$p=wc_get_product($id);if($p)$p->delete(true);return $op;}return $op;
 }
 public static function elementor_page_layout($a){
  $post_id=absint($a['post_id']??0);$before=OAI_Elementor::page_layout($post_id);if(is_wp_error($before))return $before;
  $template=OAI_Elementor::validate_page_template($a['page_template']??'');if(is_wp_error($template))return $template;
  $after=$before;$after['page_template']=$template;
  return self::op('elementor_page_layout',(string)$post_id,$before,$after,array('post_id'=>$post_id),true);
 }
 public static function current($type,$target){
  if($type==='elementor_page_layout'){ $v=OAI_Elementor::page_layout(absint($target));return is_wp_error($v)?array(false,null):array(true,$v);}
  if($type==='media_alt'){ $s=self::media_snapshot($target);return is_wp_error($s)?array(false,null):array(true,$s['alt']);}
  if($type==='media_title'){ $s=self::media_snapshot($target);return is_wp_error($s)?array(false,null):array(true,$s['title']);}
  if($type==='media_featured'){ $p=get_post(absint($target));return $p?array(true,(int)get_post_thumbnail_id($p)):array(false,null);}
  if($type==='media_upload'){ $s=self::media_snapshot($target);return is_wp_error($s)?array(false,null):array(true,$s);}
  if($type==='menu_create'){ $s=self::menu_snapshot($target);return is_wp_error($s)?array(false,null):array(true,$s);}
  if(in_array($type,array('menu_add_item','menu_remove_item','menu_move_item'),true)){ $s=self::menu_item_snapshot($target);return is_wp_error($s)?array(false,null):array(true,$s);}
  if($type==='menu_assign_location'){ $l=(array)get_theme_mod('nav_menu_locations',array());return array(array_key_exists((string)$target,$l),array_key_exists((string)$target,$l)?(int)$l[(string)$target]:0);}
  if($type==='woocommerce_product_create'){ $s=OAI_WooCommerce::get_full(absint($target));return is_wp_error($s)?array(false,null):array(true,$s);}
  return null;
 }
 private static function apply_menu_item_snapshot($id,$v){
  $id=absint($id);if(!$id||!is_array($v))return new WP_Error('oai_menu_item','Invalid menu item snapshot',array('status'=>422));
  $p=get_post($id);if(!$p)return new WP_Error('oai_menu_item_not_found','Menu item not found',array('status'=>404));
  $terms=wp_get_object_terms($id,'nav_menu',array('fields'=>'ids'));$menu_id=!is_wp_error($terms)&&$terms?absint($terms[0]):0;
  if(!$menu_id)return new WP_Error('oai_menu_not_found','Menu relation not found',array('status'=>409));
  $args=array(
   'menu-item-title'=>(string)($v['title']??''),'menu-item-url'=>(string)($v['url']??''),
   'menu-item-type'=>(string)($v['type']??'custom'),'menu-item-object'=>(string)($v['object']??'custom'),
   'menu-item-object-id'=>absint($v['object_id']??0),'menu-item-parent-id'=>absint($v['parent']??0),
   'menu-item-position'=>absint($v['order']??0),'menu-item-status'=>(string)($v['status']??'publish')
  );
  $r=wp_update_nav_menu_item($menu_id,$id,$args);return is_wp_error($r)?$r:true;
 }
 public static function write($type,$target,$v,$meta=array()){
  if($type==='elementor_page_layout'){if(!is_array($v))return new WP_Error('oai_page_layout','Invalid page layout snapshot',array('status'=>422));$template=OAI_Elementor::validate_page_template($v['page_template']??'');if(is_wp_error($template))return $template;if($template==='default')delete_post_meta(absint($target),'_wp_page_template');else update_post_meta(absint($target),'_wp_page_template',$template);clean_post_cache(absint($target));return true;}
  if($type==='media_alt'){update_post_meta(absint($target),'_wp_attachment_image_alt',sanitize_text_field((string)$v));return true;}
  if($type==='media_title')return wp_update_post(array('ID'=>absint($target),'post_title'=>sanitize_text_field((string)$v)),true);
  if($type==='media_featured'){if(absint($v))return set_post_thumbnail(absint($target),absint($v));delete_post_thumbnail(absint($target));return true;}
  if($type==='media_upload'){delete_post_meta(absint($target),'_oai_sandbox_artifact');return wp_update_post(array('ID'=>absint($target),'post_status'=>'inherit'),true);}
  if($type==='menu_create'){delete_term_meta(absint($target),'_oai_sandbox_artifact');return true;}
  if($type==='menu_add_item'){delete_post_meta(absint($target),'_oai_sandbox_artifact');return self::apply_menu_item_snapshot($target,$v);}
  if(in_array($type,array('menu_remove_item','menu_move_item'),true))return self::apply_menu_item_snapshot($target,$v);
  if($type==='menu_assign_location'){ $locations=(array)get_theme_mod('nav_menu_locations',array());if(absint($v))$locations[(string)$target]=absint($v);else unset($locations[(string)$target]);set_theme_mod('nav_menu_locations',$locations);return true;}
  if($type==='woocommerce_product_create'){delete_post_meta(absint($target),'_oai_sandbox_artifact');return true;}
  return new WP_Error('oai_p0_type','Unsupported P0 operation type',array('status'=>400));
 }
 public static function rollback($type,$target,$before,$meta=array()){
  if($type==='media_upload'){return wp_delete_attachment(absint($target),true)?true:new WP_Error('oai_media_delete','Failed to delete sandbox attachment',array('status'=>500));}
  if($type==='menu_create'){return wp_delete_nav_menu(absint($target))?true:new WP_Error('oai_menu_delete','Failed to delete sandbox menu',array('status'=>500));}
  if($type==='menu_add_item'){return wp_delete_post(absint($target),true)?true:new WP_Error('oai_menu_item_delete','Failed to delete sandbox menu item',array('status'=>500));}
  if($type==='woocommerce_product_create'){ $p=wc_get_product(absint($target));if(!$p)return true;$r=$p->delete(true);return $r?true:new WP_Error('oai_product_delete','Failed to delete sandbox product',array('status'=>500));}
  return self::write($type,$target,$before,$meta);
 }
 public static function cleanup_artifact($type,$target,$meta=array()){
  if($type==='media_upload'){if(get_post(absint($target)))wp_delete_attachment(absint($target),true);return true;}
  if($type==='menu_create'){if(wp_get_nav_menu_object(absint($target)))wp_delete_nav_menu(absint($target));return true;}
  if($type==='menu_add_item'){if(get_post(absint($target)))wp_delete_post(absint($target),true);return true;}
  if($type==='woocommerce_product_create'){ $p=wc_get_product(absint($target));if($p)$p->delete(true);return true;}
  return false;
 }
 public static function is_created_artifact($type){return in_array($type,array('media_upload','menu_create','menu_add_item','woocommerce_product_create'),true);}
}

