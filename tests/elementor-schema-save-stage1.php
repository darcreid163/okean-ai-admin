<?php
/* Run: php tests/elementor-schema-save-stage1.php
 * Pure fixture: no WordPress, database, gateway or production writes.
 */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()) { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int)$value); }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
class OAI_Elementor {
    public static $trees = array(42=>array(array('id'=>'a','elType'=>'widget','settings'=>array('title'=>'Old'))));
    public static $fail = false;
    public static function widget_schema($name) { return array('widget_type'=>$name,'controls'=>array('title'=>array('type'=>'text'))); }
    public static function get_data($id) { return self::$trees[$id] ?? new WP_Error('missing'); }
    public static function decode_create_nodes($data) {
        if (!is_array($data) || !array_is_list($data)) return new WP_Error('invalid_nodes');
        foreach($data as $node) if(!is_array($node) || array_is_list($node)) return new WP_Error('invalid_node');
        return $data;
    }
    public static function save_document($id,$data) {
        if(self::$fail) return new WP_Error('write_failed');
        self::$trees[$id]=$data; return true;
    }
}
require dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
function check($condition,$reason) { if(!$condition) throw new RuntimeException($reason); }
function error_code($value,$code) { check(is_wp_error($value) && $value->get_error_code()===$code,$code); }
$adapter='OAI_Elementor_Schema_Save';
$original=$adapter::snapshot(42);
check($adapter::schema('heading')['widget_type']==='heading','schema discovery failed');
check(strlen($original['sha256'])===64,'snapshot invalid');
$changed=array(array('id'=>'a','elType'=>'widget','settings'=>array('title'=>'New')));
$proposal=$adapter::validate_replace($original['tree'],$changed,$original['sha256']);
check(!is_wp_error($proposal),'Sandbox preparation failed');
error_code($adapter::validate_replace($original['tree'],$changed,str_repeat('0',64)),'oai_snapshot_mismatch');
error_code($adapter::validate_replace($original['tree'],$original['tree'],$original['sha256']),'oai_noop');
error_code($adapter::validate_replace($original['tree'],array('bad'),$original['sha256']),'invalid_node');
error_code($adapter::apply_approved(42,$proposal),'oai_approval_required');
check($adapter::snapshot(42)['tree']===$original['tree'],'approval bypass mutated page');
OAI_Elementor::$trees[42][0]['settings']['title']='Human edit';
error_code($adapter::apply_approved(42,$proposal,true),'oai_stale_target');
OAI_Elementor::$trees[42]=$original['tree'];
OAI_Elementor::$fail=true;
error_code($adapter::apply_approved(42,$proposal,true),'write_failed');
check($adapter::snapshot(42)['tree']===$original['tree'],'failure mutated page');
OAI_Elementor::$fail=false;
check($adapter::apply_approved(42,$proposal,true)===true,'approved apply failed');
check($adapter::verify(42,$changed)['ok']===true,'verify failed');
check($adapter::verify(42,$original['tree'])['ok']===false,'false positive verification');
error_code($adapter::rollback_approved(42,$proposal),'oai_approval_required');
OAI_Elementor::$trees[42][0]['settings']['title']='Concurrent edit';
error_code($adapter::rollback_approved(42,$proposal,true),'oai_rollback_conflict');
OAI_Elementor::$trees[42]=$changed;
check($adapter::rollback_approved(42,$proposal,true)===true,'rollback failed');
check($adapter::verify(42,$original['tree'])['ok']===true,'rollback verify failed');
echo "PASS: schema, sandbox, approval, stale guard, failed apply, verify, rollback, rollback conflicts, rollback verify\n";
