<?php
/** Real disposable WordPress/MySQL read-only creation claim inventory. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||
   !in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class ClaimAuditFixture { public static int $checks=0; }
function claimCheck($condition,$label){++ClaimAuditFixture::$checks;if(!$condition)throw new RuntimeException($label);}
$names=array();$workspaceFixtures=array();$original=get_current_user_id();$fixtureOperation=0;$secret='synthetic-test-secret-never-expose-7e8c';
$families=OAI_Creation_Claim_Audit::FAMILIES;
try{
 wp_set_current_user(1);
 claimCheck(current_user_can('manage_options'),'Admin permission missing');
 global $wpdb;
 claimCheck($wpdb->insert(OAI_DB::table(),array('operation_id'=>'CREATION-AUDIT-'.bin2hex(random_bytes(8)),
  'type'=>'media_upload','status'=>'pending','target'=>'synthetic',
  'created_at'=>current_time('mysql',true)))===1,'Cannot seed synthetic ledger operation');
 $fixtureOperation=(int)$wpdb->insert_id;
 claimCheck($fixtureOperation>0,'Synthetic operation ID missing');
 foreach($families as $family=>$prefix){
  $id=hash('sha256','claims-ci-'.$family.'-'.wp_generate_uuid4());
  $name=$prefix.$id;
  $record=array('state'=>'reserved','created_at'=>time()-2*DAY_IN_SECONDS,
   'claim'=>$secret,'operation_id'=>$family==='media'||$family==='menu'?$fixtureOperation:12345,'id'=>'ws-synthetic-private');
  claimCheck(add_option($name,$family==='media'?wp_json_encode($record):$record,'',false),'Cannot seed valid '.$family);
  $names[]=$name;
  $bad=$prefix.hash('sha256','claims-ci-bad-'.$family.'-'.wp_generate_uuid4());
  claimCheck(add_option($bad,'not-a-claim-'.$secret,'',false),'Cannot seed opaque '.$family);
  $names[]=$bad;
 }
 // Synthetic workspace evidence: an existing metadata file, absent
 // directory, symlink and malicious path string. All are disposable.
 $upload=wp_upload_dir(null,false);
 $root=trailingslashit($upload['basedir']).'ai-admin-workspaces';
 claimCheck(is_dir($root)||wp_mkdir_p($root),'Cannot initialize disposable workspace root');
 $realId='ws-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
 $realFolder=$root.'/'.$realId;
 claimCheck(wp_mkdir_p($realFolder),'Could not create disposable workspace fixture');
 $workspaceFixtures[]=array('directory',$realFolder);
 claimCheck(file_put_contents($realFolder.'/workspace.json',wp_json_encode(array('secret'=>$secret)))!==false,'Could not seed private workspace metadata');
 $missingId='ws-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
 $linkId='ws-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
 $linkFolder=$root.'/'.$linkId;
 claimCheck(symlink($realFolder,$linkFolder),'Cannot create synthetic workspace symlink');
 $workspaceFixtures[]=array('symlink',$linkFolder);
 foreach(array($realId,$missingId,$linkId,'../../wp-config.php') as $i=>$id){
  $name='oai_workspace_creation_'.hash('sha256','audit-workspace-footprint-'.$i.'-'.wp_generate_uuid4());
  claimCheck(add_option($name,array('id'=>$id,'state'=>'reserved','created_at'=>time(),'claim'=>$secret),'',false),'Could not seed workspace footprint');
  $names[]=$name;
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
  if($family==='media')claimCheck($row['referenced_operation_same_type']>=1,'Real media ledger link was missed');
  if($family==='menu')claimCheck($row['referenced_operation_other_type']>=1,'Wrong-type ledger link was accepted');
  if($family==='product')claimCheck($row['referenced_operation_missing']>=1,'Missing operation was accepted');
 }
 $workspace=$full['families']['workspace'];
 claimCheck($workspace['workspace_directory_present']>=1,'Safe existing workspace not detected');
 claimCheck($workspace['workspace_metadata_present']>=1,'Workspace metadata presence not detected');
 claimCheck($workspace['workspace_directory_absent']>=1,'Missing workspace directory not detected');
 claimCheck($workspace['workspace_symlink_rejected']>=1,'Workspace symlink was followed');
 claimCheck($workspace['workspace_id_unverifiable']>=1,'Unsafe workspace ID was accepted');
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
 if($fixtureOperation>0)$wpdb->delete(OAI_DB::table(),array('id'=>$fixtureOperation));
 foreach(array_reverse($workspaceFixtures) as $fixture){
  if($fixture[0]==='symlink'&&is_link($fixture[1]))unlink($fixture[1]);
  if($fixture[0]==='directory'&&is_dir($fixture[1])){
   if(is_file($fixture[1].'/workspace.json'))unlink($fixture[1].'/workspace.json');
   rmdir($fixture[1]);
  }
 }
 wp_set_current_user($original);
}
