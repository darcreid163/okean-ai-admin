<?php
/** Safe bounded cleanup of terminal operation ledger rows. */
if (!defined('ABSPATH')) exit;

class OAI_Operations_Cleanup {
 const ACTIVE_STATUSES=array('pending','approved','applying','rollback_pending','rolling_back');
 const TERMINAL_STATUSES=array('rolled_back','rejected','expired','cancelled','failed','apply_failed');
 // A failed rollback or a failed verification keeps the only copy of the
 // evidence an operator needs to reconstruct and repair the site. Generic
 // retention must never destroy it; deletion requires a separate explicit
 // administrator opt-in (`include_failed_recovery`).
 const RECOVERY_STATUSES=array('rollback_failed','verification_failed');
 // Created artifacts can outlive a lost response and must remain reconcilable.
 const CREATION_EVIDENCE_TYPES=array('media_upload','menu_create','menu_add_item','woocommerce_product_create','elementor_page_create','post_create');

 public static function self_test_contract(){
  return array(
   'dry_run_default'=>true,
   'active_statuses_protected'=>true,
   'rollback_pending_protected'=>in_array('rollback_pending',self::ACTIVE_STATUSES,true),
   'applied_requires_explicit_opt_in'=>true,
   'audit_log_preserved'=>true,
   'failed_terminal_statuses_supported'=>true,
   'recovery_evidence_protected_by_default'=>!array_intersect(self::RECOVERY_STATUSES,self::TERMINAL_STATUSES)&&count(array_intersect(self::RECOVERY_STATUSES,self::ACTIVE_STATUSES))===0,
   'failed_recovery_requires_explicit_opt_in'=>!in_array('rollback_failed',self::statuses(false,false),true)&&!in_array('verification_failed',self::statuses(false,false),true),
   'include_failed_recovery_separate_from_applied'=>in_array('applied',self::statuses(true,false),true)===in_array('applied',self::statuses(true,true),true)&&!in_array('rollback_failed',self::statuses(true,false),true),
   'admin_required'=>true,
   'media_creation_evidence_protected'=>true,
   'all_artifact_creation_evidence_protected'=>true,
  );
 }

 private static function statuses($include_applied,$include_failed_recovery){
  $s=self::TERMINAL_STATUSES;
  if($include_applied)$s[]='applied';
  if($include_failed_recovery)foreach(self::RECOVERY_STATUSES as $status)$s[]=$status;
  return $s;
 }

 private static function placeholders($count){return implode(',',array_fill(0,$count,'%s'));}

 public static function run($args=array()){
  if(!current_user_can('manage_options'))return new WP_Error('oai_cleanup_forbidden','Administrator capability required',array('status'=>403));
  global $wpdb;
  $table=OAI_DB::table();
  $dry_run=!array_key_exists('dry_run',$args)||!empty($args['dry_run']);
  $include_applied=!empty($args['include_applied']);
  $include_failed_recovery=!empty($args['include_failed_recovery']);
  $max_id=absint($args['max_id']??0);
  $statuses=self::statuses($include_applied,$include_failed_recovery);
  $ph=self::placeholders(count($statuses));
  // Journal-bound media rows are recovery evidence. Generic retention must
  // not remove them before a dedicated journal/evidence reconciliation.
  $types=self::CREATION_EVIDENCE_TYPES;
  $tph=self::placeholders(count($types));
  // Protect journal-backed media and all artifact creation rows, including
  // historical rows without a marker. Losing one of these rows destroys the
  // evidence needed to settle a durable option claim after an unknown outcome.
  $where="status IN ($ph) AND LOCATE(%s,COALESCE(meta_data,''))=0 AND type NOT IN ($tph)";
  $params=array_merge($statuses,array('"_wpai_creation_intent"'),$types);
  if($max_id>0){$where.=' AND id <= %d';$params[]=$max_id;}

  $sql="SELECT status,COUNT(*) AS c FROM $table WHERE $where GROUP BY status ORDER BY status";
  $rows=$wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A);
  if($wpdb->last_error)return new WP_Error('oai_cleanup_query','Could not inspect operations',array('status'=>500,'db_error'=>$wpdb->last_error));
  $counts=array();$eligible=0;
  foreach((array)$rows as $r){$n=(int)$r['c'];$counts[(string)$r['status']]=$n;$eligible+=$n;}

  $active_counts=array();
  $aph=self::placeholders(count(self::ACTIVE_STATUSES));
  $arows=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS c FROM $table WHERE status IN ($aph) GROUP BY status ORDER BY status",self::ACTIVE_STATUSES),ARRAY_A);
  foreach((array)$arows as $r)$active_counts[(string)$r['status']]=(int)$r['c'];

  // Recovery evidence is reported even when it is deletable, so an operator
  // can see exactly what the current policy would destroy.
  $recovery_counts=array();$recovery_total=0;
  $rph=self::placeholders(count(self::RECOVERY_STATUSES));
  $rrows=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS c FROM $table WHERE status IN ($rph) GROUP BY status ORDER BY status",self::RECOVERY_STATUSES),ARRAY_A);
  foreach((array)$rrows as $r){$recovery_counts[(string)$r['status']]=(int)$r['c'];$recovery_total+=(int)$r['c'];}

  $sample_sql="SELECT id,operation_id,status,type,target,created_at FROM $table WHERE $where ORDER BY id DESC LIMIT 20";
  $sample=$wpdb->get_results($wpdb->prepare($sample_sql,$params),ARRAY_A);

  if($dry_run){
   return array(
    'ok'=>true,'mode'=>'dry_run','eligible'=>$eligible,'eligible_by_status'=>$counts,
    'protected_active'=>$active_counts,'protected_recovery'=>$recovery_counts,
    'protected_recovery_total'=>$recovery_total,
    'recovery_evidence_protected'=>!$include_failed_recovery,
    'include_applied'=>$include_applied,'include_failed_recovery'=>$include_failed_recovery,'max_id'=>$max_id?:null,
    'sample'=>$sample,'audit_log_preserved'=>true,'deleted'=>0
   );
  }

  if($eligible===0)return array('ok'=>true,'mode'=>'execute','eligible'=>0,'deleted'=>0,'remaining'=>OAI_DB::count_all(),'protected_recovery'=>$recovery_counts,'protected_recovery_total'=>$recovery_total,'recovery_evidence_protected'=>!$include_failed_recovery,'audit_log_preserved'=>true);

  $delete_sql="DELETE FROM $table WHERE $where";
  $deleted=$wpdb->query($wpdb->prepare($delete_sql,$params));
  if($deleted===false)return new WP_Error('oai_cleanup_delete','Could not delete terminal operations',array('status'=>500,'db_error'=>$wpdb->last_error));

  $remaining=OAI_DB::count_all();
  return array(
   'ok'=>true,'mode'=>'execute','requested'=>$eligible,'deleted'=>(int)$deleted,
   'deleted_by_status'=>$counts,'include_applied'=>$include_applied,'include_failed_recovery'=>$include_failed_recovery,'max_id'=>$max_id?:null,
   'remaining'=>$remaining,'protected_active'=>$active_counts,
   'protected_recovery'=>$recovery_counts,'protected_recovery_total'=>$recovery_total,
   'recovery_evidence_protected'=>!$include_failed_recovery,
   'audit_log_preserved'=>true
  );
 }
}

