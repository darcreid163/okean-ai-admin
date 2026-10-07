<?php
/**
 * Safe internal WordPress REST discovery/GET resolver.
 * Never performs external HTTP requests and never exposes write methods.
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_REST_Resolver {
 const MAX_ROUTES=200;
 const MAX_ITEMS=100;
 const MAX_STRING=4000;

 private static function normalize_route($route){
  $route=trim((string)$route);
  if($route===''||preg_match('#^[a-z][a-z0-9+.-]*://#i',$route)||strpos($route,'\\')!==false||strpos($route,'..')!==false||strpos($route,'?')!==false||strpos($route,'#')!==false)return new WP_Error('oai_rest_route','Route must be an internal WordPress REST path without scheme, query string or traversal',array('status'=>400));
  if($route[0]!=='/')$route='/'.$route;
  $route=preg_replace('#/+#','/',$route);
  return $route;
 }
 private static function deny_reason($route){
  $r=strtolower((string)$route);
  // Universal REST intentionally does not expose WordPress user endpoints.
  // User reads must go through wp.list_users, which has a smaller projection.
  if(preg_match('#^/wp/v2/users(?:/|$)#',$r))return 'wordpress_users_blocked_use_wp_list_users';
  $blocked=array('/application-passwords','/users/me','/wp/v2/settings','/wp/v2/plugins','/wp/v2/themes','/wp/v2/block-directory','/okean-ai/');
  foreach($blocked as $needle)if(strpos($r,$needle)!==false)return 'sensitive_or_internal_route';
  return '';
 }
 private static function handlers_for_route($route,$routes){
  if(isset($routes[$route]))return $routes[$route];
  foreach((array)$routes as $pattern=>$handlers){
   $rx='#^'.str_replace('#','\\#',(string)$pattern).'$#';
   $ok=@preg_match($rx,$route);if($ok===1)return $handlers;
  }
  return null;
 }
 private static function route_supports_get($handlers){
  foreach((array)$handlers as $h){
   $methods=$h['methods']??array();
   if(is_string($methods))$methods=preg_split('/\s*,\s*/',$methods);
   foreach((array)$methods as $k=>$v){
    if(is_string($k)&&strtoupper($k)==='GET'&&$v)return true;
    if(is_string($v)&&strtoupper($v)==='GET')return true;
   }
  }
  return false;
 }
 private static function safe_arg_names($handlers){
  $args=array();foreach((array)$handlers as $h)foreach(array_keys((array)($h['args']??array())) as $k){$k=sanitize_key((string)$k);if($k!=='')$args[$k]=true;}return array_keys($args);
 }
 private static function redact_key($key){
  $k=strtolower((string)$key);
  foreach(array('password','passwd','passphrase','secret','token','authorization','cookie','nonce','api_key','apikey','consumer_key','consumer_secret','private_key','signature','email','phone','billing_address','shipping_address','address_1','address_2') as $needle)if(strpos($k,$needle)!==false)return true;
  return false;
 }
 private static function sanitize_data($value,$max_items=50,$depth=0){
  if($depth>8)return '[DEPTH_LIMIT]';
  if(is_object($value))$value=(array)$value;
  if(is_array($value)){
   $out=array();$count=0;
   foreach($value as $k=>$v){
    if($count++>=$max_items){$out['_truncated_items']=max(0,count($value)-$max_items);break;}
    $out[$k]=self::redact_key($k)?'[REDACTED]':self::sanitize_data($v,$max_items,$depth+1);
   }
   return $out;
  }
  if(is_string($value) && strlen($value)>self::MAX_STRING)return substr($value,0,self::MAX_STRING).'…[TRUNCATED]';
  return $value;
 }
 private static function project($data,$fields,$max_items){
  $fields=trim((string)$fields);
  if($fields===''||$fields==='all')return self::sanitize_data($data,$max_items);
  $wanted=$fields==='summary'?array('id','slug','status','type','name','title','link','date','modified','parent','count','sku','price','total','currency'):array_values(array_unique(array_filter(array_map('sanitize_key',explode(',',$fields)))));
  $project_one=function($row)use($wanted,$max_items){
   if(is_object($row))$row=(array)$row;if(!is_array($row))return $row;$out=array();foreach($wanted as $k)if(array_key_exists($k,$row))$out[$k]=OAI_REST_Resolver::redact_key($k)?'[REDACTED]':OAI_REST_Resolver::sanitize_data($row[$k],$max_items,1);return $out;
  };
  if($data===array())return array();
  if(is_array($data) && array_keys($data)===range(0,count($data)-1))return array_map($project_one,array_slice($data,0,$max_items));
  if(is_array($data) && isset($data['items']) && is_array($data['items'])){$copy=self::sanitize_data($data,$max_items);$copy['items']=array_map($project_one,array_slice($data['items'],0,$max_items));return $copy;}
  if(is_array($data)){
   $all_rows=true;foreach($data as $v){if(!is_array($v)&&!is_object($v)){$all_rows=false;break;}}
   if($all_rows){$out=array();$n=0;foreach($data as $k=>$v){if($n++>=$max_items)break;$out[$k]=$project_one($v);}return $out;}
  }
  return $project_one($data);
 }
 public static function discover($namespace='',$search='',$limit=100){
  if(!function_exists('rest_get_server'))return new WP_Error('oai_rest_unavailable','WordPress REST server unavailable',array('status'=>503));
  $namespace=trim((string)$namespace," /\t\n\r\0\x0B");$search=strtolower(trim((string)$search));$limit=max(1,min(self::MAX_ROUTES,(int)$limit));
  $routes=rest_get_server()->get_routes();$items=array();
  foreach((array)$routes as $route=>$handlers){
   if($namespace!=='' && strpos(ltrim($route,'/'),$namespace.'/')!==0 && ltrim($route,'/')!==$namespace)continue;
   if($search!=='' && strpos(strtolower($route),$search)===false)continue;
   if(self::deny_reason($route)!=='')continue;
   if(!self::route_supports_get($handlers))continue;
   $items[]=array('route'=>$route,'methods'=>array('GET'),'args'=>self::safe_arg_names($handlers));
   if(count($items)>=$limit)break;
  }
  return array('namespace'=>$namespace,'search'=>$search,'count'=>count($items),'limit'=>$limit,'items'=>$items,'policy'=>'registered_internal_get_only');
 }
 public static function read($route,$params=array(),$fields='summary',$max_items=50){
  if(!function_exists('rest_get_server')||!function_exists('rest_do_request'))return new WP_Error('oai_rest_unavailable','WordPress REST server unavailable',array('status'=>503));
  $route=self::normalize_route($route);if(is_wp_error($route))return $route;$reason=self::deny_reason($route);if($reason!=='')return new WP_Error('oai_rest_blocked','REST route is blocked by the safe-read policy',array('status'=>403,'reason'=>$reason,'route'=>$route));
  $routes=rest_get_server()->get_routes();$handlers=self::handlers_for_route($route,$routes);if($handlers===null)return new WP_Error('oai_rest_not_found','REST route is not registered',array('status'=>404,'route'=>$route));
  if(!self::route_supports_get($handlers))return new WP_Error('oai_rest_method','REST route does not expose GET',array('status'=>405,'route'=>$route));
  $params=is_array($params)?$params:array();$max_items=max(1,min(self::MAX_ITEMS,(int)$max_items));
  $req=new WP_REST_Request('GET',$route);foreach($params as $k=>$v){$k=sanitize_key((string)$k);if($k!=='')$req->set_param($k,$v);}
  $resp=rest_do_request($req);if(is_wp_error($resp))return $resp;if(!($resp instanceof WP_REST_Response))$resp=rest_ensure_response($resp);
  $status=(int)$resp->get_status();$data=$resp->get_data();if($status>=400)return new WP_Error('oai_rest_upstream','Internal REST route returned an error',array('status'=>$status,'route'=>$route,'response'=>self::sanitize_data($data,$max_items)));
  $projected=self::project($data,$fields,$max_items);
  return array('route'=>$route,'method'=>'GET','status'=>$status,'fields'=>$fields?:'summary','max_items'=>$max_items,'data'=>$projected,'policy'=>'same_site_registered_get_only_with_redaction');
 }
 public static function self_test_contract(){
  $a=self::normalize_route('https://example.com/wp-json/wp/v2/posts');$b=self::normalize_route('../wp/v2/posts');$c=self::normalize_route('/wp/v2/posts');$d=self::deny_reason('/wp/v2/users/me/application-passwords');$u=self::deny_reason('/wp/v2/users');$u2=self::deny_reason('/wp/v2/users/13');$probe=self::sanitize_data(array('id'=>1,'email'=>'a@example.test','nested'=>array('consumer_secret'=>'x','name'=>'safe')),10);
  return array('external_url_blocked'=>is_wp_error($a),'traversal_blocked'=>is_wp_error($b),'internal_route_allowed'=>$c==='/wp/v2/posts','sensitive_route_blocked'=>$d!=='','wordpress_users_blocked'=>$u==='wordpress_users_blocked_use_wp_list_users'&&$u2==='wordpress_users_blocked_use_wp_list_users','recursive_redaction'=>(($probe['email']??'')==='[REDACTED]'&&($probe['nested']['consumer_secret']??'')==='[REDACTED]'&&($probe['nested']['name']??'')==='safe'));
 }
}

