<?php
/** Duplicate rejection is not replay, approval or a Cloud charging policy. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||(strpos(home_url(),'localhost')===false&&strpos(home_url(),'.test')===false))throw new RuntimeException('Disposable WordPress required');
class PendingFixture {public static $ids=[];public static $checks=0;}
function pendingAssert($ok,$why){++PendingFixture::$checks;if(!$ok)throw new RuntimeException($why);}
function pendingOk($out){if(is_wp_error($out))throw new RuntimeException('Unexpected creation failure: '.$out->get_error_code());$id=is_array($out)?(int)$out['id']:(int)$out;PendingFixture::$ids[]=$id;return $id;}
function pendingError($out,$code){pendingAssert(is_wp_error($out)&&$out->get_error_code()===$code,'Expected '.$code);pendingAssert(array_keys($out->get_error_data())===['status'],'Existing operation identifier/evidence disclosed');}
function pendingPatch($post,$new='beta'){$r=new WP_REST_Request('POST','/okean-ai/v1/content/patch');foreach(['post_id'=>$post,'field'=>'post_content','old_content'=>'alpha','new_content'=>$new] as $k=>$v)$r->set_param($k,$v);return OAI_REST::content_patch($r);}
function pendingRow($target,$after,$meta='{}'){return ['operation_id'=>OAI_Operations::new_id('TEST'),'status'=>'pending','type'=>'option_update','target'=>$target,'before_data'=>'{"old":true}','after_data'=>$after,'meta_data'=>$meta,'risk'=>'high','phase'=>'awaiting_approval','created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];}
wp_set_current_user(1);global $wpdb;$post=0;$actor=0;$workers=[];$lock='';$other=null;
try{
 $post=wp_insert_post(['post_title'=>'Disposable pending intent','post_type'=>'page','post_status'=>'draft','post_content'=>'alpha'],true);if(is_wp_error($post))throw new RuntimeException('Cannot create post');
 $id=pendingOk(pendingPatch($post));$row=OAI_DB::get($id);$count=OAI_DB::count_all();
 $audits=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.OAI_DB::audit_table().' WHERE operation_db_id=%d',$id),ARRAY_A);
 pendingError(pendingPatch($post),'oai_duplicate_pending');
 pendingAssert(OAI_DB::count_all()===$count&&OAI_DB::get($id)===$row,'Duplicate changed row/count');
 pendingAssert($wpdb->get_results($wpdb->prepare('SELECT * FROM '.OAI_DB::audit_table().' WHERE operation_db_id=%d',$id),ARRAY_A)===$audits,'Duplicate changed audit');
 pendingAssert(get_post_field('post_content',$post)==='alpha','Planning changed live content');
 pendingOk(pendingPatch($post,'gamma'));
 wp_update_post(['ID'=>$post,'post_content'=>'alpha external']);pendingOk(pendingPatch($post));wp_update_post(['ID'=>$post,'post_content'=>'alpha']);
 foreach(['approved','applying','apply_failed','rollback_pending','rolling_back','rollback_failed','uncertain','unknown'] as $status){OAI_DB::update($id,['status'=>$status,'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);pendingError(pendingPatch($post),'oai_duplicate_pending');}
 OAI_DB::update($id,['status'=>'apply_failed','expires_at'=>'2000-01-01 00:00:00']);pendingError(pendingPatch($post),'oai_duplicate_pending');
 foreach(['rejected','rolled_back','expired','applied','proved'] as $status){OAI_DB::update($id,['status'=>$status]);$id=pendingOk(pendingPatch($post));}
 foreach(['pending','approved'] as $status){OAI_DB::update($id,['status'=>$status,'expires_at'=>'2000-01-01 00:00:00']);$id=pendingOk(pendingPatch($post));}
 // Cross-actor requests never receive another actor's operation or evidence.
 $actor=wp_insert_user(['user_login'=>'pending-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>'administrator']);if(is_wp_error($actor))throw new RuntimeException('Cannot create actor');
 wp_set_current_user($actor);$foreign=pendingOk(pendingPatch($post));pendingAssert($foreign!==$id,'Cross-actor result reused');wp_set_current_user(1);
 $key='wp_ai_admin_test_matrix_pending_'.str_replace('-','',wp_generate_uuid4());
 $data=pendingRow($key,'{"a":1.0,"b":[1,2]}','{"nested":{"x":true,"y":false}}');
 $canonical=pendingOk(OAI_DB::insert($data));
 $reordered=array_replace($data,['operation_id'=>OAI_Operations::new_id('TEST'),'after_data'=>'{"b":[1,2],"a":1.0}','meta_data'=>'{"nested":{"y":false,"x":true}}']);pendingError(OAI_DB::insert($reordered),'oai_duplicate_pending');
 foreach(['{"a":1,"b":[1,2]}','{"a":"1","b":[1,2]}','{"a":1.0,"b":[2,1]}'] as $after)pendingOk(OAI_DB::insert(array_replace($data,['operation_id'=>OAI_Operations::new_id('TEST'),'after_data'=>$after])));
 // A caller cannot forge the stored server fingerprint.
 $forged=$data;$forged['operation_id']=OAI_Operations::new_id('TEST');$forged['meta_data']='{"nested":{"x":true,"y":false},"_wpai_pending_intent":"forged"}';pendingError(OAI_DB::insert($forged),'oai_duplicate_pending');
 // Outer transactions must fail closed: lock release cannot precede commit.
 $wpdb->query('START TRANSACTION');try{pendingError(OAI_DB::insert($data),'oai_intent_transaction');}finally{$wpdb->query('ROLLBACK');}
 // A competing connection owns the same named lock.
 $fingerprint=json_decode(OAI_DB::get($canonical)['meta_data'],true)['_wpai_pending_intent'];
 $lock='oai_intent_'.substr(hash('sha256',DB_NAME.'|'.OAI_DB::table().'|'.$fingerprint),0,50);
 pendingAssert((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1','Cannot hold fixture lock');
 $primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);
 try{$wpdb=$other;pendingError(OAI_DB::insert($data),'oai_intent_busy');}finally{$wpdb=$primary;$primary->get_var($primary->prepare('SELECT RELEASE_LOCK(%s)',$lock));$lock='';$other->close();$other=null;}
 $wpdb->query('SET autocommit=0');try{pendingError(OAI_DB::insert($data),'oai_intent_transaction');}finally{$wpdb->query('SET autocommit=1');}
 // A lost lock is checked by the INSERT, not just by a preceding SELECT.
 $release=function($sql)use($lock,$fingerprint){global $wpdb;if(str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' ')){$name='oai_intent_'.substr(hash('sha256',DB_NAME.'|'.OAI_DB::table().'|'.$fingerprint),0,50);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}return $sql;};
 OAI_DB::update($canonical,['status'=>'rejected']);$beforeLoss=OAI_DB::count_all();
 add_filter('query',$release);try{pendingError(OAI_DB::insert($data),'oai_intent_busy');}finally{remove_filter('query',$release);}
 pendingAssert(OAI_DB::count_all()===$beforeLoss,'Lost lock permitted an unguarded insert');
 $breakInsert=function($sql){return str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' ')?'INSERT INTO '.OAI_DB::table().' (synthetic_missing_column) VALUES (1)':$sql;};
 $silent=$wpdb->suppress_errors();add_filter('query',$breakInsert);try{pendingError(OAI_DB::insert($data),'oai_intent_storage');}finally{remove_filter('query',$breakInsert);$wpdb->suppress_errors($silent);}
 pendingAssert(OAI_DB::count_all()===$beforeLoss,'Storage error created an operation');
 pendingOk(OAI_DB::insert(array_replace($data,['operation_id'=>OAI_Operations::new_id('TEST')])));
 // Six fresh WordPress processes race on a new intent, with one SQL winner.
 putenv('OAI_PENDING_POST='.$post);
 for($i=0;$i<6;$i++){$pipes=[];$process=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-pending-intent-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);pendingAssert(is_resource($process),'Cannot start worker');$workers[]=[$process,$pipes];}
 $wins=0;$denied=0;
 foreach($workers as [$process,$pipes]){$text=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$out=json_decode($text,true);pendingAssert($exit===0&&is_array($out),'Worker failed: '.$errors);if(isset($out['id'])){$wins++;PendingFixture::$ids[]=(int)$out['id'];}elseif(($out['error']??'')==='oai_duplicate_pending')$denied++;}
 $workers=[];pendingAssert($wins===1&&$denied===5,'Concurrent requests created duplicate operations');
 pendingAssert(get_post_field('post_content',$post)==='alpha','Concurrent planning wrote live content');
 echo 'PASS: '.PendingFixture::$checks." pending-intent checks, canonical typed payloads, actor/expiry/uncertain fences, transaction/lock loss and 6-process single-winner creation\n";
}finally{
 foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
 putenv('OAI_PENDING_POST');wp_set_current_user(1);
 foreach(PendingFixture::$ids as $id){$wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$id]);$wpdb->delete(OAI_DB::table(),['id'=>$id]);}
 if($post)wp_delete_post($post,true);
 if($actor&&!is_wp_error($actor)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($actor);}
}
