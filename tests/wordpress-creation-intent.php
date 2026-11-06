<?php
/** Disposable real WordPress/MySQL canonical creation-intent acceptance (#122). */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class CreationIntentChecks { public static int $count=0; }
function ciAssert($value,$message){++CreationIntentChecks::$count;if(!$value)throw new RuntimeException($message);}
function ciPages(){global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page'");}
final class CreationIntentMcpRequest {
 private $body;
 public function __construct($body){$this->body=$body;}
 public function get_json_params(){return $this->body;}
}
function ciRestAliasCreate($title,array $data){
 $request=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');
 $request->set_param('title_b64',base64_encode($title));
 $request->set_param('data_b64',base64_encode(wp_json_encode($data)));
 return OAI_REST::elementor_create_page($request);
}
function ciMcpCreate($token,$title,array $data){
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
 $body=array('jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>array(
  'name'=>'elementor.create_draft','arguments'=>array('title'=>$title,'data'=>$data)
 ));
 return OAI_MCP::handle(new CreationIntentMcpRequest($body));
}
function ciMcpData($response){
 ciAssert($response instanceof WP_REST_Response,'MCP response is not a REST response');
 $data=$response->get_data();
 ciAssert(is_array($data),'MCP response has no JSON body');
 return $data;
}
function ciMcpError($response){
 $data=ciMcpData($response);
 ciAssert(isset($data['error'])&&is_array($data['error']),'MCP request unexpectedly succeeded: '.substr(wp_json_encode($data),0,300));
 return $data['error'];
}
function ciMcpResult($response){
 $data=ciMcpData($response);
 ciAssert(isset($data['result']['content'][0]['text']),'MCP result is missing: '.substr(wp_json_encode($data),0,300));
 $out=json_decode((string)$data['result']['content'][0]['text'],true);
 ciAssert(is_array($out),'MCP result is not a JSON object');
 return $out;
}
wp_set_current_user(1);
ciAssert(current_user_can('manage_options'),'Disposable administrator required');
$license=OAI_License::activate_local_owner(1);
ciAssert(!is_wp_error($license),'Disposable license activation failed');
ciAssert(OAI_Elementor::available()&&current_user_can('edit_pages'),'Elementor/page edit support unavailable');
ciAssert(class_exists('OAI_Creation_Intent'),'Canonical creation intent contract is not loaded');

// Contract: associative order, Unicode and nesting are normalized; list order
// is semantic and must remain a different intent.
$ordered=array('b'=>array('y'=>2,'x'=>1),'a'=>array('list'=>array(array('q'=>2,'p'=>1)),'flag'=>true));
$permuted=array('a'=>array('flag'=>true,'list'=>array(array('p'=>1,'q'=>2))),'b'=>array('x'=>1,'y'=>2));
$first=OAI_Creation_Intent::fingerprint($ordered);
$second=OAI_Creation_Intent::fingerprint($permuted);
ciAssert(is_string($first)&&strlen($first)===64,'Canonical fingerprint is missing');
ciAssert($first===$second,'Map key order changed the canonical fingerprint');
ciAssert(OAI_Creation_Intent::fingerprint(array(array('a'=>1),array('b'=>2)))
 ===OAI_Creation_Intent::fingerprint(array(array('a'=>1),array('b'=>2))),'Stable list payload changed');
ciAssert(OAI_Creation_Intent::fingerprint(array(array('a'=>1),array('b'=>2)))
 !==OAI_Creation_Intent::fingerprint(array(array('b'=>2),array('a'=>1))),'List order was collapsed');
ciAssert(OAI_Creation_Intent::fingerprint(array('title'=>'Привет'))===OAI_Creation_Intent::fingerprint(array('title'=>'Привет')),'Unicode payload is unstable');
ciAssert(OAI_Creation_Intent::classify(OAI_Creation_Intent::record($first),$second)==='same','Canonical equal payload was not classified as same');
ciAssert(OAI_Creation_Intent::classify(OAI_Creation_Intent::record($first),OAI_Creation_Intent::fingerprint(array('other'=>1)))==='conflict','Canonical changed payload was not classified as conflict');
ciAssert(OAI_Creation_Intent::classify(array('payload_hash'=>$first),$first)==='legacy_unverifiable','A legacy hash was guessed comparable');
ciAssert(OAI_Creation_Intent::classify(array('payload_scheme'=>OAI_Creation_Intent::SCHEME,'payload_fingerprint'=>'bad'),$first)==='legacy_unverifiable','Malformed canonical record was guessed comparable');
ciAssert(OAI_Creation_Intent::classify('opaque',$first)==='unreadable','Opaque storage was treated as an intent');

$startPages=ciPages();$startOps=OAI_DB::count_all();
$title='WP AI Canonical Intent Page';
$data=array(array('id'=>'a1b2c3d','elType'=>'container','settings'=>array('padding'=>array('top'=>'10','unit'=>'px')),'elements'=>array()));

// Entry point 1: REST with base64 aliases.
$rest=ciRestAliasCreate($title,$data);
ciAssert(!is_wp_error($rest),'REST alias create failed: '.(is_wp_error($rest)?$rest->get_error_code():'unknown'));
ciAssert(ciPages()===$startPages+1&&OAI_DB::count_all()===$startOps+1,'REST alias create was not persisted');

// Entry point 2: MCP with plain aliases and the identical normalized payload.
$token=OAI_MCP::generate_token(1);
ciAssert(is_string($token)&&$token!=='','Cannot issue a disposable MCP token');
$duplicate=ciMcpError(ciMcpCreate($token,$title,$data));
ciAssert((int)($duplicate['data']['status']??0)===409,'MCP duplicate status is not 409: '.substr(wp_json_encode($duplicate),0,300));
ciAssert(stripos((string)($duplicate['message']??''),'reconciliation')!==false,'MCP duplicate is not a held intent');
ciAssert(ciPages()===$startPages+1&&OAI_DB::count_all()===$startOps+1,'MCP duplicate created a page or operation');

// A deliberately different payload is a new intent for the same actor.
$different=ciMcpResult(ciMcpCreate($token,'WP AI Canonical Intent Page Different',$data));
ciAssert(isset($different['id'])&&(int)$different['id']>0,'Different payload was not admitted: '.substr(wp_json_encode($different),0,300));
ciAssert(ciPages()===$startPages+2&&OAI_DB::count_all()===$startOps+2,'Different payload did not create exactly one page');

// Actor is part of the intent identity; the same payload is independent for
// another authorized owner and must not be blocked by the first owner's claim.
$other=wp_create_user('creation-intent-other','ci-local-only-password','creation-intent-other@example.invalid');
ciAssert(!is_wp_error($other),'Cannot provision a second creation actor');
(new WP_User((int)$other))->set_role('administrator');
$otherToken=OAI_MCP::generate_token((int)$other);
ciAssert(is_string($otherToken)&&$otherToken!=='','Cannot issue an alternate-actor MCP token');
$crossActor=ciMcpResult(ciMcpCreate($otherToken,$title,$data));
ciAssert(isset($crossActor['id'])&&(int)$crossActor['id']>0,'Another actor was blocked by the first owner claim');
ciAssert(ciPages()===$startPages+3&&OAI_DB::count_all()===$startOps+3,'Actor identity is not part of the intent');

wp_set_current_user(1);
unset($_SERVER['HTTP_AUTHORIZATION']);
echo 'PASS canonical creation intent: '.CreationIntentChecks::$count." checks, REST/MCP aliases, actor isolation\n";
