<?php
/** Woodmart base settings: native normalization, sandbox lifecycle and exact restoration. */
if (!defined('ABSPATH')) exit;

class OAI_Woodmart {
 const OPTION='xts-woodmart-options';
 const PRESETS='xts-options-presets';
 const TYPES=array('color','switcher','select','buttons','range','responsive_range','dimensions','typography','text_input','textarea','background');

 public static function available(){
  return defined('WOODMART_VERSION') && defined('WOODMART_MCP_VERSION') && WOODMART_MCP_VERSION==='1.0.1'
   && class_exists('XTS\\Admin\\Modules\\Options')
   && class_exists('XTS\\Admin\\Modules\\Options\\Presets')
   && class_exists('XTS\\Modules\\Styles_Storage')
   && class_exists('XTS\\AI_Abilities\\Theme_Settings\\Abilities')
   && class_exists('XTS\\AI_Abilities\\Theme_Settings\\Devices_Value');
 }
 public static function status($req=null){
  return array('adapter'=>'woodmart','available'=>self::available(),'theme_version'=>defined('WOODMART_VERSION')?WOODMART_VERSION:null,
   'native_mcp_version'=>defined('WOODMART_MCP_VERSION')?WOODMART_MCP_VERSION:null,'supported_native_mcp'=>'1.0.1',
   'write_layer'=>'base','sandbox_only'=>true,'rollback'=>'exact_field_storage_and_absence',
   'conflict_scope'=>'entire_base_option_presets_field_schema_and_theme_identity',
   'supported_types'=>self::TYPES,'headers_and_layouts'=>'next_phase');
 }
 private static function guard(){
  if(!current_user_can('manage_options'))return new WP_Error('oai_woodmart_permission','Managing theme settings requires manage_options',array('status'=>403));
  if(!self::available())return new WP_Error('oai_woodmart_unavailable','Woodmart and supported WoodMart MCP 1.0.1 are required',array('status'=>409));
  return true;
 }
 private static function field($id){
  $g=self::guard();if(is_wp_error($g))return $g;
  if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,100}$/D',$id))return new WP_Error('oai_woodmart_id','Invalid setting id',array('status'=>400));
  foreach(\XTS\Admin\Modules\Options::get_fields() as $field)if($field->get_id()===$id)return $field;
  return new WP_Error('oai_woodmart_field','Theme setting was not found',array('status'=>404));
 }
 private static function restricted($field){
  $id=$field->get_id();$args=$field->args;
  if(preg_match('/(?:password|passwd|secret|token|credential|license|api[-_]?key|private[-_]?key|captcha|custom[-_]?(?:css|js|code)|javascript|tracking|analytics)/i',$id.' '.($args['section']??'')))return true;
  $safe=OAI_Read::option_value($id,array('default'=>$args['default']??null));
  return !empty($safe['redacted']);
 }
 private static function native_types(){
  // Fixed contract of supported native MCP 1.0.1; its constant is private.
  return array_merge(array_slice(self::TYPES,0,-1),array('editor','background'));
 }
 private static function writable($field){
  $type=$field->args['type']??'';
  return !self::restricted($field) && in_array($type,self::TYPES,true)
   && in_array($type,apply_filters('woodmart_ai_writable_field_types',self::native_types()),true);
 }
 private static function stored($name,$lock=false){
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s".($lock?' FOR UPDATE':''),$name),ARRAY_A);
  return array('exists'=>is_array($row),'value'=>is_array($row)?maybe_unserialize($row['option_value']):null);
 }
 private static function canonical($value){
  if(!is_array($value))return $value;
  $list=$value===array()||array_keys($value)===range(0,count($value)-1);
  if(!$list)ksort($value);
  foreach($value as $key=>$item)$value[$key]=self::canonical($item);
  return $value;
 }
 private static function hash($value){return hash('sha256',serialize(self::canonical($value)));}
 private static function context($field,$presets){
  return self::hash(array('template'=>get_template(),'stylesheet'=>get_stylesheet(),'theme'=>WOODMART_VERSION,'mcp'=>WOODMART_MCP_VERSION,
   'field'=>$field->args,'presets'=>$presets,'types'=>apply_filters('woodmart_ai_writable_field_types',self::native_types())));
 }
 private static function state($field,$options,$presets){
  $id=$field->get_id();$exists=array_key_exists($id,$options);
  return array('id'=>$id,'layer'=>'base','base_exists'=>$exists,'raw_value'=>$exists?$options[$id]:null,
   'options_sha256'=>self::hash($options),'context_sha256'=>self::context($field,$presets));
 }
 private static function options($row){
  if(empty($row['exists'])||!is_array($row['value'])||!$row['value'])return new WP_Error('oai_woodmart_storage','Save initial Woodmart theme settings in wp-admin before using the adapter',array('status'=>409));
  return $row['value'];
 }
 public static function current($id){
  $field=self::field($id);if(is_wp_error($field))return array(false,$field);
  if(self::restricted($field))return array(false,new WP_Error('oai_woodmart_restricted','This setting is excluded from the adapter',array('status'=>403)));
  $options=self::options(self::stored(self::OPTION));if(is_wp_error($options))return array(false,$options);
  $safe=OAI_Read::option_value($id,$options[$id]??null);
  if(!empty($safe['redacted']))return array(false,new WP_Error('oai_woodmart_restricted','Stored setting contains restricted data',array('status'=>403)));
  return array(true,self::state($field,$options,self::stored(self::PRESETS)));
 }
 public static function refresh(){
  foreach(array(self::OPTION,self::PRESETS,'alloptions','notoptions') as $key)wp_cache_delete($key,'options');
  if(!self::available())return;
  \XTS\Admin\Modules\Options\Presets::get_instance()->load_presets();
  $options=\XTS\Admin\Modules\Options::get_instance();
  $options->load_options();$options->load_defaults();$options->load_presets();$options->setup_globals();
 }
 private static function readable($field,$raw){
  return \XTS\AI_Abilities\Theme_Settings\Devices_Value::is_devices_type($field->args['type']??'')
   ?\XTS\AI_Abilities\Theme_Settings\Devices_Value::decode($raw,$field->args):$raw;
 }
 public static function get($req){return self::get_setting((string)$req->get_param('id'));}
 public static function get_setting($id){
  $field=self::field($id);if(is_wp_error($field))return $field;
  if(self::restricted($field))return new WP_Error('oai_woodmart_restricted','This setting is excluded from the adapter',array('status'=>403));
  self::refresh();
  $native=new \XTS\AI_Abilities\Theme_Settings\Abilities();$descriptor=$native->ability_get(array('id'=>$id));
  if(is_wp_error($descriptor))return $descriptor;
  list($exists,$state)=self::current($id);if(is_wp_error($state))return $state;
  $safe=OAI_Read::option_value($id,$descriptor);$descriptor=$safe['value'];
  if(!empty($safe['redacted']))return new WP_Error('oai_woodmart_restricted','This setting contains restricted data',array('status'=>403));
  $descriptor['writable']=self::writable($field);$descriptor['write_layer']='base';
  $descriptor['base_exists']=$state['base_exists'];$descriptor['base_value']=self::readable($field,$state['raw_value']);
  $descriptor['snapshot_sha256']=self::hash($state);
  if(strlen(wp_json_encode($descriptor))>16384)return new WP_Error('oai_woodmart_size','Setting exceeds adapter read size limit',array('status'=>413));
  return $descriptor;
 }
 public static function list_settings($req){
  $g=self::guard();if(is_wp_error($g))return $g;
  $search=substr((string)$req->get_param('search'),0,100);$section=(string)$req->get_param('section');
  $page=max(1,min(10000,absint($req->get_param('page')?:1)));$limit=max(1,min(50,absint($req->get_param('per_page')?:20)));
  $ids=array();foreach(\XTS\Admin\Modules\Options::get_fields() as $field){
   if(self::restricted($field))continue;
   if($section!==''&&($field->args['section']??'')!==$section)continue;
   if($search!==''&&stripos($field->get_id().' '.($field->args['name']??''),$search)===false)continue;
   $ids[]=$field->get_id();
  }
  $items=array();foreach(array_slice($ids,($page-1)*$limit,$limit) as $id){$d=self::get_setting($id);if(!is_wp_error($d))$items[]=$d;}
  return array('page'=>$page,'per_page'=>$limit,'total'=>count($ids),'items'=>$items,'write_layer'=>'base');
 }
 private static function normalize($field,$input,$current){
  $safe=OAI_Read::option_value($field->get_id(),$input);
  if(!empty($safe['redacted']))return new WP_Error('oai_woodmart_restricted','Restricted nested input is not allowed',array('status'=>403));
  $json=wp_json_encode($input);
  if(!is_string($json)||strlen($json)>8192)return new WP_Error('oai_woodmart_size','Setting input exceeds the size limit',array('status'=>413));
  $type=$field->args['type']??'';
  if($type==='color'){
   $colors=is_array($input)?$input:array('idle'=>$input);
   if(!$colors)return new WP_Error('oai_woodmart_value','A color value is required',array('status'=>422));
   foreach($colors as $state=>$color)if(!in_array($state,array('idle','hover','active','focus'),true)||!is_string($color)||!preg_match('/^(?:#[a-f0-9]{3}|#[a-f0-9]{6}|#[a-f0-9]{8}|(?:rgba?|hsla?)\([0-9.,%\s+\-]+\)|transparent)$/iD',$color))return new WP_Error('oai_woodmart_value','Invalid color value or state',array('status'=>422));
  }
  if(in_array($type,array('typography','background'),true)&&!is_array($input))return new WP_Error('oai_woodmart_value','This setting requires an object',array('status'=>422));
  if(in_array($type,array('select','buttons'),true)&&(!is_scalar($input)||!array_key_exists((string)$input,$field->args['options']??array())))return new WP_Error('oai_woodmart_value','Choose a declared setting option',array('status'=>422));
  if($type==='switcher'&&!in_array($input,array('0','1','yes',0,1),true))return new WP_Error('oai_woodmart_value','Switcher requires 0, 1 or yes',array('status'=>422));
  if($type==='range'&&(!is_numeric($input)||(isset($field->args['min'])&&$field->args['min']!==''&&(float)$input<(float)$field->args['min'])||(isset($field->args['max'])&&$field->args['max']!==''&&(float)$input>(float)$field->args['max'])))return new WP_Error('oai_woodmart_value','Range value is outside declared bounds',array('status'=>422));
  if(in_array($type,array('text_input','textarea'),true)&&!is_string($input))return new WP_Error('oai_woodmart_value','Text settings require a string',array('status'=>422));
  return \XTS\AI_Abilities\Theme_Settings\Devices_Value::is_devices_type($type)
   ?\XTS\AI_Abilities\Theme_Settings\Devices_Value::encode($input,$field->args,$current):$field->sanitize($input);
 }
 private static function roundtrip($options){
  $normalized=OAI_REST::normalize_option_snapshot(self::OPTION,$options);
  if(is_wp_error($normalized))return $normalized;
  if(!OAI_REST::same_option_value($options,$normalized))return new WP_Error('oai_woodmart_sanitize','Whole-option sanitizer would change other fields or prevent exact restoration; no write is permitted',array('status'=>409));
  return true;
 }
 public static function plan($id,$input){
  $field=self::field($id);if(is_wp_error($field))return $field;
  if(!self::writable($field))return new WP_Error('oai_woodmart_readonly','This setting is not writable through the adapter',array('status'=>403));
  $options=self::options(self::stored(self::OPTION));if(is_wp_error($options))return $options;
  $presets=self::stored(self::PRESETS);$before=self::state($field,$options,$presets);
  if(strlen(wp_json_encode($before))>16384)return new WP_Error('oai_woodmart_size','Stored setting exceeds snapshot size limit',array('status'=>413));
  $safe=OAI_Read::option_value($id,$before['raw_value']);if(!empty($safe['redacted']))return new WP_Error('oai_woodmart_restricted','Stored setting contains restricted data',array('status'=>403));
  $normalized=self::normalize($field,$input,$before['raw_value']);if(is_wp_error($normalized))return $normalized;
  $next=$options;$next[$id]=$normalized;
  foreach(array($options,$next) as $snapshot){$g=self::roundtrip($snapshot);if(is_wp_error($g))return $g;}
  // JSON persistence must retain the exact raw field type, including fractional numbers.
  $after=self::state($field,$next,$presets);
  foreach(array($before,$after) as $snapshot)if(!OAI_REST::same_option_value($snapshot,json_decode(wp_json_encode($snapshot,JSON_PRESERVE_ZERO_FRACTION),true)))return new WP_Error('oai_woodmart_snapshot','Setting cannot be restored from a JSON snapshot',array('status'=>422));
  return array('before'=>$before,'after'=>$after,'meta'=>array('native_input'=>$input,'write_layer'=>'base','save_pipeline'=>'woodmart_native_theme_settings','has_preset_overrides'=>self::has_overrides($id,$options,$presets)));
 }
 private static function has_overrides($id,$options,$presets){
  foreach(array_keys((array)$presets['value']) as $preset)if(isset($options[$preset])&&is_array($options[$preset])&&array_key_exists($id,$options[$preset]))return true;
  return false;
 }
 public static function sandbox($req){
  if(!array_key_exists('value',$req->get_params()))return new WP_Error('oai_woodmart_value','value is required',array('status'=>400));
  $r=new WP_REST_Request('POST','/okean-ai/v1/sandbox');$r->set_param('type','woodmart_theme_setting');
  $r->set_param('target',$req->get_param('id'));$r->set_param('value',$req->get_param('value'));
  return OAI_REST::sandbox_create($r);
 }
 private static function invalidate_css(){
  $ids=array_merge(array('default'),array_keys(\XTS\Admin\Modules\Options\Presets::get_all()));
  foreach($ids as $id){$storage=new \XTS\Modules\Styles_Storage('theme_settings_'.$id);$storage->reset_data();$storage->delete_css();}
  $storage=new \XTS\Modules\Styles_Storage('icons_fonts','option','',false);$storage->reset_data();$storage->delete_css();
 }
 public static function write($id,$desired,$expected,$input=null,$restore=false){
  global $wpdb;
  $field=self::field($id);if(is_wp_error($field))return $field;
  if(!self::writable($field))return new WP_Error('oai_woodmart_readonly','This setting is not writable',array('status'=>403,'write_committed'=>false));
  $table=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$wpdb->options),ARRAY_A);
  if(strtolower($table['Engine']??'')!=='innodb')return new WP_Error('oai_woodmart_engine','Transactional InnoDB option storage is required',array('status'=>409,'write_committed'=>false));
  if($wpdb->query('START TRANSACTION')===false)return new WP_Error('oai_woodmart_transaction','Cannot lock theme settings',array('status'=>500,'write_committed'=>false));
  $committed=false;
  try {
   $options=self::options(self::stored(self::OPTION,true));$presets=self::stored(self::PRESETS,true);
   if(is_wp_error($options))throw new RuntimeException('storage');
   $current=self::state($field,$options,$presets);
   if(!OAI_REST::same_option_value($current,$expected))throw new RuntimeException('conflict');
   if(!is_array($desired)||($desired['id']??'')!==$id||($desired['layer']??'')!=='base')throw new RuntimeException('snapshot');
   $next=$options;
   if(!empty($desired['base_exists']))$next[$id]=$desired['raw_value'];else unset($next[$id]);
   if(!OAI_REST::same_option_value(self::state($field,$next,$presets),$desired))throw new RuntimeException('snapshot');
   foreach(array($options,$next) as $snapshot){$g=self::roundtrip($snapshot);if(is_wp_error($g))throw new RuntimeException('sanitize');}
   self::refresh();
   if($restore){
    // Restore raw bytes/types or inherited absence, without re-encoding old device blobs.
    \XTS\Admin\Modules\Options::get_instance()->update_options($next);self::invalidate_css();
   }else{
    $normalized=self::normalize($field,$input,$current['raw_value']);
    if(is_wp_error($normalized)||!OAI_REST::same_option_value(array('v'=>$normalized),array('v'=>$desired['raw_value'])))throw new RuntimeException('normalize');
    $native=new \XTS\AI_Abilities\Theme_Settings\Abilities();$result=$native->ability_update(array('id'=>$id,'value'=>$input));
    if(is_wp_error($result))throw new RuntimeException('native');
   }
   list($exists,$actual)=self::current($id);
   if(!$exists||!OAI_REST::same_option_value($actual,$desired))throw new RuntimeException('verify');
   if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit');
   $committed=true;return true;
  }catch(Throwable $e){
   return new WP_Error('oai_woodmart_write','Woodmart write was blocked or failed; transactional changes were reverted',array('status'=>409,'reason'=>in_array($e->getMessage(),array('storage','conflict','snapshot','sanitize','normalize','native','verify','commit'),true)?$e->getMessage():'native_exception','write_committed'=>false));
  }finally{
   if(!$committed)$wpdb->query('ROLLBACK');
   self::refresh();
  }
 }
}
