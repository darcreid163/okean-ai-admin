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
 if($key==='native_integer')return abs((int)$value);
 if($key==='reordered')return array_reverse($value,true);
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
$GLOBALS['options']=array('wpseo_titles'=>array('title'=>'old'),'scalar'=>'old','unstable'=>1,'throwing'=>'old','legacy'=>array('x'=>1),'native_integer'=>'150');
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
$native=OAI_REST::sandbox_create(new Request('native_integer','0151'));
expect(!is_wp_error($native),'native string-to-integer sanitizer must not block a restorable baseline');
expect($native['before_data']==='150'&&$native['after_data']==='151','numeric snapshot must match persistent scalar representation');
$native_noop=OAI_REST::sandbox_create(new Request('native_integer','0150'));
expect(is_wp_error($native_noop)&&$native_noop->get_error_code()==='oai_noop','effective native numeric no-op must be rejected');
expect(OAI_REST::option_storage_value(array('count'=>1,'flag'=>false))===array('count'=>1,'flag'=>false),'nested scalar types must remain exact');
expect(OAI_REST::option_storage_value(false)===''&&OAI_REST::option_storage_value(true)==='1','boolean storage representation must match WordPress');
expect(count(OAI_DB::$rows)===3,'failed plans must not create operations');
$GLOBALS['options']['reordered']=array('title'=>'old','cache'=>array('width'=>69,'height'=>50));
$reordered=OAI_REST::sandbox_create(new Request('reordered',array('title'=>'new','cache'=>array('height'=>50,'width'=>69))));
expect(!is_wp_error($reordered),'map reordering must not make a baseline unsafe');
expect($reordered['before_data']===$GLOBALS['options']['reordered'],'baseline snapshot must remain unchanged');
$reordered_noop=OAI_REST::sandbox_create(new Request('reordered',$GLOBALS['options']['reordered']));
expect(is_wp_error($reordered_noop)&&$reordered_noop->get_error_code()==='oai_noop','map reordering alone must be a no-op');
expect(OAI_REST::same_snapshot('option_update',array('a'=>1,'b'=>false),array('b'=>false,'a'=>1)),'option verification must ignore map insertion order');
expect(!OAI_REST::same_option_value(array('a'=>1),array('a'=>'1')),'nested scalar type change must not compare equal');
expect(!OAI_REST::same_option_value(array('a'=>false),array('a'=>'')),'nested boolean must not be coerced');
expect(!OAI_REST::same_option_value(array('a'=>null),array()),'missing keys must not compare equal to null');
expect(!OAI_REST::same_option_value(array('categories'=>array(1,2)),array('categories'=>array(2,1))),'option lists must stay ordered even under categories keys');
expect(!OAI_REST::same_option_value(array(1,2),array('1','2')),'list item types must remain exact');
expect(count(OAI_DB::$rows)===4,'reordered no-op must not create an operation');
$missing_false=OAI_REST::sandbox_create(new Request('missing_false',false));
expect(!is_wp_error($missing_false)&&$missing_false['after_data']==='','creating a missing false option is a change even though its storage value is empty');
expect(empty($missing_false['meta_data']['before_exists']),'missing option existence must remain authoritative');
require dirname(__DIR__).'/includes/class-oai-regression-matrix.php';
$status_method=new ReflectionMethod('OAI_Regression_Matrix','scenario_status');
foreach(array('PASS','SKIP','FAIL','BLOCKED') as $state)expect($status_method->invoke(null,array('status'=>$state))===$state,'matrix alias must preserve scenario status');
expect($status_method->invoke(null,array('status'=>'unknown'))==='FAIL','unknown scenario status must fail closed');
echo "Option normalization planning tests passed\n";

