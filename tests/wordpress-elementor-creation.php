<?php
/** Actual disposable WordPress/Elementor draft-creation intent acceptance. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class ECounters {public static int $count=0;}
function ecAssert($v,$msg){++ECounters::$count;if(!$v)throw new RuntimeException($msg);}
function ecErr($v,$code){ecAssert(is_wp_error($v)&&$v->get_error_code()===$code,'Expected '.$code.' got '.(is_wp_error($v)?$v->get_error_code():gettype($v)));}
function ecCount(){global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page'");}
function ecCreate($title,$key,$data=null){
 $r=new WP_REST_Request('POST','/okean-ai/v1/elementor/create-page');
 $r->set_param('title',$title);if($key!==null)$r->set_param('idempotency_key',$key);
 $r->set_param('data',$data??[['id'=>'a1b2c3d','elType'=>'container','settings'=>[],'elements'=>[]]]);
 return OAI_REST::elementor_create_page($r);
}
wp_set_current_user(1);
$lic=OAI_License::activate_local_owner(1);ecAssert(!is_wp_error($lic),'Disposable license activation failed');
ecAssert(current_user_can('edit_pages')&&OAI_Elementor::available(),'Elementor/page edit support unavailable');
$initial=ecCount();$ops=OAI_DB::count_all();
$a=ecCreate('WP AI Elementor First','elementor-create-first-1234567');
ecAssert(!is_wp_error($a),'First Elementor page failed '.(is_wp_error($a)?$a->get_error_code():'unknown'));
ecAssert(ecCount()===$initial+1&&OAI_DB::count_all()===$ops+1,'First Elementor page/operation not persisted');
ecErr(ecCreate('WP AI Elementor First','elementor-create-first-1234567'),'oai_page_creation_pending');
ecAssert(ecCount()===$initial+1&&OAI_DB::count_all()===$ops+1,'Retry produced another Elementor draft');
ecErr(ecCreate('WP AI Elementor Changed','elementor-create-first-1234567'),'oai_page_intent_conflict');
ecAssert(ecCount()===$initial+1,'Payload conflict produced page');
$b=ecCreate('WP AI Elementor First','elementor-create-second-1234567');
ecAssert(!is_wp_error($b),'Second intentional Elementor page denied');
ecAssert(ecCount()===$initial+2&&OAI_DB::count_all()===$ops+2,'Second page not persisted');
ecErr(ecCreate('WP AI Bad Key','bad'),'oai_page_request_key');
ecAssert(ecCount()===$initial+2,'Invalid key created page');
global $wpdb;
ecAssert($wpdb->query('START TRANSACTION')!==false,'Could not enter transaction');
try{ecErr(ecCreate('WP AI Elementor Transaction','elementor-transaction-1234567'),'oai_intent_transaction');}
finally{$wpdb->query('ROLLBACK');}
ecAssert(ecCount()===$initial+2,'Transaction-denied page created');
$legacy=ecCreate('WP AI Elementor Legacy',null);
ecAssert(!is_wp_error($legacy),'First legacy Elementor page denied');
ecErr(ecCreate('WP AI Elementor Legacy',null),'oai_page_creation_pending');
ecAssert(ecCount()===$initial+3,'Legacy retry created duplicate page');
// Normalization injects random nodes for root-only widget inputs. An omitted key
// must still deduplicate against the original normalized caller payload.
$rootWidget=[['id'=>'b1c2d3e','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'A heading'],'elements'=>[]]];
$c=ecCreate('WP AI Elementor Root Widget',null,$rootWidget);
ecAssert(!is_wp_error($c),'Legacy root-widget create failed');
ecErr(ecCreate('WP AI Elementor Root Widget',null,$rootWidget),'oai_page_creation_pending');
ecAssert(ecCount()===$initial+4,'Random wrapper IDs made retry non-idempotent');
$token=bin2hex(random_bytes(8));$workers=[];
for($i=0;$i<6;$i++){
 $pipes=[];$env=array_merge($_ENV,['OAI_DISPOSABLE_WP_TEST'=>'1','OAI_ELEMENTOR_TEST_TOKEN'=>$token]);
 $proc=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-elementor-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 ecAssert(is_resource($proc),'Cannot launch Elementor worker');$workers[]=[$proc,$pipes];
}
$wins=0;$denied=0;
foreach($workers as [$proc,$pipes]){
 $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 foreach($pipes as $pipe)fclose($pipe);
 $exit=proc_close($proc);$out=json_decode(trim($stdout),true);
 ecAssert($exit===0&&is_array($out),'Elementor worker failure: '.substr($stderr,0,380));
 if(isset($out['id']))$wins++;
 elseif(($out['error']??'')==='oai_page_creation_pending')$denied++;
 else throw new RuntimeException('Unexpected Elementor worker result: '.substr($stdout,0,150));
}
ecAssert($wins===1&&$denied===5,'Expected one concurrent Elementor draft: '.$wins.'/'.$denied);
ecAssert(ecCount()===$initial+5&&OAI_DB::count_all()===$ops+5,'Concurrent Elementor duplicate page/operation');
wp_set_current_user(0);
ecErr(ecCreate('WP AI Anonymous Elementor','elementor-anonymous-1234567'),'oai_page_permission');
ecAssert(ecCount()===$initial+5,'Anonymous page created');
echo "PASS Elementor page creation admission: ".ECounters::$count." assertions and six separate WP workers\n";
