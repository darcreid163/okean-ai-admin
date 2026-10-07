<?php
/** CI-only minimal theme API; actual public WoodMart MCP classes are downloaded separately. */
namespace XTS\Admin\Modules {
 class Options {
  public static $fields=array();private static $options=array();private static $instance;
  public static function get_instance(){if(!self::$instance)self::$instance=new self();return self::$instance;}
  public static function get_fields(){return self::$fields;}
  public static function get_options(){return self::$options;}
  public static function get_default($args){return $args['default']??null;}
  public function load_options(){self::$options=get_option('xts-woodmart-options',array());}
  public function load_defaults(){foreach(self::$fields as $f)if(!isset(self::$options[$f->get_id()]))self::$options[$f->get_id()]=self::get_default($f->args);}
  public function load_presets(){if(get_option('oai_ci_preset_active',false)&&isset(self::$options['ci-preset']))self::$options=array_merge(self::$options,self::$options['ci-preset']);}
  public function setup_globals(){$GLOBALS['xts_woodmart_options']=self::$options;}
  public function update_options($options){update_option('xts-woodmart-options',$options);}
 }
 class OAI_CI_Field {
  public $args;public function __construct($args){$this->args=$args;}
  public function get_id(){return $this->args['id'];}
  public function sanitize($value){
   switch($this->args['type']){
    case 'color':return is_string($value)?array('idle'=>$value):$value;
    case 'switcher':return $value==='yes'?'1':$value;
    case 'text_input':return sanitize_text_field($value);
    case 'textarea':return wp_kses_post($value);
    case 'typography':return isset($value[0])?$value:array($value);
    default:return $value;
   }
  }
 }
}
namespace XTS\Admin\Modules\Options {
 class Presets {
  private static $presets=array();private static $instance;
  public static function get_instance(){if(!self::$instance)self::$instance=new self();return self::$instance;}
  public function load_presets(){self::$presets=get_option('xts-options-presets',array());}
  public static function get_all(){return self::$presets;}
 }
}
namespace XTS\Modules {
 class Styles_Storage {
  private $id;public function __construct($id,$type='option',$path='',$flag=true){$this->id=$id;}
  public function reset_data(){update_option('oai_ci_css_'.$this->id.'_reset',(int)get_option('oai_ci_css_'.$this->id.'_reset',0)+1,false);}
  public function delete_css(){update_option('oai_ci_css_'.$this->id.'_deleted',(int)get_option('oai_ci_css_'.$this->id.'_deleted',0)+1,false);}
 }
}
namespace {
 define('WOODMART_VERSION','8.6.2');define('WOODMART_MCP_VERSION','1.0.1');
 // Exercise native Devices_Value merge/validation with a deterministic fixture codec.
 function woodmart_compress($value){return 'ci:'.base64_encode(gzcompress($value));}
 function woodmart_is_compressed_data($value){return is_string($value)&&strpos($value,'ci:')===0;}
 function woodmart_decompress($value){return gzuncompress(base64_decode(substr($value,3)));}
 $native=getenv('OAI_WOODMART_NATIVE_DIR');
 if(!$native||!is_file($native.'/inc/theme-settings/class-abilities.php'))throw new \RuntimeException('Actual public WoodMart MCP source is required');
 foreach(array('class-devices-value.php','class-value-format.php','class-abilities.php') as $file)require_once $native.'/inc/theme-settings/'.$file;
 add_action('init',function(){
  $fields=array(
   array('id'=>'primary-color','name'=>'Primary color','type'=>'color','section'=>'colors','default'=>array('idle'=>'#83b735')),
   array('id'=>'spacing','name'=>'Spacing','type'=>'dimensions','section'=>'design','devices'=>array('desktop'=>array(),'tablet'=>array(),'mobile'=>array()),'default'=>''),
   array('id'=>'inherited-color','name'=>'Inherited color','type'=>'color','section'=>'colors','default'=>array('idle'=>'#112233')),
   array('id'=>'font','name'=>'Font','type'=>'typography','section'=>'design','default'=>array(array('font-family'=>'Arial'))),
   array('id'=>'mode','name'=>'Mode','type'=>'select','section'=>'design','options'=>array('wide'=>'Wide','boxed'=>'Boxed'),'default'=>'wide'),
   array('id'=>'count','name'=>'Count','type'=>'range','section'=>'design','min'=>1,'max'=>10,'default'=>'2'),
   array('id'=>'google_api_key','name'=>'API key','type'=>'text_input','section'=>'keys','default'=>'hidden'),
   array('id'=>'custom_js','name'=>'Javascript','type'=>'textarea','section'=>'custom_code','default'=>''),
   array('id'=>'html','name'=>'HTML','type'=>'editor','section'=>'design','default'=>''),
  );
  \XTS\Admin\Modules\Options::$fields=array_map(function($args){return new \XTS\Admin\Modules\OAI_CI_Field($args);},$fields);
  \XTS\Admin\Modules\Options\Presets::get_instance()->load_presets();
  $options=\XTS\Admin\Modules\Options::get_instance();$options->load_options();$options->load_defaults();$options->load_presets();$options->setup_globals();
 },20);
}

