<?php
/** Public path sweep and evidence/rollback integrity; disposable WordPress only. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable loopback WordPress required');
function evidenceAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function evidenceOk($result,$message){evidenceAssert(!is_wp_error($result),$message.': '.(is_wp_error($result)?$result->get_error_code():''));return $result;}
function evidenceClean($value,$name){
 $json=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 foreach([ABSPATH,'/srv/old-private-tenant','PrivateWindowsTenant','legacy-mount'] as $path)evidenceAssert(strpos($json,$path)===false,'Path disclosed by '.$name);
}
function evidenceRequest($route,$id=0){$r=new WP_REST_Request('GET',$route);if($id)$r->set_url_params(['id'=>$id]);return $r;}
function evidenceHttp($route,$params=[]){
 $r=evidenceRequest($route);foreach($params as $k=>$v)$r->set_param($k,$v);
 $response=rest_do_request($r);
 return apply_filters('rest_post_dispatch',$response,rest_get_server(),$r);
}
function evidenceTool($name,$args=[]){
 // Exercise the real MCP formatter without manufacturing an OAuth identity.
 // Authentication/owner isolation is covered by the separate transport suites.
 $method=new ReflectionMethod('OAI_MCP','call_tool');
 $response=$method->invoke(null,1,['name'=>$name,'arguments'=>$args]);
 $wire=$response->get_data();
 evidenceAssert(!isset($wire['error']),'Unexpected MCP error: '.$name);
 return json_decode($wire['result']['content'][0]['text'],true);
}
wp_set_current_user(1);
global $wpdb;
$legacyId=0;$opId=0;$attachment=0;$testFile='';
try{
 evidenceOk(OAI_License::activate_local_owner(1),'Activate disposable owner');
 evidenceOk(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped test lifecycle');
 $op=evidenceOk(OAI_P0::media_upload(['filename'=>'evidence-fixture.png','content_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGPkiephYGBgYmBgYGBgAAALAgD2hsU36QAAAABJRU5ErkJggg==']),'Create real private media sandbox');
 $opId=(int)$op['id'];$attachment=(int)$op['target'];$file=get_attached_file($attachment);
 foreach(['approve','apply'] as $action)evidenceOk(OAI_AI_Lifecycle_Control::perform((int)$op['id'],$action,'disposable-evidence',1),'Real media '.$action);
 evidenceAssert(!empty(evidenceOk(OAI_Verify::operation((int)$op['id']),'Real media verify')['verified']),'Real media not verified');

 // A synthetic historical envelope has internally consistent original hashes.
 // No migration/re-signing is performed by any public read.
 $after=$op['after_data'];$after['file']=ABSPATH.'wp-content/uploads/old-private-tenant.png';
 $meta=['before_exists'=>false,'legacy_file'=>'/legacy-mount/old-private-tenant/image.png'];
 $verification=['verified'=>true,'expected'=>$after,'current'=>$after];
 $operationId=OAI_Operations::new_id('TEST');
 $payload=['operation_id'=>$operationId,'type'=>'media_upload','target'=>(string)$attachment,'before'=>null,'after'=>$after,'applied'=>$after,'meta'=>$meta,'verification'=>$verification];
 $proof=['proof_kind'=>'execution','phase'=>'after','verified'=>true,'algorithm'=>'sha256',
  'hash'=>hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
  'payload_hash'=>hash('sha256',wp_json_encode(['before'=>null,'after'=>$after,'applied'=>$after,'meta'=>$meta],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
  'verification'=>$verification];
 $legacyId=evidenceOk(OAI_DB::insert([
  'operation_id'=>$operationId,'status'=>'applied','type'=>'media_upload','target'=>(string)$attachment,
  'before_data'=>'null','after_data'=>wp_json_encode($after),'applied_data'=>wp_json_encode($after),
  'meta_data'=>wp_json_encode($meta),'diff_data'=>wp_json_encode(['file'=>$after['file']]),
  'rollback_data'=>wp_json_encode(['action'=>'delete_created_attachment','attachment_id'=>$attachment]),
  'receipt'=>wp_json_encode(['verification'=>$verification,'legacy_error'=>'Cannot read C:\\PrivateWindowsTenant\\image.png']),
  'proof'=>wp_json_encode($proof),'risk'=>'medium','phase'=>'applied','created_at'=>current_time('mysql',true),
 ]),'Create historical fixture');
 OAI_Audit::log('synthetic_evidence',OAI_DB::get($legacyId),['file'=>$after['file'],'error'=>'Old failure in /srv/old-private-tenant/image.png']);
 $raw=OAI_DB::get($legacyId);
 $freshRaw=OAI_DB::get($opId);
 $auditBefore=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.OAI_DB::audit_table().' WHERE operation_db_id=%d',$legacyId),ARRAY_A);

 $direct=[
  'status'=>OAI_REST::status(),'fingerprint'=>OAI_REST::runtime_fingerprint(),
  'health'=>OAI_REST::health(),'diagnostics'=>OAI_REST::diagnostics(),
  'operation'=>OAI_REST::operation(evidenceRequest('/okean-ai/v1/operations/'.$legacyId,$legacyId)),
  'proof'=>OAI_REST::proof(evidenceRequest('/okean-ai/v1/proof/'.$legacyId,$legacyId)),
  'receipt'=>OAI_Receipts::build($legacyId),'ledger'=>OAI_REST::sandbox_list(),
  'audit'=>OAI_REST::audit(evidenceRequest('/okean-ai/v1/audit')),
 ];
 foreach($direct as $name=>$value)evidenceClean($value,$name);
 evidenceAssert($direct['proof']['hash']===$proof['hash']&&$direct['proof']['payload_hash']===$proof['payload_hash']&&$direct['proof']['verified']===true,'Original proof claims changed');
 evidenceAssert($direct['receipt']['proof']['hash']===$proof['hash']&&$direct['receipt']['verified']===true,'Receipt claims changed');
 evidenceAssert($direct['proof']['_wpai_projection']['hashes_attest']==='original_evidence'&&!$direct['proof']['_wpai_projection']['canonical_payload_available'],'Redacted proof semantics not explicit');
 foreach($direct['fingerprint']['methods'] as $method)evidenceAssert($method['sha256']===hash_file('sha256',OAI_DIR.'includes/class-oai-rest.php'),'Runtime fingerprint hash changed');
 foreach(['/status','/runtime/fingerprint','/diagnostics','/read/health','/read/site','/read/capabilities','/sandbox','/audit','/operations/'.$legacyId,'/operations/'.$legacyId.'/receipt','/proof/'.$legacyId,'/verify/'.$legacyId] as $route){
  $response=evidenceHttp('/okean-ai/v1'.$route);
  evidenceAssert($response->get_status()===200,'REST metadata failed: '.$route);
  evidenceClean($response->get_data(),'REST '.$route);
 }
 foreach(['wp.get_site','wp.health','wp.diagnostics','system.manifest','system.capabilities','system.tool_catalog','system.security_status','wp.list_operations','operation.get','operation.status','operation.diff','operation.verify','verify.operation','operation.proof','proof.operation','operation.get_receipt'] as $name)evidenceClean(evidenceTool($name,['id'=>$legacyId]),'MCP '.$name);
 foreach(['okean://site/status','okean://capabilities','okean://operations'] as $uri){
  $method=new ReflectionMethod('OAI_MCP','resource_read');
  $resource=$method->invoke(null,1,['uri'=>$uri])->get_data();
  evidenceAssert(!isset($resource['error']),'MCP resource failed');
  evidenceClean(json_decode($resource['result']['contents'][0]['text'],true),'MCP resource '.$uri);
 }
 evidenceAssert(OAI_DB::get($legacyId)===$raw,'Public reads rewrote original row/proof bytes');
 evidenceAssert(OAI_DB::get($opId)===$freshRaw,'Public reads rewrote fresh operation evidence');
 evidenceAssert($wpdb->get_results($wpdb->prepare('SELECT * FROM '.OAI_DB::audit_table().' WHERE operation_db_id=%d',$legacyId),ARRAY_A)===$auditBefore,'Public reads rewrote audit');
 echo "PASS: direct/REST/MCP metadata and historical operation/ledger/audit/receipt/proof path sweep; original bytes/hashes/verified claims unchanged\n";

 // Scope check: file content is editable source, not a redacted evidence view.
 $testName='wpai-disposable-path-content.txt';$testFile=trailingslashit(get_stylesheet_directory()).$testName;
 evidenceAssert(!file_exists($testFile),'Disposable file already exists');
 $content="Synthetic literal: ".ABSPATH."\nWindows literal: C:\\PrivateWindowsTenant\\source.txt\n";
 file_put_contents($testFile,$content);
 $data=evidenceHttp('/okean-ai/v1/files/read',['scope'=>'child_theme','path'=>$testName])->get_data();
 evidenceAssert(($data['content']??null)===$content&&$data['sha256']===hash('sha256',$content),'REST file bytes/hash changed');
 $data=evidenceTool('file.read',['scope'=>'child_theme','path'=>$testName]);
 evidenceAssert($data['content']===$content&&$data['sha256']===hash('sha256',$content),'MCP file bytes/hash changed');
 echo "PASS: REST/MCP file reads retain exact editable bytes and content hashes\n";
 $error=new WP_REST_Response(['code'=>'synthetic_error','message'=>'Cannot decode '.ABSPATH.'private/image.png','data'=>['status'=>503]],503,['X-Synthetic'=>'preserved']);
 $projected=OAI_REST::public_metadata_response($error,rest_get_server(),evidenceRequest('/okean-ai/v1/diagnostics'));
 evidenceClean($projected->get_data(),'REST structured error');
 evidenceAssert($projected->get_status()===503&&$projected->get_headers()['X-Synthetic']==='preserved'&&$projected->get_data()['code']==='synthetic_error','Error status/code/headers changed');
 wp_set_current_user(0);
 $denied=evidenceHttp('/okean-ai/v1/operations/'.$legacyId);
 evidenceAssert(in_array($denied->get_status(),[401,403],true),'Metadata projection bypassed REST authentication');
 wp_set_current_user(1);
 evidenceOk(OAI_AI_Lifecycle_Control::perform((int)$op['id'],'rollback','disposable-evidence',1),'Real media rollback');
 evidenceAssert(!empty(evidenceOk(OAI_Verify::operation((int)$op['id']),'Verify rollback')['verified'])&&!get_post($attachment)&&!is_file($file),'Projection interfered with normal rollback');
 echo "PASS: fresh media approval/apply/verify/receipt/rollback/verify and cleanup unaffected\n";
}finally{
 if($testFile&&is_file($testFile))unlink($testFile);
 if($legacyId){$wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$legacyId]);$wpdb->delete(OAI_DB::table(),['id'=>$legacyId]);}
 if($opId){$wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$opId]);$wpdb->delete(OAI_DB::table(),['id'=>$opId]);}
 if($attachment&&get_post($attachment))wp_delete_attachment($attachment,true);
 OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');OAI_License::deactivate_local_owner();
}
