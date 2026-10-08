<?php
// Run via WP-CLI eval-file after installing the generated release ZIP on a fresh disposable WordPress.
function release_install_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);

release_install_expect(defined('OAI_VERSION') && OAI_VERSION==='2.1.14','Installed release version mismatch');
release_install_expect(defined('OAI_BUILD_ID') && OAI_BUILD_ID==='2.1.14-native-consent-return-20261007','Installed release build ID mismatch');
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

// Conflict guard 1: an external change after Sandbox but before Apply must never be overwritten.
$nonce=wp_create_nonce('wp_rest');
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

echo 'PASS fresh release ZIP install: WP '.get_bloginfo('version').', plugin '.OAI_VERSION.', tools '.count($catalog).', self-test '.$self['passed'].'/'.$self['total'].', fuzz '.$fuzz['summary']['PASS'].'/'.$fuzz['summary']['total'].PHP_EOL;
