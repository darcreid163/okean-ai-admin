<?php
// Run only on a disposable WordPress installation using WP-CLI eval-file.
function oai_connect_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
global $wpdb;
wp_set_current_user(1);
OAI_Platform::site_id();OAI_Platform::installation_id();
$keys=array('oai_mcp_enabled','oai_site_id','oai_installation_id','oai_mcp_token_hash','oai_mcp_token_user_id','oai_bearer_token','oai_bearer_user_id');
$before=array();foreach($keys as $key)$before[$key]=get_option($key,null);
$seed_hash=hash('sha256','disposable-onboarding-ci-access');
oai_connect_expect($wpdb->insert(OAI_MCP_OAuth::tokens_table(),array('access_hash'=>$seed_hash,'refresh_hash'=>hash('sha256','disposable-onboarding-ci-refresh'),'client_id'=>'onboarding-ci','user_id'=>1,'scope'=>'mcp:read mcp:write','resource'=>rest_url('okean-ai/mcp'),'created_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+1200),'revoked'=>0))!==false,'seed retained OAuth token');
$oauth_before=$wpdb->get_results('SELECT * FROM '.OAI_MCP_OAuth::tokens_table().' ORDER BY id',ARRAY_A);
$post_before=$_POST;$method_before=$_SERVER['REQUEST_METHOD']??null;
try{
 $_SERVER['REQUEST_METHOD']='POST';
 $_POST=array('oai_mcp_state'=>'enabled','_wpnonce'=>wp_create_nonce('oai_mcp_toggle'));
 wp_set_current_user(0);
 $result=OAI_Admin::handle_mcp_toggle();
 oai_connect_expect(is_wp_error($result)&&$result->get_error_code()==='oai_forbidden','anonymous enable must be rejected');
 oai_connect_expect(get_option('oai_mcp_enabled',null)===$before['oai_mcp_enabled'],'anonymous request must preserve state');
 wp_set_current_user(1);
 $_POST['_wpnonce']='invalid-nonce';
 $result=OAI_Admin::handle_mcp_toggle();
 oai_connect_expect(is_wp_error($result)&&$result->get_error_code()==='oai_mcp_nonce','CSRF must be rejected');
 $_POST['_wpnonce']=wp_create_nonce('oai_mcp_toggle');$_SERVER['REQUEST_METHOD']='GET';
 oai_connect_expect(is_wp_error(OAI_Admin::handle_mcp_toggle()),'GET cannot toggle state');
 $_SERVER['REQUEST_METHOD']='POST';$_POST['oai_mcp_state']=array('enabled');
 oai_connect_expect(is_wp_error(OAI_Admin::handle_mcp_toggle()),'malformed state rejected');
 $_POST['oai_mcp_state']='disabled';
 oai_connect_expect(OAI_Admin::handle_mcp_toggle()===array('enabled'=>false),'administrator disable works');
 oai_connect_expect(!get_option('oai_mcp_enabled')&&!OAI_AI_Lifecycle_Control::status()['active']&&!OAI_Test_Automation::status()['active'],'disable stops MCP and automation');
 $_POST['oai_mcp_state']='enabled';
 oai_connect_expect(OAI_Admin::handle_mcp_toggle()===array('enabled'=>true),'administrator enable works');
 oai_connect_expect(get_option('oai_mcp_enabled')&&!OAI_AI_Lifecycle_Control::status()['active']&&!OAI_Test_Automation::status()['active'],'enable does not grant automatic lifecycle');
 foreach($keys as $key)if($key!=='oai_mcp_enabled')oai_connect_expect(get_option($key,null)===$before[$key],'identity/credential changed: '.$key);
 oai_connect_expect($wpdb->get_results('SELECT * FROM '.OAI_MCP_OAuth::tokens_table().' ORDER BY id',ARRAY_A)===$oauth_before,'OAuth tokens must not be rotated or revoked');
 $_POST=array();oai_connect_expect(OAI_Admin::handle_mcp_toggle()===null,'normal page request has no effect');
 echo "PASS: MCP administrator consent, CSRF, malformed input, automation separation and identity/OAuth retention\n";
}finally{
 $wpdb->delete(OAI_MCP_OAuth::tokens_table(),array('access_hash'=>$seed_hash));
 foreach($before as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
 $_POST=$post_before;if($method_before===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$method_before;
 wp_set_current_user(1);
}
