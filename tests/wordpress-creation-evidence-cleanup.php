<?php
/** Disposable WordPress/MySQL: creation evidence survives generic terminal cleanup. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class CleanupEvidenceChecks {public static int $count=0;}
function ceAssert($condition,$reason){++CleanupEvidenceChecks::$count;if(!$condition)throw new RuntimeException($reason);}
global $wpdb;
wp_set_current_user(1);
ceAssert(current_user_can('manage_options'),'Fixture needs administrator');
$originalPrefix=$wpdb->prefix;
$table=$originalPrefix.'ci_creation_evidence_'.bin2hex(random_bytes(4)).'_oai_operations';
$prefix=substr($table,0,-strlen('oai_operations'));
ceAssert($wpdb->query('CREATE TABLE '.$table.' LIKE '.OAI_DB::table())!==false,'Cannot create isolated operation fixture');
try{
 $wpdb->prefix=$prefix;
 $protected=['media_upload','menu_create','menu_add_item','woocommerce_product_create','elementor_page_create'];
 foreach($protected as $i=>$type){
  $inserted=$wpdb->insert($table,['operation_id'=>'CREATION-CI-'.bin2hex(random_bytes(8)),'type'=>$type,
   'status'=>$i%2===0?'rolled_back':'applied','target'=>(string)($i+1),
   'created_at'=>current_time('mysql',true)]);
  ceAssert($inserted===1,'Could not insert protected '.$type);
 }
 // Historical ordinary metadata with a creation marker must also be preserved.
 ceAssert($wpdb->insert($table,['operation_id'=>'CREATION-CI-MARKED','type'=>'post_title',
  'status'=>'rolled_back','target'=>'fixture','meta_data'=>'{"_wpai_creation_intent":"history"}',
  'created_at'=>current_time('mysql',true)])===1,'Cannot seed historical marker');
 foreach(['post_title','post_excerpt'] as $type){
  ceAssert($wpdb->insert($table,['operation_id'=>'ORDINARY-CI-'.bin2hex(random_bytes(8)),
   'type'=>$type,'status'=>'rolled_back','target'=>'fixture',
   'created_at'=>current_time('mysql',true)])===1,'Cannot seed ordinary terminal row');
 }
 ceAssert(OAI_DB::count_all()===count($protected)+3,'Fixture count incorrect');
 $dry=OAI_Operations_Cleanup::run(['dry_run'=>true,'include_applied'=>true]);
 ceAssert(!is_wp_error($dry)&&$dry['eligible']===2&&$dry['deleted']===0,'Dry run incorrectly includes creation evidence');
 $applied=OAI_Operations_Cleanup::run(['dry_run'=>false,'include_applied'=>true]);
 ceAssert(!is_wp_error($applied)&&$applied['deleted']===2,'Ordinary terminal cleanup failed');
 ceAssert(OAI_DB::count_all()===count($protected)+1,'Creation evidence was erased');
 foreach($protected as $type){
  $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE type=%s",$type));
  ceAssert($remaining===1,'Lost creation evidence '.$type);
 }
 ceAssert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE meta_data LIKE %s",'%_wpai_creation_intent%'))===1,'Historical journal marker was lost');
 $again=OAI_Operations_Cleanup::run(['dry_run'=>false,'include_applied'=>true]);
 ceAssert(!is_wp_error($again)&&$again['deleted']===0,'Repeated cleanup touched protected evidence');
 wp_set_current_user(0);
 $denied=OAI_Operations_Cleanup::run(['dry_run'=>false,'include_applied'=>true]);
 ceAssert(is_wp_error($denied)&&$denied->get_error_code()==='oai_cleanup_forbidden','Anonymous cleanup was allowed');
 echo "PASS creation evidence cleanup: ".CleanupEvidenceChecks::$count." checks, 5 artifact types, historical marker, eligible ordinary rows\n";
}finally{
 wp_set_current_user(1);$wpdb->prefix=$originalPrefix;$wpdb->query('DROP TABLE '.$table);
}
