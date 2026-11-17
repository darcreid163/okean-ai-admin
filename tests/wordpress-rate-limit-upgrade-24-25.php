<?php
// WP-CLI eval-file uses eval() internally; strict_types cannot be declared here.
if (!defined('WP_CLI') || !WP_CLI || !defined('OAI_DB_VERSION') || OAI_DB_VERSION!==25) {
    throw new RuntimeException('Disposable WP-CLI and Native DB schema 25 required');
}
global $wpdb;
function rateUpgradeAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }

$table=OAI_DB::rate_limits_table();
$operationTable=OAI_DB::table();
$oauthTable=OAI_MCP_OAuth::clients_table();
$operationId='rate-upgrade-'.wp_generate_uuid4();
$clientId='rate-upgrade-'.wp_generate_uuid4();
$now=gmdate('Y-m-d H:i:s');
rateUpgradeAssert($wpdb->insert($operationTable,['operation_id'=>$operationId,'status'=>'pending','type'=>'content_patch','target'=>'{}','created_at'=>$now])===1,'Cannot create retained operation fixture');
rateUpgradeAssert($wpdb->insert($oauthTable,['client_id'=>$clientId,'client_name'=>'Rate migration fixture','redirect_uris'=>'[]','created_at'=>$now])===1,'Cannot create retained OAuth client fixture');

$wpdb->query('DROP TABLE IF EXISTS `'.$table.'`');
rateUpgradeAssert($wpdb->last_error==='','Cannot reset disposable rate-limit table');
update_option('oai_db_version',24,false);
OAI_DB::maybe_upgrade();
rateUpgradeAssert((int)get_option('oai_db_version')===25,'DB schema 25 not recorded');
$found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
rateUpgradeAssert($found===$table,'Missing atomic rate-limit table after 24 -> 25 upgrade');
rateUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$operationTable.' WHERE operation_id=%s',$operationId))===1,'Pending operation was lost');
rateUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$oauthTable.' WHERE client_id=%s',$clientId))===1,'OAuth client was lost');
OAI_DB::maybe_upgrade();
rateUpgradeAssert((int)get_option('oai_db_version')===25,'Repeated migration changed schema version');
rateUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$operationTable.' WHERE operation_id=%s',$operationId))===1,'Repeated migration changed pending operation');
rateUpgradeAssert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$oauthTable.' WHERE client_id=%s',$clientId))===1,'Repeated migration changed OAuth client');

$indexes=$wpdb->get_results('SHOW INDEX FROM '.$table,ARRAY_A);
$primary=array_values(array_filter($indexes,static fn($row)=>($row['Key_name']??'')==='PRIMARY'));
rateUpgradeAssert(count($primary)===2,'Rate-limit primary key is not the bucket/window composite');
echo "PASS: Native DB 24 -> 25 creates atomic rate-limit table and preserves operations/OAuth; repeat is idempotent\n";
