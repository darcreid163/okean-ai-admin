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
if (function_exists('wp_register_ability_category')) wp_register_ability_category('oai-acceptance', ['label'=>'Acceptance tests','description'=>'Disposable test category']);
$registered=wp_register_ability($name,[
    'label'=>'Smoke metadata read',
    'description'=>'Disposable ability for MCP discovery',
    'category'=>'oai-acceptance',
    'input_schema'=>['type'=>'object','properties'=>[]],
    'output_schema'=>['type'=>'object','properties'=>[]],
    'execute_callback'=>static fn($input)=>['ok'=>true],
    'permission_callback'=>static fn()=>current_user_can('manage_options'),
    'meta'=>['show_in_rest'=>true],
]);
if (is_wp_error($registered)) throw new RuntimeException('Ability registration error: '.$registered->get_error_message());
$names=array_column(OAI_Abilities::discover(),'name');
if (!in_array($name,$names,true)) { $entry=wp_get_ability($name); throw new RuntimeException('Registered REST-visible Ability missing; admin='.(int)current_user_can('manage_options').' registry='.count(wp_get_abilities()).' meta='.wp_json_encode($entry && method_exists($entry,'get_meta')?$entry->get_meta():null).' discovered='.wp_json_encode($names)); }
$info=OAI_Abilities::info($name);
if (!is_array($info) || $info['execution']!=='disabled_until_explicit_policy') throw new RuntimeException('Ability metadata not protected');
wp_set_current_user(0);
if (OAI_Abilities::discover()!==[])throw new RuntimeException('Anonymous Ability discovery leaked');
echo "PASS disposable WordPress Abilities discovery + admin ACL\n";
