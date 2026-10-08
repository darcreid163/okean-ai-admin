<?php
// Native authentication boundary acceptance on disposable WordPress.
function native_auth_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
add_filter('wp_is_application_passwords_available','__return_true');

$adminId=get_current_user_id();
$admin=get_user_by('id',$adminId);
native_auth_expect($admin instanceof WP_User && user_can($admin,'manage_options'),'Admin fixture missing');

$subscriberId=wp_create_user('release-auth-subscriber-'.wp_generate_password(6,false,false),'disposable-password','release-auth-subscriber@example.test');
native_auth_expect(!is_wp_error($subscriberId),'Unable to create subscriber auth fixture');
$subscriber=get_user_by('id',$subscriberId);
$subscriber->set_role('subscriber');

$adminApp=WP_Application_Passwords::create_new_application_password($adminId,array('name'=>'WP AI Admin release auth test'));
$subApp=WP_Application_Passwords::create_new_application_password($subscriberId,array('name'=>'WP AI Admin release auth test'));
native_auth_expect(is_array($adminApp)&&isset($adminApp[1])&&is_string($adminApp[1]),'Unable to create admin Application Password');
native_auth_expect(is_array($subApp)&&isset($subApp[1])&&is_string($subApp[1]),'Unable to create subscriber Application Password');

$makeBasic=function($user,$pass){
    $r=new WP_REST_Request('GET','/okean-ai/v1/status');
    $r->set_header('authorization','Basic '.base64_encode($user.':'.$pass));
    return $r;
};

wp_set_current_user(0);
$subReq=$makeBasic($subscriber->user_login,$subApp[1]);
native_auth_expect(OAI_REST::perm($subReq)===false,'Subscriber Application Password reached native admin API');

$adminReq=$makeBasic($admin->user_login,$adminApp[1]);
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

WP_Application_Passwords::delete_all_application_passwords($adminId);
WP_Application_Passwords::delete_all_application_passwords($subscriberId);
wp_delete_user($subscriberId);
wp_set_current_user($adminId);

echo "PASS native Application Password / bearer / MCP demotion boundaries\n";
