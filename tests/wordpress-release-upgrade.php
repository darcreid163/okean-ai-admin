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

    update_option('wpai_release_upgrade_operation','baseline',false);
    $opReq=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
    $opReq->set_param('type','option_update');
    $opReq->set_param('target','wpai_release_upgrade_operation');
    $opReq->set_param('new_data','pending-after-upgrade');
    $pendingOp=OAI_REST::sandbox_create($opReq);
    release_upgrade_expect(!is_wp_error($pendingOp)&&!empty($pendingOp['id'])&&($pendingOp['status']??'')==='pending','Baseline pending operation fixture failed');
    $pendingRow=OAI_DB::get((int)$pendingOp['id']);
    release_upgrade_expect(is_array($pendingRow)&&!empty($pendingRow['rollback_data'])&&!empty($pendingRow['diff_data']),'Baseline pending operation missing rollback/diff evidence');

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
        'operation_id'=>(int)$pendingOp['id'],
        'operation_key'=>(string)($pendingOp['operation_id']??''),
        'operation_before'=>(string)($pendingRow['before_data']??''),
        'operation_after'=>(string)($pendingRow['after_data']??''),
        'operation_diff'=>(string)($pendingRow['diff_data']??''),
        'operation_rollback'=>(string)($pendingRow['rollback_data']??''),
    ),false);
    echo 'PASS release ZIP upgrade seed: '.OAI_VERSION.PHP_EOL;
}elseif($phase==='check'){
    release_upgrade_expect(defined('OAI_VERSION')&&OAI_VERSION==='2.1.15','Release ZIP upgrade did not load 2.1.15');
    release_upgrade_expect(defined('OAI_BUILD_ID')&&OAI_BUILD_ID==='2.1.15-release-hardening-20261009','Release ZIP upgrade loaded unexpected build ID');
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    release_upgrade_expect(is_plugin_active('okean-ai-admin/okean-ai-admin.php'),'Native plugin became inactive during ZIP upgrade');
    release_upgrade_expect((int)get_option('oai_db_version',0)===(int)OAI_DB_VERSION,'Native DB schema version marker changed across ZIP upgrade');
    release_upgrade_expect((int)get_option('oai_oauth_schema_version',0)===(int)OAI_MCP_OAuth::SCHEMA_VERSION,'Native OAuth schema version marker changed across ZIP upgrade');
    $tools=OAI_MCP::tool_definitions();
    $toolNames=array_values(array_filter(array_map(fn($tool)=>is_array($tool)?($tool['name']??''):'',$tools)));
    release_upgrade_expect(count($toolNames)>=100,'MCP tool catalog unexpectedly small after ZIP upgrade');
    foreach(['system.manifest','operation.apply','operation.verify','operation.get_receipt','operation.rollback'] as $requiredTool){
        release_upgrade_expect(in_array($requiredTool,$toolNames,true),'Required MCP tool missing after ZIP upgrade: '.$requiredTool);
    }
    $expected=get_option('wpai_release_upgrade_expected',array());
    $manifest=OAI_Platform::manifest();
    release_upgrade_expect(is_array($manifest)&&($manifest['plugin_name']??'')==='WP AI Admin','System manifest identity invalid after ZIP upgrade');
    release_upgrade_expect(($manifest['version']??'')===OAI_VERSION&&($manifest['build_id']??'')===OAI_BUILD_ID,'System manifest version/build mismatch after ZIP upgrade');
    release_upgrade_expect(($manifest['site_id']??'')===$expected['site_id']&&($manifest['installation_id']??'')===$expected['installation_id'],'System manifest identity claims changed across ZIP upgrade');
    release_upgrade_expect((($manifest['release']['production_defaults']['direct_live_writes']??true)===false),'System manifest enabled direct live writes after ZIP upgrade');
    release_upgrade_expect(is_array($expected)&&!empty($expected['site_id']),'Upgrade expected-state fixture missing');
    release_upgrade_expect(OAI_Platform::site_id()===$expected['site_id'],'site_id changed across ZIP upgrade');
    release_upgrade_expect(OAI_Platform::installation_id()===$expected['installation_id'],'installation_id changed across ZIP upgrade');
    release_upgrade_expect((string)get_option('oai_mcp_token_hash','')===$expected['mcp_token_hash'],'MCP credential hash changed across ZIP upgrade');
    release_upgrade_expect((bool)get_option('oai_mcp_enabled',false)===true,'MCP enable state was lost across ZIP upgrade');

    $oauth=$wpdb->get_row($wpdb->prepare('SELECT access_hash,client_id,revoked FROM '.OAI_MCP_OAuth::tokens_table().' WHERE access_hash=%s',$expected['oauth_access_hash']),ARRAY_A);
    release_upgrade_expect(is_array($oauth)&&$oauth['client_id']==='release-upgrade-client'&&(int)$oauth['revoked']===0,'OAuth grant/token was lost or revoked by ZIP upgrade');

    $license=OAI_License::status();
    release_upgrade_expect(!empty($license['active'])&&($license['license_id']??'')===$expected['license_id'],'Local owner license changed across ZIP upgrade');

    $pendingRow=OAI_DB::get((int)$expected['operation_id']);
    release_upgrade_expect(is_array($pendingRow)&&($pendingRow['operation_id']??'')===$expected['operation_key']&&($pendingRow['status']??'')==='pending','Pending operation was lost or changed across ZIP upgrade');
    release_upgrade_expect((string)$pendingRow['before_data']===$expected['operation_before']&&(string)$pendingRow['after_data']===$expected['operation_after'],'Pending operation snapshots changed across ZIP upgrade');
    release_upgrade_expect((string)$pendingRow['diff_data']===$expected['operation_diff']&&(string)$pendingRow['rollback_data']===$expected['operation_rollback'],'Pending operation diff/rollback evidence changed across ZIP upgrade');
    release_upgrade_expect((string)get_option('wpai_release_upgrade_operation')==='baseline','ZIP upgrade applied pending sandbox data');

    release_upgrade_expect(!OAI_AI_Lifecycle_Control::is_active(),'AI Lifecycle Control must reset on build change');
    release_upgrade_expect(!OAI_Test_Automation::is_active(),'Test Automation must reset on build change');
    release_upgrade_expect((get_option(OAI_AI_Lifecycle_Control::OPTION,array())['build_id']??'')===OAI_BUILD_ID,'AI lifecycle reset did not bind new build');
    release_upgrade_expect((get_option(OAI_Test_Automation::OPTION,array())['build_id']??'')===OAI_BUILD_ID,'Test automation reset did not bind new build');

    delete_option('wpai_release_upgrade_operation');
    delete_option('wpai_release_upgrade_expected');
    echo 'PASS real ZIP upgrade 2.1.13 -> '.OAI_VERSION.' with identity/OAuth/license/operation evidence preserved and automation reset'.PHP_EOL;
}else{
    throw new RuntimeException('Unknown OAI_RELEASE_UPGRADE_PHASE');
}
