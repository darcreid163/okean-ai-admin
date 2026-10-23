<?php
/** Disposable WordPress + MySQL regression for #103 workspace creation. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable loopback WordPress required');
$checks=0;
function wsAssert($truth,$msg){global $checks;++$checks;if(!$truth)throw new RuntimeException($msg);}
function wsError($result,$code){wsAssert(is_wp_error($result)&&$result->get_error_code()===$code,'Expected '.$code.', got '.(is_wp_error($result)?$result->get_error_code():gettype($result)));}
function wsCount(){return count(OAI_Workspace::list_workspaces()['items']);}
$user=wp_create_user('workspace-admission-ci','ci-local-only-password','workspace-ci@example.invalid');
wsAssert(!is_wp_error($user),'Could not create fixture administrator');
(new WP_User((int)$user))->set_role('administrator');
wp_set_current_user((int)$user);
wsAssert(current_user_can('manage_options'),'Fixture lacks admin capability');
$initial=wsCount();
$key='workspace-creation-retry-123456';
$first=OAI_Workspace::create(false,$key);
wsAssert(!is_wp_error($first),'First workspace failed: '.(is_wp_error($first)?$first->get_error_code().' '.$first->get_error_message():'missing result'));
wsAssert(is_file(OAI_Workspace::root().'/'.$first['id'].'/workspace.json'),'Workspace metadata absent');
wsError(OAI_Workspace::create(false,$key),'oai_workspace_creation_pending');
wsAssert(wsCount()===$initial+1,'Retry produced another workspace');
$second=OAI_Workspace::create(false,'workspace-create-new-12345678');
wsAssert(!is_wp_error($second)&&$second['id']!==$first['id'],'Distinct explicit key failed to create legitimate new workspace');
wsAssert(wsCount()===$initial+2,'New key did not add one workspace');
// REST request must convey the same explicit idempotency key to the creator.
$restRequest=new WP_REST_Request('POST','/okean-ai/v1/workspace/create');
$restRequest->set_param('clone_theme','false');
$restRequest->set_param('idempotency_key','workspace-rest-create-123456');
$restCreated=OAI_REST::workspace_create($restRequest);
wsAssert(!is_wp_error($restCreated),'REST key was not forwarded to workspace creator');
$restDuplicate=OAI_REST::workspace_create($restRequest);
wsError($restDuplicate,'oai_workspace_creation_pending');
wsAssert(array_keys($restDuplicate->get_error_data())===['status'],'Duplicate exposed claim or workspace ID');
wsAssert(wsCount()===$initial+3,'REST retry created extra artifact');
wsError(OAI_Workspace::create(false,'bad'),'oai_workspace_request_key');
wsAssert(wsCount()===$initial+3,'Invalid key produced artifact');
$legacy=OAI_Workspace::create(false);
wsAssert(!is_wp_error($legacy),'Legacy first create failed');
wsError(OAI_Workspace::create(false),'oai_workspace_creation_pending');
wsAssert(wsCount()===$initial+4,'Legacy duplicate produced artifact');
// Transaction/autocommit protection must happen before creating files or claims.
global $wpdb;
wsAssert($wpdb->query('START TRANSACTION')!==false,'Cannot start transaction');
try {
 wsError(OAI_Workspace::create(false,'workspace-in-transaction-123456'),'oai_intent_transaction');
}finally{$wpdb->query('ROLLBACK');}
wsAssert(wsCount()===$initial+4,'Transaction-guarded creation produced artifact');
$other=wp_create_user('workspace-other-ci','ci-local-only-password','workspace-other@example.invalid');
wsAssert(!is_wp_error($other),'Cannot provision second disposable actor');
(new WP_User((int)$other))->set_role('administrator');
wp_set_current_user((int)$other);
$third=OAI_Workspace::create(false,$key);
wsAssert(!is_wp_error($third)&&$third['id']!==$first['id'],'Independent actor intent was improperly blocked');
wsAssert(wsCount()===$initial+5,'Independent actor did not create one workspace');
wp_set_current_user((int)$user);
$parallelKey='workspace-parallel-0123456789abcdef';
$workers=[];
for($i=0;$i<6;$i++){
 $pipes=[];
 $env=array_merge($_ENV,['OAI_DISPOSABLE_WP_TEST'=>'1','OAI_WORKSPACE_TEST_ACTOR'=>(string)$user,'OAI_WORKSPACE_TEST_KEY'=>$parallelKey]);
 $proc=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-workspace-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 wsAssert(is_resource($proc),'Cannot launch parallel worker');
 $workers[]=[$proc,$pipes];
}
$winners=0;$denied=0;
foreach($workers as [$proc,$pipes]){
 $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 foreach($pipes as $pipe)fclose($pipe);
 $exit=proc_close($proc);
 $result=json_decode(trim($stdout),true);
 wsAssert($exit===0&&is_array($result),'Parallel worker error: '.substr($stderr,0,350));
 if(isset($result['id']))$winners++;
 elseif(($result['error']??'')==='oai_workspace_creation_pending')$denied++;
 else throw new RuntimeException('Unexpected concurrent worker response');
}
wsAssert($winners===1&&$denied===5,'Atomic creation failed: winners='.$winners.' rejected='.$denied);
wsAssert(wsCount()===$initial+6,'Concurrent workers left duplicated directories');
wp_set_current_user(0);
wsError(OAI_Workspace::create(false,'workspace-anonymous-123456'),'oai_workspace_auth');
wsAssert(wsCount()===$initial+6,'Anonymous request created artifact');
echo "PASS workspace admission: $checks checks, 6 processes, actor isolation, retries, distinct key, transactions\n";
