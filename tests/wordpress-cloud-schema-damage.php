<?php
// Destructive schema-damage acceptance. Run last on a disposable Cloud WordPress.
function cloud_schema_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
global $wpdb;

cloud_schema_expect(defined('WPAI_CLOUD_VERSION')&&WPAI_CLOUD_VERSION==='0.3.7','Schema-damage test requires Cloud 0.3.7');
cloud_schema_expect((string)get_option('wpai_gateway_db_version','')==='2','Gateway DB version must already be current');
cloud_schema_expect(\WPAICloudGateway\Settings::enabled(),'Gateway must be enabled before schema-damage test');

$table=$wpdb->prefix.'wpai_gateway_requests';
$columns=$wpdb->get_col("SHOW COLUMNS FROM `$table`",0);
cloud_schema_expect(in_array('reconciliation_evidence',$columns,true),'Required gateway column missing before damage fixture');

$wpdb->query("ALTER TABLE `$table` DROP COLUMN reconciliation_evidence");
cloud_schema_expect($wpdb->last_error==='','Unable to damage disposable gateway schema: '.$wpdb->last_error);

$failedClosed=false;
try{ \WPAICloudGateway\WordPress::operatorCore(); }
catch(\WPAICloudGateway\Fault $e){ $failedClosed=$e->reason==='gateway_schema_incompatible'&&$e->status===503; }
cloud_schema_expect($failedClosed,'Current DB-version marker bypassed damaged gateway schema');

do_action('rest_api_init');
$health=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/health'));
$data=$health->get_data();
cloud_schema_expect($health->get_status()===200&&is_array($data)&&(($data['configured']??true)===false),'Health did not report configured=false after gateway schema damage');
$encoded=wp_json_encode($data,JSON_UNESCAPED_SLASHES);
foreach(['PDOException','SQLSTATE','reconciliation_evidence',ABSPATH,DB_PASSWORD] as $marker){
    cloud_schema_expect(!str_contains($encoded,(string)$marker),'Schema-damage health leaked internal diagnostic marker: '.$marker);
}

echo "PASS Cloud current-version schema damage fails closed without diagnostic leak\n";
