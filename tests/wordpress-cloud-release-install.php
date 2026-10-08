<?php
// Fresh WP AI Admin Cloud Core ZIP acceptance on disposable WordPress.
function cloud_release_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);

cloud_release_expect(defined('WPAI_CLOUD_VERSION')&&WPAI_CLOUD_VERSION==='0.3.6','Cloud Core version mismatch');
cloud_release_expect(defined('WPAI_CLOUD_DB_VERSION'),'Cloud Core DB version constant missing');
cloud_release_expect(class_exists('WPAI_Cloud_DB')&&class_exists('WPAI_Cloud_Plans'),'Cloud Core v1 classes missing');
cloud_release_expect(class_exists('WPAICloudGateway\\WordPress')&&class_exists('WPAICloudGateway\\Settings')&&class_exists('WPAICloudGateway\\Core'),'Gateway classes missing');

cloud_release_expect(!\WPAICloudGateway\Settings::enabled(),'Fresh Cloud Core must not enable gateway automatically');
cloud_release_expect(!\WPAICloudGateway\WordPress::configured(),'Fresh Cloud Core must report unconfigured before explicit gateway enable');
cloud_release_expect(get_option('wpai_gateway_wrapped_keys_v1','')==='','Fresh install generated gateway keys before explicit enable');
cloud_release_expect((string)get_option('wpai_cloud_db_version','')===(string)WPAI_CLOUD_DB_VERSION,'Cloud Core activation did not install base schema');

global $wpdb;
foreach(['accounts','plans','plan_versions','licenses','sites','site_tokens','intents','usage_events','usage_balances','usage_reservations','audit_log'] as $name){
    $table=WPAI_Cloud_DB::table($name);
    $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
    cloud_release_expect((string)$found===$table,'Missing Cloud Core table: '.$name);
}
$plans=$wpdb->get_col('SELECT slug FROM '.WPAI_Cloud_DB::table('plans').' WHERE active=1 ORDER BY slug');
foreach(['free','pro','power','agency','scale'] as $slug)cloud_release_expect(in_array($slug,$plans,true),'Missing seeded plan: '.$slug);

cloud_release_expect(\WPAICloudGateway\Settings::endpoint()==='https://wpaiadmin.ru/mcp','Default public MCP endpoint mismatch');
cloud_release_expect(\WPAICloudGateway\Settings::sseEndpoint()==='https://wpaiadmin.ru/sse','Default public SSE endpoint mismatch');

do_action('rest_api_init');
$routes=rest_get_server()->get_routes();
cloud_release_expect(isset($routes['/wpai-cloud/v1/health']),'Cloud health route not registered');
$health=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/health'));
cloud_release_expect($health->get_status()===200,'Cloud health route failed');
$data=$health->get_data();
cloud_release_expect(($data['service']??'')==='wp-ai-cloud'&&($data['version']??'')===WPAI_CLOUD_VERSION,'Cloud health identity mismatch');
cloud_release_expect(($data['configured']??null)===false,'Fresh Cloud health must report configured=false');
cloud_release_expect(($data['public_mcp_url']??'')==='https://wpaiadmin.ru/mcp','Health public MCP URL mismatch');
cloud_release_expect(($data['billing']??null)===false&&($data['commercial_client_entitlements']??null)===false,'Fresh Cloud health overstates unfinished commercial enforcement');

// Explicit administrator opt-in must be the only action that creates gateway keys and enables the service.
\WPAICloudGateway\WordPress::enable();
cloud_release_expect(\WPAICloudGateway\Settings::enabled(),'Explicit Cloud gateway enable did not persist');
cloud_release_expect(\WPAICloudGateway\WordPress::configured(),'Enabled Cloud gateway is not configured');
$wrapped=(string)get_option('wpai_gateway_wrapped_keys_v1','');
cloud_release_expect(strlen($wrapped)>80,'Wrapped gateway key material missing');
cloud_release_expect(!str_contains($wrapped,'PRIVATE KEY')&&!str_contains($wrapped,'wpai_cloud_'),'Wrapped gateway option appears to contain plaintext credential material');
$autoload=$wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'wpai_gateway_wrapped_keys_v1'));
cloud_release_expect(in_array((string)$autoload,['no','off','auto-off'],true),'Gateway wrapped keys must not be autoloaded');
cloud_release_expect((string)get_option('wpai_gateway_db_version','')==='2','Gateway schema migration did not complete');
foreach(['accounts','balances','sites','tokens','requests','audit','rate','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'] as $name){
    $table=$wpdb->prefix.'wpai_gateway_'.$name;
    $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
    cloud_release_expect((string)$found===$table,'Missing gateway table after enable: '.$name);
}
$health2=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/health'));
cloud_release_expect($health2->get_status()===200,'Enabled Cloud health route failed');
$data2=$health2->get_data();
cloud_release_expect(($data2['configured']??null)===true&&($data2['oauth']??null)===true,'Enabled Cloud health did not report configured OAuth gateway');
cloud_release_expect(($data2['billing']??null)===false&&($data2['batch_accounting']??null)===false,'Enabled Cloud health overstates unfinished release capabilities');

echo 'PASS fresh Cloud Core ZIP install+enable: WP '.get_bloginfo('version').', PHP '.PHP_VERSION.', cloud '.WPAI_CLOUD_VERSION.', plans '.count($plans).PHP_EOL;
