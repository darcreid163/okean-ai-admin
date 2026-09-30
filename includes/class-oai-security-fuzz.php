<?php
/** Safe negative/security fuzz checks. No live writes are executed. */
if (!defined('ABSPATH')) exit;
class OAI_Security_Fuzz {
 private static function case_row($id,$ok,$detail=''){return array('id'=>$id,'status'=>$ok?'PASS':'FAIL','detail'=>is_scalar($detail)?(string)$detail:$detail);}
 private static function blocked($v){return is_wp_error($v);}
 public static function run($iterations=1){
  $iterations=max(1,min(10,absint($iterations?:1)));$started=microtime(true);$run_id='fz_'.gmdate('Ymd_His').'_'.substr(wp_generate_uuid4(),0,8);$cases=array();
  for($i=0;$i<$iterations;$i++){
   $cases[]=self::case_row('file.traversal.'.($i+1),self::blocked(OAI_Files::read('child_theme','../wp-config.php')),'../ blocked');
   $cases[]=self::case_row('file.absolute.'.($i+1),self::blocked(OAI_Files::read('child_theme','/etc/passwd')),'absolute path blocked');
   $cases[]=self::case_row('file.invalid_scope.'.($i+1),self::blocked(OAI_Files::read('uploads','test.txt')),'invalid scope blocked');
   foreach(array('core version; plugin list','core version | plugin list','core version && plugin list','core version `whoami`') as $n=>$cmd)$cases[]=self::case_row('command.metachar.'.($i+1).'.'.$n,self::blocked(OAI_Command_Registry::resolve($cmd)),'metacharacter blocked');
   global $wpdb;$sql='db query SELECT user_pass FROM '.$wpdb->users;$cases[]=self::case_row('db.scope.'.($i+1),self::blocked(OAI_Execution_Dispatcher::dispatch($sql,'read')),'arbitrary SELECT blocked');
   $cases[]=self::case_row('woo.negative_price.'.($i+1),self::blocked(OAI_WooCommerce::validate_price(-1)),'negative price blocked');
   $cases[]=self::case_row('woo.nan_price.'.($i+1),self::blocked(OAI_WooCommerce::validate_price('NaN')),'non-finite/non-numeric price blocked');
   $cases[]=self::case_row('woo.sale_gt_regular.'.($i+1),self::blocked(OAI_WooCommerce::validate_snapshot(array('regular_price'=>'10','sale_price'=>'11'))),'sale > regular blocked');
   $cases[]=self::case_row('woo.negative_stock.'.($i+1),self::blocked(OAI_WooCommerce::validate_snapshot(array('stock'=>-1))),'negative stock blocked');
   $cases[]=self::case_row('woo.bad_status.'.($i+1),self::blocked(OAI_WooCommerce::validate_snapshot(array('stock_status'=>'invalid'))),'invalid stock status blocked');
   $safe=OAI_Read::option_value('oai_agent_key_hash','synthetic-secret');$cases[]=self::case_row('option.redaction.'.($i+1),is_array($safe)&&!empty($safe['redacted'])&&($safe['value']??null)!=='synthetic-secret','synthetic sensitive option redacted');
   $protected_req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');$protected_req->set_param('type','option_update');$protected_req->set_param('target','siteurl');$protected_req->set_param('value','https://example.invalid');$protected=OAI_REST::sandbox_create($protected_req);$cases[]=self::case_row('option.protected_write.'.($i+1),self::blocked($protected)&&$protected->get_error_code()==='oai_option_protected','protected core option blocked before operation creation');
   $secret_req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');$secret_req->set_param('type','option_update');$secret_req->set_param('target','synthetic_api_key');$secret_req->set_param('value','secret');$secret=OAI_REST::sandbox_create($secret_req);$cases[]=self::case_row('option.sensitive_write.'.($i+1),self::blocked($secret)&&$secret->get_error_code()==='oai_option_sensitive','sensitive credential option blocked before operation creation');
   $r=new WP_REST_Request('GET','/okean-ai/v1/read/html');$r->set_param('url','http://127.0.0.1/');$r->set_param('mode','outline');$cases[]=self::case_row('html.localhost_ssrf.'.($i+1),self::blocked(OAI_HTML_Inspector::inspect($r)),'localhost SSRF blocked');
   $cases[]=self::case_row('rest.external_url.'.($i+1),self::blocked(OAI_REST_Resolver::read('https://example.com/wp-json/wp/v2/posts')),'external REST URL blocked');
   $cases[]=self::case_row('rest.traversal.'.($i+1),self::blocked(OAI_REST_Resolver::read('../wp/v2/posts')),'REST traversal blocked');
   $cases[]=self::case_row('rest.sensitive_route.'.($i+1),self::blocked(OAI_REST_Resolver::read('/wp/v2/users/me/application-passwords')),'sensitive REST route blocked');
   $cases[]=self::case_row('rest.users_collection.'.($i+1),self::blocked(OAI_REST_Resolver::read('/wp/v2/users')),'WordPress users collection blocked in Universal REST');
   $cases[]=self::case_row('rest.users_single.'.($i+1),self::blocked(OAI_REST_Resolver::read('/wp/v2/users/13')),'WordPress user endpoint blocked in Universal REST');
  }
  $pass=0;$fail=0;foreach($cases as $c){if($c['status']==='PASS')$pass++;else$fail++;}
  $result=array('schema_version'=>1,'run_id'=>$run_id,'plugin_version'=>OAI_VERSION,'build_id'=>OAI_BUILD_ID,'iterations'=>$iterations,'safe_mode'=>'negative_checks_no_live_writes','summary'=>array('PASS'=>$pass,'FAIL'=>$fail,'total'=>count($cases)),'cases'=>$cases,'duration_ms'=>(int)round((microtime(true)-$started)*1000),'finished_at'=>gmdate('c'),'status'=>$fail===0?'PASS':'FAIL');
  return $result;
 }
 public static function self_test_contract(){return array('max_iterations'=>10,'no_live_write_cases'=>true,'negative_only'=>true,'wordpress_users_blocked_cases'=>true);}
}
