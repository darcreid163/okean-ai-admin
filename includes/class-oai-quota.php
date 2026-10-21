<?php
/** Okean AI Admin plan quota. */
if (!defined('ABSPATH')) exit;

class OAI_Quota {
 private const ENGINEERING_PLAN='__engineering__';
 private const DISPATCH_LEASE_SECONDS=300;
 private static $metered_depth=0;
 private static $transactional_storage_checked=false;
 private static $transaction_dbh=null;
 private const FREE_TOOLS=array('operation_get','operation_status','operation_verify','operation_proof','operation_preview_link','operation_get_receipt','operation_diff','operation_rollback','verify_operation','proof_operation','preview_get','batch_receipt','batch_proof','batch_status','batch_list','batch_rollback');

 public static function units($tool){return in_array(str_replace('.','_',strtolower((string)$tool)),self::FREE_TOOLS,true)?0:1;}

 public const PLANS=array(
  'free'=>array('label'=>'Free','limit'=>100,'period'=>'day'),
  'daily'=>array('label'=>'Daily','limit'=>500,'period'=>'day'),
  'pro'=>array('label'=>'Pro','limit'=>2000,'period'=>'day'),
  'business'=>array('label'=>'Business','limit'=>5000,'period'=>'day'),
 );

 public static function plans(){return self::PLANS;}

 public static function usage_events($limit=100){
  global $wpdb; $limit=max(1,min(500,absint($limit))); $table=OAI_DB::quota_events_table();
  return $wpdb->get_results($wpdb->prepare('SELECT request_key,channel,units,state,reserved_units,created_at FROM '.$table.' ORDER BY created_at DESC,request_key DESC LIMIT %d',$limit),ARRAY_A);
 }

 public static function is_engineering_access(){
  $s=OAI_License::status();
  return !empty($s['active'])
   && ($s['mode']??'')===OAI_License::LOCAL_MODE
   && !empty($s['local_valid']);
 }

 public static function current_plan(){
  if(self::is_engineering_access()) return self::ENGINEERING_PLAN;
  $s=OAI_License::status();
  $p=sanitize_key((string)($s['plan']??''));
  if(isset(self::PLANS[$p])) return $p;
  $p=sanitize_key((string)get_option('oai_plan','free'));
  return isset(self::PLANS[$p])?$p:'free';
 }

 public static function period_key($plan=null){
  $plan=$plan?:self::current_plan();
  if($plan===self::ENGINEERING_PLAN) return 'unlimited';
  $period=self::PLANS[$plan]['period']??'day';
  $now=current_time('timestamp');
  return $period==='day'?wp_date('Y-m-d',$now):wp_date('Y-m',$now);
 }

 public static function status(){
  $plan=self::current_plan();
  // Engineering access is intentionally not represented in the public quota model.
  if($plan===self::ENGINEERING_PLAN) return self::public_status('free');
  return self::public_status($plan);
 }

 private static function public_status($plan){
  global $wpdb;
  $cfg=self::PLANS[$plan]??self::PLANS['free'];
  $period=self::period_key($plan);
  $row=$wpdb->get_row($wpdb->prepare(
   'SELECT used,reserved FROM '.OAI_DB::quota_table().' WHERE plan=%s AND period_key=%s',
   $plan,$period
  ),ARRAY_A);
  $used=$row?(int)$row['used']:0;
  $reserved=$row?(int)($row['reserved']??0):0;
  return array(
   'plan'=>$plan,
   'label'=>$cfg['label'],
   'limit'=>(int)$cfg['limit'],
   'used'=>$used,
   'reserved'=>$reserved,
   'remaining'=>max(0,(int)$cfg['limit']-$used-$reserved),
   'period'=>$cfg['period'],
   'period_key'=>$period,
   'reset_at'=>self::reset_at($cfg['period']),
  );
 }

 public static function request_key($channel,$request_id,$fingerprint='',$client_id=''){
  $uid=get_current_user_id();
  $channel=sanitize_key((string)$channel);
  $request_id=(string)$request_id;
  $fingerprint=(string)$fingerprint;
  // Bind the logical ID independently of its payload: changed arguments must
  // conflict, rather than silently becoming another billable execution.
  return hash('sha256',$channel.'|'.$uid.'|'.(string)$client_id.'|'.$request_id);
 }
 public static function legacy_request_key($channel,$request_id,$fingerprint=''){
  return hash('sha256',sanitize_key((string)$channel).'|'.get_current_user_id().'|'.(string)$request_id.'|'.(string)$fingerprint);
 }

 public static function consume_request($request_key,$channel='unknown',$amount=1){
  if(self::$metered_depth>0 || self::is_engineering_access())return self::status();
  if((string)$request_key==='')return self::consume($amount);
  $reservation=self::reserve($request_key,$channel,max(1,absint($amount)),hash('sha256',(string)$request_key));
  if(is_wp_error($reservation))return $reservation;
  if(empty($reservation['replayed'])){
   $settled=self::settle($request_key,'succeeded');if(is_wp_error($settled))return $settled;
  }
  return self::status();
 }

 /** Business receipts contain only state/units/fingerprint, never tool output. */
 public static function run_metered($key,$channel,$fingerprint,$callback,$amount=1,$legacy_key=''){
  if(self::$metered_depth>0 || self::is_engineering_access() || $amount===0)return self::without_local_metering($callback);
  $r=self::reserve($key,$channel,max(1,absint($amount)),hash('sha256',(string)$fingerprint),$legacy_key);
  if(is_wp_error($r))return $r;
  if(!empty($r['replayed']))return array('_oai_quota_replay'=>true,'state'=>$r['state'],'charged_units'=>0,'original_output_retained'=>false);
  try{$result=self::without_local_metering($callback);}
  catch(Throwable $e){self::settle($key,'uncertain');return self::pending_error();}
  $ok=self::successful_result($result);
  $settled=self::settle($key,$ok?'succeeded':'failed');
  return is_wp_error($settled)?$settled:$result;
 }

 private static function successful_result($result){
  if(is_wp_error($result)||$result===false||$result===null)return false;
  if($result instanceof WP_REST_Response){
   if($result->get_status()>=400)return false;
   return self::successful_result($result->get_data());
  }
  if(!is_array($result))return true;
  if(array_key_exists('jsonrpc',$result)){
   return !array_key_exists('error',$result)&&isset($result['result']['content'])&&is_array($result['result']['content'])&&empty($result['result']['isError']);
  }
  if(isset($result['http_status']))return (int)$result['http_status']<400&&array_key_exists('data',$result)&&self::successful_result($result['data']);
  return (!array_key_exists('ok',$result)||$result['ok']!==false)&&empty($result['isError'])&&empty($result['error']);
 }

 public static function without_local_metering($callback){
  self::$metered_depth++;
  try{return $callback();}finally{self::$metered_depth--;}
 }

 private static function pending_error(){return new WP_Error('oai_quota_outcome_pending','The original request needs evidence-based reconciliation; do not repeat it with a new ID',array('status'=>409));}

 /** wpdb retries a lost connection outside the original transaction. Never
  * allow that retry for quota writes: keep one captured mysqli connection. */
 private static function raw_query($sql){
  global $wpdb;
  if($wpdb->dbh!==self::$transaction_dbh)throw new RuntimeException('Quota connection changed');
  $sql=apply_filters('query',$sql);
  if(!$sql || $wpdb->dbh!==self::$transaction_dbh)throw new RuntimeException('Quota connection changed');
  $result=mysqli_query(self::$transaction_dbh,$sql);
  if($result===false)throw new RuntimeException('Quota storage unavailable');
  return $result;
 }
 private static function query($sql){
  global $wpdb;
  if(self::$transaction_dbh!==null){
   $result=self::raw_query($sql);
   if($result instanceof mysqli_result)$result->free();
   return mysqli_affected_rows(self::$transaction_dbh);
  }
  $n=$wpdb->query($sql);if($n===false)throw new RuntimeException('Quota storage unavailable');return $n;
 }
 private static function row($sql){
  global $wpdb;
  if(self::$transaction_dbh===null)return $wpdb->get_row($sql,ARRAY_A);
  $result=self::raw_query($sql);
  if(!($result instanceof mysqli_result))throw new RuntimeException('Quota row unavailable');
  try{return $result->fetch_assoc();}finally{$result->free();}
 }

 private static function transaction($callback){
  global $wpdb;
  // Row locks/rollback must not be simulated on a nontransactional WP table.
  if(!self::$transactional_storage_checked){
   $n=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s) AND ENGINE='InnoDB'",OAI_DB::quota_table(),OAI_DB::quota_events_table()));
   if((int)$n!==2)return new WP_Error('oai_quota_storage','Transactional quota storage is required',array('status'=>503));
   self::$transactional_storage_checked=true;
  }
  // WordPress/drop-ins must provide a real mysqli connection. The standalone
  // non-WordPress fixture retains its small adapter; no production PDO fallback.
  if(class_exists('wpdb',false)){
   if(!($wpdb->dbh instanceof mysqli))return new WP_Error('oai_quota_storage','Transactional mysqli quota storage is required',array('status'=>503));
   self::$transaction_dbh=$wpdb->dbh;
  }
  try{
   self::query('START TRANSACTION');$result=$callback();self::query('COMMIT');return $result;
  }catch(Throwable $e){
   try{self::query('ROLLBACK');}catch(Throwable $ignored){}
   return new WP_Error('oai_quota_storage','Quota storage could not confirm the outcome; do not redispatch',array('status'=>503));
  }finally{self::$transaction_dbh=null;}
 }

 private static function reserve($key,$channel,$amount,$fingerprint,$legacy_key=''){
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$key))return new WP_Error('oai_quota_request','A stable request key is required',array('status'=>400));
  return self::transaction(function()use($key,$channel,$amount,$fingerprint,$legacy_key){
   global $wpdb;$s=self::status();$usage=OAI_DB::quota_table();$events=OAI_DB::quota_events_table();$at=current_time('mysql',true);
   self::query($wpdb->prepare('INSERT INTO '.$usage.' (plan,period_key,used,reserved,updated_at) VALUES (%s,%s,0,0,%s) ON DUPLICATE KEY UPDATE updated_at=updated_at',$s['plan'],$s['period_key'],$at));
   $balance=self::row($wpdb->prepare('SELECT used,reserved FROM '.$usage.' WHERE plan=%s AND period_key=%s FOR UPDATE',$s['plan'],$s['period_key']));
   if(!is_array($balance))throw new RuntimeException('Quota balance missing');
   $previous=self::row($wpdb->prepare('SELECT * FROM '.$events.' WHERE request_key=%s FOR UPDATE',$key));
   if($previous){
    if(($previous['fingerprint']??'')!==''&&!hash_equals($previous['fingerprint'],$fingerprint))return new WP_Error('oai_quota_idempotency_conflict','Request ID is already bound to different arguments',array('status'=>409));
    if(!in_array($previous['state'],array('succeeded','failed'),true))return self::pending_error();
    return array('replayed'=>true,'state'=>$previous['state'],'units'=>(int)$previous['units']);
   }
   // Bridge a matching v23 receipt before testing capacity. No balance reset
   // and no second debit when a client retries across the schema upgrade.
   if(preg_match('/^[a-f0-9]{64}$/D',(string)$legacy_key)){
    $legacy=self::row($wpdb->prepare('SELECT * FROM '.$events.' WHERE request_key=%s FOR UPDATE',$legacy_key));
    if($legacy && $legacy['state']==='succeeded'){
     self::query($wpdb->prepare('INSERT INTO '.$events.' (request_key,channel,units,state,reserved_units,plan,period_key,fingerprint,created_at,updated_at) VALUES (%s,%s,0,%s,0,%s,%s,%s,%s,%s)',$key,sanitize_key((string)$channel),'succeeded',$s['plan'],$s['period_key'],$fingerprint,$at,$at));
     return array('replayed'=>true,'state'=>'succeeded','units'=>(int)$legacy['units']);
    }
   }
   if((int)$balance['used']+(int)$balance['reserved']+$amount>$s['limit'])return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array('status'=>429,'limit'=>$s['limit'],'used'=>(int)$balance['used'],'reserved'=>(int)$balance['reserved']));
   if(self::query($wpdb->prepare('UPDATE '.$usage.' SET reserved=reserved+%d,updated_at=%s WHERE plan=%s AND period_key=%s',$amount,$at,$s['plan'],$s['period_key']))!==1)throw new RuntimeException('Quota reservation missing');
   self::query($wpdb->prepare('INSERT INTO '.$events.' (request_key,channel,units,state,reserved_units,plan,period_key,fingerprint,created_at,updated_at) VALUES (%s,%s,0,%s,%d,%s,%s,%s,%s,%s)',$key,sanitize_key((string)$channel),'dispatching',$amount,$s['plan'],$s['period_key'],$fingerprint,$at,$at));
   return array('replayed'=>false);
  });
 }

 private static function settle($key,$state,$operator=false,$evidence=''){
  return self::transaction(function()use($key,$state,$operator,$evidence){
   global $wpdb;$events=OAI_DB::quota_events_table();$usage=OAI_DB::quota_table();
   $hint=self::row($wpdb->prepare('SELECT plan,period_key FROM '.$events.' WHERE request_key=%s',$key));
   if(!$hint)throw new RuntimeException('Quota receipt missing');
   $balance=self::row($wpdb->prepare('SELECT used,reserved FROM '.$usage.' WHERE plan=%s AND period_key=%s FOR UPDATE',$hint['plan'],$hint['period_key']));
   $row=self::row($wpdb->prepare('SELECT * FROM '.$events.' WHERE request_key=%s FOR UPDATE',$key));
   if(!$balance||!$row)throw new RuntimeException('Quota reservation missing');
   $expired=$row['state']==='dispatching' && strtotime((string)$row['updated_at'].' UTC')+self::DISPATCH_LEASE_SECONDS<=time();
   if($operator){if($row['state']!=='uncertain'&&!$expired)return self::pending_error();}
   elseif($row['state']!=='dispatching')return self::pending_error();
   $at=current_time('mysql',true);
   if($state==='uncertain'){
    self::query($wpdb->prepare('UPDATE '.$events.' SET state=%s,updated_at=%s WHERE request_key=%s','uncertain',$at,$key));return true;
   }
   $reserved=(int)$row['reserved_units'];$charged=$state==='succeeded'?$reserved:0;
   if(self::query($wpdb->prepare('UPDATE '.$usage.' SET reserved=reserved-%d,used=used+%d,updated_at=%s WHERE plan=%s AND period_key=%s AND reserved>=%d',$reserved,$charged,$at,$row['plan'],$row['period_key'],$reserved))!==1)throw new RuntimeException('Invalid quota reservation');
   self::query($wpdb->prepare('UPDATE '.$events.' SET state=%s,units=%d,reserved_units=0,evidence_sha256=%s,updated_at=%s WHERE request_key=%s',$state,$charged,$evidence,$at,$key));return true;
  });
 }

 /** Server-side administrator/CLI recovery only; not exposed as a model tool. */
 public static function reconcile_request($key,$outcome,$receipt_sha256){
  if(!current_user_can('manage_options')||!in_array($outcome,array('succeeded','failed'),true)||!preg_match('/^[a-f0-9]{64}$/D',(string)$receipt_sha256))return new WP_Error('oai_quota_reconcile','Administrator and independent receipt evidence required',array('status'=>403));
  return self::settle($key,$outcome,true,$receipt_sha256);
 }

 public static function consume($amount=1){
  if(self::$metered_depth>0 || self::is_engineering_access()){
   // Internal engineering mode: no quota row is written and no usage is exposed.
   return self::status();
  }

  global $wpdb;
  $amount=max(1,absint($amount));
  $s=self::status();
  if($s['used']+$s['reserved']+$amount>$s['limit']){
   return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array(
    'status'=>429,'limit'=>$s['limit'],'used'=>$s['used'],
    'remaining'=>$s['remaining'],'reset_at'=>$s['reset_at']
   ));
  }

  $table=OAI_DB::quota_table();
  $wpdb->query($wpdb->prepare(
   'INSERT IGNORE INTO '.$table.' (plan,period_key,used,updated_at) VALUES (%s,%s,0,%s)',
   $s['plan'],$s['period_key'],current_time('mysql',true)
  ));
  $updated=$wpdb->query($wpdb->prepare(
   'UPDATE '.$table.' SET used=used+%d, updated_at=%s WHERE plan=%s AND period_key=%s AND used+reserved+%d<=%d',
   $amount,current_time('mysql',true),$s['plan'],$s['period_key'],$amount,$s['limit']
  ));
  if($updated!==1){
   $latest=self::status();
   return new WP_Error('oai_quota_exceeded','Лимит запросов текущего периода исчерпан',array(
    'status'=>429,'limit'=>$latest['limit'],'used'=>$latest['used'],
    'remaining'=>$latest['remaining'],'reset_at'=>$latest['reset_at']
   ));
  }
  return self::status();
 }

 private static function reset_at($period){
  $now=current_time('timestamp');
  return $period==='day'
   ?wp_date('c',strtotime('+1 day',strtotime(wp_date('Y-m-d 00:00:00',$now))))
   :wp_date('c',strtotime('+1 month',strtotime(wp_date('Y-m-01 00:00:00',$now))));
 }
}

