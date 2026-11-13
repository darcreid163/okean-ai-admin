<?php
/**
 * Regression tests for durable AI Lifecycle Control authorization.
 * Run: php tests/ai-lifecycle-principal.php
 */
define('ABSPATH', __DIR__);
define('OAI_BUILD_ID', 'lifecycle-test-build');
define('ARRAY_A', 'ARRAY_A');

function absint($v){return abs((int)$v);}
function user_can($id,$cap){return $cap==='manage_options' && in_array((int)$id,array(7,8),true);}
function get_option($key,$default=false){return $GLOBALS['test_options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['test_options'][$key]=$value;return true;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_salt($scheme='auth'){return 'test-site-auth-salt';}
function home_url($path='/'){return ($GLOBALS['test_home']??'https://example.test').$path;}
function site_url($path='/'){return 'https://example.test/wp'.$path;}
function get_current_blog_id(){return 1;}
function untrailingslashit($value){return rtrim((string)$value,'/');}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function is_wp_error($value){return $value instanceof WP_Error;}

class WP_Error {
 private $code; private $message; private $data;
 public function __construct($code,$message='',$data=array()){$this->code=$code;$this->message=$message;$this->data=$data;}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Request {
 private $route; private $params=array();
 public function __construct($method,$route){$this->route=$route;}
 public function set_url_params($params){$this->params=$params;}
 public function get_route(){return $this->route;}
}
class TestWpdb {
 public $creator_ids=array(7);
 public $approval=null;
 public function prepare($sql,...$args){return $sql;}
 public function get_col($sql){return $this->creator_ids;}
 public function get_row($sql,$format){return $this->approval;}
}
$GLOBALS['wpdb']=new TestWpdb();

class OAI_DB {
 public static $row;
 public static function get($id){return self::$row;}
 public static function audit_table(){return 'wp_oai_audit_log';}
}
class OAI_Audit {
 public static function log($event,$row=array(),$details=array()){
  if($event==='ai_lifecycle_approve'){
   $GLOBALS['wpdb']->approval=array(
    'actor'=>$details['principal_user_id'],
    'details'=>json_encode($details),
   );
  }
 }
}
class OAI_REST {
 public static function sandbox_action($req){
  $action=basename($req->get_route());
  if(!OAI_AI_Lifecycle_Control::request_authorized(42,$action))return new WP_Error('request_context_missing');
  if($action==='approve')OAI_DB::$row['status']='approved';
  if($action==='apply')OAI_DB::$row['status']='applied';
  if($action==='rollback')OAI_DB::$row['status']='rolled_back';
  return array('ok'=>true);
 }
}

require dirname(__DIR__).'/includes/class-oai-ai-lifecycle-control.php';

function check($condition,$message){
 if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
 echo "PASS: {$message}\n";
}

$GLOBALS['test_options']=array();
OAI_DB::$row=array(
 'id'=>42,
 'operation_id'=>'op_lifecycle_42',
 'status'=>'pending',
 'type'=>'post_content',
 'target'=>'page:99',
 'before_data'=>'"before"',
 'after_data'=>'"after"',
 'diff_data'=>'{"changed":true}',
 'rollback_data'=>'{"restore":"before"}',
 'workspace_id'=>'',
 'expires_at'=>'2099-01-01 00:00:00',
 'meta_data'=>'{}',
);
check(!is_wp_error(OAI_AI_Lifecycle_Control::enable(7)),'administrator can enable lifecycle control');

$approved=OAI_AI_Lifecycle_Control::perform(42,'approve','gateway_client_first',7);
check(!is_wp_error($approved),'operation creator can approve from first MCP client');
check(isset($GLOBALS['wpdb']->approval['details']),'approval stores the approved snapshot hash in audit');

$applied=OAI_AI_Lifecycle_Control::perform(42,'apply','gateway_client_after_reconnect',7);
check(!is_wp_error($applied),'same administrator can apply after MCP client/token rotation');

$rolled_back=OAI_AI_Lifecycle_Control::perform(42,'rollback','gateway_client_after_second_reconnect',7);
check(!is_wp_error($rolled_back),'same administrator can rollback after another MCP client rotation');

OAI_DB::$row['status']='approved';
OAI_DB::$row['after_data']='"tampered"';
$changed=OAI_AI_Lifecycle_Control::perform(42,'apply','gateway_client_third',7);
check(is_wp_error($changed) && $changed->get_error_code()==='oai_ai_lifecycle_approval_mismatch','changed approved snapshot is denied');

OAI_DB::$row['after_data']='"after"';
$wrong_user=OAI_AI_Lifecycle_Control::perform(42,'apply','gateway_client_other_user',8);
check(is_wp_error($wrong_user) && $wrong_user->get_error_code()==='oai_ai_lifecycle_owner','different administrator is denied');

$GLOBALS['test_home']='https://other.example.test';
$wrong_site=OAI_AI_Lifecycle_Control::perform(42,'apply','gateway_client_other_site',7);
check(is_wp_error($wrong_site) && $wrong_site->get_error_code()==='oai_ai_lifecycle_approval_mismatch','approval cannot be replayed on another site context');

$GLOBALS['test_home']='https://example.test';
$GLOBALS['wpdb']->creator_ids=array();
$unknown_creator=OAI_AI_Lifecycle_Control::perform(42,'apply','gateway_client_unknown_creator',7);
check(is_wp_error($unknown_creator) && $unknown_creator->get_error_code()==='oai_ai_lifecycle_operation_owner','operation without verifiable creator is denied');

// Reject is non-mutating: historical rows without creator evidence must remain
// closable, matching OAI_REST's preserved reject contract.
OAI_DB::$row['status']='pending';
$legacy_reject=OAI_AI_Lifecycle_Control::perform(42,'reject','gateway_client_legacy_reject',7);
check(!is_wp_error($legacy_reject),'operation without verifiable creator can still be rejected');

// Rollback is the recovery path for pre-upgrade rows, so missing creator
// evidence must not lock a previously applied change in place.
OAI_DB::$row['status']='applied';
$legacy_rollback=OAI_AI_Lifecycle_Control::perform(42,'rollback','gateway_client_legacy_rollback',7);
check(!is_wp_error($legacy_rollback),'operation without verifiable creator can still be rolled back');

echo "All lifecycle principal regression tests passed.\n";
