<?php
/** Protected options, historical operations and safe lifecycle; disposable WP only. */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Option policy test requires disposable loopback WordPress');
}
function optionPolicyAssert($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function optionPolicyOk($result, $message) {
    optionPolicyAssert(!is_wp_error($result), $message.': '.(is_wp_error($result) ? $result->get_error_code() : ''));
    return $result;
}
function optionPolicyRequest($type, $target, $value) {
    $req = new WP_REST_Request('POST', '/okean-ai/v1/sandbox');
    foreach (['type'=>$type,'target'=>$target,'value'=>$value] as $key=>$input) $req->set_param($key,$input);
    return $req;
}
function optionPolicyAction($id, $action, $ai) {
    if ($ai) return OAI_AI_Lifecycle_Control::perform($id,$action,'disposable-option-policy',1);
    $req = new WP_REST_Request('POST', '/okean-ai/v1/sandbox/'.$id.'/'.$action);
    $req->set_url_params(['id'=>$id]);
    $req->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    return OAI_REST::sandbox_action($req);
}
function optionPolicyStored($target) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name=%s",$target),ARRAY_A);
}
function optionPolicyHistorical($target, $status) {
    $before = get_option($target, null);
    $meta = ['before_exists'=>$before !== null];
    $descriptor = OAI_Lifecycle::descriptor('option_update',$target,$before,$meta);
    return optionPolicyOk(OAI_DB::insert([
        'operation_id'=>OAI_Operations::new_id('TEST'), 'status'=>$status, 'type'=>'option_update', 'target'=>$target,
        'before_data'=>wp_json_encode($before), 'after_data'=>wp_json_encode('synthetic-forbidden-value'),
        'meta_data'=>wp_json_encode($meta), 'diff_data'=>wp_json_encode(['changed'=>true]),
        'rollback_data'=>wp_json_encode($descriptor), 'risk'=>'low', 'phase'=>$status,
        'created_at'=>current_time('mysql',true), 'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),
    ]),'Create synthetic historical operation');
}
global $wpdb;
wp_set_current_user(1);
$owned = [];
$safeKey = 'wpai_disposable_content_setting';
optionPolicyAssert(get_option($safeKey,null) === null,'Disposable option key already exists');
$protected = ['siteurl','HOME','active_plugins',$wpdb->prefix.'user_roles','UPLOAD_PATH','upload_url_path',
    'OAI_ai_lifecycle_control','WpAi_Cloud_Settings','WPAI_GATEWAY_SETTINGS','synthetic_client_secret','_transient_synthetic_cache'];
try {
    // The options table compares aliases without case sensitivity on standard WP.
    optionPolicyAssert(optionPolicyStored('SITEURL') === optionPolicyStored('siteurl'), 'Fixture must use case-insensitive WP options collation');
    foreach (array_merge($protected,['SITEURL','siteurl ',"sítéurl",str_repeat('a',192)]) as $target) {
        $count = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table());
        $before = optionPolicyStored($target);
        $result = OAI_REST::sandbox_create(optionPolicyRequest('option_update',$target,'synthetic-forbidden-value'));
        if (is_array($result) && !empty($result['id'])) $owned[] = (int)$result['id'];
        optionPolicyAssert(is_wp_error($result) && in_array($result->get_error_data()['status'] ?? 0,[400,403],true), 'Protected or ambiguous option entered sandbox: '.$target);
        optionPolicyAssert($before === optionPolicyStored($target) && $count === (int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table()), 'Denied creation changed option or operation count');
    }
    echo "PASS: protected core/control-plane/credential/cache keys, case aliases and ambiguous keys denied before sandbox creation\n";
    optionPolicyOk(OAI_License::activate_local_owner(1),'Activate disposable local owner');
    optionPolicyOk(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped disposable lifecycle');
    foreach ($protected as $target) foreach ([true,false] as $ai) foreach (['approve'=>'pending','apply'=>'approved'] as $action=>$status) {
        $id = optionPolicyHistorical($target,$status); $owned[] = $id;
        $row = OAI_DB::get($id); $before = optionPolicyStored($target);
        $result = optionPolicyAction($id,$action,$ai);
        optionPolicyAssert(is_wp_error($result) && ($result->get_error_data()['status'] ?? 0) === 403,'Historical protected '.$target.' allowed '.$action);
        optionPolicyAssert($row === OAI_DB::get($id) && $before === optionPolicyStored($target),'Denied lifecycle changed operation or option');
        optionPolicyOk(optionPolicyAction($id,'reject',$ai),'Reject historical protected operation');
        optionPolicyAssert(OAI_DB::get($id)['status'] === 'rejected','Historical protected operation could not be rejected');
    }
    echo "PASS: AI and WP-admin approval/apply deny historical low-risk protected rows; rejection remains available\n";
    update_option($safeKey,'original',false);
    $op = optionPolicyOk(OAI_REST::sandbox_create(optionPolicyRequest('option_update',$safeKey,'updated')),'Plan safe option');
    $owned[] = (int)$op['id'];
    optionPolicyAssert($op['status'] === 'pending' && get_option($safeKey) === 'original','Planning safe option mutated live value');
    foreach (['approve','apply'] as $action) optionPolicyOk(optionPolicyAction((int)$op['id'],$action,true),'Safe '.$action);
    optionPolicyAssert(get_option($safeKey) === 'updated' && !empty(optionPolicyOk(OAI_Verify::operation((int)$op['id']),'Verify safe apply')['verified']),'Safe apply failed');
    optionPolicyOk(optionPolicyAction((int)$op['id'],'rollback',true),'Safe rollback');
    optionPolicyAssert(get_option($safeKey) === 'original' && !empty(optionPolicyOk(OAI_Verify::operation((int)$op['id']),'Verify safe rollback')['verified']),'Safe rollback failed');
    // A historical operation whose target becomes protected must still be reversible.
    // Use a temporary prefix, avoiding changes to core/security configuration.
    $historicalTarget = 'oai_disposable_rollback_fixture';
    optionPolicyAssert(get_option($historicalTarget,null) === null,'Historical rollback fixture exists');
    update_option($historicalTarget,'updated',false);
    // Synthetic pre-upgrade applied row, separate from the real lifecycle above.
    $historicalId = optionPolicyHistorical($historicalTarget,'applied'); $owned[] = $historicalId;
    $descriptor = OAI_Lifecycle::descriptor('option_update',$historicalTarget,'original',['before_exists'=>true]);
    OAI_DB::update($historicalId,[
        'before_data'=>wp_json_encode('original'), 'after_data'=>wp_json_encode('updated'),
        'applied_data'=>wp_json_encode('updated'), 'applied_at'=>current_time('mysql',true),
        'meta_data'=>wp_json_encode(['before_exists'=>true,'rollback_descriptor'=>$descriptor]),
        'rollback_data'=>wp_json_encode($descriptor),
    ]);
    try {
        optionPolicyOk(optionPolicyAction($historicalId,'rollback',true),'Rollback protected historical target');
        optionPolicyAssert(get_option($historicalTarget) === 'original','Protected historical target rollback failed');
        optionPolicyAssert(!empty(optionPolicyOk(OAI_Verify::operation($historicalId),'Verify historical rollback')['verified']),'Historical rollback not verified');
    } finally { delete_option($historicalTarget); }
    echo "PASS: safe option pending → approve → apply → verify → rollback → verify; historical protected target rollback → verify remains available\n";
} finally {
    OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    delete_option($safeKey);
    foreach ($owned as $id) {
        $wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$id]);
        $wpdb->delete(OAI_DB::table(),['id'=>$id]);
    }
}
