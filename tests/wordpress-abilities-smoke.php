<?php
/**
 * Real disposable WordPress smoke for public Abilities metadata.
 * No execution of third-party Abilities and no external writes.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1') throw new RuntimeException('Disposable WP test guard missing');
if (!function_exists('wp_register_ability') || !function_exists('wp_get_abilities')) {
    echo "SKIP Abilities API unavailable in pinned WordPress version\n";
    return;
}
wp_set_current_user((int) get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
$name='oai-acceptance/read-example';
if (!wp_get_ability($name)) throw new RuntimeException('CI MU plugin did not register Ability at init');
$names=array_column(OAI_Abilities::discover(),'name');
if (!in_array($name,$names,true)) throw new RuntimeException('Registered REST-visible Ability missing from discovery');
$info=OAI_Abilities::info($name);
if (!is_array($info) || $info['execution']!=='disabled_until_explicit_policy') throw new RuntimeException('Ability metadata not protected');
// Exercise Native MCP tool registry and JSON-RPC tool dispatcher in the
// real WordPress runtime (token/OAuth end-to-end is covered separately).
$tools=OAI_MCP::tool_definitions();
$byName=array_column($tools,null,'name');
foreach(['ability.list','ability.get'] as $tool) {
    if(!isset($byName[$tool]) || empty($byName[$tool]['annotations']['readOnlyHint']))
        throw new RuntimeException('MCP read-only tool missing from catalog: '.$tool);
}
$dispatch=new ReflectionMethod('OAI_MCP','call_tool');
$reply=$dispatch->invoke(null,'ability-smoke',['name'=>'ability.get','arguments'=>['name'=>$name]]);
$data=$reply->get_data();
if(($data['id']??null)!=='ability-smoke' || isset($data['error']))
    throw new RuntimeException('MCP ability.get failed in real WordPress');
$decoded=json_decode($data['result']['content'][0]['text']??'',true);
if(($decoded['name']??'')!==$name || ($decoded['execution']??'')!=='disabled_until_explicit_policy')
    throw new RuntimeException('MCP metadata response incorrect or unsafe');
$bad=$dispatch->invoke(null,'ability-denied',['name'=>'ability.run','arguments'=>['name'=>$name]]);
if(($bad->get_data()['error']['code']??null)!==-32602)
    throw new RuntimeException('Unknown ability.run incorrectly exposed');
wp_set_current_user(0);
$denied=$dispatch->invoke(null,'ability-anonymous',['name'=>'ability.get','arguments'=>['name'=>$name]]);
if(!isset($denied->get_data()['error']))
    throw new RuntimeException('Anonymous MCP ability.get unexpectedly disclosed metadata');
if (OAI_Abilities::discover()!==[])throw new RuntimeException('Anonymous Ability discovery leaked');
echo "PASS disposable WordPress Abilities discovery + admin ACL\n";
