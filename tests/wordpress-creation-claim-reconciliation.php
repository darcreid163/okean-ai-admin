<?php
/**
 * Disposable WordPress/MySQL: explicit operator reconciliation and retention
 * for durable creation claims (issue #123).
 *
 * This fixture proves that the only claim mutation path requires an
 * authenticated administrator, the exact current claim generation, a verified
 * artifact/operation link (or an explicitly confirmed absence) and operator
 * evidence. It never releases a claim by age, a missing reply or a single
 * failed lookup, never deletes a claim and never edits an artifact.
 */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||
   !in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true))
 throw new RuntimeException('Disposable localhost WordPress required');

final class ClaimReconcileChecks { public static int $checks=0; }
function rcCheck($condition,$label){++ClaimReconcileChecks::$checks;if(!$condition)throw new RuntimeException($label);}
function rcError($result,$code,$label){
 rcCheck(is_wp_error($result),$label.' (expected WP_Error)');
 rcCheck($result->get_error_code()===$code,$label.' (got '.$result->get_error_code().')');
}
function rcOptionRaw($name){
 global $wpdb;
 return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
}
function rcOptionRecord($name){
 $raw=rcOptionRaw($name);
 if(!is_string($raw))return null;
 $json=json_decode($raw,true,32);
 if(json_last_error()===JSON_ERROR_NONE&&is_array($json))return $json;
 $value=maybe_unserialize($raw);
 return is_array($value)?$value:null;
}
function rcAuditRows($claimId){
 global $wpdb;
 return $wpdb->get_results($wpdb->prepare(
  'SELECT * FROM '.OAI_DB::audit_table().' WHERE event=%s AND details LIKE %s ORDER BY id ASC',
  'creation_claim_reconciled','%'.$claimId.'%'
 ),ARRAY_A);
}
function rcPostState($id){
 $post=get_post($id);
 return $post?array($post->post_type,$post->post_status,$post->post_title,$post->post_content,$post->post_modified_gmt):null;
}
function rcArtifactState($family,$id){
 if($family==='workspace'){
  $uploads=wp_upload_dir(null,false);
  $file=trailingslashit($uploads['basedir']).'ai-admin-workspaces/'.$id.'/workspace.json';
  return is_file($file)?hash_file('sha256',$file):null;
 }
 if($family==='menu'){
  $menu=wp_get_nav_menu_object($id);
  return $menu?array((int)$menu->term_id,$menu->name,$menu->slug):null;
 }
 return rcPostState($id);
}
function rcRemoveTree($dir){
 if(!is_dir($dir))return;
 foreach(scandir($dir) as $entry){
  if($entry==='.'||$entry==='..')continue;
  $path=$dir.'/'.$entry;
  if(is_dir($path)&&!is_link($path))rcRemoveTree($path);
  else @unlink($path);
 }
 @rmdir($dir);
}
function rcSeedOperation($type,$target,$meta=array()){
 global $wpdb;
 $inserted=$wpdb->insert(OAI_DB::table(),array(
  'operation_id'=>'RECONCILE-'.strtoupper(bin2hex(random_bytes(8))),
  'status'=>'pending','type'=>$type,'target'=>(string)$target,
  'after_data'=>wp_json_encode(array('fixture'=>true)),
  'meta_data'=>wp_json_encode($meta),
  'risk'=>'low','created_at'=>current_time('mysql',true),
  'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),
 ));
 if($inserted!==1)throw new RuntimeException('Cannot seed '.$type.' operation');
 return (int)$wpdb->insert_id;
}
function rcSeedClaim($family,$fields=array(),$json=false,$dropState=false){
 $cfg=OAI_Creation_Claim_Reconciler::FAMILIES[$family];
 $claimId=hash('sha256','reconcile-'.$family.'-'.wp_generate_uuid4());
 $name=$cfg['prefix'].$claimId;
 $record=array_merge(array(
  'actor'=>1,'created_at'=>time()-YEAR_IN_SECONDS,'state'=>'reserved','claim'=>bin2hex(random_bytes(16)),
 ),$fields);
 if($dropState)unset($record['state']);
 $fingerprint=OAI_Creation_Intent::fingerprint(array('fixture'=>$family,$claimId));
 $record=OAI_Creation_Intent::record($fingerprint,$record);
 $value=$json?wp_json_encode($record):$record;
 if(!add_option($name,$value,'',false))throw new RuntimeException('Cannot seed '.$family.' claim');
 return array(
  'family'=>$family,'claim_id'=>$claimId,'name'=>$name,'token'=>$record['claim']??null,
  'fingerprint'=>$fingerprint,'record'=>$record,
 );
}
function rcReconcile(array $payload){
 return OAI_Creation_Claim_Reconciler::reconcile($payload);
}
function rcRestReconcile(array $payload){
 $request=new WP_REST_Request('POST','/okean-ai/v1/diagnostics/creation-intents/reconcile');
 $request->set_header('Content-Type','application/json');
 $request->set_body(wp_json_encode($payload));
 return rest_do_request($request);
}
function rcRestStatus($response){
 if($response instanceof WP_REST_Response)return $response->get_status();
 if(is_wp_error($response)){
  $data=$response->get_error_data();
  return is_array($data)&&isset($data['status'])?(int)$data['status']:500;
 }
 return 0;
}

$claims=array();$claimNames=array();$operationIds=array();$postIds=array();$menuIds=array();
$workspaceDirs=array();$subscriberId=0;$original=get_current_user_id();
$evidenceLinked='Operator verified the created artifact and its operation, proof and receipt linkage.';
$evidenceAbsent='Operator verified through independent lookups and database inspection that the artifact was never created.';
$expectedStrength=array(
 'media'=>'operation_intent_claim',
 'workspace'=>'workspace_directory_metadata',
 'menu'=>'operation_type_target',
 'menu_item'=>'operation_type_target',
 'product'=>'operation_type_target',
 'elementor_page'=>'operation_type_target',
);

try{
 wp_set_current_user(1);
 rcCheck(current_user_can('manage_options'),'Fixture needs administrator');
 global $wpdb;

 // Retention is policy metadata only: nothing may be released or deleted.
 $retention=OAI_Creation_Claim_Reconciler::retention_policy();
 rcCheck(($retention['policy_version']??'')===OAI_Creation_Claim_Reconciler::RETENTION_SCHEMA,'Retention schema mismatch');
 rcCheck(($retention['unresolved']??'')==='held_indefinitely','Unresolved claims are not held indefinitely');
 rcCheck(($retention['reconciled_created']??'')==='retained_with_linked_evidence','Created claims are not retained with evidence');
 rcCheck(($retention['reconciled_absent']??'')==='retained_for_audit','Absent claims are not retained for audit');
 rcCheck(($retention['automatic_deletion']??true)===false,'Automatic claim deletion is enabled');
 rcCheck(($retention['age_or_missing_reply_releases']??true)===false,'Age or missing reply releases a claim');
 rcCheck(($retention['sandbox_snapshot_expiry_releases']??true)===false,'Sandbox expiry releases a claim');
 rcCheck(($retention['operator_purge_available']??true)===false,'Operator purge is offered');

 // One real artifact per family, all synthetic and disposable.
 $attachmentId=wp_insert_post(array(
  'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Reconcile fixture attachment',
  'post_mime_type'=>'image/png',
 ),true);
 rcCheck(!is_wp_error($attachmentId)&&$attachmentId>0,'Cannot seed attachment');
 $postIds[]=(int)$attachmentId;
 $media=rcSeedClaim('media',array(
  'v'=>1,'operation_id'=>0,'attachment_id'=>(int)$attachmentId,
  'source_name'=>'fixture.png','content_sha256'=>str_repeat('a',64),
 ),true,true);
 $mediaOp=rcSeedOperation('media_upload',(string)$attachmentId,array(
  '_wpai_creation_intent'=>$media['fingerprint'],
  '_wpai_creation_claim'=>hash('sha256',$media['token']),
 ));
 $operationIds[]=$mediaOp;
 $media['artifact_id']=(int)$attachmentId;$media['operation_id']=$mediaOp;
 $claims['media']=$media;$claimNames[]=$media['name'];

 $upload=wp_upload_dir(null,false);
 $root=trailingslashit($upload['basedir']).'ai-admin-workspaces';
 rcCheck(is_dir($root)||wp_mkdir_p($root),'Cannot initialize workspace root');
 $workspaceId='ws-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
 $workspaceDir=$root.'/'.$workspaceId;
 rcCheck(wp_mkdir_p($workspaceDir),'Cannot seed workspace directory');
 $workspaceDirs[]=$workspaceDir;
 rcCheck(file_put_contents($workspaceDir.'/workspace.json',wp_json_encode(array('id'=>$workspaceId,'fixture'=>true)))!==false,'Cannot seed workspace metadata');
 $workspace=rcSeedClaim('workspace',array('id'=>$workspaceId,'claim'=>null));
 $workspace['artifact_id']=$workspaceId;$workspace['operation_id']=0;
 $claims['workspace']=$workspace;$claimNames[]=$workspace['name'];

 $menuId=wp_create_nav_menu('Reconcile fixture menu '.wp_generate_password(6,false,false));
 rcCheck(is_int($menuId)&&$menuId>0,'Cannot seed menu');
 $menuIds[]=$menuId;
 $menu=rcSeedClaim('menu');
 $menuOp=rcSeedOperation('menu_create',(string)$menuId,array('menu_id'=>$menuId,'sandbox_artifact'=>true));
 $operationIds[]=$menuOp;
 $menu['artifact_id']=$menuId;$menu['operation_id']=$menuOp;
 $claims['menu']=$menu;$claimNames[]=$menu['name'];

 $itemId=wp_update_nav_menu_item($menuId,0,array(
  'menu-item-title'=>'Reconcile fixture item','menu-item-type'=>'custom',
  'menu-item-url'=>'https://example.invalid/reconcile','menu-item-status'=>'publish',
 ));
 rcCheck(is_int($itemId)&&$itemId>0,'Cannot seed menu item');
 $postIds[]=$itemId;
 $menuItem=rcSeedClaim('menu_item');
 $menuItemOp=rcSeedOperation('menu_add_item',(string)$itemId,array('menu_id'=>$menuId,'item_id'=>$itemId,'sandbox_artifact'=>true));
 $operationIds[]=$menuItemOp;
 $menuItem['artifact_id']=$itemId;$menuItem['operation_id']=$menuItemOp;
 $claims['menu_item']=$menuItem;$claimNames[]=$menuItem['name'];

 if(class_exists('WC_Product_Simple')){
  $product=new WC_Product_Simple();
  $product->set_name('Reconcile fixture product');
  $product->set_status('draft');
  $productId=(int)$product->save();
 }else{
  $productId=(int)wp_insert_post(array('post_type'=>'product','post_status'=>'draft','post_title'=>'Reconcile fixture product'));
 }
 rcCheck($productId>0,'Cannot seed product');
 $postIds[]=$productId;
 $productClaim=rcSeedClaim('product');
 $productOp=rcSeedOperation('woocommerce_product_create',(string)$productId,array('product_id'=>$productId,'sandbox_artifact'=>true));
 $operationIds[]=$productOp;
 $productClaim['artifact_id']=$productId;$productClaim['operation_id']=$productOp;
 $claims['product']=$productClaim;$claimNames[]=$productClaim['name'];

 $pageId=(int)wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'Reconcile fixture Elementor page'));
 rcCheck($pageId>0,'Cannot seed page');
 $postIds[]=$pageId;
 rcCheck(update_post_meta($pageId,'_elementor_data','[]')!==false,'Cannot seed Elementor metadata');
 $elementor=rcSeedClaim('elementor_page');
 $elementorOp=rcSeedOperation('elementor_page_create','elementor_page:'.$pageId,array('post_id'=>$pageId,'sandbox_artifact'=>true));
 $operationIds[]=$elementorOp;
 $elementor['artifact_id']=$pageId;$elementor['operation_id']=$elementorOp;
 $claims['elementor_page']=$elementor;$claimNames[]=$elementor['name'];

 $operationCount=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table());
 $postCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
 $termCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}");
 $optionCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

 // Linked reconciliation for all six families: claim -> artifact ->
 // operation -> lifecycle evidence, with no artifact mutation.
 foreach($claims as $family=>$claim){
  $before=rcArtifactState($family,$claim['artifact_id']);
  $payload=array(
   'family'=>$family,'claim_id'=>$claim['claim_id'],'decision'=>'linked',
   'expected'=>$claim['token']?:$claim['fingerprint'],
   'artifact_id'=>$claim['artifact_id'],'operation_id'=>$claim['operation_id'],
   'evidence_note'=>$evidenceLinked,
  );
  $result=rcReconcile($payload);
  rcCheck(is_array($result),'Linked reconciliation failed for '.$family.': '.(is_wp_error($result)?$result->get_error_code():'not an array'));
  rcCheck(($result['ok']??false)===true,'Linked result not ok for '.$family);
  rcCheck(($result['schema']??'')===OAI_Creation_Claim_Reconciler::SCHEMA,'Result schema mismatch for '.$family);
  rcCheck(($result['read_only']??true)===false,'Reconciliation reported read-only for '.$family);
  rcCheck(($result['claim_deleted']??true)===false,'Reconciliation reported claim deletion for '.$family);
  rcCheck(($result['artifacts_modified']??true)===false,'Reconciliation reported artifact mutation for '.$family);
  rcCheck(($result['replayed']??true)===false,'First reconciliation reported replay for '.$family);
  rcCheck(($result['state']??'')==='reconciled_created','Wrong state for '.$family);
  rcCheck(($result['decision']??'')==='linked','Wrong decision for '.$family);
  rcCheck(($result['artifact_id']??'')===(string)$claim['artifact_id'],'Wrong artifact id for '.$family);
  rcCheck((int)($result['operation_id']??-1)===$claim['operation_id'],'Wrong operation id for '.$family);
  rcCheck(($result['link_strength']??'')===$expectedStrength[$family],'Wrong link strength for '.$family.' ('.($result['link_strength']??'').')');
  rcCheck((int)($result['reconciled_by']??0)===1,'Wrong operator for '.$family);
  rcCheck(($result['retention']??array())===$retention,'Result retention policy mismatch for '.$family);
  rcCheck(array_key_exists('operation_status',$result['lifecycle'])
   &&$result['lifecycle']['operation_status']===($family==='workspace'?null:'pending'),'Lifecycle status mismatch for '.$family);
  rcCheck(($result['lifecycle']['has_proof']??true)===false,'Proof fabricated for '.$family);
  rcCheck(($result['lifecycle']['has_receipt']??true)===false,'Receipt fabricated for '.$family);
  rcCheck(($result['lifecycle']['verified']??true)===false,'Unverified operation reported verified for '.$family);
  if($claim['token']!==null)
   rcCheck(strpos(wp_json_encode($result),$claim['token'])===false,'Claim token disclosed in result for '.$family);

  $stored=rcOptionRecord($claim['name']);
  rcCheck(is_array($stored),'Claim disappeared after '.$family);
  rcCheck(($stored['state']??'')==='reconciled_created','Stored state mismatch for '.$family);
  rcCheck(($stored['reconciliation']['schema']??'')===OAI_Creation_Claim_Reconciler::SCHEMA,'Stored schema missing for '.$family);
  rcCheck(($stored['reconciliation']['decision']??'')==='linked','Stored decision missing for '.$family);
  rcCheck(($stored['reconciliation']['artifact_type']??'')===OAI_Creation_Claim_Reconciler::FAMILIES[$family]['artifact'],'Stored artifact type mismatch for '.$family);
  rcCheck(($stored['reconciliation']['artifact_id']??'')===(string)$claim['artifact_id'],'Stored artifact id mismatch for '.$family);
  rcCheck((int)($stored['reconciliation']['operation_id']??-1)===$claim['operation_id'],'Stored operation id mismatch for '.$family);
  rcCheck(($stored['reconciliation_evidence']['note']??'')===$evidenceLinked,'Evidence note not retained for '.$family);
  rcCheck(($stored['reconciliation_evidence']['sha256']??'')===hash('sha256',$evidenceLinked),'Evidence hash mismatch for '.$family);
  rcCheck((int)($stored['reconciled_by']??0)===1,'Stored operator mismatch for '.$family);
  rcCheck(($stored['retention']['hold']??'')==='indefinite','Stored retention hold missing for '.$family);
  rcCheck(($stored['retention']['automatic_deletion']??true)===false,'Stored automatic deletion enabled for '.$family);
  rcCheck(rcArtifactState($family,$claim['artifact_id'])===$before,'Artifact changed by '.$family.' reconciliation');

  $audit=rcAuditRows($claim['claim_id']);
  rcCheck(count($audit)===1,'Expected one audit event for '.$family.'; got '.count($audit));
  $details=json_decode((string)$audit[0]['details'],true);
  rcCheck(is_array($details)&&($details['family']??'')===$family,'Audit family mismatch for '.$family);
  rcCheck(($details['decision']??'')==='linked','Audit decision mismatch for '.$family);
  rcCheck((string)($details['artifact_id']??'')===(string)$claim['artifact_id'],'Audit artifact mismatch for '.$family);
  rcCheck((int)($details['operation_id']??-1)===$claim['operation_id'],'Audit operation mismatch for '.$family);
  rcCheck(($details['evidence_sha256']??'')===hash('sha256',$evidenceLinked),'Audit evidence hash mismatch for '.$family);
  rcCheck(strpos((string)$audit[0]['details'],$evidenceLinked)===false,'Audit stored the raw evidence note for '.$family);
  $operationKey=$claim['operation_id']>0?(string)OAI_DB::get($claim['operation_id'])['operation_id']:'';
  rcCheck((string)$audit[0]['operation_id']===$operationKey,'Audit operation id column mismatch for '.$family);
  if($claim['operation_id']>0){
   $operationRow=OAI_DB::get($claim['operation_id']);
   rcCheck((string)$operationRow['status']==='pending','Operation status changed for '.$family);
   rcCheck((string)$operationRow['proof']===''&&(string)$operationRow['receipt']==='','Operation proof/receipt fabricated for '.$family);
  }

  // Idempotent replay: same decision and evidence, no new audit row.
  $replay=rcReconcile($payload);
  rcCheck(is_array($replay)&&($replay['replayed']??false)===true,'Replay not marked for '.$family);
  rcCheck((int)($replay['reconciled_at']??0)===(int)($result['reconciled_at']??-1),'Replay changed timestamp for '.$family);
  rcCheck(count(rcAuditRows($claim['claim_id']))===1,'Replay wrote another audit event for '.$family);

  $changed=$payload;$changed['evidence_note']=$evidenceLinked.' changed';
  rcError(rcReconcile($changed),'oai_creation_claim_already_reconciled','Changed evidence accepted for '.$family);
  $changedDecision=$payload;$changedDecision['decision']='absent';$changedDecision['confirm_absent']=true;
  $changedDecision['operation_id']=0;$changedDecision['evidence_note']=$evidenceAbsent;
  rcError(rcReconcile($changedDecision),'oai_creation_claim_already_reconciled','Changed decision accepted for '.$family);
  rcCheck(count(rcAuditRows($claim['claim_id']))===1,'Failed replay wrote an audit event for '.$family);
 }

 rcCheck((int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table())===$operationCount,'Reconciliation created operations');
 rcCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}")===$postCount,'Reconciliation created or deleted posts');
 rcCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}")===$termCount,'Reconciliation created or deleted terms');
 rcCheck((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}")===$optionCount,'Reconciliation created or deleted options');

 // Explicit absence for every family. An absent artifact has no linked
 // operation and requires a separate operator confirmation plus evidence.
 $absentClaims=array();
 foreach(array_keys(OAI_Creation_Claim_Reconciler::FAMILIES) as $family){
  $missing=$family==='workspace'
   ?'ws-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16))
   :(string)(900000000+random_int(1,99999999));
  if($family==='media')$claim=rcSeedClaim('media',array('v'=>1,'attachment_id'=>0),true,true);
  elseif($family==='workspace')$claim=rcSeedClaim('workspace',array('claim'=>null));
  else $claim=rcSeedClaim($family);
  $claim['artifact_id']=$missing;$claim['operation_id']=0;
  $absentClaims[$family]=$claim;$claimNames[]=$claim['name'];
 }
 foreach($absentClaims as $family=>$claim){
  $base=array(
   'family'=>$family,'claim_id'=>$claim['claim_id'],'decision'=>'absent',
   'expected'=>$claim['token']?:$claim['fingerprint'],'artifact_id'=>$claim['artifact_id'],
   'evidence_note'=>$evidenceAbsent,
  );
  $unconfirmed=$base;
  rcError(rcReconcile($unconfirmed),'oai_creation_absence_confirmation','Absence without confirmation accepted for '.$family);
  $linkedOperation=$base;$linkedOperation['operation_id']=12345;$linkedOperation['confirm_absent']=true;
  rcError(rcReconcile($linkedOperation),'oai_creation_operation','Absence accepted an operation link for '.$family);
  $before=rcArtifactState($family,$claim['artifact_id']);
  $confirmed=$base;$confirmed['confirm_absent']=true;
  $result=rcReconcile($confirmed);
  rcCheck(is_array($result),'Absent reconciliation failed for '.$family.': '.(is_wp_error($result)?$result->get_error_code():'not an array'));
  rcCheck(($result['state']??'')==='reconciled_absent','Wrong absent state for '.$family);
  rcCheck(($result['decision']??'')==='absent','Wrong absent decision for '.$family);
  rcCheck(($result['artifact_id']??'')===(string)$claim['artifact_id'],'Wrong absent artifact id for '.$family);
  rcCheck((int)($result['operation_id']??-1)===0,'Absent result kept an operation id for '.$family);
  rcCheck(($result['link_strength']??'')==='operator_confirmed_absence','Wrong absence link strength for '.$family);
  rcCheck(array_key_exists('operation_status',$result['lifecycle'])
   &&$result['lifecycle']['operation_status']===null,'Absent result invented a lifecycle for '.$family);
  rcCheck(($result['lifecycle']['has_proof']??true)===false,'Absent result invented a proof for '.$family);
  rcCheck(($result['retention']??array())===$retention,'Absent retention policy mismatch for '.$family);
  rcCheck(rcArtifactState($family,$claim['artifact_id'])===$before,'Absent reconciliation changed an artifact for '.$family);
  $stored=rcOptionRecord($claim['name']);
  rcCheck(is_array($stored)&&($stored['state']??'')==='reconciled_absent','Absent claim was not retained for '.$family);
  rcCheck(($stored['reconciliation_evidence']['note']??'')===$evidenceAbsent,'Absent evidence not retained for '.$family);
  $audit=rcAuditRows($claim['claim_id']);
  rcCheck(count($audit)===1,'Absent audit event missing for '.$family);
  rcCheck((string)$audit[0]['operation_id']==='','Absent audit invented an operation id for '.$family);
 }

 // A present artifact can never be recorded as absent, even with evidence.
 $present=rcSeedClaim('menu');
 $present['artifact_id']=$menuId;$present['operation_id']=0;
 $claimNames[]=$present['name'];
 $presentPayload=array(
  'family'=>'menu','claim_id'=>$present['claim_id'],'decision'=>'absent',
  'expected'=>$present['token'],'artifact_id'=>$menuId,'operation_id'=>0,
  'confirm_absent'=>true,'evidence_note'=>$evidenceAbsent,
 );
 rcError(rcReconcile($presentPayload),'oai_creation_artifact_present','Present menu recorded as absent');
 rcCheck(rcOptionRecord($present['name'])['state']==='reserved','Present menu claim changed after rejected absence');
 rcCheck(count(rcAuditRows($present['claim_id']))===0,'Rejected absence wrote an audit event');

 // Wrong operation type, target, intent and claim are rejected without edits.
 $wrongClaim=rcSeedClaim('menu');
 $wrongClaim['artifact_id']=$menuId;$claimNames[]=$wrongClaim['name'];
 $wrongType=rcSeedOperation('media_upload',(string)$menuId);
 $operationIds[]=$wrongType;
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$wrongClaim['claim_id'],'decision'=>'linked',
  'expected'=>$wrongClaim['token'],'artifact_id'=>$menuId,'operation_id'=>$wrongType,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_type_mismatch','Wrong operation type accepted');
 $wrongTarget=rcSeedOperation('menu_create','999999999');
 $operationIds[]=$wrongTarget;
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$wrongClaim['claim_id'],'decision'=>'linked',
  'expected'=>$wrongClaim['token'],'artifact_id'=>$menuId,'operation_id'=>$wrongTarget,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_target_mismatch','Wrong operation target accepted');
 rcCheck(rcOptionRecord($wrongClaim['name'])['state']==='reserved','Failed operation link changed the claim');

 $mediaWrong=rcSeedClaim('media',array('v'=>1,'attachment_id'=>(int)$attachmentId),true,true);
 $mediaWrong['artifact_id']=(int)$attachmentId;$claimNames[]=$mediaWrong['name'];
 $noStrong=rcSeedOperation('media_upload',(string)$attachmentId);
 $operationIds[]=$noStrong;
 rcError(rcReconcile(array(
  'family'=>'media','claim_id'=>$mediaWrong['claim_id'],'decision'=>'linked',
  'expected'=>$mediaWrong['token'],'artifact_id'=>(int)$attachmentId,'operation_id'=>$noStrong,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_link_unverified','Media operation without intent/claim binding accepted');
 $intentOnly=rcSeedOperation('media_upload',(string)$attachmentId,array(
  '_wpai_creation_intent'=>$mediaWrong['fingerprint'],
 ));
 $operationIds[]=$intentOnly;
 rcError(rcReconcile(array(
  'family'=>'media','claim_id'=>$mediaWrong['claim_id'],'decision'=>'linked',
  'expected'=>$mediaWrong['token'],'artifact_id'=>(int)$attachmentId,'operation_id'=>$intentOnly,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_claim_mismatch','Media operation without claim binding accepted');
 $wrongIntent=rcSeedOperation('media_upload',(string)$attachmentId,array(
  '_wpai_creation_intent'=>str_repeat('0',64),
  '_wpai_creation_claim'=>hash('sha256',$mediaWrong['token']),
 ));
 $operationIds[]=$wrongIntent;
 rcError(rcReconcile(array(
  'family'=>'media','claim_id'=>$mediaWrong['claim_id'],'decision'=>'linked',
  'expected'=>$mediaWrong['token'],'artifact_id'=>(int)$attachmentId,'operation_id'=>$wrongIntent,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_intent_mismatch','Wrong operation intent accepted');
 $wrongClaimHash=rcSeedOperation('media_upload',(string)$attachmentId,array(
  '_wpai_creation_intent'=>$mediaWrong['fingerprint'],
  '_wpai_creation_claim'=>hash('sha256','wrong-claim-token'),
 ));
 $operationIds[]=$wrongClaimHash;
 rcError(rcReconcile(array(
  'family'=>'media','claim_id'=>$mediaWrong['claim_id'],'decision'=>'linked',
  'expected'=>$mediaWrong['token'],'artifact_id'=>(int)$attachmentId,'operation_id'=>$wrongClaimHash,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_claim_mismatch','Wrong operation claim hash accepted');
 rcError(rcReconcile(array(
  'family'=>'media','claim_id'=>$mediaWrong['claim_id'],'decision'=>'linked',
  'expected'=>$mediaWrong['token'],'artifact_id'=>(int)$attachmentId,'operation_id'=>999999999,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation_not_found','Missing operation accepted');
 rcCheck((rcOptionRecord($mediaWrong['name'])['state']??'')==='','Failed media links changed the claim');

 // The exact current claim generation is mandatory and a stale generation
 // can never overwrite a newer claim.
 $generation=rcSeedClaim('menu');
 $generation['artifact_id']=$menuId;$claimNames[]=$generation['name'];
 $generationRaw=rcOptionRaw($generation['name']);
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$generation['claim_id'],'decision'=>'linked',
  'expected'=>str_repeat('a',64),'artifact_id'=>$menuId,'operation_id'=>$menuOp,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_claim_conflict','Stale 64-hex generation accepted');
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$generation['claim_id'],'decision'=>'linked',
  'expected'=>str_repeat('b',32),'artifact_id'=>$menuId,'operation_id'=>$menuOp,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_claim_conflict','Stale 32-hex generation accepted');
 rcCheck(rcOptionRaw($generation['name'])===$generationRaw,'Stale generation changed the claim');

 $casClaim=rcSeedClaim('menu');
 $claimNames[]=$casClaim['name'];
 $casRaw=rcOptionRaw($casClaim['name']);
 $casNext=maybe_serialize(array_merge($casClaim['record'],array('fixture_marker'=>'next')));
 rcCheck(OAI_DB::cas_creation_claim($casClaim['name'],$casRaw.'stale',$casNext)===false,'CAS accepted a stale value');
 rcCheck(rcOptionRaw($casClaim['name'])===$casRaw,'CAS changed the claim on conflict');
 rcCheck(OAI_DB::cas_creation_claim($casClaim['name'],$casRaw,$casNext)===true,'CAS rejected the exact value');
 rcCheck(rcOptionRaw($casClaim['name'])===$casNext,'CAS did not store the next value');
 rcCheck(is_wp_error(OAI_DB::cas_creation_claim('invalid-claim-name',$casRaw,$casNext)),'CAS accepted an invalid claim name');
 rcCheck(is_wp_error(OAI_DB::cas_creation_claim($casClaim['name'],'',$casNext)),'CAS accepted an empty generation');

 // Opaque claims, unknown claims and malformed input never get a guess.
 $opaqueName='oai_menu_creation_'.hash('sha256','opaque-'.wp_generate_uuid4());
 rcCheck(add_option($opaqueName,'opaque-claim-value','',false),'Cannot seed opaque claim');
 $claimNames[]=$opaqueName;
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>substr($opaqueName,-64),'decision'=>'linked',
  'expected'=>str_repeat('c',32),'artifact_id'=>$menuId,'operation_id'=>$menuOp,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_claim_unreadable','Opaque claim was guessed');
 rcCheck(rcOptionRaw($opaqueName)==='opaque-claim-value','Opaque claim was changed');
 $notFound=array(
  'family'=>'menu','claim_id'=>hash('sha256','missing-'.wp_generate_uuid4()),'decision'=>'linked',
  'expected'=>str_repeat('d',32),'artifact_id'=>$menuId,'operation_id'=>$menuOp,
  'evidence_note'=>$evidenceLinked,
 );
 rcError(rcReconcile($notFound),'oai_creation_claim_not_found','Missing claim was accepted');
 rcError(rcReconcile(array_merge($notFound,array('family'=>'unknown'))),'oai_creation_family','Unknown family accepted');
 rcError(rcReconcile(array_merge($notFound,array('claim_id'=>'abc'))),'oai_creation_claim_id','Short claim id accepted');
 rcError(rcReconcile(array_merge($notFound,array('decision'=>'maybe'))),'oai_creation_decision','Unknown decision accepted');
 rcError(rcReconcile(array_merge($notFound,array('expected'=>'abc'))),'oai_creation_expected','Invalid generation accepted');
 rcError(rcReconcile(array_merge($notFound,array('evidence_note'=>str_repeat('x',19)))),'oai_creation_evidence','Short linked evidence accepted');
 $shortAbsent=array_merge($notFound,array('decision'=>'absent','operation_id'=>0,'confirm_absent'=>true,'evidence_note'=>str_repeat('x',39)));
 rcError(rcReconcile($shortAbsent),'oai_creation_evidence','Short absent evidence accepted');
 rcError(rcReconcile(array_merge($notFound,array('evidence_note'=>str_repeat('x',2001)))),'oai_creation_evidence','Oversized evidence accepted');
 rcError(rcReconcile(array_merge($notFound,array('evidence_note'=>str_repeat("\x80",20)))),'oai_creation_evidence','Invalid UTF-8 evidence accepted');
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$generation['claim_id'],'decision'=>'linked',
  'expected'=>$generation['token'],'artifact_id'=>$menuId,'operation_id'=>0,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation','Linked family accepted no operation');
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$generation['claim_id'],'decision'=>'linked',
  'expected'=>$generation['token'],'artifact_id'=>-5,'operation_id'=>$menuOp,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_artifact','Negative artifact id accepted');
 rcError(rcReconcile(array(
  'family'=>'menu','claim_id'=>$generation['claim_id'],'decision'=>'linked',
  'expected'=>$generation['token'],'artifact_id'=>$menuId,'operation_id'=>-1,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation','Negative operation id accepted');
 rcError(rcReconcile(array(
  'family'=>'workspace','claim_id'=>$workspace['claim_id'],'decision'=>'linked',
  'expected'=>$workspace['fingerprint'],'artifact_id'=>$workspaceId,'operation_id'=>1,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_operation','Workspace accepted an operation');
 rcError(rcReconcile(array(
  'family'=>'workspace','claim_id'=>$workspace['claim_id'],'decision'=>'linked',
  'expected'=>$workspace['fingerprint'],'artifact_id'=>'not-a-workspace-id','operation_id'=>0,
  'evidence_note'=>$evidenceLinked,
 )),'oai_creation_artifact','Invalid workspace id accepted');

 // An old unresolved claim is still held; the read-only inventory never
 // reconciles and never deletes it.
 $old=rcSeedClaim('elementor_page',array('created_at'=>time()-5*YEAR_IN_SECONDS));
 $old['artifact_id']=$pageId;$old['operation_id']=0;
 $claimNames[]=$old['name'];
 $oldRaw=rcOptionRaw($old['name']);
 $auditRequest=new WP_REST_Request('GET','/okean-ai/v1/diagnostics/creation-intents');
 $auditRequest->set_param('limit',100);
 $summary=OAI_Creation_Claim_Audit::summary($auditRequest);
 rcCheck(is_array($summary)&&($summary['reconciliation']??'')==='not_performed','Read-only inventory claimed reconciliation');
 rcCheck(($summary['retention']??array())===$retention,'Read-only inventory retention mismatch');
 rcCheck(rcOptionRaw($old['name'])===$oldRaw,'Read-only inventory changed an old claim');
 rcCheck((rcOptionRecord($old['name'])['state']??'')==='reserved','Old unresolved claim was released');
 foreach(array_keys(OAI_Creation_Claim_Reconciler::FAMILIES) as $family){
  $row=$summary['families'][$family];
  if(!$row['truncated']){
   rcCheck($row['reconciled_created']>=1,'Audit did not count a linked claim for '.$family);
   rcCheck($row['reconciled_absent']>=1,'Audit did not count an absent claim for '.$family);
  }
 }
 if(!$summary['families']['elementor_page']['truncated'])
  rcCheck($summary['families']['elementor_page']['needs_reconciliation']>=1,'Old unresolved claim not counted');

 // Authorization: only an administrator may reconcile, through direct calls
 // and through the REST route.
 $createdSubscriber=wp_insert_user(array(
  'user_login'=>'rc-sub-'.wp_generate_password(8,false,false),
  'user_pass'=>wp_generate_password(24),
  'user_email'=>'rc-sub-'.wp_generate_password(8,false,false).'@example.invalid',
  'role'=>'subscriber',
 ));
 $subscriberId=is_int($createdSubscriber)?$createdSubscriber:0;
 rcCheck($subscriberId>0,'Cannot create subscriber');
 $authClaim=rcSeedClaim('menu');
 $authClaim['artifact_id']=$menuId;$claimNames[]=$authClaim['name'];
 $authOp=rcSeedOperation('menu_create',(string)$menuId,array('menu_id'=>$menuId,'sandbox_artifact'=>true));
 $operationIds[]=$authOp;
 $authPayload=array(
  'family'=>'menu','claim_id'=>$authClaim['claim_id'],'decision'=>'linked',
  'expected'=>$authClaim['token'],'artifact_id'=>$menuId,'operation_id'=>$authOp,
  'evidence_note'=>$evidenceLinked,
 );
 wp_set_current_user($subscriberId);
 rcError(rcReconcile($authPayload),'oai_creation_reconcile_forbidden','Subscriber direct reconciliation allowed');
 $subscriberRest=rcRestReconcile($authPayload);
 $subscriberStatus=rcRestStatus($subscriberRest);
 rcCheck($subscriberStatus===403,'Subscriber REST reconciliation allowed ('.$subscriberStatus.')');
 wp_set_current_user(0);
 rcError(rcReconcile($authPayload),'oai_creation_reconcile_forbidden','Anonymous direct reconciliation allowed');
 $anonymousRest=rcRestReconcile($authPayload);
 $anonymousStatus=rcRestStatus($anonymousRest);
 rcCheck($anonymousStatus>=400,'Anonymous REST reconciliation allowed ('.$anonymousStatus.')');
 rcCheck((rcOptionRecord($authClaim['name'])['state']??'')==='reserved','Unauthorized call changed the claim');
 wp_set_current_user(1);

 // The administrator REST route performs the same explicit action.
 $restClaim=rcSeedClaim('menu');
 $restClaim['artifact_id']=$menuId;$claimNames[]=$restClaim['name'];
 $restOp=rcSeedOperation('menu_create',(string)$menuId,array('menu_id'=>$menuId,'sandbox_artifact'=>true));
 $operationIds[]=$restOp;
 $restPayload=array(
  'family'=>'menu','claim_id'=>$restClaim['claim_id'],'decision'=>'linked',
  'expected'=>$restClaim['token'],'artifact_id'=>$menuId,'operation_id'=>$restOp,
  'evidence_note'=>$evidenceLinked,
 );
 $restResult=rcRestReconcile($restPayload);
 $restStatus=rcRestStatus($restResult);
 rcCheck($restResult instanceof WP_REST_Response&&$restStatus===200,'Admin REST reconciliation status '.$restStatus);
 $restData=$restResult->get_data();
 rcCheck(is_array($restData)&&($restData['state']??'')==='reconciled_created','Admin REST reconciliation state mismatch');
 rcCheck((int)($restData['reconciled_by']??0)===1,'Admin REST reconciliation operator mismatch');
 rcCheck((rcOptionRecord($restClaim['name'])['state']??'')==='reconciled_created','Admin REST claim not reconciled');
 $restReplay=rcRestReconcile($restPayload);
 $restReplayStatus=rcRestStatus($restReplay);
 rcCheck($restReplay instanceof WP_REST_Response&&$restReplayStatus===200,'Admin REST replay status '.$restReplayStatus);
 $restReplayData=$restReplay instanceof WP_REST_Response?$restReplay->get_data():array();
 rcCheck(is_array($restReplayData)&&($restReplayData['replayed']??false)===true,'Admin REST replay not marked');
 rcCheck(count(rcAuditRows($restClaim['claim_id']))===1,'Admin REST replay wrote another audit event');

 // Every claim created here, reconciled or unresolved, still exists.
 foreach($claimNames as $name)rcCheck(rcOptionRaw($name)!==null,'Claim was deleted: '.substr($name,-8));

 echo 'PASS creation claim reconciliation: '.ClaimReconcileChecks::$checks.
  " checks, 6 linked families, 6 explicit absences, CAS, audit, retention, REST and authorization\n";
}finally{
 wp_set_current_user(1);
 global $wpdb;
 foreach($claimNames as $name){
  $claimId=substr($name,-64);
  $auditIds=$wpdb->get_col($wpdb->prepare(
   'SELECT id FROM '.OAI_DB::audit_table().' WHERE event=%s AND details LIKE %s',
   'creation_claim_reconciled','%'.$claimId.'%'
  ));
  foreach($auditIds as $auditId)$wpdb->delete(OAI_DB::audit_table(),array('id'=>(int)$auditId));
  delete_option($name);
 }
 foreach($operationIds as $operationId)$wpdb->delete(OAI_DB::table(),array('id'=>(int)$operationId));
 foreach($postIds as $postId){
  if($postId&&get_post($postId)){
   if(get_post_type($postId)==='product'&&function_exists('wc_get_product')){
    $product=wc_get_product($postId);
    if($product)$product->delete(true);
    else wp_delete_post($postId,true);
   }else{
    wp_delete_post($postId,true);
   }
  }
 }
 foreach($menuIds as $menuId)wp_delete_nav_menu($menuId);
 foreach(array_reverse($workspaceDirs) as $directory)rcRemoveTree($directory);
 if($subscriberId>0){
  require_once ABSPATH.'wp-admin/includes/user.php';
  wp_delete_user($subscriberId);
 }
 wp_set_current_user($original);
}
