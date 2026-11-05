<?php
// WP-CLI eval-file uses eval(); no strict_types declaration here.
if (!defined('WP_CLI') || !WP_CLI || getenv('OAI_DISPOSABLE_WP_TEST')!=='1') {
    throw new RuntimeException('Disposable WP-CLI opt-in required');
}
$phase=getenv('WPAI_UPGRADE_PHASE');
$snapshot=getenv('WPAI_UPGRADE_SNAPSHOT');
if (!in_array($phase,['seed','upgraded','repeat','restored','upgraded-again'],true) || !$snapshot) {
    throw new RuntimeException('Explicit acceptance phase and snapshot path required');
}
global $wpdb;
function nativeRestoreAssert($ok,$message) { if (!$ok) throw new RuntimeException($message); }
wp_set_current_user(1);
nativeRestoreAssert(current_user_can('manage_options'),'Disposable administrator missing');
$token='oai_pair_'.str_repeat('u',43);
$oauthToken='synthetic-upgrade-oauth-access';
$options=['oai_site_id','oai_installation_id','oai_mcp_enabled','oai_mcp_token_hash','oai_oauth_schema_version','oai_license',OAI_Gateway_Pairing::CREDENTIAL_PREFIX.hash('sha256',$token),'wpai_upgrade_sentinel'];
$tables=[OAI_DB::table(),OAI_MCP_OAuth::clients_table(),OAI_MCP_OAuth::codes_table(),OAI_MCP_OAuth::tokens_table()];
$now=gmdate('Y-m-d H:i:s');
if ($phase==='seed') {
    nativeRestoreAssert(OAI_VERSION==='2.1.14' && (int)get_option('oai_db_version')===23,'Historical code/schema not installed');
    OAI_Platform::site_id(); OAI_Platform::installation_id();
    $license=OAI_License::activate_local_owner(1);
    nativeRestoreAssert(!is_wp_error($license),'Cannot seed local license');
    update_option('oai_mcp_enabled',true,false);
    update_option('oai_mcp_token_hash',hash('sha256','synthetic-manual-upgrade-token'),false);
    update_option(OAI_Gateway_Pairing::CREDENTIAL_PREFIX.hash('sha256',$token),['user_id'=>1,'account_id'=>'1','account_label'=>'Synthetic upgrade owner','created_at'=>time()],false);
    update_option('wpai_upgrade_sentinel','before-upgrade',false);
    $client='synthetic-upgrade-client';
    nativeRestoreAssert($wpdb->insert($tables[1],['client_id'=>$client,'client_name'=>'Synthetic upgrade acceptance','redirect_uris'=>'["https://example.invalid/callback"]','created_at'=>$now])===1,'Cannot seed OAuth client');
    nativeRestoreAssert($wpdb->insert($tables[2],['code_hash'=>hash('sha256','synthetic-code'),'client_id'=>$client,'user_id'=>1,'redirect_uri'=>'https://example.invalid/callback','scope'=>'mcp:read','resource'=>rest_url('okean-ai/mcp'),'code_challenge'=>str_repeat('x',43),'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'used'=>0])===1,'Cannot seed OAuth code');
    nativeRestoreAssert($wpdb->insert($tables[3],['access_hash'=>hash('sha256',$oauthToken),'refresh_hash'=>hash('sha256','synthetic-refresh'),'client_id'=>$client,'user_id'=>1,'scope'=>'mcp:read','resource'=>rest_url('okean-ai/mcp'),'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+86400),'revoked'=>0])===1,'Cannot seed OAuth token');
    nativeRestoreAssert($wpdb->insert($tables[3],['access_hash'=>hash('sha256','synthetic-revoked-access'),'client_id'=>$client,'user_id'=>1,'scope'=>'mcp:read','resource'=>rest_url('okean-ai/mcp'),'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'revoked'=>1])===1,'Cannot seed revoked OAuth token');
    nativeRestoreAssert($wpdb->insert($tables[0],['operation_id'=>'synthetic-upgrade-pending','status'=>'pending','type'=>'content_patch','target'=>'{"post_id":0}','created_at'=>$now])===1,'Cannot seed pending operation');
}
$actual=['options'=>[],'rows'=>[]];
// Compare persisted values: freshly updated bools and values read on a new
// WordPress process have different PHP types, despite identical DB contents.
foreach($options as $key) $actual['options'][$key]=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));
foreach($tables as $table) $actual['rows'][$table]=$wpdb->get_results('SELECT * FROM `'.$table.'` ORDER BY id',ARRAY_A);
nativeRestoreAssert($wpdb->last_error==='','Cannot read retained records');
if ($phase==='seed') {
    $actual['database_tables']=$wpdb->get_col('SHOW TABLES');
    nativeRestoreAssert(file_put_contents($snapshot,wp_json_encode($actual,JSON_THROW_ON_ERROR))!==false,'Cannot persist synthetic snapshot');
} else {
    $expected=json_decode(file_get_contents($snapshot),true,512,JSON_THROW_ON_ERROR);
    nativeRestoreAssert($actual['options']===$expected['options'],'Identity, credentials, license or sentinel changed');
    nativeRestoreAssert($actual['rows']===$expected['rows'],'OAuth rows or pending operation changed');
    if ($phase==='restored') {
        nativeRestoreAssert(OAI_VERSION==='2.1.14' && (int)get_option('oai_db_version')===23,'Historical code/schema restore failed');
        nativeRestoreAssert($wpdb->get_col('SHOW TABLES')===$expected['database_tables'],'Full database table restore failed');
    } else {
        nativeRestoreAssert(OAI_VERSION==='2.1.17' && (int)get_option('oai_db_version')===24,'Candidate migration did not run');
        foreach([OAI_DB::quota_table(),OAI_DB::quota_events_table()] as $table) {
            nativeRestoreAssert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table,'Quota table missing');
        }
        if ($phase==='upgraded') {
            nativeRestoreAssert($wpdb->insert(OAI_DB::quota_table(),['plan'=>'synthetic-upgrade','period_key'=>'test','used'=>7,'reserved'=>2,'updated_at'=>$now])===1,'Cannot seed candidate quota row');
        } elseif ($phase==='repeat') {
            $quota=$wpdb->get_row("SELECT used,reserved FROM ".OAI_DB::quota_table()." WHERE plan='synthetic-upgrade' AND period_key='test'",ARRAY_A);
            nativeRestoreAssert($quota && (int)$quota['used']===7 && (int)$quota['reserved']===2,'Repeated boot reset quota');
        } elseif ($phase==='upgraded-again') {
            nativeRestoreAssert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::quota_table())===0,'Post-backup quota leaked through restore');
        }
        if ($phase==='repeat') {
            // Prove the subsequent import restores data as well as table shape.
            update_option('wpai_upgrade_sentinel','after-upgrade',false);
        }
    }
}
nativeRestoreAssert(is_array(OAI_Gateway_Pairing::authenticate($token)),'Retained paired credential no longer authenticates');
nativeRestoreAssert(is_array(OAI_MCP_OAuth::authenticate_access_token($oauthToken)),'Retained OAuth credential no longer authenticates');
nativeRestoreAssert(OAI_MCP_OAuth::authenticate_access_token('synthetic-revoked-access')===false,'Revoked OAuth credential became valid');
nativeRestoreAssert(OAI_Gateway_Pairing::authenticate('oai_pair_'.str_repeat('z',43))===false,'Unknown paired credential became valid');
nativeRestoreAssert(OAI_License::status()['active']===true,'Retained local license invalid');
echo 'PASS: '.$phase.' identities, license, paired credential, OAuth client/code/token and pending operation'."\n";
