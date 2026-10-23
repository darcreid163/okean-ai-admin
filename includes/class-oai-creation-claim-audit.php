<?php
/**
 * Bounded, read-only diagnostics for creation-intent reservations.
 * This is NOT a reconciliation, release, retry, rollback or cleanup command.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Creation_Claim_Audit {
 const FAMILIES=array(
  'media'=>'oai_media_creation_',
  'workspace'=>'oai_workspace_creation_',
  'menu'=>'oai_menu_creation_',
  'menu_item'=>'oai_menu_item_creation_',
  'product'=>'oai_product_creation_',
  'elementor_page'=>'oai_elementor_page_creation_',
 );
 const MAX_PER_FAMILY=100;

 private static function metadata($raw) {
  if(!is_string($raw)||strlen($raw)>65536)return null;
  $json=json_decode($raw,true,32);
  if(json_last_error()===JSON_ERROR_NONE&&is_array($json))return $json;
  // Do not allow arbitrary PHP object construction from stored values.
  try{$arr=@unserialize($raw,array('allowed_classes'=>false));}
  catch(Throwable $e){return null;}
  return is_array($arr)?$arr:null;
 }

 public static function summary($request=null) {
  if(!current_user_can('manage_options'))
   return new WP_Error('oai_creation_audit_forbidden','Administrator capability required',array('status'=>403));
  $limit=50;
  if($request instanceof WP_REST_Request && $request->has_param('limit')){
   $raw=$request->get_param('limit');
   if(!(is_int($raw)||is_string($raw)&&preg_match('/^[1-9][0-9]{0,2}$/D',$raw))||
       (int)$raw<1||(int)$raw>self::MAX_PER_FAMILY)
    return new WP_Error('oai_creation_audit_limit','Invalid per-family limit',array('status'=>400));
   $limit=(int)$raw;
  }
  global $wpdb;
  $results=array();
  foreach(self::FAMILIES as $family=>$prefix){
   $sql=$wpdb->prepare(
    "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
    $wpdb->esc_like($prefix).'%', $limit+1
   );
   $rows=$wpdb->get_results($sql,ARRAY_A);
   if(!is_array($rows)||$wpdb->last_error!=='')
    return new WP_Error('oai_creation_audit_unavailable','Creation audit database unavailable',array('status'=>503));
   $truncated=count($rows)>$limit;
   $rows=array_slice($rows,0,$limit);
   $group=array(
    'sampled'=>count($rows),'truncated'=>$truncated,
    'declared_reserved'=>0,'declared_other_state'=>0,'state_unknown'=>0,
    'with_operation_reference'=>0,'operation_reference_invalid'=>0,
    'referenced_operation_same_type'=>0,'referenced_operation_other_type'=>0,
    'referenced_operation_missing'=>0,'with_creation_time'=>0,
    'older_than_24h'=>0,'unreadable_metadata'=>0
   );
   $expected_types=array(
    'media'=>'media_upload','menu'=>'menu_create','menu_item'=>'menu_add_item',
    'product'=>'woocommerce_product_create','elementor_page'=>'elementor_page_create'
   );
   $referenced=array();
   foreach($rows as $record){
    $meta=self::metadata($record['option_value']??null);
    if($meta===null){$group['unreadable_metadata']++;$group['state_unknown']++;continue;}
    $state=$meta['state']??null;
    if($state==='reserved')$group['declared_reserved']++;
    elseif(is_string($state)&&$state!=='')$group['declared_other_state']++;
    else $group['state_unknown']++;
    if(isset($meta['operation_id'])&&$meta['operation_id']!==''&&$meta['operation_id']!==null){
     $group['with_operation_reference']++;
     $reference=$meta['operation_id'];
     if((is_int($reference)||is_string($reference))&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$reference))
      $referenced[]=(int)$reference;
     else $group['operation_reference_invalid']++;
    }
    $created=$meta['created_at']??null;
    if(is_int($created)||is_string($created)&&preg_match('/^[0-9]{10}$/D',$created)){
     $created=(int)$created;
     if($created>0 && $created<=time()){
      $group['with_creation_time']++;
      if($created<=time()-DAY_IN_SECONDS)$group['older_than_24h']++;
     }
    }
   }
   // One bounded ledger lookup per family, not a query per claim.
   // A matching operation TYPE is only preliminary evidence; it does not
   // prove the operation belongs to the option claim or succeeded.
   if($referenced){
    $unique=array_values(array_unique($referenced));
    $marks=implode(',',array_fill(0,count($unique),'%d'));
    $table=OAI_DB::table();
    $found=$wpdb->get_results($wpdb->prepare("SELECT id,type FROM $table WHERE id IN ($marks)",$unique),ARRAY_A);
    if(!is_array($found)||$wpdb->last_error!=='')
     return new WP_Error('oai_creation_audit_unavailable','Creation audit database unavailable',array('status'=>503));
    $types=array();
    foreach($found as $entry)$types[(int)$entry['id']]=(string)$entry['type'];
    foreach($referenced as $id){
     if(!isset($types[$id]))$group['referenced_operation_missing']++;
     elseif(($expected_types[$family]??null)===$types[$id])$group['referenced_operation_same_type']++;
     else $group['referenced_operation_other_type']++;
    }
   }
   $results[$family]=$group;
  }
  return array(
   'ok'=>true,'read_only'=>true,'schema'=>'oai/creation-claims-summary/v1',
   'per_family_limit'=>$limit,'complete'=>!array_reduce($results,static function($carry,$r){return $carry||$r['truncated'];},false),
   'families'=>$results,
   'reconciliation'=>'not_performed',
   'warning'=>'Counts and stored states are not proof of whether an artifact was created. Do not release a reservation by age or missing operation reference.'
  );
 }
}
