<?php
// Production central/native pairing and MCP authentication; WordPress identity/HTTP are fixtures.
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('OAI_VERSION','2.1.13');
use WPAICloudGateway\{Core,Store,Vault,Signer,Security,Pairing,Transport,PairingTransport,Fault};
$origin='https://wpaiadmin.ru';$uid=42;$admin=true;$ssl=true;$options=[];$deleteFails=[];$httpOverride=null;$checks=0;
function home_url($path=''){global $origin;return $origin.$path;}
function untrailingslashit($s){return rtrim($s,'/');}
function is_ssl(){global $ssl;return $ssl;}
function current_user_can($cap){global $admin;return $admin;}
function get_current_user_id(){global $uid;return $uid;}
function get_user_by($field,$id){return $id>0?(object)['ID'=>(int)$id]:false;}
function user_can($user,$cap){global $admin;return $admin;}
function wp_set_current_user($id){global $uid;$uid=$id;}
function get_option($key,$default=false){global $options,$origin;return $options[$origin][$key]??$default;}
function add_option($key,$value,$ignored='',$autoload=true){global $options,$origin;if(isset($options[$origin][$key]))return false;$options[$origin][$key]=$value;return true;}
function update_option($key,$value,$autoload=null){global $options,$origin;$options[$origin][$key]=$value;return true;}
function delete_option($key){global $options,$origin,$deleteFails;if(isset($deleteFails[$key]))return false;if(!isset($options[$origin][$key]))return false;unset($options[$origin][$key]);return true;}
function wp_schedule_single_event($when,$hook,$args){return true;}
function wp_unslash($s){return $s;}
function absint($n){return abs((int)$n);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_get($url,$args){global $pairing,$now,$httpOverride;if($httpOverride!==null)return $httpOverride;parse_str(parse_url($url,PHP_URL_QUERY),$q);$data=at('https://wpaiadmin.ru',42,fn()=>$pairing->descriptor($q['state'],$now));return ['response'=>['code'=>200],'body'=>json_encode($data)];}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
class WP_Error {public function __construct(public $code,public $message,public $data=[]){ }public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}
class WP_REST_Response {public function __construct(public $data,public $status=200,public $headers=[]){ }public function set_status($s){$this->status=$s;}public function header($k,$v){$this->headers[$k]=$v;}}
class OAI_License {public static function can($feature){return true;}public static function activate_local_owner($uid){update_option('oai_license',['owner_user_id'=>$uid,'active'=>true],false);return true;}}
class OAI_Rate_Limit {public static function check(...$args){return true;}}
class OAI_REST {public static function site(){return ['url'=>home_url('/')];}}
function at($host,$user,callable $fn){global $origin,$uid;$old=[$origin,$uid];$origin=$host;$uid=$user;try{return $fn();}finally{[$origin,$uid]=$old;}}
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,$reason){try{$fn();}catch(Fault $e){check($e->reason===$reason,'Wrong fault: '.$e->reason);return;}throw new RuntimeException('Expected '.$reason);}
foreach(['Security','Transport','Store','Core','Gateway','Pairing'] as $file)require dirname(__DIR__).'/wp-ai-admin-cloud/src/'.$file.'.php';
require dirname(__DIR__).'/includes/class-oai-gateway-pairing.php';require dirname(__DIR__).'/includes/class-oai-mcp.php';
class BrowserTransport implements Transport,PairingTransport {
 public $wrongSite=false;public $lastToken='';
 public function pairing(string $home,string $action,array $p=[]):array {
  return at($home,7,function()use($action,$p){global $now;if($action==='capabilities')return ['protocol'=>'wpai-browser-pairing-v1','site_url'=>$this->wrongSite?'https://mismatch.example':untrailingslashit(home_url('/'))];$r=OAI_Gateway_Pairing::exchange($p,$now);if(is_wp_error($r))throw new Fault($r->code,401);$this->lastToken=$r['token'];return $r;});
 }
 public function rpc(string $home,string $token,array $p):array {
  return at($home,7,function()use($token,$p,$home){global $now;$actor=OAI_Gateway_Pairing::authenticate($token,$now);if(!$actor)throw new Fault('upstream_outcome_unknown',502);
   if(($p['params']['name']??'')==='system_manifest')return ['jsonrpc'=>'2.0','id'=>$p['id'],'result'=>['content'=>[['type'=>'text','text'=>json_encode(['plugin_name'=>'WP AI Admin','site_id'=>'site-'.hash('sha256',$home),'installation_id'=>'inst-'.hash('sha256',$home),'release'=>['production_defaults'=>['direct_live_writes'=>false]]])]]]];
   if($p['method']==='tools/list')return ['jsonrpc'=>'2.0','id'=>$p['id'],'result'=>['tools'=>[['name'=>'wp.get_site','inputSchema'=>['type'=>'object']]]]];
   $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;$req=new class($p){public function __construct(private $p){}public function get_json_params(){return $this->p;}};$r=OAI_MCP::handle($req);return $r->data;
  });
 }
}
$db=tempnam(sys_get_temp_dir(),'pairing-');$store=new Store(new PDO('sqlite:'.$db),'pair_fixture_');$store->migrate();$vault=new Vault(str_repeat('p',32));$transport=new BrowserTransport();$keys=sodium_crypto_sign_keypair();
$core=new Core($store,$vault,$transport,json_decode(file_get_contents(dirname(__DIR__).'/wp-ai-admin-cloud/config/plans.json'),true),new Signer(sodium_crypto_sign_secretkey($keys)));
$pairing=new Pairing($core,$vault,$transport);$now=time();
$preflight=$pairing->checkSite('42','https://okean.market',$now);
check($preflight['site_url']==='https://okean.market'&&$preflight['pairing_available']===true,'Preflight failed');
check(count($core->sites('42'))===0,'Preflight registered a site without consent');
$transport->wrongSite=true;rejects(fn()=>$pairing->checkSite('42','https://okean.market',$now),'pairing_client_update_required');$transport->wrongSite=false;
rejects(fn()=>$pairing->checkSite('42','http://okean.market',$now),'public_https_site_required');
function begin($home){global $pairing,$now;$url=$pairing->start('42',$home,'Николай',$now);parse_str(parse_url($url,PHP_URL_QUERY),$q);return $q['state'];}
function approve($home,$state){global $now;return at($home,7,function()use($state){global $now;$r=OAI_Gateway_Pairing::request($state,$now);check(!is_wp_error($r),'Trusted descriptor rejected');return OAI_Gateway_Pairing::issue_code($r,$now);});}
$state=begin('https://okean.market');$descriptor=$pairing->descriptor($state,$now);check(!isset($descriptor['verifier']),'PKCE verifier leaked');
check($descriptor['callback']===Pairing::CALLBACK&&$descriptor['account_label']==='Николай','Wrong callback/account identity');
$code=approve('https://okean.market',$state);check(is_string($code)&&strlen($code)===43,'Code missing');
$pending=get_option('wpai_pair_request_'.hash('sha256',$state));$verifier=$vault->open($pending['verifier'],'pairing:'.$state);
at('https://okean.market',7,function()use($state,$code){global $now;$r=OAI_Gateway_Pairing::exchange(['state'=>$state,'code'=>$code,'verifier'=>str_repeat('x',43),'callback'=>Pairing::CALLBACK],$now);check(is_wp_error($r),'Wrong verifier accepted');});
rejects(fn()=>$pairing->complete('99',$state,$code,$now),'pairing_account_mismatch');
$site=$pairing->complete('42',$state,$code,$now);$token=$transport->lastToken;
check($site['home']==='https://okean.market'&&count($core->sites('42'))===1,'Automatic registration failed');
check(!str_contains(json_encode($options['https://okean.market']),$token),'Native credential stored in plaintext');
$row=$store->row('SELECT * FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);check(!str_contains($row['token_sealed'],$token),'Central token stored in plaintext');
rejects(fn()=>$pairing->complete('42',$state,$code,$now),'pairing_request_expired');
at('https://okean.market',7,function()use($state,$code,$verifier){global $now;$r=OAI_Gateway_Pairing::exchange(['state'=>$state,'code'=>$code,'verifier'=>$verifier,'callback'=>Pairing::CALLBACK],$now);check(is_wp_error($r),'Code replay accepted');});
$state2=begin('https://wpaiadmin.ru');$code2=approve('https://wpaiadmin.ru',$state2);$site2=$pairing->complete('42',$state2,$code2,$now);
check(count($core->sites('42'))===2&&$site2['id']!==$site['id'],'Two-site registration failed');
$access=$core->mint('42',[$site['id'],$site2['id']],['mcp:read','mcp:write'],$now);$actor=$core->authenticate($access['access_token'],$now);
foreach([$site,$site2] as $s){$r=$core->call($actor,$s['id'],'wp.get_site',[],'read-'.$s['id'],$now);check($r['state']==='succeeded','Read dispatch failed');$json=str_replace('\\/','/',json_encode($r,JSON_UNESCAPED_SLASHES));check(str_contains($json,$s['home']),'Read crossed site boundary');}
$state3=begin('https://okean.market');$code3=approve('https://okean.market',$state3);$again=$pairing->complete('42',$state3,$code3,$now);check($again['id']===$site['id']&&count($core->sites('42'))===2,'Reconnect duplicated site');
$freshToken=$transport->lastToken;$core->disconnect('42',$site['id'],$now);
$state4=begin('https://okean.market');$code4=approve('https://okean.market',$state4);$restored=$pairing->complete('42',$state4,$code4,$now);check($restored['id']!==$site['id'],'Revoked registration ID reused');
rejects(fn()=>$core->catalog($actor,$restored['id'],$now),'scope_or_site_denied');
at('https://okean.market',7,function()use($freshToken){global $now,$admin;$r=OAI_Gateway_Pairing::authenticate($freshToken,$now);check($r['user']->ID===7,'Credential owner lost');$admin=false;check(OAI_Gateway_Pairing::authenticate($freshToken,$now)===false,'Demoted administrator retained access');$admin=true;check(OAI_Gateway_Pairing::revoke(hash('sha256',$freshToken))===true,'Revocation failed');check(OAI_Gateway_Pairing::authenticate($freshToken,$now)===false,'Revoked credential accepted');});
$cancel=begin('https://cancel.example');$pairing->cancel('42',$cancel,$now);rejects(fn()=>$pairing->descriptor($cancel,$now),'pairing_request_expired');
$expired=begin('https://expire.example');rejects(fn()=>$pairing->descriptor($expired,$now+601),'pairing_request_expired');
$bad=begin('https://bad.example');$httpOverride=['response'=>['code'=>200],'body'=>json_encode($pairing->descriptor($bad,$now)+[])];
$altered=json_decode($httpOverride['body'],true);$altered['callback']='https://evil.example/callback';$httpOverride['body']=json_encode($altered);
at('https://bad.example',7,function()use($bad){global $now;check(is_wp_error(OAI_Gateway_Pairing::request($bad,$now)),'Untrusted callback accepted');});$httpOverride=null;
$transport->wrongSite=true;rejects(fn()=>begin('https://wrong.example'),'pairing_client_update_required');$transport->wrongSite=false;
rejects(fn()=>$pairing->descriptor('bad',$now),'invalid_pairing_state');
$state5=begin('https://admin.example');at('https://admin.example',7,function()use($state5){global $admin,$now,$ssl;$r=OAI_Gateway_Pairing::request($state5,$now);$admin=false;check(is_wp_error(OAI_Gateway_Pairing::issue_code($r,$now)),'Non-admin consent accepted');$admin=true;$ssl=false;check(is_wp_error(OAI_Gateway_Pairing::issue_code($r,$now)),'HTTP consent accepted');$ssl=true;});
$race=begin('https://race.example');$raceCode=approve('https://race.example',$race);$deleteFails['wpai_pair_request_'.hash('sha256',$race)]=true;rejects(fn()=>$pairing->complete('42',$race,$raceCode,$now),'pairing_request_already_used');unset($deleteFails['wpai_pair_request_'.hash('sha256',$race)]);
$racePending=get_option('wpai_pair_request_'.hash('sha256',$race));$raceVerifier=$vault->open($racePending['verifier'],'pairing:'.$race);
at('https://race.example',7,function()use($race,$raceCode,$raceVerifier){global $deleteFails,$now;$key='oai_pair_code_'.hash('sha256',$raceCode);$deleteFails[$key]=true;$p=['state'=>$race,'code'=>$raceCode,'verifier'=>$raceVerifier,'callback'=>Pairing::CALLBACK];$r=OAI_Gateway_Pairing::exchange($p,$now);check(is_wp_error($r)&&$r->code==='oai_pair_code_used','Native exchange issued a credential after losing atomic claim');unset($deleteFails[$key]);$r=OAI_Gateway_Pairing::exchange($p,$now+121);check(is_wp_error($r),'Expired native code exchanged');});
// The existing manual token hash is independent from every newly paired credential.
at('https://okean.market',7,function(){check(get_option('oai_mcp_token_hash','untouched')==='untouched','Pairing rotated manual token');});
@unlink($db);@unlink($db.'-wal');@unlink($db.'-shm');echo "PASS $checks browser pairing assertions; two sites, PKCE, owner binding, replay, revocation and native MCP read\n";
