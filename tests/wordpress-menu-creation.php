<?php
/** Disposable WordPress/MySQL menu-creation atomic admission acceptance. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class MenuCreationFixture {public static int $checks=0;}
function mcAssert($condition,$why){++MenuCreationFixture::$checks;if(!$condition)throw new RuntimeException($why);}
function mcErr($out,$code){mcAssert(is_wp_error($out)&&$out->get_error_code()===$code,'Expected '.$code.' got '.(is_wp_error($out)?$out->get_error_code():gettype($out)));}
function mcMenus(){
 // Worker processes commit via their own DB connection. Parent WordPress term
 // caches are not automatically invalidated cross-process; read the DB truth.
 global $wpdb;
 return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='nav_menu'");
}
$user=wp_create_user('menu-admission-ci','ci-local-only-password','menu-ci@example.invalid');
mcAssert(!is_wp_error($user),'Cannot provision menu actor');
(new WP_User((int)$user))->set_role('administrator');wp_set_current_user((int)$user);
mcAssert(current_user_can('edit_theme_options'),'Menu actor lacks permission');
$claimName='oai_menu_creation_'.hash('sha256','strict-insert-fixture-'.wp_generate_uuid4());
$claim=array('actor'=>(int)$user,'state'=>'reserved','claim'=>'private-test-only');
mcAssert(OAI_DB::insert_creation_claim($claimName,$claim)===true,'Strict database claim did not insert');
mcAssert(OAI_DB::insert_creation_claim($claimName,$claim)===false,'Strict database claim accepted duplicate insert');
mcAssert(delete_option($claimName),'Could not clean synthetic admission claim');
$startMenus=mcMenus();$startOps=OAI_DB::count_all();
$one=OAI_P0::menu_create(['name'=>'WP AI Menu Creation First','idempotency_key'=>'menu-create-one-1234567890']);
mcAssert(!is_wp_error($one),'Initial menu creation failed: '.(is_wp_error($one)?$one->get_error_code():'unknown'));
mcAssert(mcMenus()===$startMenus+1&&OAI_DB::count_all()===$startOps+1,'Initial menu or operation not persisted');
$retry=OAI_P0::menu_create(['name'=>'WP AI Menu Creation First','idempotency_key'=>'menu-create-one-1234567890']);
mcErr($retry,'oai_menu_creation_pending');
mcErr(OAI_P0::menu_create(['name'=>'WP AI Menu Changed Same Key','idempotency_key'=>'menu-create-one-1234567890']),'oai_menu_intent_conflict');
// Exact-key legacy v1 menu reservation must not be bypassed by the v2 identity.
$priorMenuKey='menu-prior-claim-12345678';$priorMenuName='WP AI Prior Menu';
$normalizedPrior=function_exists('mb_strtolower')?mb_strtolower($priorMenuName,'UTF-8'):strtolower($priorMenuName);
$oldMenuRaw=wp_json_encode(['menu-creation-v1',get_current_blog_id(),(int)$user,$normalizedPrior,$priorMenuKey],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
mcAssert(add_option('oai_menu_creation_'.hash('sha256',$oldMenuRaw),['actor'=>(int)$user,'state'=>'reserved','claim'=>'fixture'],'',false),'Cannot seed old menu claim');
mcErr(OAI_P0::menu_create(['name'=>$priorMenuName,'idempotency_key'=>$priorMenuKey]),'oai_menu_creation_pending');
mcAssert(array_keys($retry->get_error_data())===['status'],'Duplicate response exposes IDs or internal claim');
mcAssert(mcMenus()===$startMenus+1&&OAI_DB::count_all()===$startOps+1,'Retry created extra menu or operation');
// Normalized case aliases must be the same intent.
mcErr(OAI_P0::menu_create(['name'=>'wp ai menu creation first','idempotency_key'=>'menu-create-one-1234567890']),'oai_menu_creation_pending');
$two=OAI_P0::menu_create(['name'=>'WP AI Menu Creation Second','idempotency_key'=>'menu-create-two-1234567890']);
mcAssert(!is_wp_error($two),'New intentional menu failed');
mcAssert(mcMenus()===$startMenus+2&&OAI_DB::count_all()===$startOps+2,'Intentional second menu missing');
mcErr(OAI_P0::menu_create(['name'=>'WP AI Invalid','idempotency_key'=>'bad']),'oai_menu_request_key');
mcAssert(mcMenus()===$startMenus+2,'Invalid key created menu');
// Request with explicit open transaction must fail before writing any term.
global $wpdb;
mcAssert($wpdb->query('START TRANSACTION')!==false,'Cannot start transaction');
try{mcErr(OAI_P0::menu_create(['name'=>'WP AI Transaction Denied','idempotency_key'=>'menu-transaction-12345678']),'oai_intent_transaction');}
finally{$wpdb->query('ROLLBACK');}
mcAssert(mcMenus()===$startMenus+2,'Transaction-denied menu was created');
// Alternate actors and payloads should not share a creation claim.
$other=wp_create_user('menu-other-ci','ci-local-only-password','menu-other@example.invalid');
mcAssert(!is_wp_error($other),'Cannot provision other menu actor');
(new WP_User((int)$other))->set_role('administrator');wp_set_current_user((int)$other);
$three=OAI_P0::menu_create(['name'=>'WP AI Menu Third','idempotency_key'=>'menu-create-one-1234567890']);
mcAssert(!is_wp_error($three),'Alternate actor was blocked by first actor claim');
mcAssert(mcMenus()===$startMenus+3&&OAI_DB::count_all()===$startOps+3,'Alternate actor menu/operation absent');
wp_set_current_user((int)$user);
function mcParallelWave($actor,$token){
 $workers=[];
 for($i=0;$i<6;$i++){
  $pipes=[];
  $env=array_merge($_ENV,['OAI_DISPOSABLE_WP_TEST'=>'1','OAI_MENU_TEST_ACTOR'=>(string)$actor,'OAI_MENU_TEST_TOKEN'=>$token]);
  $proc=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-menu-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
  mcAssert(is_resource($proc),'Could not start parallel menu worker');
  $workers[]=[$proc,$pipes];
 }
 $wins=0;$errors=0;
 foreach($workers as [$proc,$pipes]){
  $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
  foreach($pipes as $pipe)fclose($pipe);
  $exit=proc_close($proc);$res=json_decode(trim($stdout),true);
  mcAssert($exit===0&&is_array($res),'Menu worker failed: '.substr($stderr,0,300));
  if(isset($res['id']))$wins++;
  elseif(($res['error']??'')==='oai_menu_creation_pending')$errors++;
  else throw new RuntimeException('Unexpected menu worker result: '.substr($stdout,0,180));
 }
 return [$wins,$errors];
}
for($round=0;$round<3;$round++){
 [$wins,$errors]=mcParallelWave((int)$user,bin2hex(random_bytes(8)));
 mcAssert($wins===1&&$errors===5,'Concurrent menu winner count invalid in round '.$round.': '.$wins.'/'.$errors);
 $termCount=mcMenus();$operationCount=OAI_DB::count_all();
 $expected=$startMenus+4+$round;
 mcAssert($termCount===$expected&&$operationCount===$startOps+4+$round,'Concurrent menu/operation mismatch in round '.$round.': menus='.$termCount.' ops='.$operationCount);
}
wp_set_current_user(0);
mcErr(OAI_P0::menu_create(['name'=>'WP AI Menu Anonymous','idempotency_key'=>'menu-anonymous-12345678']),'oai_menu_permission');
mcAssert(mcMenus()===$startMenus+6,'Anonymous created menu');
echo "PASS menu creation admission: ".MenuCreationFixture::$checks." checks; 3 rounds of 6 workers, one menu/operation per round\n";
