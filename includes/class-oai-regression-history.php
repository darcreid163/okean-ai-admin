<?php
/**
 * Compact persisted regression history and per-tool stage coverage.
 * No raw content, secrets, option values, HTML bodies or user PII are stored.
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Regression_History {
 const OPTION='oai_regression_history_v2';
 const MAX_ENTRIES=50;
 private static function now(){return gmdate('c');}
 private static function rows(){ $v=get_option(self::OPTION,array()); return is_array($v)?array_values($v):array(); }
 private static function scenario_map(){return array(
  'content'=>'content.patch','woocommerce'=>'woocommerce.update_product_sandbox','elementor'=>'elementor.patch_widget','elementor_fragment'=>'elementor.patch_fragment',
  'elementor_settings'=>'elementor.patch_settings','elementor_create'=>'elementor.create_draft','file'=>'file.write_sandbox',
  'gutenberg'=>'gutenberg.patch','reject_guard'=>'operation.reject'
 );}
 private static function clean_stages($stages){
  $out=array(); foreach((array)$stages as $k=>$v){$k=sanitize_key((string)$k);$v=strtoupper(sanitize_key((string)$v));if(in_array($v,array('PASS','FAIL','SKIP','BLOCKED'),true))$out[$k]=$v;} return $out;
 }
 private static function normalize_tools($kind,$result){
  $out=array();
  if($kind==='regression_matrix'){
   foreach((array)($result['tools']??array()) as $row){
    if(!is_array($row)||empty($row['tool']))continue;$tool=sanitize_text_field((string)$row['tool']);$status=strtoupper((string)($row['status']??'SKIP'));
    $stages=array();$ev=$row['evidence']??null;if(is_array($ev)&&isset($ev['stages']))$stages=self::clean_stages($ev['stages']);
    if(!$stages&&$status==='PASS')$stages=array('read'=>'PASS');
    $out[$tool]=array('status'=>$status,'stages'=>$stages);
   }
  }elseif($kind==='regression_run'){
   $map=self::scenario_map();foreach((array)($result['scenarios']??array()) as $s){if(!is_array($s))continue;$name=(string)($s['name']??'');if(!isset($map[$name]))continue;$out[$map[$name]]=array('status'=>strtoupper((string)($s['status']??'SKIP')),'stages'=>self::clean_stages($s['stages']??array()));}
   $ok=($result['status']??'')==='PASS';$out['system.regression_run']=array('status'=>$ok?'PASS':'FAIL','stages'=>array('run'=>$ok?'PASS':'FAIL'));
  }elseif($kind==='security_fuzz'){
   $ok=($result['status']??'')==='PASS';$out['system.security_fuzz']=array('status'=>$ok?'PASS':'FAIL','stages'=>array('negative_security'=>$ok?'PASS':'FAIL'));
  }
  return $out;
 }
 public static function record($kind,$result){
  if(!is_array($result))return false;$kind=sanitize_key((string)$kind);
  $entry=array(
   'kind'=>$kind,'run_id'=>sanitize_text_field((string)($result['run_id']??'')),'status'=>strtoupper(sanitize_key((string)($result['status']??'UNKNOWN'))),
   'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'','plugin_version'=>defined('OAI_VERSION')?OAI_VERSION:'',
   'finished_at'=>sanitize_text_field((string)($result['finished_at']??self::now())),'duration_ms'=>absint($result['duration_ms']??0),
   'baseline_restored'=>array_key_exists('baseline_restored',$result)?(bool)$result['baseline_restored']:null,
   'summary'=>is_array($result['summary']??null)?$result['summary']:array(),'tools'=>self::normalize_tools($kind,$result),
  );
  $rows=self::rows();array_unshift($rows,$entry);$rows=array_slice($rows,0,self::MAX_ENTRIES);update_option(self::OPTION,$rows,false);return $entry;
 }
 public static function history($limit=20,$kind='all',$include_tools=false){
  $limit=max(1,min(self::MAX_ENTRIES,absint($limit?:20)));$kind=sanitize_key((string)$kind);$rows=self::rows();
  if($kind!==''&&$kind!=='all')$rows=array_values(array_filter($rows,function($r)use($kind){return ($r['kind']??'')===$kind;}));
  $rows=array_slice($rows,0,$limit);
  if(!$include_tools){foreach($rows as &$row)unset($row['tools']);unset($row);}
  return array('schema_version'=>1,'retained_max'=>self::MAX_ENTRIES,'count'=>count($rows),'filter'=>$kind?:'all','include_tools'=>(bool)$include_tools,'items'=>$rows);
 }
 public static function coverage($limit=50){
  $limit=max(1,min(self::MAX_ENTRIES,absint($limit?:50)));$rows=array_slice(self::rows(),0,$limit);$tools=array();
  foreach(OAI_Platform::expected_tools() as $tool)$tools[$tool]=array('tool'=>$tool,'attempts'=>0,'PASS'=>0,'FAIL'=>0,'SKIP'=>0,'BLOCKED'=>0,'stages'=>array());
  foreach($rows as $entry){foreach((array)($entry['tools']??array()) as $tool=>$data){if(!isset($tools[$tool]))$tools[$tool]=array('tool'=>$tool,'attempts'=>0,'PASS'=>0,'FAIL'=>0,'SKIP'=>0,'BLOCKED'=>0,'stages'=>array());$status=strtoupper((string)($data['status']??'SKIP'));$tools[$tool]['attempts']++;if(isset($tools[$tool][$status]))$tools[$tool][$status]++;foreach((array)($data['stages']??array()) as $stage=>$st){$stage=sanitize_key((string)$stage);if(!isset($tools[$tool]['stages'][$stage]))$tools[$tool]['stages'][$stage]=array('attempts'=>0,'PASS'=>0,'FAIL'=>0,'SKIP'=>0,'BLOCKED'=>0);$tools[$tool]['stages'][$stage]['attempts']++;$st=strtoupper((string)$st);if(isset($tools[$tool]['stages'][$stage][$st]))$tools[$tool]['stages'][$stage][$st]++;}}}
  $covered=0;$clean=0;foreach($tools as &$t){if($t['attempts']>0)$covered++;if($t['attempts']>0&&$t['FAIL']===0&&$t['BLOCKED']===0)$clean++;$t['pass_rate']=$t['attempts']?round($t['PASS']*100/$t['attempts'],1):null;}unset($t);
  return array('schema_version'=>1,'history_entries'=>count($rows),'tool_count'=>count($tools),'covered_tools'=>$covered,'clean_tools'=>$clean,'coverage_percent'=>count($tools)?round($covered*100/count($tools),1):0,'tools'=>array_values($tools));
 }
 public static function self_test_contract(){
  $h=self::history(1,'all');$c=self::coverage(1);return array('history_shape'=>isset($h['items'],$h['count']),'coverage_shape'=>isset($c['tools'],$c['tool_count']),'bounded_retention'=>self::MAX_ENTRIES<=100);
 }
}
