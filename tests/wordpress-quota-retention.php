<?php
// Native quota-event retention acceptance on disposable WordPress.
function quota_ret_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
global $wpdb;

$table=OAI_DB::quota_events_table();
$wpdb->query("DELETE FROM $table");
delete_option('oai_quota_events_pruned_at');

$old=gmdate('Y-m-d H:i:s',time()-91*DAY_IN_SECONDS);
$fresh=gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS);
$wpdb->insert($table,array('request_key'=>str_repeat('a',64),'channel'=>'release-test','units'=>1,'created_at'=>$old));
$wpdb->insert($table,array('request_key'=>str_repeat('b',64),'channel'=>'release-test','units'=>1,'created_at'=>$fresh));
quota_ret_expect((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===2,'Quota retention fixture insert failed');

OAI_DB::prune_quota_events();
quota_ret_expect((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE request_key=%s",str_repeat('a',64)))===0,'Quota event older than 90 days survived prune');
quota_ret_expect((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE request_key=%s",str_repeat('b',64)))===1,'Recent quota event was pruned');
$stamp=(int)get_option('oai_quota_events_pruned_at',0);
quota_ret_expect($stamp>0,'Quota prune timestamp missing');

// Same-day second invocation must be throttled and therefore preserve a newly inserted old row.
$wpdb->insert($table,array('request_key'=>str_repeat('c',64),'channel'=>'release-test','units'=>1,'created_at'=>$old));
OAI_DB::prune_quota_events();
quota_ret_expect((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE request_key=%s",str_repeat('c',64)))===1,'Quota prune throttle did not prevent repeated cleanup');
quota_ret_expect((int)get_option('oai_quota_events_pruned_at',0)===$stamp,'Quota prune throttle changed timestamp unexpectedly');

// Advance cleanup lock beyond one day and verify cleanup resumes.
update_option('oai_quota_events_pruned_at',time()-DAY_IN_SECONDS-60,false);
OAI_DB::prune_quota_events();
quota_ret_expect((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE request_key=%s",str_repeat('c',64)))===0,'Quota cleanup did not resume after throttle window');

echo "PASS native quota event retention\n";
