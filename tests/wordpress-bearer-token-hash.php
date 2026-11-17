<?php
if (!defined('WP_CLI') || !WP_CLI || !class_exists('OAI_REST')) {
    throw new RuntimeException('Run through WP-CLI with WP AI Admin active');
}
function bearerHashAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
global $wpdb;
$keys=['oai_bearer_token_hash','oai_bearer_token','oai_api_token','oai_bearer_user_id','oai_runtime_build_id'];
$before=[];
foreach($keys as $key){
    $row=$wpdb->get_var($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name=%s',$key));
    $before[$key]=['exists'=>$row!==null,'value'=>get_option($key,null)];
}
$originalUser=get_current_user_id();
try {
    $legacy='wpai_legacy_rest_'.bin2hex(random_bytes(24));
    delete_option('oai_bearer_token_hash');
    delete_option('oai_api_token');
    update_option('oai_bearer_token',$legacy,false);
    bearerHashAssert(OAI_DB::migrate_token()===true,'Legacy bearer token migration failed');
    $expected=hash('sha256',$legacy);
    bearerHashAssert(hash_equals($expected,(string)get_option('oai_bearer_token_hash','')),'Bearer hash does not match original token');
    bearerHashAssert(get_option('oai_bearer_token',null)===null && get_option('oai_api_token',null)===null,'Plaintext bearer option survived migration');

    update_option('oai_bearer_user_id',1,false);
    $request=new WP_REST_Request('GET','/okean-ai/v1/status');
    $request->set_header('authorization','Bearer '.$legacy);
    wp_set_current_user(0);
    bearerHashAssert(OAI_REST::perm($request)===true,'Existing bearer token stopped authenticating after hash migration');
    wp_set_current_user(0);
    $wrong=new WP_REST_Request('GET','/okean-ai/v1/status');
    $wrong->set_header('authorization','Bearer '.$legacy.'-wrong');
    bearerHashAssert(OAI_REST::perm($wrong)===false,'Incorrect bearer token was accepted');

    // Legacy alias migration must preserve the exact same credential.
    $legacyAlias='wpai_legacy_alias_'.bin2hex(random_bytes(24));
    delete_option('oai_bearer_token_hash');
    delete_option('oai_bearer_token');
    update_option('oai_api_token',$legacyAlias,false);
    bearerHashAssert(OAI_DB::migrate_token()===true,'Legacy API token alias migration failed');
    bearerHashAssert(hash_equals(hash('sha256',$legacyAlias),(string)get_option('oai_bearer_token_hash','')),'Legacy API token hash mismatch');
    bearerHashAssert(get_option('oai_api_token',null)===null,'Legacy API token plaintext survived migration');
    $aliasHash=(string)get_option('oai_bearer_token_hash','');
    bearerHashAssert(OAI_DB::migrate_token()===true && hash_equals($aliasHash,(string)get_option('oai_bearer_token_hash','')),'Bearer hash migration is not idempotent');

    // New installs create only a hash; no plaintext token is written to wp_options.
    delete_option('oai_bearer_token_hash');
    delete_option('oai_bearer_token');
    delete_option('oai_api_token');
    update_option('oai_runtime_build_id',defined('OAI_BUILD_ID')?OAI_BUILD_ID:'',false);
    OAI_Admin::ensure_token();
    $newHash=(string)get_option('oai_bearer_token_hash','');
    bearerHashAssert((bool)preg_match('/^[a-f0-9]{64}$/D',$newHash),'New bearer hash was not created');
    bearerHashAssert(get_option('oai_bearer_token',null)===null && get_option('oai_api_token',null)===null,'New bearer token was stored in plaintext');
    $autoload=$wpdb->get_var($wpdb->prepare('SELECT autoload FROM '.$wpdb->options.' WHERE option_name=%s','oai_bearer_token_hash'));
    bearerHashAssert(in_array($autoload,['no','off','auto-off'],true),'Bearer hash option is autoloaded');
    echo "PASS: legacy REST bearer migration, authentication compatibility, idempotency and hash-only storage\n";
} finally {
    foreach($before as $key=>$saved){
        if($saved['exists'])update_option($key,$saved['value'],false);
        else delete_option($key);
    }
    wp_set_current_user($originalUser);
}
