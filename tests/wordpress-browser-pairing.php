<?php
// Executed by WP-CLI on a disposable real WordPress with the new native plugin active.
use WPAICloudGateway\{Core,Store,Vault,Signer,Security,Pairing,Transport,PairingTransport,Fault};
function pair_expect($ok,$message){global $pair_checks;$pair_checks++;if(!$ok)throw new RuntimeException($message);}
function pair_reject(callable $fn,$reason){try{$fn();}catch(Fault $e){pair_expect($e->reason===$reason,'Unexpected pairing fault: '.$e->reason);return;}throw new RuntimeException('Expected '.$reason);}
global $pair_checks;$pair_checks=0;wp_set_current_user(1);$_SERVER['HTTPS']='on';
$old_home=get_option('home');$old_siteurl=get_option('siteurl');update_option('home','https://wpaiadmin.ru');update_option('siteurl','https://wpaiadmin.ru');
$subscriber=wp_create_user('pair-fixture-subscriber-'.wp_generate_password(8,false,false),'disposable-password','pair-fixture@example.test');
if(is_wp_error($subscriber))throw new RuntimeException('Pairing fixture user setup failed');
foreach(['Security','Transport','Store','Core','Gateway','Pairing'] as $component)require_once dirname(__DIR__).'/wp-ai-admin-cloud/src/'.$component.'.php';
class RealWpPairTransport implements Transport,PairingTransport {
 public $lastToken='';
 public function pairing(string $home,string $action,array $data=[]):array {
  $req=new WP_REST_Request($action==='capabilities'?'GET':'POST','/okean-ai/v1/pairing/'.$action);$req->set_header('content-type','application/json');if($action!=='capabilities')$req->set_body(wp_json_encode($data));
  $response=rest_do_request($req);if($response->get_status()!==200)throw new Fault('pairing_exchange_failed',502);$r=$response->get_data();if(isset($r['token']))$this->lastToken=$r['token'];return $r;
 }
 public function rpc(string $home,string $token,array $data):array {
  $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;$req=new WP_REST_Request('POST','/okean-ai/mcp');$req->set_header('content-type','application/json');$req->set_body(wp_json_encode($data));$r=rest_do_request($req);
  if($r->get_status()!==200)throw new Fault('upstream_outcome_unknown',502);return $r->get_data();
 }
}
global $wpdb;
$dsn='mysql:host=127.0.0.1;dbname='.DB_NAME.';charset=utf8mb4';$store=new Store(new PDO($dsn,DB_USER,DB_PASSWORD),'pair_real_');$store->migrate();
$transport=new RealWpPairTransport();$vault=new Vault(str_repeat('w',32));$keys=sodium_crypto_sign_keypair();$policy=json_decode(file_get_contents(dirname(__DIR__).'/wp-ai-admin-cloud/config/plans.json'),true);
$core=new Core($store,$vault,$transport,$policy,new Signer(sodium_crypto_sign_secretkey($keys)));$pairing=new Pairing($core,$vault,$transport);$now=time();$state='';$code='';
$http_filter=function($pre,$args,$url)use(&$state,$pairing,&$now){if(str_starts_with($url,'https://wpaiadmin.ru/wp-json/wpai-cloud/v1/pairing/request?state=')){parse_str(parse_url($url,PHP_URL_QUERY),$query);$d=$pairing->descriptor($query['state'],$now);return ['headers'=>[],'body'=>wp_json_encode($d),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];}return $pre;};
add_filter('pre_http_request',$http_filter,10,3);
try {
 $cap=rest_do_request(new WP_REST_Request('GET','/okean-ai/v1/pairing/capabilities'));pair_expect($cap->get_status()===200&&$cap->get_data()['protocol']==='wpai-browser-pairing-v1','Native capabilities route missing');
 $manualHash=get_option('oai_mcp_token_hash','');$nativeClients=$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_MCP_OAuth::clients_table());
 $url=$pairing->start('1','https://wpaiadmin.ru','Real WP administrator',$now);parse_str(parse_url($url,PHP_URL_QUERY),$query);$state=$query['state'];$descriptor=OAI_Gateway_Pairing::request($state,$now);pair_expect(!is_wp_error($descriptor),'Trusted server descriptor rejected');
 pair_expect(!isset($descriptor['verifier'])&&$descriptor['callback']===Pairing::CALLBACK,'Secret/callback descriptor invalid');
 $nonce=wp_create_nonce('oai_gateway_pair_'.$state);pair_expect(wp_verify_nonce($nonce,'oai_gateway_pair_'.$state)!==false,'Administrator nonce failed');pair_expect(wp_verify_nonce($nonce,'oai_gateway_pair_other_state')===false,'Nonce was not request-bound');
 wp_set_current_user($subscriber);pair_expect(is_wp_error(OAI_Gateway_Pairing::issue_code($descriptor,$now)),'Subscriber could approve');wp_set_current_user(1);
 $code=OAI_Gateway_Pairing::issue_code($descriptor,$now);pair_expect(is_string($code)&&strlen($code)===43,'Native code was not issued');
 $bad=new WP_REST_Request('POST','/okean-ai/v1/pairing/exchange');$bad->set_header('content-type','application/json');$bad->set_body(wp_json_encode(['code'=>$code,'state'=>$state,'verifier'=>str_repeat('x',43),'callback'=>Pairing::CALLBACK]));pair_expect(rest_do_request($bad)->get_status()===401,'Incorrect verifier exchanged');
 pair_reject(fn()=>$pairing->complete((string)$subscriber,$state,$code,$now),'pairing_account_mismatch');
 $site=$pairing->complete('1',$state,$code,$now);$token=$transport->lastToken;pair_expect($site['home']==='https://wpaiadmin.ru'&&count($core->sites('1'))===1,'Real WordPress pairing did not register');
 pair_expect(get_option('oai_mcp_token_hash','')===$manualHash,'Pairing rotated old manual token');pair_expect((string)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_MCP_OAuth::clients_table())===(string)$nativeClients,'Pairing rewrote OAuth clients');
 $stored=get_option(OAI_Gateway_Pairing::CREDENTIAL_PREFIX.hash('sha256',$token));pair_expect(is_array($stored)&&!str_contains(wp_json_encode($stored),$token),'Native token stored plaintext');
 $row=$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$site['id']]);pair_expect(!str_contains($row['token_sealed'],$token),'Gateway token stored plaintext');
 $access=$core->mint('1',[$site['id']],['mcp:read','mcp:write'],$now);$actor=$core->authenticate($access['access_token'],$now);$reply=$core->call($actor,$site['id'],'wp.get_site',[],'real-wp-pair-read',$now);pair_expect($reply['state']==='succeeded','Real MCP read failed');
 $data=json_decode($reply['upstream']['result']['content'][0]['text'],true);pair_expect(str_starts_with($data['url'],'https://wpaiadmin.ru'),'MCP read selected wrong site');
 // Full central-gateway lifecycle on a disposable WordPress option: sandbox -> approve -> apply -> verify -> receipt/proof -> rollback -> verify baseline.
 update_option('thumbnail_size_w',150,false);
 OAI_AI_Lifecycle_Control::enable(1);
 $sandbox=$core->call($actor,$site['id'],'wp.option_update_sandbox',['key'=>'thumbnail_size_w','value'=>151],'release-lifecycle-sandbox',$now);
 pair_expect($sandbox['state']==='succeeded','Central sandbox creation failed: '.wp_json_encode($sandbox,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $sandboxData=json_decode($sandbox['upstream']['result']['content'][0]['text'],true);
 $opId=absint($sandboxData['id']??0);pair_expect($opId>0,'Central sandbox did not return operation ID');
 pair_expect((int)get_option('thumbnail_size_w')===150,'Sandbox wrote before approval');
 $approve=$core->call($actor,$site['id'],'operation.approve',['id'=>$opId],'release-lifecycle-approve',$now);
 pair_expect($approve['state']==='succeeded','Central approval failed');
 $wrongClient=OAI_AI_Lifecycle_Control::perform($opId,'apply','release-fixture-forged-client',1);
 pair_expect(is_wp_error($wrongClient)&&$wrongClient->get_error_code()==='oai_ai_lifecycle_client','Lifecycle client binding did not reject a second MCP client');
 pair_expect((int)get_option('thumbnail_size_w')===150,'Rejected second client changed state');
 $apply=$core->call($actor,$site['id'],'operation.apply',['id'=>$opId],'release-lifecycle-apply',$now);
 pair_expect($apply['state']==='succeeded'&&(int)get_option('thumbnail_size_w')===151,'Central apply did not persist expected value');
 $verify=$core->call($actor,$site['id'],'operation.verify',['id'=>$opId],'release-lifecycle-verify-apply',$now);
 $verifyData=json_decode($verify['upstream']['result']['content'][0]['text'],true);
 pair_expect($verify['state']==='succeeded'&&!empty($verifyData['verified']),'Central post-apply verification failed');
 $receipt=$core->call($actor,$site['id'],'operation.get_receipt',['id'=>$opId],'release-lifecycle-receipt',$now);
 $receiptData=json_decode($receipt['upstream']['result']['content'][0]['text'],true);
 pair_expect($receipt['state']==='succeeded'&&is_array($receiptData)&&!empty($receiptData),'Operation receipt missing');
 $proof=$core->call($actor,$site['id'],'operation.proof',['id'=>$opId],'release-lifecycle-proof',$now);
 $proofData=json_decode($proof['upstream']['result']['content'][0]['text'],true);
 pair_expect($proof['state']==='succeeded'&&is_array($proofData)&&!empty($proofData),'Operation proof missing');
 $rollback=$core->call($actor,$site['id'],'operation.rollback',['id'=>$opId],'release-lifecycle-rollback',$now);
 pair_expect($rollback['state']==='succeeded'&&(int)get_option('thumbnail_size_w')===150,'Central rollback did not restore baseline');
 $rollbackVerify=$core->call($actor,$site['id'],'operation.verify',['id'=>$opId],'release-lifecycle-verify-rollback',$now);
 $rollbackVerifyData=json_decode($rollbackVerify['upstream']['result']['content'][0]['text'],true);
 pair_expect($rollbackVerify['state']==='succeeded'&&!empty($rollbackVerifyData['verified']),'Central rollback verification failed');
 OAI_AI_Lifecycle_Control::disable(1);
 pair_reject(fn()=>$pairing->complete('1',$state,$code,$now),'pairing_request_expired');
 $req=new WP_REST_Request('POST','/okean-ai/mcp');$req->set_header('content-type','application/json');$req->set_body(wp_json_encode(['jsonrpc'=>'2.0','id'=>'apply-guard','method'=>'tools/call','params'=>['name'=>'operation.apply','arguments'=>['id'=>1]]]));$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
 OAI_AI_Lifecycle_Control::disable(1);OAI_Test_Automation::disable();$guard=rest_do_request($req)->get_data();pair_expect(($guard['error']['code']??0)===-32003,'Pairing bypassed apply approval');
 pair_expect(OAI_Gateway_Pairing::revoke(hash('sha256',$token))===true,'Native revoke failed');pair_expect(OAI_Gateway_Pairing::authenticate($token,$now)===false,'Revoked key still authenticated');
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;pair_expect(rest_do_request($req)->get_status()===401,'Revoked key passed actual MCP REST authentication');
 echo 'PASS '.$pair_checks.' real WordPress browser pairing checks; WP '.get_bloginfo('version').', native '.OAI_VERSION."\n";
}finally{
 remove_filter('pre_http_request',$http_filter,10);update_option('home',$old_home);update_option('siteurl',$old_siteurl);if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($subscriber);$_SERVER['HTTP_AUTHORIZATION']='';
}
