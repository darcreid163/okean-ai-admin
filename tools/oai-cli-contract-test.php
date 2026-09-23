<?php
/**
 * AI Admin WP-CLI parser/registry contract tests.
 * Runs without WordPress by providing the minimal WP helpers used by these classes.
 */
class WP_Error {
 private $code; private $message; private $data;
 public function __construct($code,$message='',$data=array()){ $this->code=$code; $this->message=$message; $this->data=$data; }
 public function get_error_code(){return $this->code;}
 public function get_error_message(){return $this->message;}
 public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($key){
 $key=strtolower((string)$key);
 return preg_replace('/[^a-z0-9_\\-]/','',$key);
}
require_once __DIR__.'/../includes/class-oai-argument-parser.php';
require_once __DIR__.'/../includes/class-oai-command-normalizer.php';
require_once __DIR__.'/../includes/class-oai-command-registry.php';
require_once __DIR__.'/../includes/class-oai-agent.php';

$fail=0; $tests=0;
function check_test($ok,$label){
 global $fail,$tests; $tests++;
 if(!$ok){$fail++;echo "FAIL: {$label}\n";} else echo "PASS: {$label}\n";
}

$t=OAI_Argument_Parser::tokenize('wp post list --number 1 --post_type page');
check_test(is_array($t) && $t[0]==='wp' && $t[2]==='list' && $t[3]==='--number' && $t[4]==='1','tokenize basic CLI');
$f=OAI_Argument_Parser::flags($t);
check_test($f['flags']['number']==='1' && $f['flags']['post_type']==='page' && count($f['args'])===3,'parse separated flag values');
$r=OAI_Command_Registry::resolve('wp post list --number 1 --post_type page');
check_test(!is_wp_error($r) && $r['canonical']==='post list' && $r['flags']['number']==='1' && $r['flags']['post_type']==='page','registry resolves separated flags');

$r=OAI_Command_Registry::resolve('post list --number=1 --bogus=1');
check_test(is_wp_error($r) && $r->get_error_code()==='oai_cli_unknown_flag','registry rejects unknown flags');

$r=OAI_Command_Registry::resolve('wp option update demo_key demo_value');
check_test(!is_wp_error($r) && empty($r['read']) && $r['execution_mode']==='plan_only','writes remain plan-only');

$r=OAI_Command_Registry::resolve('cron event list --number 2');
check_test(!is_wp_error($r) && $r['canonical']==='cron event list' && $r['flags']['number']==='2','cron subcommand normalization');

$r=OAI_Command_Registry::resolve('db query "SELECT 1 LIMIT 1"');
check_test(!is_wp_error($r) && $r['canonical']==='db query' && $r['args'][0]==='SELECT 1 LIMIT 1','quoted SQL argument');

$bad=OAI_Argument_Parser::tokenize('post get "123');
check_test(is_wp_error($bad) && $bad->get_error_code()==='oai_cli_unclosed_quote','unclosed quotes rejected');

$r=OAI_Command_Normalizer::normalize('post get "123');
check_test(is_wp_error($r) && $r->get_error_code()==='oai_cli_unclosed_quote','normalizer propagates parser error');

$r=OAI_Command_Registry::resolve('');
check_test(is_wp_error($r) && $r->get_error_code()==='oai_cli_empty','empty command rejected');

$am=new ReflectionMethod('OAI_Agent','allowlist'); $am->setAccessible(true); $sm=new ReflectionMethod('OAI_Agent','scope_for'); $sm->setAccessible(true);
$allow=$am->invoke(null); $scope_errors=array();
foreach(array_keys($allow) as $action){ $scope=$sm->invoke(null,$action); if($scope==='')$scope_errors[]=$action; }
check_test(empty($scope_errors),'every Agent allowlist action has a scope mapping'.(empty($scope_errors)?'':': '.implode(',',$scope_errors)));

$agent_source=file_get_contents(__DIR__.'/../includes/class-oai-agent.php'); $callback_errors=array();
if(preg_match('/\\$callbacks=array\\((.*?)\\n\\s*\\);/s',$agent_source,$cm) && preg_match_all("/'([^']+)'\\s*=>\\s*array\\(/",$cm[1],$km)){
 $callback_keys=array_fill_keys($km[1],true);
 foreach(array_keys($allow) as $action){ if(in_array($action,array('approve','apply','rollback'),true))continue; if(!isset($callback_keys[$action]))$callback_errors[]=$action; }
} else {$callback_errors=array('callback-map-unparseable');}
check_test(empty($callback_errors),'every non-lifecycle Agent allowlist action has a callback'.(empty($callback_errors)?'':': '.implode(',',$callback_errors)));

$gutenberg_source=file_get_contents(__DIR__.'/../includes/class-oai-gutenberg-resolver.php');
$matcher_pos=strpos($gutenberg_source,'private static function find_raw_matches');
$matcher_tail=$matcher_pos===false?'':substr($gutenberg_source,$matcher_pos,2500);
check_test($matcher_pos!==false && strpos($matcher_tail,'isset($selector[\'html_contains\'])')!==false,'native Gutenberg matcher honors html_contains');

$cli_source=file_get_contents(__DIR__.'/../includes/class-oai-wp-cli-execution.php');
check_test(strpos($cli_source,"'oai_db_limit'")!==false && strpos($cli_source,"requested_limit")!==false,'DB resolver enforces maximum SQL row limit');

echo "WP-CLI parser/registry contract tests: {$tests} tests, {$fail} failures\n";
if($fail)exit(1);
echo "RESULT: PASS\n";
