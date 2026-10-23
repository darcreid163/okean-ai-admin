<?php
/** Actual private artifacts + durable pre-write claims; no production/network. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable loopback WordPress required');
class CreationFixture {public static $ids=[];public static $attachments=[];public static $checks=0;}
function creationAssert($ok,$why){++CreationFixture::$checks;if(!$ok)throw new RuntimeException($why);}
function creationRemember($out){if(is_array($out)&&!empty($out['id'])){CreationFixture::$ids[]=(int)$out['id'];CreationFixture::$attachments[]=(int)$out['target'];}return $out;}
function creationOk($out){creationRemember($out);creationAssert(!is_wp_error($out),'Unexpected creation failure: '.(is_wp_error($out)?$out->get_error_code():''));return $out;}
function creationError($out,$code){creationRemember($out);creationAssert(is_wp_error($out)&&$out->get_error_code()===$code,'Expected '.$code);if(in_array($code,['oai_duplicate_pending','oai_creation_unresolved','oai_creation_busy'],true))creationAssert(array_keys($out->get_error_data())===['status'],'Creation error exposed IDs/evidence');}
function creationClaims(){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('oai_media_creation_').'%'),ARRAY_A);return array_column($rows,'option_value','option_name');}
function creationState(){global $wpdb;$files=[];$base=wp_upload_dir()['basedir'];if(is_dir($base))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$files[$file->getPathname()]=hash_file('sha256',$file->getPathname());ksort($files);return [$files,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'"),OAI_DB::count_all()];}
function creationFile($prefix,$suffix){return ['filename'=>$prefix.'-'.$suffix.'.png','content_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGPkiephYGBgYmBgYGBgAAALAgD2hsU36QAAAABJRU5ErkJggg=='];}
function creationRunWorker($filename){$pipes=[];putenv('OAI_CREATION_FILE='.$filename);$process=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-media-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);creationAssert(is_resource($process),'Cannot start media worker');return [$process,$pipes];}
function creationWorkerResult($worker){[$process,$pipes]=$worker;$text=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($process);$out=json_decode($text,true);creationAssert($exit===0&&is_array($out),'Worker failed: '.$errors);if(isset($out['id'])){CreationFixture::$ids[]=(int)$out['id'];CreationFixture::$attachments[]=(int)$out['attachment'];}return $out;}
wp_set_current_user(1);global $wpdb;$prefix='oai-creation-'.str_replace('-','',wp_generate_uuid4());$claimsBefore=creationClaims();$actor=0;$workers=[];
$editor=getenv('OAI_MEDIA_TEST_EDITOR');if($editor)add_filter('wp_image_editors',static function()use($editor){return [$editor];});
try{
 creationOk(OAI_License::activate_local_owner(1));creationOk(OAI_AI_Lifecycle_Control::enable(1));
 $payload=creationFile($prefix,'original');$op=creationOk(OAI_P0::media_upload($payload));$id=(int)$op['id'];$attachment=(int)$op['target'];$file=get_attached_file($attachment);
 $row=OAI_DB::get($id);$state=creationState();$claims=creationClaims();
 creationError(OAI_P0::media_upload($payload),'oai_duplicate_pending');
 creationAssert(creationState()===$state&&OAI_DB::get($id)===$row&&creationClaims()===$claims,'Repeat changed artifacts/operation/claim');
 $meta=json_decode($row['meta_data'],true);$key='oai_media_creation_'.$meta['_wpai_creation_intent'];$record=json_decode($claims[$key],true);
 creationAssert($record['operation_id']===$id&&hash('sha256',$record['claim'])===$meta['_wpai_creation_claim'],'Operation was not bound to persisted claim');
 creationAssert(!in_array(get_option($key),[null,false],true),'Claim not persisted');
 // Normalization does not let a redundant MIME declaration or encoding bypass the intent.
 creationError(OAI_P0::media_upload(array_replace($payload,['mime_type'=>'image/png','content_base64'=>chunk_split($payload['content_base64'])])),'oai_duplicate_pending');
 creationError(OAI_P0::media_upload(array_replace($payload,['_wpai_creation_intent'=>'forged','_wpai_creation_claim'=>'forged'])),'oai_duplicate_pending');
 // Import fetch is intercepted; its normalized upload crosses the same boundary.
 $remote=static function($pre,$args,$url)use($payload){if($url==='https://8.8.8.8/creation.png')return ['headers'=>[],'body'=>base64_decode($payload['content_base64']),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];return $pre;};
 add_filter('pre_http_request',$remote,10,3);try{creationError(OAI_P0::media_import_url(['url'=>'https://8.8.8.8/creation.png','filename'=>$payload['filename']]),'oai_duplicate_pending');}finally{remove_filter('pre_http_request',$remote,10);}
 creationAssert(creationState()===$state,'URL retry wrote artifacts');
 wp_set_current_user(0);creationError(OAI_P0::media_upload($payload),'oai_media_permission');wp_set_current_user(1);
 creationAssert(creationState()===$state,'Unauthorized request wrote artifacts');
 creationOk(OAI_P0::media_upload(array_replace($payload,['title'=>'Independent title'])));
 creationOk(OAI_P0::media_upload(array_replace($payload,['alt'=>'Independent alt'])));
 $actor=wp_insert_user(['user_login'=>'creation-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>'administrator']);creationAssert(!is_wp_error($actor),'Cannot create actor');wp_set_current_user($actor);$foreign=creationOk(OAI_P0::media_upload($payload));creationAssert((int)$foreign['id']!==$id,'Actor reused another operation');wp_set_current_user(1);
 foreach(['approve','apply'] as $action)creationOk(OAI_AI_Lifecycle_Control::perform($id,$action,'disposable-creation',1));
 creationAssert(!empty(OAI_Verify::operation($id)['verified']),'Apply verification failed');
 creationOk(OAI_AI_Lifecycle_Control::perform($id,'rollback','disposable-creation',1));
 creationAssert(!empty(OAI_Verify::operation($id)['verified'])&&!get_post($attachment)&&!is_file($file),'Rollback did not restore absence');
 $again=creationOk(OAI_P0::media_upload($payload));creationAssert((int)$again['id']!==$id,'Terminal generation reused old operation');
 // A status label cannot hide a remaining private artifact.
 OAI_DB::update((int)$again['id'],['status'=>'rejected']);creationError(OAI_P0::media_upload($payload),'oai_duplicate_pending');OAI_DB::update((int)$again['id'],['status'=>'pending']);
 creationOk(OAI_AI_Lifecycle_Control::perform((int)$again['id'],'reject','disposable-creation',1));$again=creationOk(OAI_P0::media_upload($payload));
 OAI_DB::update((int)$again['id'],['expires_at'=>'2000-01-01 00:00:00']);creationError(OAI_P0::media_upload($payload),'oai_duplicate_pending');
 creationError(OAI_AI_Lifecycle_Control::perform((int)$again['id'],'approve','disposable-creation',1),'oai_expired');creationOk(OAI_P0::media_upload($payload));
 // Invalid input and outer transactions cannot install a creation claim.
 $before=creationState();$beforeClaims=creationClaims();creationError(OAI_P0::media_upload(array_replace(creationFile($prefix,'invalid'),['mime_type'=>'image/jpeg'])),'oai_media_mime');
 $wpdb->query('START TRANSACTION');try{creationError(OAI_P0::media_upload(creationFile($prefix,'transaction')),'oai_intent_transaction');}finally{$wpdb->query('ROLLBACK');}
 creationAssert(creationState()===$before&&creationClaims()===$beforeClaims,'Validation/transaction wrote artifacts or claims');
 // A known upload error still leaves a durable unresolved reservation.
 $failed=creationFile($prefix,'failed');$uploadFail=static function(){return 'synthetic upload failure';};$before=creationState();
 add_filter('wp_upload_bits',$uploadFail);try{creationError(OAI_P0::media_upload($failed),'oai_media_upload');}finally{remove_filter('wp_upload_bits',$uploadFail);}
 creationError(OAI_P0::media_upload($failed),'oai_creation_unresolved');creationAssert(creationState()===$before,'Failed retry wrote artifacts');
 $worker=creationRunWorker($failed['filename']);$out=creationWorkerResult($worker);creationAssert(($out['error']??'')==='oai_creation_unresolved','Unresolved reservation did not survive a fresh process');
 $throwing=static function(){throw new RuntimeException('private path '.ABSPATH.'unknown.png');};$unknown=creationFile($prefix,'unknown');
 add_filter('wp_upload_bits',$throwing);try{try{OAI_P0::media_upload($unknown);throw new LogicException('Unknown creation was converted to a definite result');}catch(RuntimeException $error){creationAssert($error->getMessage()==='Media creation outcome requires reconciliation','Unknown transport outcome lost or leaked');}}finally{remove_filter('wp_upload_bits',$throwing);}
 $changed=array_diff_key(creationClaims(),$beforeClaims);foreach($changed as $name=>$raw){$r=json_decode($raw,true);if(($r['operation_id']??0)===0){$r['created_at']=1;$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($r)],['option_name'=>$name]);}}
 creationError(OAI_P0::media_upload($unknown),'oai_creation_unresolved');creationAssert(creationState()===$before,'Old unresolved reservation aged out');
 // Failure to record the operation cleans the known artifact but holds the intent.
 $breakInsert=static function($sql){return str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' ')?'INSERT INTO '.OAI_DB::table().' (synthetic_missing_column) VALUES (1)':$sql;};
 $silent=$wpdb->suppress_errors();add_filter('query',$breakInsert);$storeFailure=creationFile($prefix,'storage');try{try{OAI_P0::media_upload($storeFailure);throw new LogicException('Storage ambiguity became definite failure');}catch(RuntimeException $error){creationAssert($error->getMessage()==='Media creation outcome requires reconciliation','Storage ambiguity did not propagate');}}finally{remove_filter('query',$breakInsert);$wpdb->suppress_errors($silent);}
 $held=creationState();creationAssert($held[1]===$before[1]+1&&$held[2]===$before[2],'Ambiguous storage lost private artifact or inserted an unverified operation');creationError(OAI_P0::media_upload($storeFailure),'oai_creation_unresolved');creationAssert(creationState()===$held,'Ambiguous storage retried artifact creation');$before=$held;
 // Deleting ownership just before the INSERT must reject rather than insert bare.
 $claimName='';$capture=static function($name)use(&$claimName){if(str_starts_with($name,'oai_media_creation_'))$claimName=$name;};
 $lose=static function($sql)use(&$claimName){if(str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' '))delete_option($claimName);return $sql;};
 add_action('added_option',$capture);add_filter('query',$lose);try{try{OAI_P0::media_upload(creationFile($prefix,'lost'));throw new LogicException('Lost ownership became definite failure');}catch(RuntimeException $error){creationAssert($error->getMessage()==='Media creation outcome requires reconciliation','Lost ownership did not propagate uncertainty');}}finally{remove_filter('query',$lose);remove_action('added_option',$capture);}
 $lostState=creationState();creationAssert($lostState[1]===$before[1]+1&&$lostState[2]===$before[2],'Lost ownership inserted an operation or discarded ambiguous evidence');creationError(OAI_P0::media_upload(creationFile($prefix,'lost')),'oai_creation_unresolved');creationAssert(creationState()===$lostState,'Missing claim was not restored conservatively');
 // A lost final binding reply leaves a valid row AND a reservation that blocks repeats.
 $bindUpdates=0;$breakBind=static function($sql)use(&$bindUpdates){global $wpdb;if(str_starts_with($sql,'UPDATE '.$wpdb->options.' SET option_value=')&&str_contains($sql,'oai_media_creation_'))$bindUpdates++;return $bindUpdates===2&&str_starts_with($sql,'UPDATE '.$wpdb->options.' SET option_value=')&&str_contains($sql,'oai_media_creation_')?'UPDATE '.$wpdb->options.' SET option_value=option_value WHERE option_name=\'synthetic_missing_option\'':$sql;};
 $binding=creationFile($prefix,'binding');add_filter('query',$breakBind);try{creationOk(OAI_P0::media_upload($binding));}finally{remove_filter('query',$breakBind);}
 $boundState=creationState();creationError(OAI_P0::media_upload($binding),'oai_creation_unresolved');creationAssert(creationState()===$boundState,'Binding failure permitted another creation');
 // Option sanitization/hooks cannot give the callback an unowned claim.
 $tamper=static function($name,$value){global $wpdb;if(str_starts_with($name,'oai_media_creation_')){$r=json_decode($value,true);$r['claim']=str_repeat('a',32);$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($r)],['option_name'=>$name]);}};
 $beforeTamper=creationState();add_action('added_option',$tamper,10,2);try{creationError(OAI_P0::media_upload(creationFile($prefix,'tamper')),'oai_creation_unresolved');}finally{remove_action('added_option',$tamper,10);}
 creationAssert(creationState()===$beforeTamper,'Unowned claim reached artifact creation');
 // Commit the INSERT, repeat it, and discard the reply: recover the same invocation only.
 $replayed=false;$lostReply=static function($sql)use(&$replayed){global $wpdb;if(!$replayed&&str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' ')){$replayed=true;creationAssert($wpdb->query($sql)===1,'Cannot simulate committed insert');creationAssert($wpdb->query($sql)===0,'Repeated SQL created or errored instead of retaining its row');return 'SELECT 1 WHERE 1=0';}return $sql;};
 $beforeReply=creationState();add_filter('query',$lostReply);try{creationOk(OAI_P0::media_upload(creationFile($prefix,'reply')));}finally{remove_filter('query',$lostReply);}
 $afterReply=creationState();creationAssert($afterReply[1]===$beforeReply[1]+1&&$afterReply[2]===$beforeReply[2]+1,'Lost reply did not recover one original row/artifact');
 // A new MySQL connection still sees the durable claim at the INSERT boundary.
 $reconnected=false;$connection=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
 $reconnect=static function($sql)use(&$reconnected){global $wpdb;if(!$reconnected&&str_starts_with($sql,'INSERT INTO '.OAI_DB::table().' ')){$reconnected=true;$wpdb->close();creationAssert($wpdb->db_connect(false),'Cannot reconnect fixture database');}return $sql;};
 add_filter('query',$reconnect);try{creationOk(OAI_P0::media_upload(creationFile($prefix,'reconnect')));}finally{remove_filter('query',$reconnect);}
 creationAssert($reconnected&&(int)$wpdb->get_var('SELECT CONNECTION_ID()')!==$connection,'Reconnect was not exercised');
 // Generic ledger cleanup cannot remove a journal-bound recovery row.
 $originalPrefix=$wpdb->prefix;$originalTable=OAI_DB::table();$fixtureTable=$originalPrefix.'creation_cleanup_'.bin2hex(random_bytes(4)).'_oai_operations';
 creationAssert($wpdb->query('CREATE TABLE '.$fixtureTable.' LIKE '.$originalTable)!==false,'Cannot create isolated cleanup fixture');
 try{
  $wpdb->query($wpdb->prepare('INSERT INTO '.$fixtureTable.' SELECT * FROM '.$originalTable.' WHERE id=%d',$id));
  $wpdb->insert($fixtureTable,['operation_id'=>OAI_Operations::new_id('TEST'),'type'=>'post_title','target'=>'synthetic','status'=>'rolled_back','created_at'=>current_time('mysql',true)]);
  $wpdb->prefix=substr($fixtureTable,0,-strlen('oai_operations'));
  $clean=OAI_Operations_Cleanup::run(['dry_run'=>false]);creationAssert(!is_wp_error($clean)&&$clean['deleted']===1&&OAI_DB::get($id)!==null&&OAI_DB::count_all()===1,'Generic cleanup erased journal evidence or stopped ordinary retention');
 }finally{$wpdb->prefix=$originalPrefix;$wpdb->query('DROP TABLE '.$fixtureTable);}
 // Six independent WP processes race before any persistent media side effects.
 $concurrent=creationFile($prefix,'concurrent');$before=creationState();
 for($i=0;$i<6;$i++)$workers[]=creationRunWorker($concurrent['filename']);
 $wins=0;$denied=0;foreach($workers as $worker){$out=creationWorkerResult($worker);if(isset($out['id']))$wins++;elseif(in_array($out['error']??'',['oai_duplicate_pending','oai_creation_unresolved','oai_creation_busy'],true)&&($out['status']??0)===409)$denied++;}$workers=[];
 $after=creationState();creationAssert($wins===1&&$denied===5&&$after[1]===$before[1]+1&&$after[2]===$before[2]+1&&count($after[0])===count($before[0])+1,'Concurrent media requests created extra artifacts/operations');
 echo 'PASS: '.CreationFixture::$checks." durable media creation checks; sequential/URL/concurrent retries, actor/terminal/uncertain fences, SQL ownership, rollback and 6-process single artifact\n";
}finally{
 foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
 putenv('OAI_CREATION_FILE');wp_set_current_user(1);
 foreach(array_diff_key(creationClaims(),$claimsBefore) as $raw){$r=json_decode($raw,true);if(!empty($r['attachment_id']))CreationFixture::$attachments[]=(int)$r['attachment_id'];}
 foreach(array_unique(CreationFixture::$attachments) as $id)if(get_post($id))wp_delete_attachment($id,true);
 foreach(array_unique(CreationFixture::$ids) as $id){$wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$id]);$wpdb->delete(OAI_DB::table(),['id'=>$id]);}
 foreach(array_diff_key(creationClaims(),$claimsBefore) as $name=>$raw){$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name=%s AND BINARY option_value=BINARY %s',$name,$raw));wp_cache_delete($name,'options');}
 wp_cache_delete('notoptions','options');
 if($actor&&!is_wp_error($actor)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($actor);}
 OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');OAI_License::deactivate_local_owner();
}
