<?php
// Native operation cleanup acceptance on disposable WordPress.
function cleanup_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
global $wpdb;

$table=OAI_DB::table();
$audit=OAI_DB::audit_table();
$now=gmdate('Y-m-d H:i:s');
$wpdb->query("DELETE FROM $table");
$wpdb->query("DELETE FROM $audit");

$insert=function(string $status,string $suffix) use($wpdb,$table,$now){
    $ok=$wpdb->insert($table,array(
        'operation_id'=>'cleanup-'.$suffix,
        'status'=>$status,
        'type'=>'option_update',
        'target'=>'cleanup_'.$suffix,
        'before_data'=>'"before"',
        'after_data'=>'"after"',
        'risk'=>'low',
        'created_at'=>$now
    ));
    if(!$ok)throw new RuntimeException('Unable to seed cleanup operation '.$suffix.': '.$wpdb->last_error);
    return (int)$wpdb->insert_id;
};

$pending=$insert('pending','pending');
$approved=$insert('approved','approved');
$applying=$insert('applying','applying');
$rollbackPending=$insert('rollback_pending','rollback-pending');
$rolling=$insert('rolling_back','rolling');
$applied=$insert('applied','applied');
$failed1=$insert('failed','failed1');
$rollbackFailed=$insert('rollback_failed','rollback-failed');
$rejected=$insert('rejected','rejected');

foreach([$pending,$applied,$failed1,$rollbackFailed] as $id){
    $row=OAI_DB::get($id);
    OAI_DB::audit_insert(array(
        'operation_id'=>$row['operation_id'],
        'operation_db_id'=>$id,
        'event'=>'cleanup_fixture',
        'status'=>$row['status'],
        'actor'=>1,
        'target'=>$row['target'],
        'details'=>'fixture',
        'created_at'=>$now
    ));
}
$auditBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $audit");

$dry=OAI_Operations_Cleanup::run();
cleanup_expect(!is_wp_error($dry)&&($dry['mode']??'')==='dry_run','Cleanup default was not dry-run');
cleanup_expect(($dry['deleted']??-1)===0,'Dry-run deleted operations');
cleanup_expect(($dry['include_applied']??true)===false,'Dry-run unexpectedly included applied');
cleanup_expect(OAI_DB::count_all()===9,'Dry-run changed operation count');

$exec=OAI_Operations_Cleanup::run(array('dry_run'=>false));
cleanup_expect(!is_wp_error($exec)&&($exec['mode']??'')==='execute','Terminal cleanup execute failed');
cleanup_expect(OAI_DB::get($pending)!==null&&OAI_DB::get($approved)!==null&&OAI_DB::get($applying)!==null&&OAI_DB::get($rollbackPending)!==null&&OAI_DB::get($rolling)!==null,'Active operation was deleted');
cleanup_expect(OAI_DB::get($applied)!==null,'Applied operation deleted without explicit opt-in');
cleanup_expect(OAI_DB::get($failed1)===null&&OAI_DB::get($rollbackFailed)===null&&OAI_DB::get($rejected)===null,'Terminal operation survived cleanup');
cleanup_expect((int)$wpdb->get_var("SELECT COUNT(*) FROM $audit")===$auditBefore,'Cleanup deleted audit evidence');

$oldApplied=$applied;
$newFailed=$insert('failed','failed2');
$newApplied=$insert('applied','applied2');
$bounded=OAI_Operations_Cleanup::run(array('dry_run'=>false,'include_applied'=>true,'max_id'=>$newFailed));
cleanup_expect(!is_wp_error($bounded),'Bounded cleanup failed');
cleanup_expect(OAI_DB::get($oldApplied)===null,'Explicit include_applied did not delete eligible old applied row');
cleanup_expect(OAI_DB::get($newFailed)===null,'Bounded cleanup did not delete max_id terminal row');
cleanup_expect(OAI_DB::get($newApplied)!==null,'Bounded cleanup crossed max_id');

wp_set_current_user(0);
$forbidden=OAI_Operations_Cleanup::run(array('dry_run'=>false,'include_applied'=>true));
cleanup_expect(is_wp_error($forbidden)&&$forbidden->get_error_code()==='oai_cleanup_forbidden','Non-admin cleanup was not denied');
cleanup_expect(OAI_DB::get($newApplied)!==null,'Denied cleanup mutated operation state');

echo "PASS native operation cleanup safety\n";
