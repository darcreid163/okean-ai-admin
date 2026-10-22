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
wp_register_ability($name,[
    'label'=>'Smoke metadata read',
    'description'=>'Disposable ability for MCP discovery',
    'category'=>'data-retrieval',
    'input_schema'=>['type'=>'object','properties'=>[]],
    'output_schema'=>['type'=>'object','properties'=>[]],
    'execute_callback'=>static fn($input)=>['ok'=>true],
    'permission_callback'=>static fn()=>current_user_can('manage_options'),
    'meta'=>['show_in_rest'=>true],
]);
$names=array_column(OAI_Abilities::discover(),'name');
if (!in_array($name,$names,true)) throw new RuntimeException('Registered REST-visible Ability missing from discovery');
$info=OAI_Abilities::info($name);
if (!is_array($info) || $info['execution']!=='disabled_until_explicit_policy') throw new RuntimeException('Ability metadata not protected');
wp_set_current_user(0);
if (OAI_Abilities::discover()!==[])throw new RuntimeException('Anonymous Ability discovery leaked');
echo "PASS disposable WordPress Abilities discovery + admin ACL\n";
