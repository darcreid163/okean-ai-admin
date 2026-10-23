<?php
/** Disposable real WordPress/MySQL menu item creation admission regression. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable localhost only');
final class MenuItemChecks {public static int $count=0;}
function miAssert($v,$why){++MenuItemChecks::$count;if(!$v)throw new RuntimeException($why);}
function miErr($v,$code){miAssert(is_wp_error($v)&&$v->get_error_code()===$code,'Expected '.$code.' but got '.(is_wp_error($v)?$v->get_error_code():gettype($v)));}
function miCount(){global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='nav_menu_item'");}
wp_set_current_user(1);miAssert(current_user_can('edit_theme_options'),'Menu admin permission missing');
$menu=wp_create_nav_menu('WP AI Menu Item Fixture '.substr(wp_generate_uuid4(),0,12));
miAssert(!is_wp_error($menu)&&$menu>0,'Could not create temporary nav menu');
$before=miCount();$ops=OAI_DB::count_all();
function miCreate($menu,$title,$key,$url='https://example.invalid/one'){
 $data=['menu_id'=>$menu,'title'=>$title,'url'=>$url,'type'=>'custom'];
 if($key!==null)$data['idempotency_key']=$key;
 return OAI_P0::menu_add_item($data);
}
$one=miCreate($menu,'WP AI Test Menu Item One','menu-item-create-one-123456');
miAssert(!is_wp_error($one),'First menu item failed '.(is_wp_error($one)?$one->get_error_code():'unknown'));
miAssert(miCount()===$before+1&&OAI_DB::count_all()===$ops+1,'Menu item/operation not persisted');
miErr(miCreate($menu,'WP AI Test Menu Item One','menu-item-create-one-123456'),'oai_menu_item_creation_pending');
miErr(miCreate($menu,'<strong>WP AI Test Menu Item One</strong>','menu-item-create-one-123456'),'oai_menu_item_creation_pending');
miErr(miCreate($menu,'WP AI Test Menu Item One','menu-item-create-one-123456','https://example.invalid/change'),'oai_menu_item_intent_conflict');
miAssert(miCount()===$before+1&&OAI_DB::count_all()===$ops+1,'Retry or conflict created artifact');
$two=miCreate($menu,'WP AI Test Menu Item One','menu-item-create-two-123456');
miAssert(!is_wp_error($two),'Deliberate second menu item denied');
miAssert(miCount()===$before+2&&OAI_DB::count_all()===$ops+2,'Second item missing');
miErr(miCreate($menu,'WP AI Test Invalid','bad'),'oai_menu_item_request_key');
miAssert(miCount()===$before+2,'Invalid key wrote item');
global $wpdb;
miAssert($wpdb->query('START TRANSACTION')!==false,'Could not begin transaction');
try{miErr(miCreate($menu,'WP AI Test Transaction','menu-item-transaction-123456'),'oai_intent_transaction');}
finally{$wpdb->query('ROLLBACK');}
miAssert(miCount()===$before+2,'Transaction refused after writing item');
$other=wp_create_user('menu-item-other-ci','ci-local-only-password','menu-item-other@example.invalid');
miAssert(!is_wp_error($other),'Could not create second actor');
(new WP_User((int)$other))->set_role('administrator');wp_set_current_user((int)$other);
$three=miCreate($menu,'WP AI Test Menu Item One','menu-item-create-one-123456');
miAssert(!is_wp_error($three),'Other authorized actor was not isolated');
miAssert(miCount()===$before+3&&OAI_DB::count_all()===$ops+3,'Other actor item missing');
wp_set_current_user(1);
$legacy=miCreate($menu,'WP AI Legacy Item',null,'https://example.invalid/legacy');
miAssert(!is_wp_error($legacy),'First legacy item failed');
miErr(miCreate($menu,'WP AI Legacy Item',null,'https://example.invalid/legacy'),'oai_menu_item_creation_pending');
miAssert(miCount()===$before+4,'Legacy duplicate created item');
$token=bin2hex(random_bytes(8));$workers=[];
for($i=0;$i<6;$i++){
 $pipes=[];$env=array_merge($_ENV,['OAI_DISPOSABLE_WP_TEST'=>'1','OAI_MENU_ITEM_TEST_TOKEN'=>$token,'OAI_MENU_ITEM_TEST_MENU'=>(string)$menu]);
 $proc=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-menu-item-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 miAssert(is_resource($proc),'Cannot launch menu item worker');$workers[]=[$proc,$pipes];
}
$wins=0;$denied=0;
foreach($workers as [$proc,$pipes]){
 $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
 foreach($pipes as $pipe)fclose($pipe);
 $exit=proc_close($proc);$r=json_decode(trim($output),true);
 miAssert($exit===0&&is_array($r),'Menu item worker error: '.substr($error,0,350));
 if(isset($r['id']))$wins++;
 elseif(($r['error']??'')==='oai_menu_item_creation_pending')$denied++;
 else throw new RuntimeException('Unexpected worker reply '.substr($output,0,160));
}
miAssert($wins===1&&$denied===5,'Menu item concurrent winners '.$wins.' denied '.$denied);
miAssert(miCount()===$before+5&&OAI_DB::count_all()===$ops+5,'Concurrent menu item wrote extra rows');
wp_set_current_user(0);miErr(miCreate($menu,'WP AI Anonymous','menu-item-anonymous-123456'),'oai_menu_permission');
miAssert(miCount()===$before+5,'Anonymous item written');
echo "PASS menu item creation admission: ".MenuItemChecks::$count." checks, six separate WP workers\n";
