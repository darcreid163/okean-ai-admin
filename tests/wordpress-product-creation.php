<?php
/** Disposable actual WooCommerce/MySQL creation intent acceptance. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable localhost WP only');
final class ProductCreationChecks {public static int $count=0;}
function pcAssert($ok,$msg){++ProductCreationChecks::$count;if(!$ok)throw new RuntimeException($msg);}
function pcErr($v,$name){pcAssert(is_wp_error($v)&&$v->get_error_code()===$name,'Expected '.$name.' got '.(is_wp_error($v)?$v->get_error_code():gettype($v)));}
function pcCount(){global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'");}
function pcCreate($data,$key){$x=OAI_WooCommerce::create_draft($data,$key);if(is_wp_error($x))return $x;$op=OAI_P0::op_for_created_product($x);return $op;}
$actor=wp_create_user('product-creation-ci','ci-local-only-password','products-ci@example.invalid');
pcAssert(!is_wp_error($actor),'Cannot provision test actor');
(new WP_User((int)$actor))->set_role('administrator');wp_set_current_user((int)$actor);
pcAssert(current_user_can('edit_products'),'Actor cannot edit products');
$start=pcCount();$ops=OAI_DB::count_all();
$payload=['type'=>'simple','name'=>'WP AI Product Creation First','regular_price'=>'10.20'];
$one=pcCreate($payload,'product-create-first-123456');
pcAssert(!is_wp_error($one),'First product failed: '.(is_wp_error($one)?$one->get_error_code():'unknown'));
pcAssert(pcCount()===$start+1&&OAI_DB::count_all()===$ops+1,'First product/operation missing');
pcErr(pcCreate($payload,'product-create-first-123456'),'oai_product_creation_pending');
pcErr(pcCreate(['regular_price'=>'10.20','name'=>'WP AI Product Creation First','type'=>'simple'],'product-create-first-123456'),'oai_product_creation_pending');
pcErr(pcCreate(['name'=>'WP AI Product Creation First','type'=>'simple','regular_price'=>'11.30'],'product-create-first-123456'),'oai_product_intent_conflict');
pcAssert(pcCount()===$start+1&&OAI_DB::count_all()===$ops+1,'Retry or conflict created product');
$two=pcCreate($payload,'product-create-second-123456');
pcAssert(!is_wp_error($two),'Explicit second product was blocked');
pcAssert(pcCount()===$start+2&&OAI_DB::count_all()===$ops+2,'Second product/operation missing');
pcErr(pcCreate($payload,'short'),'oai_product_request_key');
pcAssert(pcCount()===$start+2,'Invalid key created product');
global $wpdb;
pcAssert($wpdb->query('START TRANSACTION')!==false,'Cannot start fixture transaction');
try{pcErr(pcCreate($payload,'product-transaction-123456'),'oai_intent_transaction');}
finally{$wpdb->query('ROLLBACK');}
pcAssert(pcCount()===$start+2,'Transaction-denied product created');
$other=wp_create_user('product-other-ci','ci-local-only-password','product-other@example.invalid');
pcAssert(!is_wp_error($other),'Cannot provision alternate actor');
(new WP_User((int)$other))->set_role('administrator');wp_set_current_user((int)$other);
$three=pcCreate($payload,'product-create-first-123456');
pcAssert(!is_wp_error($three),'Cross-actor creation blocked');
pcAssert(pcCount()===$start+3&&OAI_DB::count_all()===$ops+3,'Cross-actor draft missing');
wp_set_current_user((int)$actor);
$token=bin2hex(random_bytes(8));$workers=[];
for($i=0;$i<6;$i++){
 $pipes=[];
 $env=array_merge($_ENV,['OAI_DISPOSABLE_WP_TEST'=>'1','OAI_PRODUCT_TEST_ACTOR'=>(string)$actor,'OAI_PRODUCT_TEST_TOKEN'=>$token]);
 $proc=proc_open([PHP_BINARY,$_SERVER['argv'][0],'--path='.ABSPATH,'--allow-root','--quiet','eval-file',(getenv('GITHUB_WORKSPACE')?:getcwd()).'/tests/wordpress-product-creation-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 pcAssert(is_resource($proc),'Could not launch product worker');$workers[]=[$proc,$pipes];
}
$wins=0;$denied=0;
foreach($workers as [$proc,$pipes]){
 $text=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);
 $exit=proc_close($proc);$out=json_decode(trim($text),true);
 pcAssert($exit===0&&is_array($out),'Product worker failed '.substr($err,0,350));
 if(isset($out['id']))$wins++;
 elseif(($out['error']??'')==='oai_product_creation_pending')$denied++;
 else throw new RuntimeException('Unexpected product worker outcome: '.substr($text,0,180));
}
pcAssert($wins===1&&$denied===5,'Concurrent product winner count '.$wins.'/'.$denied);
pcAssert(pcCount()===$start+4&&OAI_DB::count_all()===$ops+4,'Concurrent products/ops mismatch '.pcCount().'/'.OAI_DB::count_all());
wp_set_current_user(0);pcErr(pcCreate($payload,'product-anonymous-123456'),'oai_woo_permission');
pcAssert(pcCount()===$start+4,'Anonymous created product');
echo "PASS product creation admission: ".ProductCreationChecks::$count." assertions and six WP processes\n";
