<?php
// Run via WP-CLI eval-file after installing the generated release ZIP on a fresh disposable WordPress.
function release_install_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);

release_install_expect(defined('OAI_VERSION') && OAI_VERSION==='2.1.15','Installed release version mismatch');
release_install_expect(defined('OAI_BUILD_ID') && OAI_BUILD_ID==='2.1.15-release-hardening-20261008','Installed release build ID mismatch');
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

$proofApprove=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/approve');$proofApprove->set_header('X-WP-Nonce',$nonce);
$proofApply=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/apply');$proofApply->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofApprove)),'Proof fixture approval failed');
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofApply))&&(string)get_option('wpai_release_proof_fixture')==='applied-value','Proof fixture Apply failed');
$applyProof=OAI_Proof::make((int)$proofOp['id']);
$applyReceipt=OAI_Receipts::build((int)$proofOp['id']);
release_install_expect(($applyProof['proof_kind']??'')==='execution'&&($applyProof['phase']??'')==='after'&&!empty($applyProof['verified']),'Apply execution proof missing or unverified');
release_install_expect(preg_match('/^[a-f0-9]{64}$/',(string)($applyProof['hash']??''))===1&&($applyProof['build_id']??'')===OAI_BUILD_ID,'Apply proof hash/build binding invalid');
release_install_expect(($applyReceipt['operation_id']??'')===($proofOp['operation_id']??'')&&!empty($applyReceipt['verified'])&&(($applyReceipt['proof']['hash']??'')===($applyProof['hash']??'')),'Apply receipt not bound to Apply proof');

$proofRollback=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$proofOp['id'].'/rollback');$proofRollback->set_header('X-WP-Nonce',$nonce);
release_install_expect(!is_wp_error(OAI_REST::sandbox_action($proofRollback))&&(string)get_option('wpai_release_proof_fixture')==='baseline','Proof fixture Rollback failed');
$rollbackProof=OAI_Proof::make((int)$proofOp['id']);
$rollbackReceipt=OAI_Receipts::build((int)$proofOp['id']);
release_install_expect(($rollbackProof['proof_kind']??'')==='execution'&&($rollbackProof['phase']??'')==='rollback'&&!empty($rollbackProof['verified']),'Rollback execution proof missing or unverified');
release_install_expect(preg_match('/^[a-f0-9]{64}$/',(string)($rollbackProof['hash']??''))===1,'Rollback proof hash invalid');
release_install_expect(!hash_equals((string)$applyProof['hash'],(string)$rollbackProof['hash']),'Rollback reused stale Apply proof');
release_install_expect(($rollbackReceipt['status']??'')==='rolled_back'&&!empty($rollbackReceipt['verified'])&&(($rollbackReceipt['proof']['phase']??'')==='rollback')&&(($rollbackReceipt['proof']['hash']??'')===($rollbackProof['hash']??'')),'Rollback receipt not bound to fresh rollback proof');
delete_option('wpai_release_proof_fixture');

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
