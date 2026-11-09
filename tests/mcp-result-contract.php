<?php
declare(strict_types=1);
/**
 * #136 Native transport contract: enumerate every business-failure marker that
 * must reach the Gateway as `isError` (never as a billable success). Pure PHP;
 * the real WordPress wiring is exercised by wordpress-mcp-error-contract.php.
 */
define('ABSPATH',__DIR__.'/');
if(!function_exists('sanitize_key')){function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}}
if(!class_exists('WP_Error')){
    class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct($code='',$message='',$data=null){$this->code=(string)$code;$this->message=(string)$message;$this->data=$data;}
        public function get_error_code(){return $this->code;}
        public function get_error_message(){return $this->message;}
        public function get_error_data(){return $this->data;}
    }
}
if(!class_exists('WP_REST_Response')){
    class WP_REST_Response {
        public function __construct(private $data=null,private int $status=200){}
        public function get_data(){return $this->data;}
        public function get_status(){return $this->status;}
    }
}
require dirname(__DIR__).'/includes/class-oai-errors.php';

$checks=0;
function rc(bool $ok,string $message): void { global $checks; ++$checks; if(!$ok)throw new RuntimeException($message); }
function rcFailed($value,string $marker): void {
    $failure=OAI_Errors::failure($value);
    rc(is_array($failure),'Expected a failure descriptor for '.$marker);
    rc(($failure['marker']??'')===$marker,'Expected marker '.$marker.', got '.($failure['marker']??'none'));
    rc(OAI_Errors::failed($value)===true,'failed() disagreed with failure() for '.$marker);
}
function rcSuccess($value,string $label): void {
    rc(OAI_Errors::failure($value)===null,'Unexpected failure for '.$label.': '.json_encode(OAI_Errors::failure($value)));
    rc(OAI_Errors::failed($value)===false,'failed() disagreed with failure() for '.$label);
}

// Real error objects and HTTP transports.
rcFailed(new WP_Error('oai_forbidden','Forbidden',['status'=>403]),'wp_error');
rc((OAI_Errors::failure(new WP_Error('oai_forbidden','Forbidden',['status'=>403]))['status']??0)===403,'WP_Error status was lost');
rcFailed(new WP_REST_Response(['ok'=>false,'code'=>'x','status'=>422],422),'http_status');
rcFailed(new WP_REST_Response('plain failure',500),'http_status');
rcFailed(new WP_REST_Response(['ok'=>false,'status'=>400],200),'ok');
rcSuccess(new WP_REST_Response(['ok'=>true,'items'=>[]],200),'200 response');
rcSuccess(new WP_REST_Response([['ok'=>false,'id'=>1]],200),'200 list response');

// Canonical business-failure markers returned as ordinary content.
rcFailed(['ok'=>false,'code'=>'validation_error','message'=>'Bad argument','status'=>400],'ok');
rcFailed(['success'=>false,'message'=>'Handler failed'],'success');
rcFailed(['isError'=>true,'message'=>'Tool error'],'isError');
rcFailed(['http_status'=>500,'data'=>[]],'http_status');
rcFailed(['status'=>422,'message'=>'Unprocessable'],'status');
rcFailed(['error'=>'wp_error','message'=>'Handler threw'],'error');
rcFailed(['error'=>['code'=>'x']],'error');

// JSON-RPC envelopes.
rcFailed(['jsonrpc'=>'2.0','id'=>1,'error'=>['code'=>-32000,'message'=>'Failed']],'jsonrpc_error');
rcFailed(['jsonrpc'=>'2.0','id'=>1,'result'=>['content'=>[],'isError'=>true]],'isError');
rcFailed(['jsonrpc'=>'2.0','id'=>1,'result'=>['isError'=>false]],'jsonrpc_result');
rcSuccess(['jsonrpc'=>'2.0','id'=>1,'result'=>['content'=>[['type'=>'text','text'=>'ok']],'isError'=>false]],'jsonrpc success');

// Successful payloads must never be misclassified.
rcSuccess(['ok'=>true,'status'=>'publish','id'=>7],'entity status string');
rcSuccess(['status'=>200,'ok'=>true],'HTTP 200 status');
rcSuccess(['status'=>399],'status below 400');
rcSuccess(['error'=>null,'ok'=>true],'null error member');
rcSuccess(['error'=>'','ok'=>true],'empty error member');
rcSuccess(['errors'=>[['message'=>'child']],'ok'=>true],'plural errors are not a marker');
rcSuccess([['ok'=>false],['ok'=>true]],'list of rows');
rcSuccess([],'empty object');
rcSuccess('plain string','scalar string');
rcSuccess(42,'scalar int');
rcSuccess(true,'scalar bool');
rcSuccess(null,'null');

// Marker metadata is auditable: code, message and HTTP status survive.
$failure=OAI_Errors::failure(['ok'=>false,'code'=>'validation_error','message'=>'Bad argument','status'=>400]);
rc(($failure['code']??'')==='validation_error','Failure code lost');
rc(($failure['message']??'')==='Bad argument','Failure message lost');
rc(($failure['status']??0)===400,'Failure status lost');

// Numeric-string HTTP status is still a definite upstream failure.
rcFailed(['http_status'=>'503'],'http_status');
// A string entity status (e.g. publish) is not an HTTP failure.
rcSuccess(['status'=>'publish'],'entity status');

echo "PASS {$checks} Native MCP result-contract assertions\n";
