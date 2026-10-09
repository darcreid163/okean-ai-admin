<?php
// Fresh WP AI Admin Cloud Core ZIP acceptance on disposable WordPress.
function cloud_release_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);

cloud_release_expect(defined('WPAI_CLOUD_VERSION')&&WPAI_CLOUD_VERSION==='0.3.7','Cloud Core version mismatch');
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
cloud_release_expect(($data['mode']??'')==='approved_beta','Fresh Cloud health must remain approved_beta');
cloud_release_expect(($data['billing']??null)===false&&($data['batch_accounting']??null)===false&&($data['commercial_client_entitlements']??null)===false,'Fresh Cloud health overstates unfinished commercial enforcement');
$freshHealthEncoded=wp_json_encode($data,JSON_UNESCAPED_SLASHES);
foreach(['wpai_gateway_wrapped_keys_v1','AUTH_KEY','SECURE_AUTH_KEY','DB_PASSWORD','wpai_gateway_','PDOException',ABSPATH] as $marker){
    cloud_release_expect(!str_contains($freshHealthEncoded,(string)$marker),'Fresh health leaked internal/secret marker: '.$marker);
}

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
cloud_release_expect(($data2['mode']??'')==='approved_beta','Enabled Cloud health must remain approved_beta');
cloud_release_expect(($data2['billing']??null)===false&&($data2['batch_accounting']??null)===false&&($data2['commercial_client_entitlements']??null)===false,'Enabled Cloud health overstates unfinished release capabilities');
$enabledHealthEncoded=wp_json_encode($data2,JSON_UNESCAPED_SLASHES);
cloud_release_expect(!str_contains($enabledHealthEncoded,$wrapped),'Enabled health leaked wrapped gateway key material');
foreach(['wpai_gateway_wrapped_keys_v1','AUTH_KEY','SECURE_AUTH_KEY','DB_PASSWORD','wpai_gateway_','PDOException',ABSPATH] as $marker){
    cloud_release_expect(!str_contains($enabledHealthEncoded,(string)$marker),'Enabled health leaked internal/secret marker: '.$marker);
}

$policy=\WPAICloudGateway\Settings::policy();
cloud_release_expect(($policy['period']??'')==='calendar_month_utc','Cloud quota period contract mismatch');
cloud_release_expect(($policy['billing']??'')==='successful_single_tool_call','Cloud billing contract mismatch');
cloud_release_expect(($policy['batch']??'')==='disabled_until_receipt_accounting_contract','Cloud batch accounting gate mismatch');
foreach(['free'=>100,'pro'=>500,'power'=>2000,'agency'=>5000,'scale'=>10000] as $slug=>$limit){
    cloud_release_expect(($policy['plans'][$slug]??null)===$limit,'Cloud plan quota mismatch: '.$slug);
}

// Repeated administrator enable must be idempotent: never rotate central keys.
$wrappedBefore=$wrapped;
\WPAICloudGateway\WordPress::enable();
$wrappedAfter=(string)get_option('wpai_gateway_wrapped_keys_v1','');
cloud_release_expect(hash_equals($wrappedBefore,$wrappedAfter),'Repeated Cloud enable rotated wrapped gateway keys');
cloud_release_expect(\WPAICloudGateway\WordPress::configured(),'Repeated Cloud enable left gateway unconfigured');
$gatewayTableCountBefore=count($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}wpai_gateway_%'"));
\WPAICloudGateway\WordPress::enable();
$gatewayTableCountAfter=count($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}wpai_gateway_%'"));
cloud_release_expect($gatewayTableCountAfter===$gatewayTableCountBefore,'Repeated Cloud enable changed gateway schema table count');

// Wrapped gateway key corruption must fail closed after a fresh runtime load.
$coreRef=new ReflectionProperty('WPAICloudGateway\\WordPress','core');$coreRef->setAccessible(true);
$storeRef=new ReflectionProperty('WPAICloudGateway\\WordPress','store');$storeRef->setAccessible(true);
update_option('wpai_gateway_wrapped_keys_v1',substr($wrappedBefore,0,-1).($wrappedBefore[-1]==='A'?'B':'A'),false);
$coreRef->setValue(null,null);$storeRef->setValue(null,null);
cloud_release_expect(!\WPAICloudGateway\WordPress::configured(),'Corrupted wrapped gateway keys did not fail closed');
update_option('wpai_gateway_wrapped_keys_v1',$wrappedBefore,false);
$coreRef->setValue(null,null);$storeRef->setValue(null,null);
cloud_release_expect(\WPAICloudGateway\WordPress::configured(),'Restored wrapped gateway keys did not recover configuration');

// Public MCP transport must fail closed before any tool dispatch.
// WP-CLI has no HTTPS request server context even when home_url() is HTTPS.
$_SERVER['HTTPS']='on';
$evil=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$evil->set_header('origin','https://evil.example.com');
$evilResponse=rest_do_request($evil);
cloud_release_expect($evilResponse->get_status()===403&&($evilResponse->get_data()['error']??'')==='origin_denied','Foreign MCP Origin was not denied');

$noBearer=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$noBearerResponse=rest_do_request($noBearer);
cloud_release_expect($noBearerResponse->get_status()===401&&($noBearerResponse->get_data()['error']??'')==='bearer_required','MCP request without Bearer was not denied');
cloud_release_expect(str_contains((string)$noBearerResponse->get_headers()['WWW-Authenticate']??'','resource_metadata='),'MCP 401 challenge missing protected-resource metadata');

$badVersion=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$badVersion->set_header('mcp-protocol-version','1900-01-01');
$badVersionResponse=rest_do_request($badVersion);
cloud_release_expect($badVersionResponse->get_status()===400&&($badVersionResponse->get_data()['error']??'')==='unsupported_protocol_version','Unsupported MCP protocol version was not denied');

foreach([$evilResponse,$noBearerResponse,$badVersionResponse] as $negative){
    $encoded=wp_json_encode($negative->get_data());
    cloud_release_expect(!str_contains($encoded,'PDOException')&&!str_contains($encoded,'Stack trace')&&!str_contains($encoded,ABSPATH),'MCP negative response leaked internal diagnostics');
    $headers=$negative->get_headers();
    cloud_release_expect(str_contains(strtolower((string)($headers['Cache-Control']??'')),'no-store'),'MCP negative response missing no-store');
    cloud_release_expect(strtolower((string)($headers['X-Content-Type-Options']??''))==='nosniff','MCP negative response missing nosniff');
}

// Provisioning/control-plane REST must never accept anonymous, ineligible, bearer-only, or non-HTTPS access.
$adminId=get_current_user_id();
wp_set_current_user(0);
$guestSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($guestSites->get_status()===401,'Anonymous provisioning request was not denied');

$subscriber=wp_create_user('cloud-release-subscriber-'.wp_generate_password(8,false,false),'disposable-password','cloud-release-subscriber@example.test');
cloud_release_expect(!is_wp_error($subscriber),'Unable to create provisioning boundary subscriber');
wp_set_current_user((int)$subscriber);
$subscriberSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($subscriberSites->get_status()===401,'Ineligible WordPress user reached provisioning API');

update_option('wpai_gateway_beta_users',array((int)$subscriber),false);
$allowedSubscriberSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($allowedSubscriberSites->get_status()===200&&is_array($allowedSubscriberSites->get_data())&&count($allowedSubscriberSites->get_data())===0,'Explicit beta allowlist did not enable isolated subscriber tenant');

// Already-issued Cloud bearer must be invalidated by owner eligibility loss.
$subscriberCore=\WPAICloudGateway\WordPress::operatorCore();
$subscriberCore->ensureAccount((string)$subscriber);
$cloudRef=new ReflectionClass('WPAICloudGateway\\WordPress');
$storeProp=$cloudRef->getProperty('store');$storeProp->setAccessible(true);
$gatewayStore=$storeProp->getValue();
cloud_release_expect($gatewayStore instanceof \WPAICloudGateway\Store,'Unable to inspect gateway store for bearer eligibility fixture');
$subscriberBearer='wpai_cloud_'.\WPAICloudGateway\Security::b64(random_bytes(32));
$gatewayStore->execute('INSERT INTO '.$gatewayStore->table('tokens').' (hash,owner,scopes,site_ids,expires_at,revoked) VALUES (?,?,?,?,?,0)',array(
    hash('sha256',$subscriberBearer),(string)$subscriber,
    \WPAICloudGateway\Security::canonical(array('mcp:read')),
    \WPAICloudGateway\Security::canonical(array()),
    time()+3600
));
$subscriberActor=\WPAICloudGateway\WordPress::authenticateHeader('Bearer '.$subscriberBearer);
cloud_release_expect(($subscriberActor['owner']??'')===(string)$subscriber,'Eligible beta subscriber bearer was rejected');

$subscriberPlan=new WP_REST_Request('POST','/wpai-cloud/v1/accounts/'.(int)$subscriber.'/plan');
$subscriberPlan->set_header('content-type','application/json');
$subscriberPlan->set_body(wp_json_encode(array('plan'=>'scale')));
$subscriberPlanResponse=rest_do_request($subscriberPlan);
cloud_release_expect($subscriberPlanResponse->get_status()===403,'Beta subscriber self-assigned an operator-only plan');

$subscriberReconcile=new WP_REST_Request('POST','/wpai-cloud/v1/accounts/'.(int)$subscriber.'/requests/'.str_repeat('a',64).'/reconcile');
$subscriberReconcile->set_header('content-type','application/json');
$subscriberReconcile->set_body(wp_json_encode(array('outcome'=>'failed','evidence'=>array('note'=>'subscriber must never reach reconciliation','receipt_sha256'=>str_repeat('b',64)))));
$subscriberReconcileResponse=rest_do_request($subscriberReconcile);
cloud_release_expect($subscriberReconcileResponse->get_status()===403,'Beta subscriber reached operator-only reconciliation');

foreach([$subscriberPlanResponse,$subscriberReconcileResponse] as $negative){
    $encoded=wp_json_encode($negative->get_data());
    cloud_release_expect(!str_contains($encoded,'PDOException')&&!str_contains($encoded,'Stack trace')&&!str_contains($encoded,ABSPATH),'Operator denial leaked internal diagnostics');
}

update_option('wpai_gateway_beta_users',array(),false);
$revokedSubscriberSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($revokedSubscriberSites->get_status()===401,'Removing user from beta allowlist did not revoke provisioning access');
$bearerRevoked=false;
try{ \WPAICloudGateway\WordPress::authenticateHeader('Bearer '.$subscriberBearer); }
catch(\WPAICloudGateway\Fault $e){ $bearerRevoked=$e->reason==='account_unavailable'; }
cloud_release_expect($bearerRevoked,'Removing owner eligibility did not invalidate an already-issued Cloud bearer');

// Deleting a central WordPress user must invalidate an already-issued bearer on the next request.
$deletedUser=wp_create_user('cloud-release-deleted-'.wp_generate_password(8,false,false),'disposable-password','cloud-release-deleted@example.test');
cloud_release_expect(!is_wp_error($deletedUser),'Unable to create deleted-owner fixture');
update_option('wpai_gateway_beta_users',array((int)$deletedUser),false);
$subscriberCore->ensureAccount((string)$deletedUser);
$deletedBearer='wpai_cloud_'.\WPAICloudGateway\Security::b64(random_bytes(32));
$gatewayStore->execute('INSERT INTO '.$gatewayStore->table('tokens').' (hash,owner,scopes,site_ids,expires_at,revoked) VALUES (?,?,?,?,?,0)',array(
    hash('sha256',$deletedBearer),(string)$deletedUser,
    \WPAICloudGateway\Security::canonical(array('mcp:read')),
    \WPAICloudGateway\Security::canonical(array()),
    time()+3600
));
$deletedActor=\WPAICloudGateway\WordPress::authenticateHeader('Bearer '.$deletedBearer);
cloud_release_expect(($deletedActor['owner']??'')===(string)$deletedUser,'Deleted-owner fixture bearer was not initially valid');
require_once ABSPATH.'wp-admin/includes/user.php';
cloud_release_expect(wp_delete_user((int)$deletedUser)===true,'Unable to delete central owner fixture');
$deletedBearerRevoked=false;
try{ \WPAICloudGateway\WordPress::authenticateHeader('Bearer '.$deletedBearer); }
catch(\WPAICloudGateway\Fault $e){ $deletedBearerRevoked=$e->reason==='account_unavailable'; }
cloud_release_expect($deletedBearerRevoked,'Deleted central WordPress owner retained existing Cloud bearer access');
update_option('wpai_gateway_beta_users',array(),false);

// Self-service account suspension HTTP contract: preview -> explicit confirmation -> fail closed.
$closureUser=wp_create_user('cloud-release-closure-'.wp_generate_password(8,false,false),'disposable-password','cloud-release-closure@example.test');
cloud_release_expect(!is_wp_error($closureUser),'Unable to create account suspension HTTP fixture');
update_option('wpai_gateway_beta_users',array((int)$closureUser),false);
wp_set_current_user((int)$closureUser);
$closurePreview=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/account/closure-preview'));
cloud_release_expect($closurePreview->get_status()===200&&(($closurePreview->get_data()['destructive_delete_performed']??true)===false),'Closure preview failed or overstated deletion');

$badSuspend=new WP_REST_Request('POST','/wpai-cloud/v1/account/suspend');
$badSuspend->set_header('content-type','application/json');
$badSuspend->set_body(wp_json_encode(array('confirm'=>'NO')));
$badSuspendResponse=rest_do_request($badSuspend);
cloud_release_expect($badSuspendResponse->get_status()===409&&(($badSuspendResponse->get_data()['error']??'')==='suspension_confirmation_required'),'Account suspension did not require exact confirmation');

$goodSuspend=new WP_REST_Request('POST','/wpai-cloud/v1/account/suspend');
$goodSuspend->set_header('content-type','application/json');
$goodSuspend->set_body(wp_json_encode(array('confirm'=>'SUSPEND')));
$goodSuspendResponse=rest_do_request($goodSuspend);
cloud_release_expect($goodSuspendResponse->get_status()===200&&!empty($goodSuspendResponse->get_data()['suspended'])&&!empty($goodSuspendResponse->get_data()['access_revoked']),'Confirmed account suspension failed');

$afterSuspendSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($afterSuspendSites->get_status()===403&&(($afterSuspendSites->get_data()['error']??'')==='account_unavailable'),'Suspended owner retained Cloud Core site access');
$repeatSuspendReq=new WP_REST_Request('POST','/wpai-cloud/v1/account/suspend');
$repeatSuspendReq->set_header('content-type','application/json');
$repeatSuspendReq->set_body(wp_json_encode(array('confirm'=>'SUSPEND')));
$repeatSuspend=rest_do_request($repeatSuspendReq);
cloud_release_expect($repeatSuspend->get_status()===403&&(($repeatSuspend->get_data()['error']??'')==='account_unavailable'),'Suspended account could invoke suspension flow again');
update_option('wpai_gateway_beta_users',array(),false);
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user((int)$closureUser);

wp_set_current_user(0);
$bearerOnly=new WP_REST_Request('GET','/wpai-cloud/v1/sites');
$bearerOnly->set_header('authorization','Bearer wpai_cloud_'.str_repeat('A',43));
$bearerSites=rest_do_request($bearerOnly);
cloud_release_expect($bearerSites->get_status()===401,'Cloud bearer token authorized provisioning without WordPress login');

wp_set_current_user($adminId);
$httpsBefore=$_SERVER['HTTPS']??null;
unset($_SERVER['HTTPS']);
$httpSites=rest_do_request(new WP_REST_Request('GET','/wpai-cloud/v1/sites'));
cloud_release_expect($httpSites->get_status()===403,'Provisioning over non-HTTPS was not denied');
$_SERVER['HTTPS']='on';

foreach([$guestSites,$subscriberSites,$bearerSites,$httpSites] as $negative){
    $encoded=wp_json_encode($negative->get_data());
    cloud_release_expect(!str_contains($encoded,'PDOException')&&!str_contains($encoded,'Stack trace')&&!str_contains($encoded,ABSPATH),'Provisioning denial leaked internal diagnostics');
}

// OAuth HTTP surface enforces HTTPS and exact request encodings before processing credentials.
wp_set_current_user($adminId);$_SERVER['HTTPS']='on';
$badRegisterType=new WP_REST_Request('POST','/wpai-cloud/v1/oauth/register');
$badRegisterType->set_header('content-type','text/plain');
$badRegisterType->set_body('{}');
$badRegisterResponse=rest_do_request($badRegisterType);
cloud_release_expect($badRegisterResponse->get_status()===415&&($badRegisterResponse->get_data()['error']??'')==='json_content_type_required','OAuth DCR accepted wrong content type');

$badTokenType=new WP_REST_Request('POST','/wpai-cloud/v1/oauth/token');
$badTokenType->set_header('content-type','application/json');
$badTokenType->set_body('{}');
$badTokenResponse=rest_do_request($badTokenType);
cloud_release_expect($badTokenResponse->get_status()===415&&($badTokenResponse->get_data()['error']??'')==='form_content_type_required','OAuth token endpoint accepted wrong content type');

unset($_SERVER['HTTPS']);
$httpRegister=new WP_REST_Request('POST','/wpai-cloud/v1/oauth/register');
$httpRegister->set_header('content-type','application/json');
$httpRegister->set_body('{}');
$httpRegisterResponse=rest_do_request($httpRegister);
cloud_release_expect($httpRegisterResponse->get_status()===403&&($httpRegisterResponse->get_data()['error']??'')==='https_required','OAuth endpoint accepted non-HTTPS request');
$_SERVER['HTTPS']='on';

foreach([$badRegisterResponse,$badTokenResponse,$httpRegisterResponse] as $negative){
    $encoded=wp_json_encode($negative->get_data());
    cloud_release_expect(!str_contains($encoded,'PDOException')&&!str_contains($encoded,'Stack trace')&&!str_contains($encoded,ABSPATH),'OAuth denial leaked internal diagnostics');
}

// Real HTTP MCP transport with a valid short-lived pilot bearer, without external network access.
$gatewayCore=\WPAICloudGateway\WordPress::operatorCore();
$gatewayCore->ensureAccount('1');
$httpSite='reg_'.str_repeat('a',32);
$vault=new \WPAICloudGateway\Vault(\WPAICloudGateway\Settings::keys()['vault']);
$siteToken='fixture-http-mcp-site-token-aaaaaaaa';
$sealed=$vault->seal($siteToken,'site:1:'.$httpSite);
$wpdb->insert($wpdb->prefix.'wpai_gateway_sites',array(
    'id'=>$httpSite,'owner'=>'1','home'=>'https://http-mcp.example.com','url_hash'=>hash('sha256','https://http-mcp.example.com'),
    'plugin_site_id'=>'plugin-http-mcp','installation_id'=>'install-http-mcp','token_sealed'=>$sealed,'status'=>'active','created_at'=>time()
));
cloud_release_expect($wpdb->last_error==='','Unable to seed HTTP MCP site registration');
$pilot=$gatewayCore->mint('1',array($httpSite),array('mcp:read'),time());
$bearer='Bearer '.$pilot['access_token'];

$getMcp=new WP_REST_Request('GET','/wpai-cloud/v1/mcp');$getMcp->set_header('authorization',$bearer);
$getMcpResponse=rest_do_request($getMcp);
cloud_release_expect($getMcpResponse->get_status()===405&&(($getMcpResponse->get_data()['error']??'')==='stateless_json_transport_only'),'Authenticated MCP GET was not rejected as stateless POST-only');
cloud_release_expect((string)($getMcpResponse->get_headers()['Allow']??'')==='POST','Authenticated MCP GET response missing Allow: POST');

$oversizedMcp=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$oversizedMcp->set_header('authorization',$bearer);$oversizedMcp->set_header('content-type','application/json');$oversizedMcp->set_header('accept','application/json, text/event-stream');
$oversizedMcp->set_body('{"jsonrpc":"2.0","id":10,"method":"ping","pad":"'.str_repeat('x',131200).'"}');
$oversizedMcpResponse=rest_do_request($oversizedMcp);
cloud_release_expect($oversizedMcpResponse->get_status()===413&&(($oversizedMcpResponse->get_data()['error']??'')==='request_too_large'),'Oversized MCP request was not rejected before dispatch');

$listBody=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$listBody->set_header('authorization',$bearer);$listBody->set_header('content-type','application/json');$listBody->set_header('accept','application/json, text/event-stream');
$listBody->set_body('[{"jsonrpc":"2.0","id":11,"method":"ping"}]');
$listBodyResponse=rest_do_request($listBody);
cloud_release_expect($listBodyResponse->get_status()===400&&(($listBodyResponse->get_data()['error']??'')==='json_object_required'),'JSON-RPC batch/list body was not rejected');

$notification=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$notification->set_header('authorization',$bearer);$notification->set_header('content-type','application/json');$notification->set_header('accept','application/json, text/event-stream');
$notification->set_body(wp_json_encode(array('jsonrpc'=>'2.0','method'=>'notifications/initialized','params'=>(object)array())));
$notificationResponse=rest_do_request($notification);
cloud_release_expect($notificationResponse->get_status()===202&&$notificationResponse->get_data()===null,'MCP notification did not return empty 202 semantics');

$wrongType=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$wrongType->set_header('authorization',$bearer);$wrongType->set_header('content-type','text/plain');$wrongType->set_header('accept','application/json, text/event-stream');$wrongType->set_body('{}');
$wrongTypeResponse=rest_do_request($wrongType);
cloud_release_expect($wrongTypeResponse->get_status()===415&&(($wrongTypeResponse->get_data()['error']??'')==='json_content_type_required'),'Authenticated MCP accepted wrong Content-Type');

$wrongAccept=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$wrongAccept->set_header('authorization',$bearer);$wrongAccept->set_header('content-type','application/json');$wrongAccept->set_header('accept','application/json');$wrongAccept->set_body(wp_json_encode(array('jsonrpc'=>'2.0','id'=>1,'method'=>'ping')));
$wrongAcceptResponse=rest_do_request($wrongAccept);
cloud_release_expect($wrongAcceptResponse->get_status()===406&&(($wrongAcceptResponse->get_data()['error']??'')==='mcp_accept_header_required'),'Authenticated MCP accepted incomplete Accept header');

$initialize=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$initialize->set_header('authorization',$bearer);$initialize->set_header('content-type','application/json');$initialize->set_header('accept','application/json, text/event-stream');$initialize->set_header('mcp-protocol-version','2025-11-25');
$initialize->set_body(wp_json_encode(array('jsonrpc'=>'2.0','id'=>7,'method'=>'initialize','params'=>array('protocolVersion'=>'2025-11-25'))));
$initializeResponse=rest_do_request($initialize);$initializeData=$initializeResponse->get_data();
cloud_release_expect($initializeResponse->get_status()===200&&(($initializeData['result']['protocolVersion']??'')==='2025-11-25')&&(($initializeData['result']['serverInfo']['version']??'')===WPAI_CLOUD_VERSION),'Authenticated MCP initialize failed');
$initializeEncoded=wp_json_encode($initializeData,JSON_UNESCAPED_SLASHES);
cloud_release_expect(!str_contains($initializeEncoded,$pilot['access_token'])&&!str_contains($initializeEncoded,$siteToken),'MCP initialize leaked bearer/site credential');
foreach(['wpai_gateway_wrapped_keys_v1','AUTH_KEY','DB_PASSWORD',ABSPATH] as $marker){
    cloud_release_expect(!str_contains($initializeEncoded,(string)$marker),'MCP initialize leaked internal/secret marker: '.$marker);
}

$toolsReq=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$toolsReq->set_header('authorization',$bearer);$toolsReq->set_header('content-type','application/json');$toolsReq->set_header('accept','application/json, text/event-stream');
$toolsReq->set_body(wp_json_encode(array('jsonrpc'=>'2.0','id'=>8,'method'=>'tools/list','params'=>(object)array())));
$toolsResponse=rest_do_request($toolsReq);$toolsData=$toolsResponse->get_data();
cloud_release_expect($toolsResponse->get_status()===200&&count($toolsData['result']['tools']??array())===4,'Authenticated gateway tools/list contract changed unexpectedly');

$gatewayCore->revokeToken('1',hash('sha256',$pilot['access_token']),time());
$revokedReq=new WP_REST_Request('POST','/wpai-cloud/v1/mcp');
$revokedReq->set_header('authorization',$bearer);$revokedReq->set_header('content-type','application/json');$revokedReq->set_header('accept','application/json, text/event-stream');
$revokedReq->set_body(wp_json_encode(array('jsonrpc'=>'2.0','id'=>9,'method'=>'tools/list','params'=>(object)array())));
$revokedResponse=rest_do_request($revokedReq);
cloud_release_expect($revokedResponse->get_status()===401&&(($revokedResponse->get_data()['error']??'')==='invalid_or_expired_token'),'Revoked MCP bearer remained valid over HTTP');
cloud_release_expect(str_contains((string)($revokedResponse->get_headers()['WWW-Authenticate']??''),'resource_metadata='),'Revoked MCP bearer response missing authorization challenge');

foreach([$getMcpResponse,$oversizedMcpResponse,$listBodyResponse,$notificationResponse,$wrongTypeResponse,$wrongAcceptResponse,$initializeResponse,$toolsResponse,$revokedResponse] as $response){
    $headers=$response->get_headers();
    cloud_release_expect(str_contains(strtolower((string)($headers['Cache-Control']??'')),'no-store'),'Authenticated MCP response missing no-store');
    cloud_release_expect(strtolower((string)($headers['X-Content-Type-Options']??''))==='nosniff','Authenticated MCP response missing nosniff');
}

echo 'PASS fresh Cloud Core ZIP install+enable: WP '.get_bloginfo('version').', PHP '.PHP_VERSION.', cloud '.WPAI_CLOUD_VERSION.', plans '.count($plans).PHP_EOL;
