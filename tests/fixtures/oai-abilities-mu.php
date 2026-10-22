<?php
/**
 * Disposable MU plugin for real WordPress Abilities boot lifecycle.
 * Only loaded by CI; never shipped in the Native artifact.
 */
add_action('wp_abilities_api_init',static function():void {
    if (!function_exists('wp_register_ability')) return;
    if (function_exists('wp_register_ability_category')) {
        wp_register_ability_category('oai-acceptance',['label'=>'Acceptance tests','description'=>'Disposable CI category']);
    }
    wp_register_ability('oai-acceptance/read-example',[
        'label'=>'Smoke metadata read',
        'description'=>'Disposable ability for MCP discovery',
        'category'=>'oai-acceptance',
        'input_schema'=>['type'=>'object','properties'=>[]],
        'output_schema'=>['type'=>'object','properties'=>[]],
        'execute_callback'=>static fn($input)=>['ok'=>true],
        'permission_callback'=>static fn()=>current_user_can('manage_options'),
        'meta'=>['show_in_rest'=>true],
    ]);
});
