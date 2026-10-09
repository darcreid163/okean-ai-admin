<?php
// Run via WP-CLI eval-file after installing the generated release ZIP on a fresh disposable WordPress.
function release_install_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);

release_install_expect(defined('OAI_VERSION') && OAI_VERSION==='2.1.15','Installed release version mismatch');
release_install_expect(defined('OAI_BUILD_ID') && OAI_BUILD_ID==='2.1.15-release-hardening-20261009','Installed release build ID mismatch');
release_install_expect(class_exists('OAI_MCP')&&class_exists('OAI_REST')&&class_exists('OAI_AI_Lifecycle_Control')&&class_exists('OAI_Test_Automation'),'Required release classes missing');
release_install_expect(!OAI_AI_Lifecycle_Control::status()['active'],'AI Lifecycle Control must be disabled on fresh install');
release_install_expect(!OAI_Test_Automation::status()['active'],'Test Automation must be disabled on fresh install');

$manifest=OAI_Platform::manifest();
release_install_expect(($manifest['plugin_name']??'')==='WP AI Admin','Fresh-install manifest plugin identity mismatch');
release_install_expect(($manifest['release']['production_defaults']['direct_live_writes']??null)===false,'Direct live writes must be disabled by default');
release_install_expect(!empty($manifest['site_id'])&&!empty($manifest['installation_id']),'Fresh install identity missing');

$tools=(new ReflectionClass('OAI_MCP'))->getMethod('tools');
$tools->setAccessible(true);
$catalog=$tools->invoke(null);
release_install_expect(is_array($catalog)&&count($catalog)>=100,'Fresh-install MCP catalog unexpectedly small');
$catalogNames=array_values(array_filter(array_map(fn($tool)=>is_array($tool)?($tool['name']??''):'',$catalog)));
release_install_expect(in_array('wp.rest_read',$catalogNames,true)&&in_array('wp.rest_routes',$catalogNames,true),'Safe REST discovery/read tools missing');
foreach(['wp.rest_write','wp.rest_request','wp.rest_delete','wp.rest_mutate'] as $unsafeRestTool){
    release_install_expect(!in_array($unsafeRestTool,$catalogNames,true),'Universal REST mutation tool exposed without lifecycle safety review: '.$unsafeRestTool);
}

$self=OAI_Platform::self_test();
$failedSelf=is_array($self['checks']??null)?array_values(array_filter($self['checks'],fn($row)=>empty($row['ok']))):array();
release_install_expect(is_array($self)&&($self['failed']??-1)===0&&($self['passed']??0)===($self['total']??-1)&&($self['total']??0)>=70,'Fresh-install runtime self-test failed: '.wp_json_encode($failedSelf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$fuzz=OAI_Security_Fuzz::run(10);
release_install_expect(($fuzz['status']??'')==='PASS'&&($fuzz['summary']['FAIL']??-1)===0&&($fuzz['summary']['PASS']??0)===($fuzz['summary']['total']??-1)&&($fuzz['summary']['total']??0)>=200,'Fresh-install security fuzz failed');

// Batch optimistic concurrency and proof/receipt integrity on a harmless read-only job.
$client='release-sandbox-client';
$batch=OAI_Batch_Executor::create(array(array('id'=>'read_site','tool'=>'wp.get_site','arguments'=>array())),'Release optimistic-version fixture',$client,1);
release_install_expect(!is_wp_error($batch)&&!empty($batch['id'])&&($batch['version']??0)===1,'Unable to create release batch fixture');
$stale=OAI_Batch_Executor::pause($batch['id'],'stale client write',$client,1,999);
release_install_expect(is_wp_error($stale)&&$stale->get_error_code()==='oai_batch_version_conflict','Stale batch expected_version did not fail closed');
$afterStale=OAI_Batch_Executor::get($batch['id'],false,$client,1);
release_install_expect(!is_wp_error($afterStale)&&($afterStale['version']??0)===1&&($afterStale['status']??'')==='ready','Stale batch mutation changed job state');
$resumed=OAI_Batch_Executor::resume($batch['id'],1,$client,1,1);
release_install_expect(!is_wp_error($resumed)&&($resumed['processed_steps']??0)===1&&(($resumed['job']['status']??'')==='completed'),'Correct-version batch resume did not complete harmless read');
$finished=$resumed['job'];
$terminalPause=OAI_Batch_Executor::pause($batch['id'],'must not reopen terminal job',$client,1,$finished['version']??0);
release_install_expect(is_wp_error($terminalPause)&&$terminalPause->get_error_code()==='oai_batch_terminal','Terminal batch accepted pause mutation');
$receipt=OAI_Batch_Executor::receipt($batch['id'],$client,1);
$proof=OAI_Batch_Executor::proof($batch['id'],$client,1);
release_install_expect(!is_wp_error($receipt)&&preg_match('/^[a-f0-9]{64}$/',(string)($receipt['payload_hash']??''))===1,'Batch receipt hash missing or malformed');
release_install_expect(!is_wp_error($proof)&&($proof['payload_hash']??'')===($receipt['payload_hash']??'')&&preg_match('/^[a-f0-9]{64}$/',(string)($proof['hash']??''))===1,'Batch proof not bound to receipt');

// MCP admin toggle must stop automation without destroying persistent identity/credentials.
$toggleSite=OAI_Platform::site_id();
$toggleInstallation=OAI_Platform::installation_id();
$toggleTokenHash=(string)get_option('oai_mcp_token_hash','');
$toggleLicense=OAI_License::status();
global $wpdb;
$toggleOauthBefore=$wpdb->get_results('SELECT * FROM '.OAI_MCP_OAuth::tokens_table().' ORDER BY id',ARRAY_A);
$aiToggle=OAI_AI_Lifecycle_Control::enable(1);
$taToggle=OAI_Test_Automation::enable(15,array(),array(),1);
release_install_expect(!is_wp_error($aiToggle)&&OAI_AI_Lifecycle_Control::is_active(),'Unable to enable AI Lifecycle for MCP-toggle fixture');
release_install_expect(!is_wp_error($taToggle)&&OAI_Test_Automation::is_active(),'Unable to enable Test Automation for MCP-toggle fixture');
$toggleMethod=new ReflectionMethod('OAI_Admin','handle_mcp_toggle');$toggleMethod->setAccessible(true);
$_SERVER['REQUEST_METHOD']='POST';
$_POST=array('oai_mcp_state'=>'disabled','_wpnonce'=>wp_create_nonce('oai_mcp_toggle'));
$toggleOff=$toggleMethod->invoke(null);
release_install_expect(!is_wp_error($toggleOff)&&empty($toggleOff['enabled'])&&!get_option('oai_mcp_enabled',true),'MCP disable handler failed');
release_install_expect(!OAI_AI_Lifecycle_Control::is_active()&&!OAI_Test_Automation::is_active(),'MCP disable did not stop automation modes');
release_install_expect(OAI_Platform::site_id()===$toggleSite&&OAI_Platform::installation_id()===$toggleInstallation,'MCP disable changed persistent site identity');
release_install_expect((string)get_option('oai_mcp_token_hash','')===$toggleTokenHash,'MCP disable changed MCP credential hash');
$toggleLicenseAfter=OAI_License::status();
release_install_expect(($toggleLicenseAfter['license_id']??'')===($toggleLicense['license_id']??''),'MCP disable changed license identity');
$toggleOauthAfter=$wpdb->get_results('SELECT * FROM '.OAI_MCP_OAuth::tokens_table().' ORDER BY id',ARRAY_A);
release_install_expect(wp_json_encode($toggleOauthAfter)===wp_json_encode($toggleOauthBefore),'MCP disable changed OAuth token rows');
$_POST=array('oai_mcp_state'=>'enabled','_wpnonce'=>wp_create_nonce('oai_mcp_toggle'));
$toggleOn=$toggleMethod->invoke(null);
release_install_expect(!is_wp_error($toggleOn)&&!empty($toggleOn['enabled'])&&get_option('oai_mcp_enabled',false),'MCP re-enable handler failed');
release_install_expect(!OAI_AI_Lifecycle_Control::is_active()&&!OAI_Test_Automation::is_active(),'MCP re-enable silently restored automation');
$_POST=array();$_REQUEST=array();$_SERVER['REQUEST_METHOD']='GET';

// Human lifecycle gate: no nonce / no explicit automation means no approval or live Apply.
$nonce=wp_create_nonce('wp_rest');
update_option('wpai_release_human_gate','baseline',false);
$humanReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$humanReq->set_param('type','option_update');$humanReq->set_param('target','wpai_release_human_gate');$humanReq->set_param('new_data','ai-value');
$humanOp=OAI_REST::sandbox_create($humanReq);
release_install_expect(!is_wp_error($humanOp)&&!empty($humanOp['id']),'Unable to create human-gate sandbox');
$approveNoNonce=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$humanOp['id'].'/approve');
$approveDenied=OAI_REST::sandbox_action($approveNoNonce);
release_install_expect(is_wp_error($approveDenied)&&$approveDenied->get_error_code()==='oai_human_approval_required','Approve succeeded without human/automation authorization');
release_install_expect((OAI_DB::get((int)$humanOp['id'])['status']??'')==='pending'&&(string)get_option('wpai_release_human_gate')==='baseline','Denied Approve mutated operation or live state');
$approveHuman=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$humanOp['id'].'/approve');$approveHuman->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($approveHuman)),'Human-gate approval with nonce failed');
$applyNoNonce=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$humanOp['id'].'/apply');
$applyDenied=OAI_REST::sandbox_action($applyNoNonce);
release_install_expect(is_wp_error($applyDenied)&&$applyDenied->get_error_code()==='oai_human_approval_required','Apply succeeded without human/automation authorization');
release_install_expect((OAI_DB::get((int)$humanOp['id'])['status']??'')==='approved'&&(string)get_option('wpai_release_human_gate')==='baseline','Denied Apply mutated operation or live state');
$rejectHuman=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$humanOp['id'].'/reject');$rejectHuman->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($rejectHuman)),'Human-gate fixture cleanup reject failed');
delete_option('wpai_release_human_gate');

// Conflict guard 1: an external change after Sandbox but before Apply must never be overwritten.
update_option('wpai_release_apply_conflict','baseline',false);
$make=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$make->set_param('type','option_update');$make->set_param('target','wpai_release_apply_conflict');$make->set_param('new_data','ai-value');
$op=OAI_REST::sandbox_create($make);
release_install_expect(!is_wp_error($op)&&!empty($op['id']),'Unable to create apply-conflict sandbox');
$approve=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op['id'].'/approve');$approve->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($approve)),'Apply-conflict fixture approval failed');
update_option('wpai_release_apply_conflict','external-newer',false);
$apply=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op['id'].'/apply');$apply->set_header('X-WP-Nonce',$nonce);
$applyConflict=OAI_REST::sandbox_action($apply);
release_install_expect(is_wp_error($applyConflict)&&$applyConflict->get_error_code()==='oai_conflict','Stale sandbox Apply did not fail closed');
release_install_expect((string)get_option('wpai_release_apply_conflict')==='external-newer','Stale Apply overwrote newer external state');
$applyRow=OAI_DB::get((int)$op['id']);
release_install_expect(($applyRow['status']??'')==='apply_failed','Apply conflict did not persist apply_failed state');
$applyReplay=OAI_REST::sandbox_action($apply);
release_install_expect(is_wp_error($applyReplay)&&$applyReplay->get_error_code()==='oai_conflict','Apply conflict replay lost original conflict semantics');
delete_option('wpai_release_apply_conflict');

// Conflict guard 2: rollback must never destroy a change made after a successful Apply.
update_option('wpai_release_rollback_conflict','baseline',false);
$make2=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$make2->set_param('type','option_update');$make2->set_param('target','wpai_release_rollback_conflict');$make2->set_param('new_data','ai-applied');
$op2=OAI_REST::sandbox_create($make2);
release_install_expect(!is_wp_error($op2)&&!empty($op2['id']),'Unable to create rollback-conflict sandbox');
$approve2=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op2['id'].'/approve');$approve2->set_header('X-WP-Nonce',$nonce);
$apply2=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op2['id'].'/apply');$apply2->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($approve2)),'Rollback-conflict fixture approval failed');
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($apply2))&&(string)get_option('wpai_release_rollback_conflict')==='ai-applied','Rollback-conflict fixture Apply failed');
update_option('wpai_release_rollback_conflict','external-after-apply',false);
$rollback2=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op2['id'].'/rollback');$rollback2->set_header('X-WP-Nonce',$nonce);
$rollbackConflict=OAI_REST::sandbox_action($rollback2);
release_install_expect(is_wp_error($rollbackConflict)&&$rollbackConflict->get_error_code()==='oai_rollback_conflict','Rollback over newer state did not fail closed');
release_install_expect((string)get_option('wpai_release_rollback_conflict')==='external-after-apply','Rollback destroyed newer external state');
$rollbackRow=OAI_DB::get((int)$op2['id']);
release_install_expect(($rollbackRow['status']??'')==='rollback_failed','Rollback conflict did not persist rollback_failed state');
$rollbackReplay=OAI_REST::sandbox_action($rollback2);
release_install_expect(is_wp_error($rollbackReplay)&&$rollbackReplay->get_error_code()==='oai_rollback_conflict','Rollback conflict replay did not remain fail closed');
release_install_expect((string)get_option('wpai_release_rollback_conflict')==='external-after-apply','Rollback replay overwrote newer state');
delete_option('wpai_release_rollback_conflict');

// Native proof lifecycle: preflight -> Apply execution proof -> distinct Rollback execution proof.
update_option('wpai_release_proof_fixture','baseline',false);
$proofReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$proofReq->set_param('type','option_update');$proofReq->set_param('target','wpai_release_proof_fixture');$proofReq->set_param('new_data','applied-value');
$proofOp=OAI_REST::sandbox_create($proofReq);
release_install_expect(!is_wp_error($proofOp)&&!empty($proofOp['id']),'Unable to create proof lifecycle fixture');
$preflightProof=OAI_Proof::make((int)$proofOp['id']);
release_install_expect(($preflightProof['proof_kind']??'')==='preflight'&&empty($preflightProof['verified'])&&empty($preflightProof['execution_proof']),'Pending operation did not return preflight proof');

// Lifecycle race semantics: stale contenders may never overwrite the winner.
$approveRace=OAI_DB::transition((int)$proofOp['id'],array('pending'),'approved','approved');
$staleReject=OAI_DB::transition((int)$proofOp['id'],array('pending'),'rejected','rejected');
release_install_expect($approveRace===1&&$staleReject===0&&((OAI_DB::get((int)$proofOp['id'])['status']??'')==='approved'),'Stale Reject overwrote winning Approve');
OAI_DB::update((int)$proofOp['id'],array('status'=>'pending','phase'=>'awaiting_approval'));

$rejectRace=OAI_DB::transition((int)$proofOp['id'],array('pending'),'rejected','rejected');
$staleApprove=OAI_DB::transition((int)$proofOp['id'],array('pending'),'approved','approved');
release_install_expect($rejectRace===1&&$staleApprove===0&&((OAI_DB::get((int)$proofOp['id'])['status']??'')==='rejected'),'Stale Approve resurrected winning Reject');
OAI_DB::update((int)$proofOp['id'],array('status'=>'pending','phase'=>'awaiting_approval'));

$expiryRace=OAI_DB::transition((int)$proofOp['id'],array('pending'),'expired','expired');
$staleExpiry=OAI_DB::transition((int)$proofOp['id'],array('pending'),'expired','expired');
release_install_expect($expiryRace===1&&$staleExpiry===0,'Expiry lifecycle claim was not single-winner atomic');
OAI_DB::update((int)$proofOp['id'],array('status'=>'pending','phase'=>'awaiting_approval'));

$proofApprove=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/approve');$proofApprove->set_header('X-WP-Nonce',$nonce);
$proofApply=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/apply');$proofApply->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofApprove)),'Proof fixture approval failed');
// Atomic lifecycle claim: only one contender can move approved -> applying.
$activeApplyLease=OAI_DB::acquire_operation_lease((int)$proofOp['id'],'apply',120);
release_install_expect(is_string($activeApplyLease)&&$activeApplyLease!=='','Unable to acquire active Apply execution lease fixture');
$claim1=OAI_DB::transition((int)$proofOp['id'],array('approved'),'applying','applying');
$claim2=OAI_DB::transition((int)$proofOp['id'],array('approved'),'applying','applying');
release_install_expect($claim1===1&&$claim2===0,'Apply lifecycle claim was not single-winner atomic');
$applyBusy=OAI_REST::sandbox_action($proofApply);
release_install_expect(is_wp_error($applyBusy)&&$applyBusy->get_error_code()==='oai_operation_busy','Active Apply execution was mistaken for a crashed request');
release_install_expect((OAI_DB::get((int)$proofOp['id'])['status']??'')==='applying'&&(string)get_option('wpai_release_proof_fixture')==='baseline','Busy Apply retry changed lifecycle/live state');
release_install_expect(OAI_DB::release_operation_lease((int)$proofOp['id'],$activeApplyLease)===true,'Unable to release active Apply execution lease fixture');
$applyRecoveryReady=OAI_REST::sandbox_action($proofApply);
release_install_expect(is_wp_error($applyRecoveryReady)&&$applyRecoveryReady->get_error_code()==='oai_apply_recovery_ready','Interrupted Apply with unchanged live state did not reconcile to retry-safe approved');
release_install_expect((OAI_DB::get((int)$proofOp['id'])['status']??'')==='approved'&&(string)get_option('wpai_release_proof_fixture')==='baseline','Apply recovery-ready path changed live state');
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofApply))&&(string)get_option('wpai_release_proof_fixture')==='applied-value','Proof fixture Apply failed');
$applyProof=OAI_Proof::make((int)$proofOp['id']);
$applyReceipt=OAI_Receipts::build((int)$proofOp['id']);
release_install_expect(($applyProof['proof_kind']??'')==='execution'&&($applyProof['phase']??'')==='after'&&!empty($applyProof['verified']),'Apply execution proof missing or unverified');
release_install_expect(preg_match('/^[a-f0-9]{64}$/',(string)($applyProof['hash']??''))===1&&($applyProof['build_id']??'')===OAI_BUILD_ID,'Apply proof hash/build binding invalid');
release_install_expect(($applyReceipt['operation_id']??'')===($proofOp['operation_id']??'')&&!empty($applyReceipt['verified'])&&(($applyReceipt['proof']['hash']??'')===($applyProof['hash']??'')),'Apply receipt not bound to Apply proof');

$proofRollback=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/rollback');$proofRollback->set_header('X-WP-Nonce',$nonce);
// Atomic lifecycle claim: only one contender can move applied -> rollback_pending.
$activeRollbackLease=OAI_DB::acquire_operation_lease((int)$proofOp['id'],'rollback',120);
release_install_expect(is_string($activeRollbackLease)&&$activeRollbackLease!=='','Unable to acquire active Rollback execution lease fixture');
$rollbackClaim1=OAI_DB::transition((int)$proofOp['id'],array('applied'),'rollback_pending','rollback_pending');
$rollbackClaim2=OAI_DB::transition((int)$proofOp['id'],array('applied'),'rollback_pending','rollback_pending');
release_install_expect($rollbackClaim1===1&&$rollbackClaim2===0,'Rollback lifecycle claim was not single-winner atomic');
$rollbackBusy=OAI_REST::sandbox_action($proofRollback);
release_install_expect(is_wp_error($rollbackBusy)&&$rollbackBusy->get_error_code()==='oai_operation_busy','Active Rollback execution was mistaken for a crashed request');
release_install_expect((OAI_DB::get((int)$proofOp['id'])['status']??'')==='rollback_pending'&&(string)get_option('wpai_release_proof_fixture')==='applied-value','Busy Rollback retry changed lifecycle/live state');
release_install_expect(OAI_DB::release_operation_lease((int)$proofOp['id'],$activeRollbackLease)===true,'Unable to release active Rollback execution lease fixture');
$rollbackRecoveryReady=OAI_REST::sandbox_action($proofRollback);
release_install_expect(is_wp_error($rollbackRecoveryReady)&&$rollbackRecoveryReady->get_error_code()==='oai_rollback_recovery_ready','Interrupted Rollback with intact applied state did not reconcile to retry-safe applied');
release_install_expect((OAI_DB::get((int)$proofOp['id'])['status']??'')==='applied'&&(string)get_option('wpai_release_proof_fixture')==='applied-value','Rollback recovery-ready path changed live state');
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofRollback))&&(string)get_option('wpai_release_proof_fixture')==='baseline','Proof fixture Rollback failed');
$rollbackProof=OAI_Proof::make((int)$proofOp['id']);
$rollbackReceipt=OAI_Receipts::build((int)$proofOp['id']);
release_install_expect(($rollbackProof['proof_kind']??'')==='execution'&&($rollbackProof['phase']??'')==='rollback'&&!empty($rollbackProof['verified']),'Rollback execution proof missing or unverified');
release_install_expect(preg_match('/^[a-f0-9]{64}$/',(string)($rollbackProof['hash']??''))===1,'Rollback proof hash invalid');
release_install_expect(!hash_equals((string)$applyProof['hash'],(string)$rollbackProof['hash']),'Rollback reused stale Apply proof');
release_install_expect(($rollbackReceipt['status']??'')==='rolled_back'&&!empty($rollbackReceipt['verified'])&&(($rollbackReceipt['proof']['phase']??'')==='rollback')&&(($rollbackReceipt['proof']['hash']??'')===($rollbackProof['hash']??'')),'Rollback receipt not bound to fresh rollback proof');
delete_option('wpai_release_proof_fixture');

// Interrupted requests that already completed the live write must be finalized without replaying it.
update_option('wpai_release_recovery_fixture','before',false);
$recoveryReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$recoveryReq->set_param('type','option_update');$recoveryReq->set_param('target','wpai_release_recovery_fixture');$recoveryReq->set_param('new_data','after');
$recoveryOp=OAI_REST::sandbox_create($recoveryReq);
release_install_expect(!is_wp_error($recoveryOp)&&!empty($recoveryOp['id']),'Unable to create interrupted-write recovery fixture');
$recoveryApprove=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$recoveryOp['id'].'/approve');$recoveryApprove->set_header('X-WP-Nonce',$nonce);
$recoveryApply=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$recoveryOp['id'].'/apply');$recoveryApply->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($recoveryApprove)),'Interrupted-write recovery fixture approval failed');
release_install_expect(OAI_DB::transition((int)$recoveryOp['id'],array('approved'),'applying','applying')===1,'Unable to seed interrupted Apply state');
update_option('wpai_release_recovery_fixture','after',false); // Simulate write completed before request/process died.
$recoveredApply=OAI_REST::sandbox_action($recoveryApply);
release_install_expect(!is_wp_error($recoveredApply)&&($recoveredApply['status']??'')==='applied','Completed interrupted Apply was not finalized');
$recoveredApplyReceipt=OAI_Receipts::build((int)$recoveryOp['id']);
release_install_expect(($recoveredApplyReceipt['receipt']['event']??'')==='apply_recovered'&&($recoveredApplyReceipt['receipt']['replayed_write']??true)===false,'Recovered Apply receipt does not prove no replay');

$recoveryRollback=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$recoveryOp['id'].'/rollback');$recoveryRollback->set_header('X-WP-Nonce',$nonce);
release_install_expect(OAI_DB::transition((int)$recoveryOp['id'],array('applied'),'rolling_back','rolling_back')===1,'Unable to seed interrupted Rollback state');
update_option('wpai_release_recovery_fixture','before',false); // Simulate rollback completed before request/process died.
$recoveredRollback=OAI_REST::sandbox_action($recoveryRollback);
release_install_expect(!is_wp_error($recoveredRollback)&&($recoveredRollback['status']??'')==='rolled_back','Completed interrupted Rollback was not finalized');
$recoveredRollbackReceipt=OAI_Receipts::build((int)$recoveryOp['id']);
release_install_expect(($recoveredRollbackReceipt['receipt']['event']??'')==='rollback_recovered'&&($recoveredRollbackReceipt['receipt']['replayed_write']??true)===false,'Recovered Rollback receipt does not prove no replay');
delete_option('wpai_release_recovery_fixture');

// If an interrupted Apply cannot be reconciled to either before or after,
// remain fail-closed and never replay a write.
update_option('wpai_release_uncertain_fixture','before',false);
$uncertainReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$uncertainReq->set_param('type','option_update');$uncertainReq->set_param('target','wpai_release_uncertain_fixture');$uncertainReq->set_param('new_data','after');
$uncertainOp=OAI_REST::sandbox_create($uncertainReq);
release_install_expect(!is_wp_error($uncertainOp)&&!empty($uncertainOp['id']),'Unable to create uncertain Apply fixture');
$uncertainApprove=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$uncertainOp['id'].'/approve');$uncertainApprove->set_header('X-WP-Nonce',$nonce);
$uncertainApply=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$uncertainOp['id'].'/apply');$uncertainApply->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($uncertainApprove)),'Uncertain Apply fixture approval failed');
release_install_expect(OAI_DB::transition((int)$uncertainOp['id'],array('approved'),'applying','applying')===1,'Unable to seed uncertain Apply state');
update_option('wpai_release_uncertain_fixture','third-party-state',false);
$uncertainResult=OAI_REST::sandbox_action($uncertainApply);
release_install_expect(is_wp_error($uncertainResult)&&$uncertainResult->get_error_code()==='oai_apply_outcome_uncertain','Ambiguous interrupted Apply did not stay fail closed');
release_install_expect((OAI_DB::get((int)$uncertainOp['id'])['status']??'')==='applying'&&(string)get_option('wpai_release_uncertain_fixture')==='third-party-state','Ambiguous Apply recovery mutated state');
delete_option('wpai_release_uncertain_fixture');

// Expired sandbox snapshots must never be approved or applied.
update_option('wpai_release_expired_fixture','baseline',false);
$expiredReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
$expiredReq->set_param('type','option_update');$expiredReq->set_param('target','wpai_release_expired_fixture');$expiredReq->set_param('new_data','must-never-apply');
$expiredOp=OAI_REST::sandbox_create($expiredReq);
release_install_expect(!is_wp_error($expiredOp)&&!empty($expiredOp['id']),'Unable to create expiry fixture');
OAI_DB::update((int)$expiredOp['id'],array('expires_at'=>gmdate('Y-m-d H:i:s',time()-60)));
$expiredApprove=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$expiredOp['id'].'/approve');$expiredApprove->set_header('X-WP-Nonce',$nonce);
$expiredResult=OAI_REST::sandbox_action($expiredApprove);
release_install_expect(is_wp_error($expiredResult)&&$expiredResult->get_error_code()==='oai_expired','Expired sandbox was not rejected');
release_install_expect((string)get_option('wpai_release_expired_fixture')==='baseline','Expired sandbox changed live state');
$expiredRow=OAI_DB::get((int)$expiredOp['id']);
release_install_expect(($expiredRow['status']??'')==='expired','Expired sandbox status not persisted');
$expiredReceipt=OAI_Receipts::build((int)$expiredOp['id']);
release_install_expect(is_array($expiredReceipt)&&($expiredReceipt['status']??'')==='expired'&&(($expiredReceipt['receipt']['reason']??'')==='sandbox_expired'),'Expired sandbox receipt missing');
delete_option('wpai_release_expired_fixture');

// Signed preview tokens are short-lived and bound to exact operation id + expiry.
$previewId=424242;
$previewExpiry=time()+300;
$previewToken=OAI_REST::preview_token($previewId,$previewExpiry);
release_install_expect(is_string($previewToken)&&preg_match('/^[a-f0-9]{64}$/',$previewToken)===1,'Preview token format invalid');
$previewUser=get_current_user_id();
wp_set_current_user(0);
$previewReq=new WP_REST_Request('GET','/okean-ai/v1/preview/'.$previewId);
$previewReq->set_param('id',$previewId);$previewReq->set_param('preview_token',$previewToken);$previewReq->set_param('expires',$previewExpiry);
release_install_expect(OAI_REST::preview_perm($previewReq)===true,'Valid signed preview token was rejected');

$wrongIdReq=new WP_REST_Request('GET','/okean-ai/v1/preview/'.($previewId+1));
$wrongIdReq->set_param('id',$previewId+1);$wrongIdReq->set_param('preview_token',$previewToken);$wrongIdReq->set_param('expires',$previewExpiry);
release_install_expect(OAI_REST::preview_perm($wrongIdReq)===false,'Preview token was reusable for another operation id');

$wrongExpiryReq=new WP_REST_Request('GET','/okean-ai/v1/preview/'.$previewId);
$wrongExpiryReq->set_param('id',$previewId);$wrongExpiryReq->set_param('preview_token',$previewToken);$wrongExpiryReq->set_param('expires',$previewExpiry+1);
release_install_expect(OAI_REST::preview_perm($wrongExpiryReq)===false,'Preview token was reusable with another expiry');

$expiredPreviewReq=new WP_REST_Request('GET','/okean-ai/v1/preview/'.$previewId);
$expiredPreviewReq->set_param('id',$previewId);$expiredPreviewReq->set_param('expires',time()-1);
$expiredPreviewReq->set_param('preview_token',OAI_REST::preview_token($previewId,time()-1));
release_install_expect(OAI_REST::preview_perm($expiredPreviewReq)===false,'Expired preview token was accepted');

$tamperedPreviewReq=new WP_REST_Request('GET','/okean-ai/v1/preview/'.$previewId);
$tamperedPreviewReq->set_param('id',$previewId);$tamperedPreviewReq->set_param('expires',$previewExpiry);
$tamperedPreviewReq->set_param('preview_token',substr($previewToken,0,-1).($previewToken[-1]==='a'?'b':'a'));
release_install_expect(OAI_REST::preview_perm($tamperedPreviewReq)===false,'Tampered preview token was accepted');
wp_set_current_user($previewUser);

// Generic option sandbox must never mutate Cloud control-plane or credential-like options.
$opsBeforeGuard=OAI_DB::count_all();
foreach(['wpai_gateway_wrapped_keys_v1','wpai_gateway_enabled','wpai_gateway_beta_users','wpai_cloud_db_version'] as $protectedKey){
    $beforeProtected=get_option($protectedKey,'__missing__');
    $protectedReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
    $protectedReq->set_param('type','option_update');$protectedReq->set_param('target',$protectedKey);$protectedReq->set_param('new_data','attacker-value');
    $protectedResult=OAI_REST::sandbox_create($protectedReq);
    release_install_expect(is_wp_error($protectedResult)&&$protectedResult->get_error_code()==='oai_option_control_plane','Cloud control-plane option was accepted by generic sandbox: '.$protectedKey);
    release_install_expect(get_option($protectedKey,'__missing__')===$beforeProtected,'Denied Cloud option sandbox changed value: '.$protectedKey);
}
foreach(['fixture_token','fixture_client_secret','fixture_api_key','fixture_auth_key','fixture_password'] as $sensitiveKey){
    $sensitiveReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
    $sensitiveReq->set_param('type','option_update');$sensitiveReq->set_param('target',$sensitiveKey);$sensitiveReq->set_param('new_data','attacker-value');
    $sensitiveResult=OAI_REST::sandbox_create($sensitiveReq);
    release_install_expect(is_wp_error($sensitiveResult)&&$sensitiveResult->get_error_code()==='oai_option_sensitive','Credential-like option was accepted by generic sandbox: '.$sensitiveKey);
}
release_install_expect(OAI_DB::count_all()===$opsBeforeGuard,'Denied sensitive/control-plane option attempts created operation rows');

// Universal REST safe-read policy must not expose WordPress users, credentials or external/traversal targets.
do_action('rest_api_init');
$blockedUsers=OAI_REST_Resolver::read('/wp/v2/users',array(),'summary',10);
release_install_expect(is_wp_error($blockedUsers)&&$blockedUsers->get_error_code()==='oai_rest_blocked'&&(($blockedUsers->get_error_data()['reason']??'')==='wordpress_users_blocked_use_wp_list_users'),'wp.rest_read exposed WordPress users endpoint');
$blockedAppPasswords=OAI_REST_Resolver::read('/wp/v2/users/1/application-passwords',array(),'summary',10);
release_install_expect(is_wp_error($blockedAppPasswords)&&$blockedAppPasswords->get_error_code()==='oai_rest_blocked','wp.rest_read exposed application passwords');
$blockedExternal=OAI_REST_Resolver::read('https://example.com/wp-json/wp/v2/posts',array(),'summary',10);
release_install_expect(is_wp_error($blockedExternal)&&$blockedExternal->get_error_code()==='oai_rest_route','wp.rest_read accepted external URL');
$blockedTraversal=OAI_REST_Resolver::read('../wp/v2/posts',array(),'summary',10);
release_install_expect(is_wp_error($blockedTraversal)&&$blockedTraversal->get_error_code()==='oai_rest_route','wp.rest_read accepted traversal route');
$secretProbe=OAI_Read::option_value('fixture_settings',array('name'=>'safe','consumer_secret'=>'must-hide','nested'=>array('api_key'=>'must-hide-too')));
release_install_expect(!empty($secretProbe['redacted'])&&($secretProbe['value']['name']??'')==='safe'&&($secretProbe['value']['consumer_secret']??'')==='[REDACTED]'&&($secretProbe['value']['nested']['api_key']??'')==='[REDACTED]','Sensitive option redaction failed');
$wholeSecret=OAI_Read::option_value('oai_bearer_token','plaintext-must-never-return');
release_install_expect(!empty($wholeSecret['redacted'])&&($wholeSecret['value']??'')==='[REDACTED]','Sensitive top-level option was exposed');
$userMarker='private-release-user@example.test';
$privacyUser=wp_create_user('release-privacy-user-'.wp_generate_password(6,false,false),'disposable-password',$userMarker);
release_install_expect(!is_wp_error($privacyUser),'Unable to create user privacy fixture');
$userReq=new WP_REST_Request('GET','/');$userReq->set_param('limit',100);$userReq->set_param('page',1);
$userList=OAI_Read::users($userReq);
release_install_expect(!is_wp_error($userList),'wp.list_users privacy fixture failed');
$userEncoded=wp_json_encode($userList,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
release_install_expect(!str_contains($userEncoded,$userMarker)&&!str_contains(strtolower($userEncoded),'user_email'),'wp.list_users leaked email data');
foreach((array)($userList['items']??array()) as $u){
    release_install_expect(array_diff(array_keys($u),array('id','login','display_name','roles','registered'))===array(),'wp.list_users returned field outside minimal projection');
}

echo 'PASS fresh release ZIP install: WP '.get_bloginfo('version').', plugin '.OAI_VERSION.', tools '.count($catalog).', self-test '.$self['passed'].'/'.$self['total'].', fuzz '.$fuzz['summary']['PASS'].'/'.$fuzz['summary']['total'].PHP_EOL;
