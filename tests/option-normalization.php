<?php
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);
class WP_Error {public $code;public function __construct($code,$message='',$data=array()){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower($v);}
function sanitize_text_field($v){return $v;}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function sanitize_option($key,$value){
 if($key==='unstable')return (int)$value+1;
 if($key==='throwing')throw new RuntimeException('private diagnostic');
 if($key==='wpseo_titles'){unset($value['legacy_flag']);return $value;}
 if($key==='scalar')return strtolower(trim($value));
 return $value;
}
function wp_rand(){return 1234;}
function current_time(){return gmdate('Y-m-d H:i:s');}
function wp_json_encode($v){return json_encode($v);}
class OAI_DB {public static $rows=array();public static function insert($r){$id=count(self::$rows)+1;$r['id']=$id;self::$rows[$id]=$r;return $id;}public static function get($id){return self::$rows[$id];}}
class OAI_Audit {public static function log($event,$row){}}
class OAI_Lifecycle {public static function descriptor($type,$target,$before,$meta){return $meta;}public static function decorate($r){return $r;}}
require dirname(__DIR__).'/includes/class-oai-rest.php';
function expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
class Request {private $args;public function __construct($key,$value){$this->args=array('type'=>'option_update','target'=>$key,'value'=>$value);}public function get_param($key){return $this->args[$key]??null;}}
$GLOBALS['options']=array('wpseo_titles'=>array('title'=>'old'),'scalar'=>'old','unstable'=>1,'throwing'=>'old','legacy'=>array('x'=>1));
$requested=array('title'=>'new','legacy_flag'=>true);
$op=OAI_REST::sandbox_create(new Request('wpseo_titles',$requested));
expect(!is_wp_error($op),'normalized option must plan successfully');
expect($op['after_data']===array('title'=>'new'),'approved snapshot must omit rejected legacy fields');
expect($op['before_data']===array('title'=>'old'),'rollback snapshot must remain exact');
expect($op['status']==='pending','normalization must not approve or apply');
expect($GLOBALS['options']['wpseo_titles']===array('title'=>'old'),'planning must not write the live option');
$scalar=OAI_REST::sandbox_create(new Request('scalar','  NEW  '));
expect($scalar['after_data']==='new','scalar normalization must be captured before approval');
$noop=OAI_REST::sandbox_create(new Request('scalar',' OLD '));
expect(is_wp_error($noop)&&$noop->get_error_code()==='oai_noop','normalization must detect effective no-op');
$GLOBALS['options']['wpseo_titles']['legacy_flag']=true;
$bad=OAI_REST::sandbox_create(new Request('wpseo_titles',array('title'=>'new')));
expect(is_wp_error($bad)&&$bad->get_error_code()==='oai_option_rollback_unsafe','unrestorable baseline must be rejected before mutation');
$unstable=OAI_REST::sandbox_create(new Request('unstable',4));
expect(is_wp_error($unstable)&&$unstable->get_error_code()==='oai_option_unstable','non-idempotent sanitizers must be rejected');
$throwing=OAI_REST::sandbox_create(new Request('throwing','new'));
expect(is_wp_error($throwing)&&$throwing->get_error_code()==='oai_option_sanitize','sanitizer exceptions must fail before mutation');
expect(count(OAI_DB::$rows)===2,'failed plans must not create operations');
echo "Option normalization planning tests passed\n";
