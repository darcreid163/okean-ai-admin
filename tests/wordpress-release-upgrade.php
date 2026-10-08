<?php
// Real ZIP-to-ZIP upgrade acceptance on a disposable WordPress.
function release_upgrade_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
global $wpdb;
$phase=getenv('OAI_RELEASE_UPGRADE_PHASE');

if($phase==='seed'){
    release_upgrade_expect(defined('OAI_VERSION')&&OAI_VERSION==='2.1.13','Upgrade baseline must be 2.1.13');
    $site=OAI_Platform::site_id();
    $installation=OAI_Platform::installation_id();
    $token=OAI_MCP::generate_token(1);
    release_upgrade_expect(is_string($token)&&strlen($token)>30,'Baseline MCP token generation failed');
    $tokenHash=(string)get_option('oai_mcp_token_hash','');
    release_upgrade_expect($tokenHash!==''&&!str_contains($tokenHash,$token),'MCP token hash baseline invalid');

    $license=OAI_License::activate_local_owner(1);
    release_upgrade_expect(!is_wp_error($license)&&!empty($license['active']),'Baseline local owner license failed');

    $ai=OAI_AI_Lifecycle_Control::enable(1);
    release_upgrade_expect(!is_wp_error($ai)&&!empty($ai['active']),'Baseline AI lifecycle enable failed');
    $ta=OAI_Test_Automation::enable(60,array(),array(),1);
    release_upgrade_expect(!is_wp_error($ta),'Baseline test automation enable failed');

    $accessHash=hash('sha256','release-upgrade-oauth-access');
    $refreshHash=hash('sha256','release-upgrade-oauth-refresh');
    $inserted=$wpdb->insert(OAI_MCP_OAuth::tokens_table(),array(
        'access_hash'=>$accessHash,
        'refresh_hash'=>$refreshHash,
        'client_id'=>'release-upgrade-client',
        'user_id'=>1,
        'scope'=>'mcp:read mcp:write',
        'resource'=>rest_url('okean-ai/mcp'),
        'created_at'=>gmdate('Y-m-d H:i:s'),
        'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
        'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+7200),
        'revoked'=>0
    ));
    release_upgrade_expect($inserted!==false,'Baseline OAuth token insert failed');

    update_option('wpai_release_upgrade_expected',array(
        'site_id'=>$site,
        'installation_id'=>$installation,
        'mcp_token_hash'=>$tokenHash,
        'oauth_access_hash'=>$accessHash,
        'license_id'=>$license['license_id']??'',
    ),false);
    echo 'PASS release ZIP upgrade seed: '.OAI_VERSION.PHP_EOL;
}elseif($phase==='check'){
    release_upgrade_expect(defined('OAI_VERSION')&&OAI_VERSION==='2.1.14','Release ZIP upgrade did not load 2.1.14');
    $expected=get_option('wpai_release_upgrade_expected',array());
    release_upgrade_expect(is_array($expected)&&!empty($expected['site_id']),'Upgrade expected-state fixture missing');
    release_upgrade_expect(OAI_Platform::site_id()===$expected['site_id'],'site_id changed across ZIP upgrade');
    release_upgrade_expect(OAI_Platform::installation_id()===$expected['installation_id'],'installation_id changed across ZIP upgrade');
    release_upgrade_expect((string)get_option('oai_mcp_token_hash','')===$expected['mcp_token_hash'],'MCP credential hash changed across ZIP upgrade');
    release_upgrade_expect((bool)get_option('oai_mcp_enabled',false)===true,'MCP enable state was lost across ZIP upgrade');

    $oauth=$wpdb->get_row($wpdb->prepare('SELECT access_hash,client_id,revoked FROM '.OAI_MCP_OAuth::tokens_table().' WHERE access_hash=%s',$expected['oauth_access_hash']),ARRAY_A);
    release_upgrade_expect(is_array($oauth)&&$oauth['client_id']==='release-upgrade-client'&&(int)$oauth['revoked']===0,'OAuth grant/token was lost or revoked by ZIP upgrade');

    $license=OAI_License::status();
    release_upgrade_expect(!empty($license['active'])&&($license['license_id']??'')===$expected['license_id'],'Local owner license changed across ZIP upgrade');

    release_upgrade_expect(!OAI_AI_Lifecycle_Control::is_active(),'AI Lifecycle Control must reset on build change');
    release_upgrade_expect(!OAI_Test_Automation::is_active(),'Test Automation must reset on build change');
    release_upgrade_expect((get_option(OAI_AI_Lifecycle_Control::OPTION,array())['build_id']??'')===OAI_BUILD_ID,'AI lifecycle reset did not bind new build');
    release_upgrade_expect((get_option(OAI_Test_Automation::OPTION,array())['build_id']??'')===OAI_BUILD_ID,'Test automation reset did not bind new build');

    delete_option('wpai_release_upgrade_expected');
    echo 'PASS real ZIP upgrade 2.1.13 -> '.OAI_VERSION.' with identity/OAuth/license preserved and automation reset'.PHP_EOL;
}else{
    throw new RuntimeException('Unknown OAI_RELEASE_UPGRADE_PHASE');
}
