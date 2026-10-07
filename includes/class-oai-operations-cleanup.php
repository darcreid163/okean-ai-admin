<?php
/** Safe bounded cleanup of terminal operation ledger rows. */
if (!defined('ABSPATH')) exit;

class OAI_Operations_Cleanup {
 const ACTIVE_STATUSES=array('pending','approved','applying','rolling_back');
 const TERMINAL_STATUSES=array('rolled_back','rejected','expired','cancelled','failed','apply_failed','rollback_failed','verification_failed');

 public static function self_test_contract(){
  return array(
   'dry_run_default'=>true,
   'active_statuses_protected'=>true,
   'applied_requires_explicit_opt_in'=>true,
   'audit_log_preserved'=>true,
   'failed_terminal_statuses_supported'=>true,
   'admin_required'=>true,
  );
 }

 private static function statuses($include_applied){
  $s=self::TERMINAL_STATUSES;
  if($include_applied)$s[]='applied';
  return $s;
 }

 private static function placeholders($count){return implode(',',array_fill(0,$count,'%s'));}

 public static function run($args=array()){
  if(!current_user_can('manage_options'))return new WP_Error('oai_cleanup_forbidden','Administrator capability required',array('status'=>403));
  global $wpdb;
  $table=OAI_DB::table();
  $dry_run=!array_key_exists('dry_run',$args)||!empty($args['dry_run']);
  $include_applied=!empty($args['include_applied']);
  $max_id=absint($args['max_id']??0);
  $statuses=self::statuses($include_applied);
  $ph=self::placeholders(count($statuses));
  $where="status IN ($ph)";
  $params=$statuses;
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

  $sample_sql="SELECT id,operation_id,status,type,target,created_at FROM $table WHERE $where ORDER BY id DESC LIMIT 20";
  $sample=$wpdb->get_results($wpdb->prepare($sample_sql,$params),ARRAY_A);

  if($dry_run){
   return array(
    'ok'=>true,'mode'=>'dry_run','eligible'=>$eligible,'eligible_by_status'=>$counts,
    'protected_active'=>$active_counts,'include_applied'=>$include_applied,'max_id'=>$max_id?:null,
    'sample'=>$sample,'audit_log_preserved'=>true,'deleted'=>0
   );
  }

  if($eligible===0)return array('ok'=>true,'mode'=>'execute','eligible'=>0,'deleted'=>0,'remaining'=>OAI_DB::count_all(),'audit_log_preserved'=>true);

  $delete_sql="DELETE FROM $table WHERE $where";
  $deleted=$wpdb->query($wpdb->prepare($delete_sql,$params));
  if($deleted===false)return new WP_Error('oai_cleanup_delete','Could not delete terminal operations',array('status'=>500,'db_error'=>$wpdb->last_error));

  $remaining=OAI_DB::count_all();
  return array(
   'ok'=>true,'mode'=>'execute','requested'=>$eligible,'deleted'=>(int)$deleted,
   'deleted_by_status'=>$counts,'include_applied'=>$include_applied,'max_id'=>$max_id?:null,
   'remaining'=>$remaining,'protected_active'=>$active_counts,'audit_log_preserved'=>true
  );
 }
}

