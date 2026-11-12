<?php
/**
 * Explicit, authorized operator reconciliation for durable creation claims.
 *
 * The read-only OAI_Creation_Claim_Audit inventory is not a reconciliation
 * tool. This class is the only path that may change a creation claim state.
 * It never releases a claim by age, a missing reply or a single failed
 * lookup; every change requires an authenticated administrator, the exact
 * current claim generation and operator evidence. Artifacts are never
 * created, edited or deleted here, and claims are never deleted.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Creation_Claim_Reconciler {
 const SCHEMA='oai/creation-claim-reconciliation/v1';
 const RETENTION_SCHEMA='oai/creation-claim-retention/v1';
 const MIN_EVIDENCE_LINKED=20;
 const MIN_EVIDENCE_ABSENT=40;
 const MAX_EVIDENCE=2000;

 const FAMILIES=array(
  'media'=>array('prefix'=>'oai_media_creation_','operation_type'=>'media_upload','artifact'=>'attachment'),
  'workspace'=>array('prefix'=>'oai_workspace_creation_','operation_type'=>null,'artifact'=>'workspace'),
  'menu'=>array('prefix'=>'oai_menu_creation_','operation_type'=>'menu_create','artifact'=>'nav_menu'),
  'menu_item'=>array('prefix'=>'oai_menu_item_creation_','operation_type'=>'menu_add_item','artifact'=>'nav_menu_item'),
  'product'=>array('prefix'=>'oai_product_creation_','operation_type'=>'woocommerce_product_create','artifact'=>'product'),
  'elementor_page'=>array('prefix'=>'oai_elementor_page_creation_','operation_type'=>'elementor_page_create','artifact'=>'page'),
 );

 /** Retention is policy metadata only. Nothing here deletes a claim. */
 public static function retention_policy(){
  return array(
   'policy_version'=>self::RETENTION_SCHEMA,
   'unresolved'=>'held_indefinitely',
   'reconciled_created'=>'retained_with_linked_evidence',
   'reconciled_absent'=>'retained_for_audit',
   'automatic_deletion'=>false,
   'age_or_missing_reply_releases'=>false,
   'sandbox_snapshot_expiry_releases'=>false,
   'operator_purge_available'=>false,
  );
 }

 /** REST callback for POST /diagnostics/creation-intents/reconcile. */
 public static function reconcile_route($request){return self::reconcile($request);}

 /**
  * @param WP_REST_Request|array $request
  * @return array|WP_Error
  */
 public static function reconcile($request){
  if(!current_user_can('manage_options'))
   return new WP_Error('oai_creation_reconcile_forbidden','Administrator capability required',array('status'=>403));
  $p=self::params($request);
  $family=isset($p['family'])&&is_string($p['family'])?$p['family']:'';
  if(!isset(self::FAMILIES[$family]))
   return new WP_Error('oai_creation_family','Unknown creation claim family',array('status'=>400));
  $cfg=self::FAMILIES[$family];
  $claim_id=isset($p['claim_id'])&&is_string($p['claim_id'])?strtolower($p['claim_id']):'';
  if(!preg_match('/^[a-f0-9]{64}$/D',$claim_id))
   return new WP_Error('oai_creation_claim_id','A full creation claim identifier is required',array('status'=>400));
  $decision=isset($p['decision'])&&is_string($p['decision'])?$p['decision']:'';
  if(!in_array($decision,array('linked','absent'),true))
   return new WP_Error('oai_creation_decision','Decision must be linked or absent',array('status'=>400));
  $expected=isset($p['expected'])&&is_string($p['expected'])?strtolower($p['expected']):'';
  if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D',$expected))
   return new WP_Error('oai_creation_expected','The exact current claim generation is required',array('status'=>400));
  $note=isset($p['evidence_note'])&&is_string($p['evidence_note'])?trim($p['evidence_note']):'';
  $minimum=$decision==='absent'?self::MIN_EVIDENCE_ABSENT:self::MIN_EVIDENCE_LINKED;
  if(strlen($note)<$minimum||strlen($note)>self::MAX_EVIDENCE)
   return new WP_Error('oai_creation_evidence','Operator evidence must be '.$minimum.'-'.self::MAX_EVIDENCE.' characters',array('status'=>400));
  if($note!==''&&@preg_match('//u',$note)!==1)
   return new WP_Error('oai_creation_evidence','Operator evidence is not valid UTF-8',array('status'=>400));

  global $wpdb;
  $name=$cfg['prefix'].$claim_id;
  $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
  if($raw===null){
   if($wpdb->last_error!=='')return new WP_Error('oai_creation_claim_storage','Creation claim storage unavailable',array('status'=>503));
   return new WP_Error('oai_creation_claim_not_found','Creation claim not found',array('status'=>404));
  }
  $record=self::decode($raw);
  if(!is_array($record))
   return new WP_Error('oai_creation_claim_unreadable','Creation claim metadata is not reconcilable; do not guess',array('status'=>422));
  $tokens=array();
  if(isset($record['claim'])&&is_string($record['claim'])&&preg_match('/^[a-f0-9]{32}$/D',$record['claim']))
   $tokens[]=$record['claim'];
  if(isset($record['payload_fingerprint'])&&is_string($record['payload_fingerprint'])&&preg_match('/^[a-f0-9]{64}$/D',$record['payload_fingerprint']))
   $tokens[]=$record['payload_fingerprint'];
  $generation_ok=false;
  foreach($tokens as $token)if(hash_equals($token,$expected)){$generation_ok=true;break;}
  if(!$generation_ok)
   return new WP_Error('oai_creation_claim_conflict','Creation claim generation changed; reload the inventory before reconciling',array('status'=>409));

  $artifact_id=self::artifact_param($p,$family,$record);
  if(is_wp_error($artifact_id))return $artifact_id;
  $operation_id=self::non_negative_int($p['operation_id']??0);
  if($operation_id===null)
   return new WP_Error('oai_creation_operation','Invalid operation id',array('status'=>400));
  if($decision==='linked'){
   if($cfg['operation_type']!==null&&$operation_id<1)
    return new WP_Error('oai_creation_operation','A linked operation id is required for this family',array('status'=>400));
   if($cfg['operation_type']===null&&$operation_id!==0)
    return new WP_Error('oai_creation_operation','This family has no linked operation',array('status'=>400));
  }elseif($operation_id!==0){
   // An absent artifact has no linked operation. Requiring or accepting an
   // operation id here would force the operator to fabricate a link.
   return new WP_Error('oai_creation_operation','An absent artifact cannot be linked to an operation',array('status'=>400));
  }

  $state=isset($record['state'])&&is_string($record['state'])?$record['state']:'';
  $evidence_sha256=hash('sha256',$note);
  if(in_array($state,array('reconciled_created','reconciled_absent'),true)){
   $prior=isset($record['reconciliation'])&&is_array($record['reconciliation'])?$record['reconciliation']:array();
   if(($prior['decision']??'')===$decision
    &&(string)($prior['artifact_id']??'')===(string)$artifact_id
    &&(int)($prior['operation_id']??0)===$operation_id
    &&hash_equals((string)($prior['evidence_sha256']??''),$evidence_sha256))
    return self::result($family,$claim_id,$record,true);
   return new WP_Error('oai_creation_claim_already_reconciled','Creation claim already reconciled with another decision or evidence',array('status'=>409));
  }

  $operation_row=null;$lifecycle=self::empty_lifecycle();$link_strength='';
  if($decision==='linked'){
   $artifact=self::verify_artifact($family,$artifact_id,$record);
   if(is_wp_error($artifact))return $artifact;
   $link_strength=$artifact['link_strength'];
   if($cfg['operation_type']!==null){
    $operation=self::verify_operation($family,$artifact_id,$operation_id,$record);
    if(is_wp_error($operation))return $operation;
    $operation_row=$operation['row'];
    $lifecycle=$operation['lifecycle'];
    // The operation link is the authoritative evidence when one is required:
    // a strong intent/claim binding is reported as such, otherwise the link
    // is only the operation type plus target match.
    $link_strength=$operation['link_strength'];
   }
  }else{
   if(!isset($p['confirm_absent'])||$p['confirm_absent']!==true)
    return new WP_Error('oai_creation_absence_confirmation','Absence requires an explicit operator confirmation',array('status'=>400));
   $absence=self::verify_absence($family,$artifact_id,$record);
   if(is_wp_error($absence))return $absence;
   $link_strength='operator_confirmed_absence';
  }

  $now=time();
  $reconciliation=array(
   'schema'=>self::SCHEMA,
   'decision'=>$decision,
   'artifact_type'=>$cfg['artifact'],
   'artifact_id'=>(string)$artifact_id,
   'operation_id'=>$operation_id,
   'operation_status'=>$lifecycle['operation_status'],
   'link_strength'=>$link_strength,
   'evidence_sha256'=>$evidence_sha256,
   'evidence_length'=>strlen($note),
   'operator_id'=>get_current_user_id(),
   'reconciled_at'=>$now,
   'previous_state'=>$state!==''?$state:'reserved',
   'lifecycle'=>$lifecycle,
   'artifacts_modified'=>false,
  );
  $next=$record;
  $next['state']=$decision==='linked'?'reconciled_created':'reconciled_absent';
  $next['reconciled_at']=$now;
  $next['reconciled_by']=get_current_user_id();
  $next['reconciliation']=$reconciliation;
  $next['reconciliation_evidence']=array('note'=>$note,'sha256'=>$evidence_sha256);
  $next['reconciliation_version']=1;
  $next['retention']=array(
   'policy_version'=>self::RETENTION_SCHEMA,
   'hold'=>'indefinite',
   'automatic_deletion'=>false,
  );
  $json=self::is_json($raw);
  $next_raw=$json
   ?wp_json_encode($next,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
   :maybe_serialize($next);
  if(!is_string($next_raw)||$next_raw==='')
   return new WP_Error('oai_creation_claim_value','Reconciled creation claim cannot be encoded',array('status'=>503));
  $cas=OAI_DB::cas_creation_claim($name,$raw,$next_raw);
  if(is_wp_error($cas))return $cas;
  if($cas!==true)
   return new WP_Error('oai_creation_claim_conflict','Creation claim changed during reconciliation; reload and retry',array('status'=>409));

  $audit_row=$operation_row?:array('operation_id'=>'','id'=>null,'target'=>(string)$artifact_id);
  OAI_Audit::log('creation_claim_reconciled',$audit_row,array(
   'schema'=>self::SCHEMA,
   'family'=>$family,
   'claim_id'=>$claim_id,
   'decision'=>$decision,
   'artifact_type'=>$cfg['artifact'],
   'artifact_id'=>(string)$artifact_id,
   'operation_id'=>$operation_id,
   'operation_status'=>$lifecycle['operation_status'],
   'link_strength'=>$link_strength,
   'evidence_sha256'=>$evidence_sha256,
   'previous_state'=>$reconciliation['previous_state'],
  ));
  return self::result($family,$claim_id,$next,false);
 }

 private static function params($request){
  if($request instanceof WP_REST_Request){
   $json=$request->get_json_params();
   if(is_array($json)&&$json)return $json;
   $all=$request->get_params();
   return is_array($all)?$all:array();
  }
  return is_array($request)?$request:array();
 }

 private static function decode($raw){
  if(!is_string($raw)||strlen($raw)>65536)return null;
  $json=json_decode($raw,true,32);
  if(json_last_error()===JSON_ERROR_NONE&&is_array($json))return $json;
  try{$value=@unserialize($raw,array('allowed_classes'=>false));}
  catch(Throwable $e){return null;}
  return is_array($value)?$value:null;
 }

 /** Accept only a non-negative decimal integer; never reinterpret a negative id. */
 private static function non_negative_int($value){
  if(is_int($value))return $value>=0?$value:null;
  if(is_string($value)&&preg_match('/^[0-9]{1,18}$/D',$value))return (int)$value;
  return null;
 }

 /** Accept only a positive decimal integer; never reinterpret a negative id. */
 private static function positive_int($value){
  if(is_int($value))return $value>0?$value:null;
  if(is_string($value)&&preg_match('/^[1-9][0-9]{0,17}$/D',$value))return (int)$value;
  return null;
 }

 private static function is_json($raw){
  if(!is_string($raw)||$raw==='')return false;
  $first=ltrim($raw);
  return isset($first[0])&&$first[0]==='{'&&is_array(json_decode($raw,true,32));
 }

 private static function artifact_param(array $p,$family,$record){
  if($family==='workspace'){
   $id=isset($p['artifact_id'])&&is_string($p['artifact_id'])?$p['artifact_id']:'';
   if($id===''&&isset($record['id'])&&is_string($record['id']))$id=$record['id'];
   if(!preg_match('/^ws-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}$/D',$id))
    return new WP_Error('oai_creation_artifact','A valid workspace id is required',array('status'=>400));
   if(isset($record['id'])&&is_string($record['id'])&&$record['id']!==''&&$record['id']!==$id)
    return new WP_Error('oai_creation_artifact_mismatch','Workspace artifact does not match the claim',array('status'=>409));
   return $id;
  }
  $id=self::positive_int($p['artifact_id']??0);
  if($id===null)return new WP_Error('oai_creation_artifact','A positive artifact id is required',array('status'=>400));
  if($family==='media'&&isset($record['attachment_id'])&&(int)$record['attachment_id']>0&&(int)$record['attachment_id']!==$id)
   return new WP_Error('oai_creation_artifact_mismatch','Attachment does not match the claim',array('status'=>409));
  return $id;
 }

 private static function verify_artifact($family,$artifact_id,$record){
  $strength='';
  if($family==='workspace'){
   $uploads=wp_upload_dir(null,false);
   if(!is_array($uploads)||empty($uploads['basedir'])||!is_string($uploads['basedir']))
    return new WP_Error('oai_creation_artifact_unavailable','Uploads directory is unavailable',array('status'=>503));
   $root=trailingslashit($uploads['basedir']).'ai-admin-workspaces';
   if(is_link($root)||!is_dir($root))
    return new WP_Error('oai_creation_artifact_missing','Workspace root is missing or unsafe',array('status'=>409));
   $folder=$root.'/'.$artifact_id;
   if(is_link($folder))return new WP_Error('oai_creation_artifact_unsafe','Workspace artifact is a symlink',array('status'=>409));
   if(!is_dir($folder))return new WP_Error('oai_creation_artifact_missing','Workspace artifact is missing',array('status'=>409));
   $metadata=$folder.'/workspace.json';
   if(is_link($metadata)||!is_file($metadata))return new WP_Error('oai_creation_artifact_missing','Workspace metadata is missing or unsafe',array('status'=>409));
   $size=@filesize($metadata);
   if($size===false||$size>65536)return new WP_Error('oai_creation_artifact_mismatch','Workspace metadata is unreadable or oversized',array('status'=>409));
   $bytes=@file_get_contents($metadata);
   if(!is_string($bytes))return new WP_Error('oai_creation_artifact_unavailable','Workspace metadata is unreadable',array('status'=>503));
   $decoded=json_decode($bytes,true,32);
   if(!is_array($decoded)||($decoded['id']??'')!==$artifact_id)
    return new WP_Error('oai_creation_artifact_mismatch','Workspace metadata does not match the claim',array('status'=>409));
   $strength='workspace_directory_metadata';
  }elseif($family==='menu'){
   $menu=wp_get_nav_menu_object($artifact_id);
   if(!$menu||is_wp_error($menu))return new WP_Error('oai_creation_artifact_missing','Menu artifact is missing',array('status'=>409));
   if((int)$menu->term_id!==(int)$artifact_id)return new WP_Error('oai_creation_artifact_mismatch','Menu artifact does not match',array('status'=>409));
   $strength='nav_menu_operation_target';
  }elseif($family==='media'){
   $post=get_post($artifact_id);
   if(!$post||$post->post_type!=='attachment'||$post->post_status==='trash')
    return new WP_Error('oai_creation_artifact_missing','Attachment artifact is missing',array('status'=>409));
   $strength='attachment_operation_intent';
  }elseif($family==='menu_item'){
   $post=get_post($artifact_id);
   if(!$post||$post->post_type!=='nav_menu_item'||$post->post_status==='trash')
    return new WP_Error('oai_creation_artifact_missing','Menu item artifact is missing',array('status'=>409));
   $strength='nav_menu_item_operation_target';
  }elseif($family==='product'){
   $post=get_post($artifact_id);
   if(!$post||$post->post_type!=='product'||$post->post_status==='trash')
    return new WP_Error('oai_creation_artifact_missing','Product artifact is missing',array('status'=>409));
   $strength='product_operation_target';
  }elseif($family==='elementor_page'){
   $post=get_post($artifact_id);
   if(!$post||$post->post_type!=='page'||$post->post_status==='trash')
    return new WP_Error('oai_creation_artifact_missing','Page artifact is missing',array('status'=>409));
   if(!metadata_exists('post',$artifact_id,'_elementor_data'))
    return new WP_Error('oai_creation_artifact_mismatch','Page is not an Elementor document',array('status'=>409));
   $strength='elementor_page_operation_target';
  }else{
   return new WP_Error('oai_creation_family','Unsupported creation family',array('status'=>400));
  }
  return array('link_strength'=>$strength);
 }

 private static function verify_absence($family,$artifact_id,$record){
  if($family==='workspace'){
   $uploads=wp_upload_dir(null,false);
   if(!is_array($uploads)||empty($uploads['basedir'])||!is_string($uploads['basedir']))
    return new WP_Error('oai_creation_artifact_unavailable','Uploads directory is unavailable',array('status'=>503));
   $root=trailingslashit($uploads['basedir']).'ai-admin-workspaces';
   if(is_link($root)||!is_dir($root))
    return new WP_Error('oai_creation_artifact_unavailable','Workspace root is unavailable or unsafe',array('status'=>503));
   $folder=$root.'/'.$artifact_id;
   if(is_link($folder)||file_exists($folder))
    return new WP_Error('oai_creation_artifact_present','Workspace artifact is present; absence cannot be recorded',array('status'=>409));
   return true;
  }
  if($family==='menu'){
   if(wp_get_nav_menu_object($artifact_id))
    return new WP_Error('oai_creation_artifact_present','Menu artifact is present; absence cannot be recorded',array('status'=>409));
   return true;
  }
  $post=get_post($artifact_id);
  if($post)return new WP_Error('oai_creation_artifact_present','Artifact is present; absence cannot be recorded',array('status'=>409));
  return true;
 }

 private static function verify_operation($family,$artifact_id,$operation_id,$record){
  $row=OAI_DB::get($operation_id);
  if(!is_array($row))return new WP_Error('oai_creation_operation_not_found','Linked operation not found',array('status'=>404));
  $cfg=self::FAMILIES[$family];
  $expected_type=(string)$cfg['operation_type'];
  if((string)($row['type']??'')!==$expected_type)
   return new WP_Error('oai_creation_operation_type_mismatch','Operation type does not belong to this claim family',array('status'=>409));
  $expected_target=$family==='elementor_page'?'elementor_page:'.$artifact_id:(string)$artifact_id;
  if((string)($row['target']??'')!==$expected_target)
   return new WP_Error('oai_creation_operation_target_mismatch','Operation target does not match the artifact',array('status'=>409));
  $meta=json_decode((string)($row['meta_data']??''),true);
  $strong=false;
  $has_intent=is_array($meta)&&array_key_exists('_wpai_creation_intent',$meta);
  $has_claim=is_array($meta)&&array_key_exists('_wpai_creation_claim',$meta);
  if($has_intent||$has_claim){
   $fingerprint=(string)($record['payload_fingerprint']??'');
   if(!$has_intent||!is_string($meta['_wpai_creation_intent'])||$fingerprint===''||!hash_equals($fingerprint,$meta['_wpai_creation_intent']))
    return new WP_Error('oai_creation_operation_intent_mismatch','Operation creation intent does not match the claim',array('status'=>409));
   $token=isset($record['claim'])&&is_string($record['claim'])&&preg_match('/^[a-f0-9]{32}$/D',$record['claim'])?$record['claim']:'';
   if($token===''||!$has_claim||!is_string($meta['_wpai_creation_claim'])||!hash_equals(hash('sha256',$token),$meta['_wpai_creation_claim']))
    return new WP_Error('oai_creation_operation_claim_mismatch','Operation creation claim does not match the claim',array('status'=>409));
   $strong=true;
  }
  if($family==='media'&&!$strong)
   return new WP_Error('oai_creation_operation_link_unverified','Media operation lacks the creation intent/claim binding',array('status'=>409));
  $proof_raw=(string)($row['proof']??'');
  $receipt_raw=(string)($row['receipt']??'');
  $proof=json_decode($proof_raw,true);
  $lifecycle=array(
   'operation_status'=>(string)($row['status']??''),
   'has_proof'=>$proof_raw!=='',
   'proof_sha256'=>$proof_raw!==''?hash('sha256',$proof_raw):null,
   'proof_hash'=>is_array($proof)&&isset($proof['hash'])&&is_string($proof['hash'])&&preg_match('/^[a-f0-9]{64}$/D',$proof['hash'])?$proof['hash']:null,
   'has_receipt'=>$receipt_raw!=='',
   'receipt_sha256'=>$receipt_raw!==''?hash('sha256',$receipt_raw):null,
   'verified'=>is_array($proof)&&isset($proof['verification'])&&is_array($proof['verification'])&&($proof['verification']['verified']??null)===true,
  );
  return array(
   'row'=>$row,
   'lifecycle'=>$lifecycle,
   'strong_link'=>$strong,
   'link_strength'=>$strong?'operation_intent_claim':'operation_type_target',
  );
 }

 private static function empty_lifecycle(){
  return array(
   'operation_status'=>null,'has_proof'=>false,'proof_sha256'=>null,'proof_hash'=>null,
   'has_receipt'=>false,'receipt_sha256'=>null,'verified'=>false,
  );
 }

 private static function result($family,$claim_id,$record,$replayed){
  $reconciliation=isset($record['reconciliation'])&&is_array($record['reconciliation'])?$record['reconciliation']:array();
  return array(
   'ok'=>true,
   'schema'=>self::SCHEMA,
   'read_only'=>false,
   'claim_deleted'=>false,
   'artifacts_modified'=>false,
   'replayed'=>(bool)$replayed,
   'family'=>$family,
   'claim_id'=>$claim_id,
   'state'=>(string)($record['state']??''),
   'decision'=>(string)($reconciliation['decision']??''),
   'artifact_type'=>(string)($reconciliation['artifact_type']??''),
   'artifact_id'=>(string)($reconciliation['artifact_id']??''),
   'operation_id'=>(int)($reconciliation['operation_id']??0),
   'operation_status'=>$reconciliation['operation_status']??null,
   'link_strength'=>(string)($reconciliation['link_strength']??''),
   'evidence_sha256'=>(string)($reconciliation['evidence_sha256']??''),
   'reconciled_at'=>(int)($reconciliation['reconciled_at']??0),
   'reconciled_by'=>(int)($reconciliation['operator_id']??0),
   'lifecycle'=>$reconciliation['lifecycle']??self::empty_lifecycle(),
   'retention'=>self::retention_policy(),
  );
 }
}
