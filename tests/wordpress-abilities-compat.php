<?php
/**
 * Disposable integration test for WPVibe-compatible Ability metadata discovery.
 * No third-party Ability is executed, and no site data is mutated.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1') throw new RuntimeException('Disposable WP test guard missing');
if (!function_exists('wp_register_ability') || !function_exists('wp_get_abilities')) {
 echo "SKIP Abilities API unavailable in this WordPress version\n"; return;
}
$adminIds=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if (!$adminIds) throw new RuntimeException('Disposable administrator not found');
wp_set_current_user((int)$adminIds[0]);
$name='oai-acceptance/read-example';
if (!wp_get_ability($name)) throw new RuntimeException('CI MU plugin did not register Ability before compatibility test');
$compat=OAI_Abilities_Compat::list_public('',1,100);
if (is_wp_error($compat)) throw new RuntimeException('Compat Ability discovery failed: '.$compat->get_error_message());
$names=array_column($compat['items'],'name');
if (!in_array($name,$names,true)) throw new RuntimeException('REST-visible Ability missing from compat discovery');
$detail=OAI_Abilities_Compat::get_public($name);
if (!is_array($detail) || ($detail['execution_policy']??'')!=='plan_required') throw new RuntimeException('Ability detail missing guarded execution policy');
if (($detail['meta']['show_in_rest']??false)!==true) throw new RuntimeException('Public REST metadata projection missing');
$schema=$detail['input_schema']??null;
if (!is_array($schema) || ($schema['type']??'')!=='object') throw new RuntimeException('Ability input schema missing');
$run=OAI_Abilities_Compat::run($name,array());
if (!is_wp_error($run) || $run->get_error_code()!=='oai_ability_requires_plan') throw new RuntimeException('Direct Ability execution was not blocked');
$privateName='oai-acceptance/private-example';
wp_register_ability($privateName,array(
 'label'=>'Private example','description'=>'Must not appear in public inventory',
 'category'=>'oai-acceptance','input_schema'=>array('type'=>'object'),
 'output_schema'=>array('type'=>'object'),'execute_callback'=>static function(){return array('ran'=>true);},
 'permission_callback'=>static function(){return current_user_can('manage_options');},
 'meta'=>array('show_in_rest'=>false,'mcp'=>array('public'=>false),'private_marker'=>'DO_NOT_EXPOSE'),
));
$privateInfo=OAI_Abilities_Compat::get_public($privateName);
if (!is_wp_error($privateInfo) || $privateInfo->get_error_code()!=='oai_ability_not_found') throw new RuntimeException('Private Ability was exposed');
if (strpos(wp_json_encode($detail),'private_marker')!==false) throw new RuntimeException('Arbitrary ability metadata leaked');
$badMetaName='oai-acceptance/malformed-meta';
wp_register_ability($badMetaName,array(
 'label'=>'Malformed meta','description'=>'Fails closed','category'=>'oai-acceptance',
 'input_schema'=>array('type'=>'object'),'output_schema'=>array('type'=>'object'),
 'execute_callback'=>static function(){return array();},'permission_callback'=>static function(){return true;},
 'meta'=>array('mcp'=>'not-an-object','public'=>true),
));
$malformed=OAI_Abilities_Compat::get_public($badMetaName);
if (!is_wp_error($malformed) || $malformed->get_error_code()!=='oai_ability_not_found') throw new RuntimeException('Malformed MCP metadata did not fail closed');
$restPage=OAI_REST::abilities_public(new WP_REST_Request('GET','/'));
if (is_wp_error($restPage) || !is_array($restPage) || !isset($restPage['items'])) throw new RuntimeException('REST Ability listing callback failed');
$restDetailRequest=new WP_REST_Request('GET','/');
$restDetailRequest->set_url_params(array('name'=>$name));
$restDetail=OAI_REST::ability_public_detail($restDetailRequest);
if (!is_array($restDetail) || ($restDetail['name']??'')!==$name) throw new RuntimeException('REST Ability detail callback failed');
$registry=OAI_Command_Registry::resolve('ability schema '.$name);
if (is_wp_error($registry) || ($registry['handler']??'')!=='ability_schema') throw new RuntimeException('Ability schema command not registered');
$dispatch=OAI_Execution_Dispatcher::dispatch('ability schema '.$name,'read');
if (is_wp_error($dispatch) || ($dispatch['name']??'')!==$name) throw new RuntimeException('Ability schema command dispatch failed');
$anonymous=OAI_Abilities_Compat::list_public('',1,20);
if (is_wp_error($anonymous) || !is_array($anonymous)) throw new RuntimeException('Unexpected failure of admin discovery test');
wp_set_current_user(0);
$denied=OAI_Abilities_Compat::list_public('',1,20);
if (!is_wp_error($denied) || $denied->get_error_code()!=='oai_abilities_forbidden') throw new RuntimeException('Anonymous discovery did not fail closed');
$deniedDetail=OAI_Abilities_Compat::get_public($name);
if (!is_wp_error($deniedDetail) || $deniedDetail->get_error_code()!=='oai_ability_forbidden') throw new RuntimeException('Anonymous detail did not fail closed');
echo "PASS disposable WP Abilities compatibility discovery, schema, privacy and plan guard\n";
