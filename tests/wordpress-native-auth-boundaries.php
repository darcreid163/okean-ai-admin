<?php
// Native authentication boundary acceptance on disposable WordPress.
function native_auth_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
add_filter('wp_is_application_passwords_available','__return_true');
// wp_authenticate_application_password() intentionally authenticates only API
// requests. This acceptance file runs under WP-CLI, so mark the isolated
// process as REST to exercise the real WordPress Application Password path.
if (!defined('REST_REQUEST')) define('REST_REQUEST', true);

$adminId=get_current_user_id();
$admin=get_user_by('id',$adminId);
native_auth_expect($admin instanceof WP_User && user_can($admin,'manage_options'),'Admin fixture missing');

$subscriberId=wp_create_user('release-auth-subscriber-'.wp_generate_password(6,false,false),'disposable-password','release-auth-subscriber@example.test');
native_auth_expect(!is_wp_error($subscriberId),'Unable to create subscriber auth fixture');
$subscriber=get_user_by('id',$subscriberId);
$subscriber->set_role('subscriber');

$adminApp=WP_Application_Passwords::create_new_application_password($adminId,array('name'=>'WP AI Admin release auth test'));
$subApp=WP_Application_Passwords::create_new_application_password($subscriberId,array('name'=>'WP AI Admin release auth test'));
native_auth_expect(is_array($adminApp)&&isset($adminApp[0])&&is_string($adminApp[0]),'Unable to create admin Application Password');
native_auth_expect(is_array($subApp)&&isset($subApp[0])&&is_string($subApp[0]),'Unable to create subscriber Application Password');

$makeBasic=function($user,$pass){
    $r=new WP_REST_Request('GET','/okean-ai/v1/status');
    $r->set_header('authorization','Basic '.base64_encode($user.':'.$pass));
    return $r;
};

wp_set_current_user(0);
$subReq=$makeBasic($subscriber->user_login,$subApp[0]);
native_auth_expect(OAI_REST::perm($subReq)===false,'Subscriber Application Password reached native admin API');

$adminReq=$makeBasic($admin->user_login,$adminApp[0]);
native_auth_expect(OAI_REST::perm($adminReq)===true,'Admin Application Password was rejected');
native_auth_expect(get_current_user_id()===$adminId,'Admin Application Password did not bind current user');

wp_set_current_user(0);
$admin->set_role('subscriber');
native_auth_expect(OAI_REST::perm($adminReq)===false,'Demoted admin retained Application Password API access');
$admin->set_role('administrator');

// Legacy REST bearer is bound to an administrator identity, not just possession of the string.
$legacy='release-legacy-bearer-'.wp_generate_password(40,false,false);
update_option('oai_bearer_token',$legacy,false);
update_option('oai_bearer_user_id',$adminId,false);
$legacyReq=new WP_REST_Request('GET','/okean-ai/v1/status');
$legacyReq->set_header('authorization','Bearer '.$legacy);
wp_set_current_user(0);
native_auth_expect(OAI_REST::perm($legacyReq)===true,'Admin-bound legacy bearer was rejected');
$admin->set_role('subscriber');wp_set_current_user(0);
native_auth_expect(OAI_REST::perm($legacyReq)===false,'Demoted admin retained legacy bearer access');
$admin->set_role('administrator');
delete_option('oai_bearer_token');delete_option('oai_bearer_user_id');

// Hashed native MCP token is also bound to the administrator who generated it.
wp_set_current_user($adminId);
$mcpToken=OAI_MCP::generate_token($adminId);
native_auth_expect(is_string($mcpToken)&&str_starts_with($mcpToken,'oai_mcp_'),'Unable to generate native MCP token');
native_auth_expect(!str_contains((string)get_option('oai_mcp_token_hash',''),$mcpToken),'Native MCP token stored in plaintext hash option');
$authMethod=new ReflectionMethod('OAI_MCP','auth');$authMethod->setAccessible(true);
$serverAuthBefore=$_SERVER['HTTP_AUTHORIZATION']??null;
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$mcpToken;
wp_set_current_user(0);
$mcpAuth=$authMethod->invoke(null);
native_auth_expect(!is_wp_error($mcpAuth),'Admin-bound MCP token was rejected');

$admin->set_role('subscriber');wp_set_current_user(0);
$mcpDenied=$authMethod->invoke(null);
native_auth_expect(is_wp_error($mcpDenied)&&$mcpDenied->get_error_code()==='oai_mcp_auth','Demoted admin retained MCP token access');
$admin->set_role('administrator');
if($serverAuthBefore===null)unset($_SERVER['HTTP_AUTHORIZATION']);else $_SERVER['HTTP_AUTHORIZATION']=$serverAuthBefore;

// Native OAuth one-time credentials must not be consumed while the approving user is demoted.
global $wpdb;
$oauthClient='release-auth-client';
$redirect='https://client.example.test/callback';
$resource=rest_url('okean-ai/mcp');
$verifier='release-auth-verifier-'.wp_generate_password(48,false,false);
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$code='release-auth-code-'.wp_generate_password(40,false,false);
$wpdb->insert(OAI_MCP_OAuth::codes_table(),array(
    'code_hash'=>hash('sha256',$code),
    'client_id'=>$oauthClient,
    'user_id'=>$adminId,
    'redirect_uri'=>$redirect,
    'scope'=>'mcp:read mcp:write offline_access',
    'resource'=>$resource,
    'code_challenge'=>$challenge,
    'created_at'=>gmdate('Y-m-d H:i:s'),
    'expires_at'=>gmdate('Y-m-d H:i:s',time()+600),
    'used'=>0
));
native_auth_expect($wpdb->last_error==='','Unable to seed OAuth code demotion fixture');

$codeReq=new WP_REST_Request('POST','/okean-ai/v1/oauth/token');
$codeReq->set_param('grant_type','authorization_code');
$codeReq->set_param('code',$code);$codeReq->set_param('client_id',$oauthClient);$codeReq->set_param('redirect_uri',$redirect);
$codeReq->set_param('code_verifier',$verifier);$codeReq->set_param('resource',$resource);

$admin->set_role('subscriber');wp_set_current_user(0);
$codeDenied=OAI_MCP_OAuth::token($codeReq);
native_auth_expect($codeDenied instanceof WP_REST_Response&&(($codeDenied->get_data()['error']??'')==='invalid_grant'),'Demoted administrator exchanged OAuth authorization code');
$codeUsed=(int)$wpdb->get_var($wpdb->prepare('SELECT used FROM '.OAI_MCP_OAuth::codes_table().' WHERE code_hash=%s',hash('sha256',$code)));
native_auth_expect($codeUsed===0,'Denied OAuth code exchange consumed one-time code');

$admin->set_role('administrator');wp_set_current_user($adminId);
$codeAllowed=OAI_MCP_OAuth::token($codeReq);
native_auth_expect($codeAllowed instanceof WP_REST_Response&&$codeAllowed->get_status()===200&&!empty($codeAllowed->get_data()['access_token']),'Restored administrator could not exchange preserved OAuth code');

// Seed an independent refresh row to verify demotion is checked before rotation.
$refresh='release-auth-refresh-'.wp_generate_password(48,false,false);
$access='release-auth-access-'.wp_generate_password(48,false,false);
$wpdb->insert(OAI_MCP_OAuth::tokens_table(),array(
    'access_hash'=>hash('sha256',$access),
    'refresh_hash'=>hash('sha256',$refresh),
    'client_id'=>$oauthClient,
    'user_id'=>$adminId,
    'scope'=>'mcp:read mcp:write offline_access',
    'resource'=>$resource,
    'created_at'=>gmdate('Y-m-d H:i:s'),
    'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
    'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
    'revoked'=>0
));
native_auth_expect($wpdb->last_error==='','Unable to seed OAuth refresh demotion fixture');

$refreshReq=new WP_REST_Request('POST','/okean-ai/v1/oauth/token');
$refreshReq->set_param('grant_type','refresh_token');$refreshReq->set_param('refresh_token',$refresh);
$refreshReq->set_param('client_id',$oauthClient);$refreshReq->set_param('resource',$resource);

$admin->set_role('subscriber');wp_set_current_user(0);
$refreshDenied=OAI_MCP_OAuth::token($refreshReq);
native_auth_expect($refreshDenied instanceof WP_REST_Response&&(($refreshDenied->get_data()['error']??'')==='invalid_grant'),'Demoted administrator rotated OAuth refresh token');
$refreshRevoked=(int)$wpdb->get_var($wpdb->prepare('SELECT revoked FROM '.OAI_MCP_OAuth::tokens_table().' WHERE refresh_hash=%s',hash('sha256',$refresh)));
native_auth_expect($refreshRevoked===0,'Denied OAuth refresh consumed/rotated token');

$admin->set_role('administrator');wp_set_current_user($adminId);
$refreshAllowed=OAI_MCP_OAuth::token($refreshReq);
native_auth_expect($refreshAllowed instanceof WP_REST_Response&&$refreshAllowed->get_status()===200&&!empty($refreshAllowed->get_data()['refresh_token']),'Restored administrator could not rotate preserved refresh token');

$wpdb->delete(OAI_MCP_OAuth::codes_table(),array('client_id'=>$oauthClient));
$wpdb->query($wpdb->prepare('DELETE FROM '.OAI_MCP_OAuth::tokens_table().' WHERE client_id=%s',$oauthClient));

WP_Application_Passwords::delete_all_application_passwords($adminId);
WP_Application_Passwords::delete_all_application_passwords($subscriberId);
wp_delete_user($subscriberId);
wp_set_current_user($adminId);

echo "PASS native Application Password / bearer / MCP demotion boundaries\n";
