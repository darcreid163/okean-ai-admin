<?php
/**
 * Disposable WordPress/MySQL: generic retention must never destroy failed
 * rollback / failed verification recovery evidence (issue #143).
 *
 * The fixture uses an isolated operations table so it can seed every status
 * without touching the lab site's own ledger.
 */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class CleanupPolicyChecks {public static int $count=0;}
function cpAssert($condition,$reason){++CleanupPolicyChecks::$count;if(!$condition)throw new RuntimeException($reason);}
global $wpdb;
wp_set_current_user(1);
cpAssert(current_user_can('manage_options'),'Fixture needs administrator');

$contract=OAI_Operations_Cleanup::self_test_contract();
cpAssert(!empty($contract['recovery_evidence_protected_by_default']),'Contract does not protect recovery evidence');
cpAssert(!empty($contract['failed_recovery_requires_explicit_opt_in']),'Contract does not require an opt-in');
cpAssert(!empty($contract['include_failed_recovery_separate_from_applied']),'Opt-ins are not independent');
cpAssert(!in_array(false,$contract,true),'Contract has a failing assertion');
cpAssert(!in_array('rollback_failed',OAI_Operations_Cleanup::TERMINAL_STATUSES,true),'rollback_failed is still a default terminal status');
cpAssert(!in_array('verification_failed',OAI_Operations_Cleanup::TERMINAL_STATUSES,true),'verification_failed is still a default terminal status');

$auditTable=OAI_DB::audit_table();
$auditBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$auditTable);

$originalPrefix=$wpdb->prefix;
$table=$originalPrefix.'ci_cleanup_policy_'.bin2hex(random_bytes(4)).'_oai_operations';
$prefix=substr($table,0,-strlen('oai_operations'));
cpAssert($wpdb->query('CREATE TABLE '.$table.' LIKE '.OAI_DB::table())!==false,'Cannot create isolated operation fixture');

$insert=function($status,$type='post_title',$before=null,$meta=null)use($wpdb,$table){
 $data=['operation_id'=>'CLEANUP-POLICY-'.bin2hex(random_bytes(8)),'type'=>$type,'status'=>$status,
  'target'=>'fixture','created_at'=>current_time('mysql',true)];
 if($before!==null)$data['before_data']=$before;
 if($meta!==null)$data['meta_data']=$meta;
 cpAssert($wpdb->insert($table,$data)===1,'Could not insert '.$status);
 return (int)$wpdb->insert_id;
};

try{
 $wpdb->prefix=$prefix;
 $active=['pending','approved','applying','rollback_pending','rolling_back'];
 foreach($active as $status)$insert($status,'post_title','ACTIVE-BEFORE-'.$status);
 $terminal=['rolled_back','rejected','expired','cancelled','failed','apply_failed'];
 $terminalIds=[];
 foreach($terminal as $status)$terminalIds[]=$insert($status);
 $appliedId=$insert('applied');
 $rollbackFailedId=$insert('rollback_failed','post_title','BEFORE-ROLLBACK-FAILED');
 $verificationFailedId=$insert('verification_failed','post_title','BEFORE-VERIFICATION-FAILED');
 // Recovery status plus creation evidence stays protected in every mode.
 $creationRecoveryId=$insert('rollback_failed','elementor_page_create','BEFORE-CREATION');
 // Historical rows without the marker but with a creation marker are protected too.
 $markerRecoveryId=$insert('verification_failed','post_title','BEFORE-MARKER','{"_wpai_creation_intent":"history"}');

 $total=count($active)+count($terminal)+1+2+1+1;
 cpAssert(OAI_DB::count_all()===$total,'Fixture count incorrect');

 /* --------------------------------------------------- default dry run --- */
 $dry=OAI_Operations_Cleanup::run([]);
 cpAssert(!is_wp_error($dry),'Dry run failed');
 cpAssert($dry['mode']==='dry_run'&&$dry['deleted']===0,'Dry run is not the default');
 cpAssert($dry['eligible']===count($terminal),'Default dry run does not allow exactly the ordinary terminal rows');
 $byStatus=$dry['eligible_by_status']??[];
 cpAssert(count($byStatus)===count($terminal),'Eligible status set is wrong');
 foreach($terminal as $status)cpAssert(($byStatus[$status]??0)===1,'Missing eligible status '.$status);
 cpAssert(empty($dry['include_failed_recovery']),'Failed recovery is enabled by default');
 cpAssert(($dry['recovery_evidence_protected']??false)===true,'Dry run does not report protected recovery evidence');
 cpAssert(($dry['protected_recovery']['rollback_failed']??0)===2,'rollback_failed protection count wrong');
 cpAssert(($dry['protected_recovery']['verification_failed']??0)===2,'verification_failed protection count wrong');
 cpAssert(($dry['protected_recovery_total']??0)===4,'Recovery protection total wrong');
 foreach($active as $status)cpAssert(((int)($dry['protected_active'][$status]??0))===1,'Active protection count wrong for '.$status);
 cpAssert(count($dry['protected_active']??[])===count($active),'Active protection reported unexpected statuses');

 /* ---------------------------------------------------------- max_id ----- */
 $bounded=OAI_Operations_Cleanup::run(['dry_run'=>true,'max_id'=>$terminalIds[0]-1]);
 cpAssert(!is_wp_error($bounded)&&$bounded['eligible']===0,'max_id lower bound did not restrict the set');
 $unbounded=OAI_Operations_Cleanup::run(['dry_run'=>true,'max_id'=>$markerRecoveryId]);
 cpAssert(!is_wp_error($unbounded)&&$unbounded['eligible']===count($terminal),'max_id upper bound excluded ordinary rows');

 /* -------------------------------------------- applied stays separate --- */
 $withApplied=OAI_Operations_Cleanup::run(['dry_run'=>true,'include_applied'=>true]);
 cpAssert(!is_wp_error($withApplied)&&$withApplied['eligible']===count($terminal)+1,'include_applied did not add only the applied row');
 cpAssert(($withApplied['protected_recovery_total']??0)===4,'include_applied changed recovery protection');
 cpAssert(empty($withApplied['include_failed_recovery']),'include_applied implied failed recovery');

 /* ------------------------------------------- real cleanup, no opt-in --- */
 $clean=OAI_Operations_Cleanup::run(['dry_run'=>false]);
 cpAssert(!is_wp_error($clean),'Default cleanup failed');
 cpAssert($clean['deleted']===count($terminal),'Default cleanup deleted the wrong number of rows');
 cpAssert(($clean['include_failed_recovery']??null)===false,'Cleanup did not report the opt-in state');
 cpAssert(($clean['recovery_evidence_protected']??false)===true,'Cleanup did not report protected evidence');
 cpAssert(OAI_DB::count_all()===$total-count($terminal),'Default cleanup removed protected rows');

 foreach([$rollbackFailedId=>'BEFORE-ROLLBACK-FAILED',$verificationFailedId=>'BEFORE-VERIFICATION-FAILED',$creationRecoveryId=>'BEFORE-CREATION',$markerRecoveryId=>'BEFORE-MARKER'] as $id=>$expected){
  $row=OAI_DB::get($id);
  cpAssert(is_array($row)&&(string)$row['before_data']===$expected,'Recovery evidence was deleted or truncated for id '.$id);
 }
 cpAssert(OAI_DB::get($appliedId)!==null,'Applied row was deleted without include_applied');
 foreach($active as $status){
  cpAssert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status=%s",$status))===1,'Active status lost: '.$status);
 }
 cpAssert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE type=%s",'elementor_page_create'))===1,'Creation evidence was erased');
 cpAssert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE meta_data LIKE %s",'%_wpai_creation_intent%'))===1,'Historical marker was erased');

 /* --------------------------------------------- explicit opt-in only --- */
 $optIn=OAI_Operations_Cleanup::run(['dry_run'=>true,'include_failed_recovery'=>true]);
 cpAssert(!is_wp_error($optIn),'Opt-in dry run failed');
 cpAssert($optIn['eligible']===2,'Opt-in did not expose exactly the two unprotected recovery rows');
 cpAssert(($optIn['include_failed_recovery']??false)===true,'Opt-in flag was not reported');
 cpAssert(($optIn['recovery_evidence_protected']??true)===false,'Opt-in still claims evidence protection');
 cpAssert(($optIn['protected_recovery_total']??0)===4,'Protected recovery accounting changed under opt-in');

 $optInRun=OAI_Operations_Cleanup::run(['dry_run'=>false,'include_failed_recovery'=>true]);
 cpAssert(!is_wp_error($optInRun)&&$optInRun['deleted']===2,'Opt-in cleanup did not delete the two recovery rows');
 cpAssert(OAI_DB::get($rollbackFailedId)===null&&OAI_DB::get($verificationFailedId)===null,'Recovery rows survived the explicit opt-in');
 // Even with the opt-in, creation evidence and historical markers stay.
 cpAssert(OAI_DB::get($creationRecoveryId)!==null,'Opt-in erased creation evidence');
 cpAssert(OAI_DB::get($markerRecoveryId)!==null,'Opt-in erased a historical marker');
 cpAssert(OAI_DB::get($appliedId)!==null,'Opt-in erased applied rows without include_applied');

 $empty=OAI_Operations_Cleanup::run([]);
 cpAssert(!is_wp_error($empty)&&$empty['eligible']===0,'Cleanup is not idempotent');
 cpAssert(($empty['protected_recovery_total']??0)===2,'Remaining protection count wrong');

 /* ------------------------------------------------- audit + permissions - */
 $auditAfter=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$auditTable);
 cpAssert($auditAfter===$auditBefore,'Cleanup touched the audit log');

 wp_set_current_user(0);
 $denied=OAI_Operations_Cleanup::run(['dry_run'=>false,'include_applied'=>true,'include_failed_recovery'=>true]);
 cpAssert(is_wp_error($denied)&&$denied->get_error_code()==='oai_cleanup_forbidden','Anonymous cleanup was allowed');
 wp_set_current_user(1);

 echo 'PASS operations cleanup policy: '.CleanupPolicyChecks::$count." checks; recovery evidence protected by default, opt-in separate from applied, audit preserved\n";
}finally{
 wp_set_current_user(1);$wpdb->prefix=$originalPrefix;$wpdb->query('DROP TABLE '.$table);
}
