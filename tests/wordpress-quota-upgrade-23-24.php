<?php
// WP-CLI eval-file uses eval() internally; strict_types cannot be declared there.
/** Only run on disposable WordPress CI: simulate 2.1.16 DB 23 -> 2.1.17 DB 24. */
if (!defined('WP_CLI') || !WP_CLI || !defined('OAI_DB_VERSION') || OAI_DB_VERSION!==24) {
    throw new RuntimeException('Disposable WP-CLI and Native DB schema 24 required');
}
global $wpdb;
function quotaUpgradeAssert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$siteId=OAI_Platform::site_id();
$installId=OAI_Platform::installation_id();
$tokenHash=hash('sha256','synthetic-migration-credential');
$clientId='test-upgrade-'.wp_generate_uuid4();
$operationId='upgrade-'.wp_generate_uuid4();
$now=gmdate('Y-m-d H:i:s');
update_option('oai_mcp_token_hash',$tokenHash,false);
update_option('oai_mcp_enabled',true,false);
$clients=OAI_MCP_OAuth::clients_table();
$insertClient=$wpdb->insert($clients,['client_id'=>$clientId,'client_name'=>'Disposable migration client','redirect_uris'=>'["https://example.test/cb"]','created_at'=>$now]);
quotaUpgradeAssert($insertClient===1,'Cannot create OAuth fixture');
$insertOperation=$wpdb->insert(OAI_DB::table(),['operation_id'=>$operationId,'status'=>'pending','type'=>'content_patch','target'=>'{"post_id":0}','created_at'=>$now]);
quotaUpgradeAssert($insertOperation===1,'Cannot create operation fixture');
$operationsBefore=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.OAI_DB::table().' WHERE operation_id=%s',$operationId));
// DB 23 had no v24 quota event tables. Drop only in disposable CI.
foreach([OAI_DB::quota_events_table(),OAI_DB::quota_table()] as $table){
    $wpdb->query('DROP TABLE IF EXISTS `'.$table.'`');
    quotaUpgradeAssert($wpdb->last_error==='','Unable to reset disposable quota fixture');
}
update_option('oai_db_version',23,false);
OAI_DB::maybe_upgrade();
quotaUpgradeAssert((int)get_option('oai_db_version')===24,'DB schema 24 not recorded');
foreach([OAI_DB::quota_events_table(),OAI_DB::quota_table()] as $table) {
    $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
    quotaUpgradeAssert($found===$table,'Missing quota v24 table '.$table);
}
quotaUpgradeAssert(OAI_Platform::site_id()===$siteId&&OAI_Platform::installation_id()===$installId,'Site/installation identity changed');
quotaUpgradeAssert(get_option('oai_mcp_token_hash')===$tokenHash&&get_option('oai_mcp_enabled')===true,'MCP credential changed');
quotaUpgradeAssert((int)get_option('oai_oauth_schema_version')===1,'OAuth schema reset');
quotaUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$clients.' WHERE client_id=%s',$clientId))===1,'OAuth client lost');
quotaUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.OAI_DB::table().' WHERE operation_id=%s',$operationId))===$operationsBefore,'Pending operation lost');
OAI_DB::maybe_upgrade();
quotaUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$clients.' WHERE client_id=%s',$clientId))===1,'Repeated upgrade mutated OAuth client');
echo "PASS: Native v23 -> v24 preserves identities, OAuth credentials and pending operation; repeat is idempotent\n";
