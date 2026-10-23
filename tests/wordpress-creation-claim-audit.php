<?php
/** Real disposable WordPress/MySQL read-only creation claim inventory. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||
   !in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class ClaimAuditFixture { public static int $checks=0; }
function claimCheck($condition,$label){++ClaimAuditFixture::$checks;if(!$condition)throw new RuntimeException($label);}
$names=array();$original=get_current_user_id();$secret='synthetic-test-secret-never-expose-7e8c';
$families=OAI_Creation_Claim_Audit::FAMILIES;
try{
 wp_set_current_user(1);
 claimCheck(current_user_can('manage_options'),'Admin permission missing');
 foreach($families as $family=>$prefix){
  $id=hash('sha256','claims-ci-'.$family.'-'.wp_generate_uuid4());
  $name=$prefix.$id;
  $record=array('state'=>'reserved','created_at'=>time()-2*DAY_IN_SECONDS,
   'claim'=>$secret,'operation_id'=>12345,'id'=>'ws-synthetic-private');
  claimCheck(add_option($name,$family==='media'?wp_json_encode($record):$record,'',false),'Cannot seed valid '.$family);
  $names[]=$name;
  $bad=$prefix.hash('sha256','claims-ci-bad-'.$family.'-'.wp_generate_uuid4());
  claimCheck(add_option($bad,'not-a-claim-'.$secret,'',false),'Cannot seed opaque '.$family);
  $names[]=$bad;
 }
 $empty=new WP_REST_Request('GET','/okean-ai/v1/diagnostics/creation-intents');
 $full=OAI_Creation_Claim_Audit::summary($empty);
 claimCheck(!is_wp_error($full)&&$full['ok']===true&&$full['read_only']===true,'Cannot read admin-only claim inventory');
 claimCheck($full['schema']==='oai/creation-claims-summary/v1'&&$full['reconciliation']==='not_performed','Report inaccurately claims reconciliation');
 foreach(array_keys($families) as $family){
  $row=$full['families'][$family];
  claimCheck($row['sampled']>=2&&!$row['truncated'],'No bounded claim inventory for '.$family);
  claimCheck($row['declared_reserved']>=1&&$row['unreadable_metadata']>=1,'Valid/opaque claim classification wrong '.$family);
  claimCheck($row['with_operation_reference']>=1&&$row['older_than_24h']>=1,'Claim evidence/time counters incorrect '.$family);
 }
 $json=wp_json_encode($full);
 claimCheck(strpos($json,$secret)===false&&strpos($json,'ws-synthetic-private')===false&&
   strpos($json,'12345')===false,'Raw claim data disclosed in report');
 foreach(array('0','101','abc','-1') as $invalid){
  $badRequest=new WP_REST_Request('GET','/okean-ai/v1/diagnostics/creation-intents');
  $badRequest->set_param('limit',$invalid);
  $badResult=OAI_Creation_Claim_Audit::summary($badRequest);
  claimCheck(is_wp_error($badResult)&&$badResult->get_error_code()==='oai_creation_audit_limit','Invalid page limit accepted');
 }
 $bounded=new WP_REST_Request('GET','/okean-ai/v1/diagnostics/creation-intents');
 $bounded->set_param('limit','1');
 $small=OAI_Creation_Claim_Audit::summary($bounded);
 claimCheck(!is_wp_error($small)&&$small['complete']===false,'Truncated samples not marked incomplete');
 foreach($small['families'] as $item)claimCheck($item['sampled']===1&&$item['truncated']===true,'Per-family sample bound failed');
 // Exercise actual REST route rather than invoking the callback exclusively.
 $routeResult=rest_do_request($empty);
 claimCheck($routeResult instanceof WP_REST_Response && $routeResult->get_status()===200,'Admin REST route not accessible');
 claimCheck(strpos(wp_json_encode($routeResult->get_data()),$secret)===false,'REST output leaked secret');
 wp_set_current_user(0);
 $forbidden=OAI_Creation_Claim_Audit::summary($empty);
 claimCheck(is_wp_error($forbidden)&&$forbidden->get_error_code()==='oai_creation_audit_forbidden','Anonymous direct inventory allowed');
 $routeDenied=rest_do_request($empty);
 claimCheck($routeDenied instanceof WP_REST_Response && $routeDenied->get_status()>=400,'Anonymous REST inventory allowed');
 echo 'PASS creation claim summary: '.ClaimAuditFixture::$checks." checks, 6 families, fixed limits, private content, admin REST denial\n";
}finally{
 wp_set_current_user(1);
 foreach($names as $name)delete_option($name);
 wp_set_current_user($original);
}
