<?php
// Disposable HTTP fixture loading the actual native authorize() handler.
// Never install this router in WordPress; all credentials/session primitives are synthetic.
define('ABSPATH',__DIR__.'/');
class WP_Error {public function __construct(public $code,private $message,public $data=[]){}public function get_error_message(){return $this->message;}}
function is_wp_error($r){return $r instanceof WP_Error;}
function is_ssl(){return !isset($_GET['fixture_http']);}
function is_user_logged_in(){return true;}
function current_user_can($cap){return !isset($_GET['fixture_reader']);}
function get_current_user_id(){return 7;}
function home_url($p=''){return 'https://okean.market'.$p;}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_unslash($v){return $v;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function admin_url($p){return getenv('WPAI_NATIVE_FIXTURE_ORIGIN').'/wp-admin/'.$p;}
function add_query_arg($args,$url){return $url.(str_contains($url,'?')?'&':'?').http_build_query($args,'','&',PHP_QUERY_RFC3986);}
function nocache_headers(){header('Cache-Control: no-store');header('Pragma: no-cache');}
function wp_redirect($url,$status=302,$by=''){header('Location: '.$url,true,$status);}
function wp_die($message,$title='',$args=[]){http_response_code($args['response']??400);echo esc_html($message);exit;}
function wp_nonce_field($action,$name){echo '<input type="hidden" name="'.esc_attr($name).'" value="fixture-'.hash('sha256',$action).'">';}
function check_admin_referer($action,$name){if(!hash_equals('fixture-'.hash('sha256',$action),(string)($_POST[$name]??'')))wp_die('Nonce rejected','',['response'=>403]);}
function fixture_options(){return json_decode(@file_get_contents(getenv('WPAI_NATIVE_FIXTURE_STORE'))?:'{}',true);}
function get_option($k,$default=false){return fixture_options()[$k]??$default;}
function update_option($k,$v,$autoload=false){$o=fixture_options();$o[$k]=$v;file_put_contents(getenv('WPAI_NATIVE_FIXTURE_STORE'),json_encode($o));return true;}
function add_option($k,$v,$deprecated='',$autoload=false){$o=fixture_options();if(isset($o[$k]))return false;return update_option($k,$v);}
function wp_schedule_single_event($t,$hook,$args){}
class OAI_License {public static function activate_local_owner($id){update_option('oai_license',['fixture'=>true]);return true;}public static function can($cap){return true;}}
function wp_remote_get($url,$args){parse_str(parse_url($url,PHP_URL_QUERY),$q);return ['response'=>['code'=>200],'body'=>json_encode(['protocol'=>'wpai-browser-pairing-v1','state'=>$q['state'],'site_url'=>isset($_GET['fixture_wrong_home'])?'https://other.example':'https://okean.market','gateway'=>'https://wpaiadmin.ru','callback'=>OAI_Gateway_Pairing::CALLBACK,'challenge'=>str_repeat('c',43),'account_id'=>'7','account_label'=>'Fixture account','expires'=>time()+590])];}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
require getenv('WPAI_NATIVE_PAIRING_SOURCE')?:dirname(__DIR__,2).'/includes/class-oai-gateway-pairing.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/fixture-options'){
 header('Content-Type: application/json');$o=fixture_options();echo json_encode(['codes'=>count(array_filter(array_keys($o),fn($k)=>str_starts_with($k,'oai_pair_code_'))),'credentials'=>count(array_filter(array_keys($o),fn($k)=>str_starts_with($k,'oai_pair_credential_'))),'mcp_enabled'=>$o['oai_mcp_enabled']??false]);exit;
}
if($path==='/wp-admin/admin-post.php'){OAI_Gateway_Pairing::authorize();exit;}
http_response_code(404);
