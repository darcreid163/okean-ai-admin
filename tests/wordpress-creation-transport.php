<?php
/**
 * #124: one logical creation request key must behave identically through the
 * Native REST route and the MCP adapter. A durable creation claim is created by
 * whichever transport arrives first; the other transport must return a
 * privacy-preserving 409 conflict, not a second artifact, operation or charge.
 * Central-gateway MCP calls must not write a second local quota ledger.
 *
 * Disposable WordPress/MySQL only. Synthetic subscription fixture; no live
 * billing, no production data.
 */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||(strpos(home_url(),'localhost')===false&&strpos(home_url(),'.test')===false))throw new RuntimeException('Disposable WordPress required');
final class CreationTransportFixture{public static int $checks=0;public static array $ops=[];public static array $posts=[];}
function ctpOk($ok,$why){++CreationTransportFixture::$checks;if(!$ok)throw new RuntimeException($why);}
final class CtpMcpRequest{private array $body;public function __construct(array $body){$this->body=$body;}public function get_json_params(){return $this->body;}}
function ctpMcp($token,$tool,array $args,$id){$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;return OAI_MCP::handle(new CtpMcpRequest(['jsonrpc'=>'2.0','id'=>$id,'method'=>'tools/call','params'=>['name'=>$tool,'arguments'=>$args]]))->get_data();}
function ctpRestPost($key,$title,$content){$r=new WP_REST_Request('POST','/okean-ai/v1/posts/create');foreach(['title'=>$title,'content'=>$content,'post_type'=>'post','final_status'=>'draft','idempotency_key'=>$key] as $k=>$v)$r->set_param($k,$v);return OAI_REST::post_create_draft($r);}
function ctpRestElementor($key,$title){$r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');$r->set_param('title',$title);$r->set_param('idempotency_key',$key);$r->set_param('data',[['id'=>'a1b2c3d','elType'=>'container','settings'=>[],'elements'=>[]]]);return OAI_REST::elementor_create_page($r);}
function ctpError($body,$status,$why){ctpOk(is_array($body)&&empty($body['result'])&&isset($body['error']),'MCP error expected: '.$why);ctpOk(($body['error']['code']??0)===-32000,'Unexpected MCP error code: '.$why);ctpOk(($body['error']['data']['status']??0)===$status,'Expected HTTP-like status '.$status.': '.$why);return $body['error'];}
function ctpSuccess($body){ctpOk(is_array($body)&&empty($body['error'])&&isset($body['result']['content'][0]['text']),'MCP success expected');$out=json_decode($body['result']['content'][0]['text'],true);ctpOk(is_array($out),'MCP success payload is not JSON');return $out;}
function ctpClaimCount($family){global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('oai_'.$family.'_creation_').'%'));}
function ctpTitleCount($title){global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title=%s AND post_status NOT IN ('trash','auto-draft')",$title));}
wp_set_current_user(1);
global $wpdb;
$licensePrior=get_option('oai_license',null);
$tokenPrior=get_option('oai_mcp_token_hash',null);
$tokenUserPrior=get_option('oai_mcp_token_user_id',null);
$enabledPrior=get_option('oai_mcp_enabled',null);
$pairOption=null;
try{
 // A synthetic active subscription keeps the local meter enabled. The
 // local-owner license deliberately switches on engineering access, which
 // bypasses local accounting and would make this test vacuous.
 OAI_License::set_local_license(['mode'=>OAI_License::MODE,'active'=>true,'plan'=>'free','expires_at'=>null,'features'=>OAI_License::LOCAL_FEATURES]);
 ctpOk(OAI_Quota::is_engineering_access()===false,'Synthetic subscription must not enable engineering access');
 $token=OAI_MCP::generate_token(1);
 ctpOk(is_string($token)&&$token!=='','Cannot create an MCP token');

 // --- 1. REST creates the durable claim; MCP cannot create a second one. ---
 $key='transport-post-'.bin2hex(random_bytes(8));
 $rest=ctpRestPost($key,'Transport parity post','transport body');
 ctpOk(is_array($rest)&&!empty($rest['id'])&&!empty($rest['post_id']),'REST post creation failed');
 CreationTransportFixture::$ops[]=(int)$rest['id'];
 CreationTransportFixture::$posts[]=(int)$rest['post_id'];
 $opsAfterRest=OAI_DB::count_all();
 $claimsAfterRest=ctpClaimCount('post');
 ctpOk($claimsAfterRest>=1,'REST did not create a post creation claim');
 $intent='oai_post_creation_'.hash('sha256',wp_json_encode(['post-create-v1',get_current_blog_id(),get_current_user_id(),$key]));
 $claim=get_option($intent,null);
 ctpOk(is_array($claim)&&!empty($claim['payload_fingerprint']),'REST claim record is missing its payload fingerprint');
 $titleCount=ctpTitleCount('Transport parity post');
 ctpOk($titleCount===1,'REST created an unexpected number of posts');
 $body=ctpMcp($token,'wordpress_create_draft',['title'=>'Transport parity post','content'=>'transport body','post_type'=>'post','final_status'=>'draft','idempotency_key'=>$key],'transport-rest-retry');
 $error=ctpError($body,409,'MCP retry of a REST post creation claim');
 ctpOk(array_keys($error['data'])===['status'],'MCP 409 disclosed claim/operation evidence');
 ctpOk(OAI_DB::count_all()===$opsAfterRest,'MCP retry created a second operation');
 ctpOk(ctpClaimCount('post')===$claimsAfterRest,'MCP retry created a second claim');
 ctpOk(ctpTitleCount('Transport parity post')===$titleCount,'MCP retry created a second post');
 // Changed payload under the same key is a conflict, not a new creation.
 $conflict=ctpError(ctpMcp($token,'wordpress_create_draft',['title'=>'Transport parity post','content'=>'CHANGED body','post_type'=>'post','final_status'=>'draft','idempotency_key'=>$key],'transport-rest-conflict'),409,'MCP changed-payload conflict');
 ctpOk(stripos((string)$conflict['message'],'reused')!==false,'Changed payload did not surface the intent conflict');
 ctpOk(OAI_DB::count_all()===$opsAfterRest,'Payload conflict created an operation');
 ctpOk(ctpTitleCount('Transport parity post')===$titleCount,'Payload conflict created a post');
 // A different key with the same payload is a new, allowed creation.
 $freshKey='transport-post-'.bin2hex(random_bytes(8));
 $fresh=ctpSuccess(ctpMcp($token,'wordpress_create_draft',['title'=>'Transport parity post','content'=>'transport body','post_type'=>'post','final_status'=>'draft','idempotency_key'=>$freshKey],'transport-fresh-key'));
 ctpOk(!empty($fresh['id'])&&!empty($fresh['post_id']),'A different key was rejected');
 CreationTransportFixture::$ops[]=(int)$fresh['id'];
 CreationTransportFixture::$posts[]=(int)$fresh['post_id'];
 ctpOk(OAI_DB::count_all()===$opsAfterRest+1,'A different key did not create exactly one operation');

 // --- 2. Elementor page claim: same parity when Elementor is available. ---
 if(class_exists('OAI_Elementor')&&OAI_Elementor::available()){
  $eKey='transport-elementor-'.bin2hex(random_bytes(8));
  $page=ctpRestElementor($eKey,'Transport Elementor parity');
  ctpOk(is_array($page)&&!empty($page['id']),'REST Elementor creation failed');
  CreationTransportFixture::$ops[]=(int)$page['id'];
  $opsAfterPage=OAI_DB::count_all();
  $pageClaims=ctpClaimCount('elementor_page');
  $pageError=ctpError(ctpMcp($token,'elementor_create_draft',['title'=>'Transport Elementor parity','idempotency_key'=>$eKey,'data'=>[['id'=>'a1b2c3d','elType'=>'container','settings'=>[],'elements'=>[]]]],'transport-elementor-retry'),409,'MCP retry of a REST Elementor page claim');
  ctpOk(stripos((string)$pageError['message'],'reconciliation')!==false,'Elementor retry did not surface the pending claim');
  ctpOk(OAI_DB::count_all()===$opsAfterPage,'Elementor retry created a second operation');
  ctpOk(ctpClaimCount('elementor_page')===$pageClaims,'Elementor retry created a second claim');
 }

 // --- 3. Central-gateway MCP must not write a local quota ledger. ---
 $pairToken='oai_pair_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
 $pairOption=OAI_Gateway_Pairing::CREDENTIAL_PREFIX.hash('sha256',$pairToken);
 ctpOk(add_option($pairOption,['user_id'=>1,'account_id'=>'124','account_label'=>'Transport fixture','site_url'=>untrailingslashit(home_url('/')),'created_at'=>time()],'',false),'Cannot create a paired credential');
 $usedBefore=OAI_Quota::status()['used'];
 $eventsBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::quota_events_table());
 $central=ctpSuccess(ctpMcp($pairToken,'wp_get_site',[],'central-read-1'));
 ctpOk(is_array($central),'Central-gateway read failed');
 ctpOk(OAI_Quota::status()['used']===$usedBefore,'Central-gateway request wrote local quota');
 ctpOk((int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::quota_events_table())===$eventsBefore,'Central-gateway request wrote a local quota event');
 $centralConflict=ctpError(ctpMcp($pairToken,'wordpress_create_draft',['title'=>'Transport parity post','content'=>'transport body','post_type'=>'post','final_status'=>'draft','idempotency_key'=>$key],'central-create-retry'),409,'Central retry of a pending creation');
 ctpOk(stripos((string)$centralConflict['message'],'reconciliation')!==false,'Central retry did not surface the pending claim');
 ctpOk(OAI_Quota::status()['used']===$usedBefore,'Central-gateway 409 wrote local quota');
 ctpOk((int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::quota_events_table())===$eventsBefore,'Central-gateway 409 wrote a local quota event');

 // --- 4. Direct (non-central) MCP: one charge per successful request. ---
 $local=ctpSuccess(ctpMcp($token,'wp_get_site',[],'local-read-1'));
 ctpOk(is_array($local),'Local MCP read failed');
 ctpOk(OAI_Quota::status()['used']===$usedBefore+1,'Local MCP read was not charged exactly once');
 $requestKey=OAI_Quota::request_key('mcp','local-read-1','','legacy_token');
 $event=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.OAI_DB::quota_events_table().' WHERE request_key=%s',$requestKey),ARRAY_A);
 ctpOk(is_array($event)&&$event['state']==='succeeded'&&(int)$event['units']===1&&$event['channel']==='mcp','Local MCP charge is not recorded in the local ledger');
 $usedAfterLocal=OAI_Quota::status()['used'];
 $replay=ctpSuccess(ctpMcp($token,'wp_get_site',[],'local-read-1'));
 ctpOk(!empty($replay['_oai_quota_replay'])&&($replay['charged_units']??null)===0,'Replay was not a zero-charge replay');
 ctpOk(OAI_Quota::status()['used']===$usedAfterLocal,'Replay double-charged');
 $conflictBody=ctpMcp($token,'wp_get_site',['changed'=>true],'local-read-1');
 ctpOk(is_array($conflictBody)&&isset($conflictBody['error'])&&($conflictBody['error']['data']['status']??0)===409,'Changed arguments under the same request id did not conflict');
 ctpOk(OAI_Quota::status()['used']===$usedAfterLocal,'Idempotency conflict changed local quota');
 // A failing tool settles as failed: no charge, no refund of the earlier unit.
 $failBody=ctpMcp($token,'content_patch',['post_id'=>999999999,'field'=>'post_content','old_content'=>'a','new_content'=>'b'],'local-fail-1');
 ctpOk(is_array($failBody)&&isset($failBody['error']),'Failing tool did not return an MCP error');
 ctpOk(OAI_Quota::status()['used']===$usedAfterLocal,'Failed tool was charged');

 echo 'PASS: '.CreationTransportFixture::$checks." creation transport checks: REST->MCP claim parity (post + Elementor), changed-payload conflict, privacy-preserving 409, central-gateway zero local ledger, local replay/conflict/failure accounting\n";
}finally{
 unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
 if($pairOption!==null)delete_option($pairOption);
 if($licensePrior===null)delete_option('oai_license');else update_option('oai_license',$licensePrior,false);
 if($tokenPrior===null)delete_option('oai_mcp_token_hash');else update_option('oai_mcp_token_hash',$tokenPrior,false);
 if($tokenUserPrior===null)delete_option('oai_mcp_token_user_id');else update_option('oai_mcp_token_user_id',$tokenUserPrior,false);
 if($enabledPrior===null)delete_option('oai_mcp_enabled');else update_option('oai_mcp_enabled',$enabledPrior,false);
}
