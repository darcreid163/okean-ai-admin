<?php
// Deactivation/reactivation safety acceptance on a disposable WordPress.
function react_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
$phase=getenv('OAI_REACTIVATION_PHASE');

if($phase==='seed'){
    react_expect(defined('OAI_VERSION'),'Plugin not active during reactivation seed');
    $site=OAI_Platform::site_id();
    $installation=OAI_Platform::installation_id();
    $token=OAI_MCP::generate_token(1);
    react_expect(is_string($token)&&strlen($token)>30,'Unable to seed MCP credential');
    $hash=(string)get_option('oai_mcp_token_hash','');
    react_expect($hash!==''&&!str_contains($hash,$token),'Seeded MCP credential was not hashed');
    $license=OAI_License::activate_local_owner(1);
    react_expect(!is_wp_error($license)&&!empty($license['active']),'Unable to seed local-owner license');
    $ai=OAI_AI_Lifecycle_Control::enable(1);
    react_expect(!is_wp_error($ai)&&OAI_AI_Lifecycle_Control::is_active(),'Unable to enable AI Lifecycle before deactivation');
    $ta=OAI_Test_Automation::enable(15,array(),array(),1);
    react_expect(!is_wp_error($ta)&&OAI_Test_Automation::is_active(),'Unable to enable Test Automation before deactivation');
    update_option('wpai_reactivation_expected',array(
        'site_id'=>$site,
        'installation_id'=>$installation,
        'mcp_token_hash'=>$hash,
        'license_id'=>$license['license_id']??'',
    ),false);
    echo "PASS reactivation seed\n";
    return;
}

if($phase==='check'){
    $expected=get_option('wpai_reactivation_expected',array());
    react_expect(is_array($expected)&&!empty($expected['site_id']),'Reactivation expected fixture missing');
    react_expect(OAI_Platform::site_id()===$expected['site_id'],'site_id changed across deactivation/reactivation');
    react_expect(OAI_Platform::installation_id()===$expected['installation_id'],'installation_id changed across deactivation/reactivation');
    react_expect((string)get_option('oai_mcp_token_hash','')===$expected['mcp_token_hash'],'MCP credential changed across deactivation/reactivation');
    $license=OAI_License::status();
    react_expect(!empty($license['active'])&&($license['license_id']??'')===$expected['license_id'],'License changed across deactivation/reactivation');
    react_expect(!OAI_AI_Lifecycle_Control::is_active(),'AI Lifecycle survived plugin deactivation');
    react_expect(!OAI_Test_Automation::is_active(),'Test Automation survived plugin deactivation');
    $aiCfg=get_option(OAI_AI_Lifecycle_Control::OPTION,array());
    $taCfg=get_option(OAI_Test_Automation::OPTION,array());
    react_expect(($aiCfg['disabled_reason']??'')==='plugin_deactivated','AI Lifecycle deactivation reason not recorded');
    react_expect(($taCfg['disabled_reason']??'')==='plugin_deactivated','Test Automation deactivation reason not recorded');
    delete_option('wpai_reactivation_expected');
    echo "PASS deactivation/reactivation preserved identity, credential and license with automation OFF\n";
    return;
}

throw new RuntimeException('Unknown OAI_REACTIVATION_PHASE');
