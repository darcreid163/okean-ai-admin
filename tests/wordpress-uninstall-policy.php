<?php
// WP-CLI eval-file integration test for guarded, opt-in uninstall behavior.
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable WP-CLI required');
}
global $wpdb;
function uninstallPolicyAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function uninstallPolicyTableExists(string $table): bool {
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
}
function uninstallPolicyOptionValue(string $name) {
    global $wpdb;
    $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
    return $raw===null?null:maybe_unserialize($raw);
}
function uninstallPolicyOptionExists(string $name): bool {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",$name))===1;
}
function uninstallPolicyRun(bool $with_guard): void {
    $bootstrap=ABSPATH.'wp-load.php';
    $uninstall=dirname(__DIR__).'/uninstall.php';
    $code='require '.var_export($bootstrap,true).';';
    if($with_guard)$code.='define("WP_UNINSTALL_PLUGIN","okean-ai-admin/okean-ai-admin.php");';
    $code.='include '.var_export($uninstall,true).'; echo "uninstall-returned";';
    $command=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code);
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Unable to start uninstall worker');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($process);
    if($status!==0)throw new RuntimeException('Uninstall worker failed: '.$stdout.' '.$stderr);
    if($with_guard&&trim($stdout)!=='uninstall-returned')throw new RuntimeException('Guarded uninstall did not return normally');
    if(!$with_guard&&trim($stdout)!=='')throw new RuntimeException('Direct access did not stop at the WP_UNINSTALL_PLUGIN guard');
}

OAI_DB::install();
OAI_MCP_OAuth::install();
$table_suffixes=['oai_operations','oai_audit_log','oai_quota_usage','oai_quota_events','oai_oauth_clients','oai_oauth_codes','oai_oauth_tokens'];
$tables=[];
foreach($table_suffixes as $suffix){$tables[]=$wpdb->prefix.$suffix;uninstallPolicyAssert(uninstallPolicyTableExists($wpdb->prefix.$suffix),'Missing fixture table '.$suffix);}
$rateTable=$wpdb->prefix.'oai_rate_limits';
$wpdb->query('CREATE TABLE IF NOT EXISTS '.$rateTable.' (bucket_hash char(64) NOT NULL, window_start bigint(20) unsigned NOT NULL, hits bigint(20) unsigned NOT NULL, expires_at bigint(20) unsigned NOT NULL, PRIMARY KEY (bucket_hash,window_start)) ENGINE=InnoDB');
$tables[]=$rateTable;
$sentinelTable=$wpdb->prefix.'oai_third_party_sentinel';
$wpdb->query('CREATE TABLE IF NOT EXISTS '.$sentinelTable.' (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');

$static_options=[
 'oai_mcp_token_hash'=>'fixture-token-hash',
 'oai_license'=>['key'=>'fixture-license'],
 'oai_site_id'=>'fixture-site-id',
 'oai_test_automation'=>['enabled'=>true],
];
foreach($static_options as $key=>$value)update_option($key,$value,false);
update_option('oai_third_party_sentinel','must-survive',false);
delete_option('oai_delete_data_on_uninstall');

$dynamic_options=[
 'oai_pair_code_'.str_repeat('a',64)=>['state'=>'fixture'],
 'oai_pair_credential_'.str_repeat('b',64)=>['token'=>'fixture'],
 'oai_operation_lease_999999'=>['token_hash'=>'fixture'],
 'oai_media_creation_'.str_repeat('c',64)=>['claim'=>'fixture'],
 'oai_cloud_usage_'.str_repeat('d',32)=>['used'=>1],
];
foreach($dynamic_options as $key=>$value)update_option($key,$value,false);
$transients=['oai_rl_fixture','oai_cimd_fixture','oai_oauth_login_fixture','oai_agent_nonce_fixture','oai_vqa_fixture','oai_vqar_fixture'];
foreach($transients as $key)set_transient($key,'fixture',600);
$pair_code='oai_pair_code_'.str_repeat('a',64);
wp_schedule_single_event(time()+3600,'oai_gateway_pair_cleanup',array($pair_code));
$post_id=wp_insert_post(['post_title'=>'WP AI Admin uninstall sentinel','post_type'=>'page','post_status'=>'draft'],true);
uninstallPolicyAssert(!is_wp_error($post_id)&&$post_id>0,'Unable to create page sentinel');

$assert_preserved=static function($label)use($tables,$static_options,$dynamic_options,$sentinelTable,$post_id){
    foreach($tables as $table)uninstallPolicyAssert(uninstallPolicyTableExists($table),$label.': plugin table was removed '.$table);
    foreach($static_options as $key=>$unused)uninstallPolicyAssert(uninstallPolicyOptionExists($key),$label.': static option was removed '.$key);
    foreach($dynamic_options as $key=>$unused)uninstallPolicyAssert(get_option($key,null)!==null,$label.': dynamic option was removed '.$key);
    uninstallPolicyAssert(uninstallPolicyOptionValue('oai_third_party_sentinel')==='must-survive',$label.': unrelated oai-prefixed option was removed');
    uninstallPolicyAssert(uninstallPolicyTableExists($sentinelTable),$label.': unrelated table was removed');
    uninstallPolicyAssert(get_post($post_id)!==null,$label.': WordPress page was removed');
};

uninstallPolicyRun(false);
$assert_preserved('direct-access guard');
uninstallPolicyRun(true);
$assert_preserved('missing opt-in');
update_option('oai_delete_data_on_uninstall','true',false);
uninstallPolicyRun(true);
$assert_preserved('malformed opt-in');

update_option('oai_delete_data_on_uninstall','1',false);
uninstallPolicyRun(true);
foreach($tables as $table)uninstallPolicyAssert(!uninstallPolicyTableExists($table),'Opt-in did not remove plugin table '.$table);
foreach(array_keys($static_options) as $key)uninstallPolicyAssert(!uninstallPolicyOptionExists($key),'Opt-in did not remove option '.$key);
foreach(array_keys($dynamic_options) as $key)uninstallPolicyAssert(get_option($key,null)===null,'Opt-in did not remove dynamic option '.$key);
uninstallPolicyAssert(!uninstallPolicyOptionExists('oai_delete_data_on_uninstall'),'Opt-in preference was not removed');
uninstallPolicyAssert(uninstallPolicyOptionValue('oai_third_party_sentinel')==='must-survive','Opt-in removed unrelated oai-prefixed option');
uninstallPolicyAssert(uninstallPolicyTableExists($sentinelTable),'Opt-in removed unrelated table');
uninstallPolicyAssert(get_post($post_id)!==null,'Opt-in removed WordPress page');
foreach($transients as $key){
    uninstallPolicyAssert(!uninstallPolicyOptionExists('_transient_'.$key),'Opt-in left transient '.$key);
    uninstallPolicyAssert(!uninstallPolicyOptionExists('_transient_timeout_'.$key),'Opt-in left transient timeout '.$key);
}
$cron=uninstallPolicyOptionValue('cron');$cron_found=false;
foreach((array)$cron as $timestamp=>$hooks)if(isset($hooks['oai_gateway_pair_cleanup']))$cron_found=true;
uninstallPolicyAssert(!$cron_found,'Opt-in left pairing cleanup cron event');

echo "PASS: uninstall guard, missing/malformed opt-in preservation, explicit cleanup allowlist, unrelated content/table/option preservation, transient and cron cleanup verified".PHP_EOL;
