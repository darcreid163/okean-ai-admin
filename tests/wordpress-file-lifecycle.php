<?php
/**
 * File sandbox lifecycle regression for staged atomic writes and large internal verification snapshots.
 * Disposable loopback WordPress only; writes one temporary file inside the child-theme scope.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) {
    throw new RuntimeException('Disposable loopback WordPress required');
}
if (!class_exists('OAI_Files') || !class_exists('OAI_REST') ||
    !class_exists('OAI_AI_Lifecycle_Control') || !class_exists('OAI_License')) {
    throw new RuntimeException('File lifecycle components are unavailable');
}
function wflAssert($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}
function wflOk($value, $message) {
    if (is_wp_error($value)) throw new RuntimeException($message . ': ' . $value->get_error_code());
    return $value;
}
$actor = wp_create_user('file-lifecycle-ci', 'ci-local-only-password', 'file-lifecycle-ci@example.invalid');
wflAssert(!is_wp_error($actor), 'Could not provision disposable file lifecycle actor');
$actor_id = (int)$actor;
(new WP_User($actor_id))->set_role('administrator');
wp_set_current_user($actor_id);
wflAssert(current_user_can('manage_options'), 'File lifecycle actor lacks manage_options');
wflOk(OAI_License::activate_local_owner($actor_id), 'Activate disposable owner license');
wflOk(OAI_AI_Lifecycle_Control::enable($actor_id), 'Enable AI lifecycle control');

$scope = 'child_theme';
$scopes = OAI_Security::scopes();
wflAssert(!empty($scopes[$scope]) && is_dir($scopes[$scope]), 'Child theme scope is unavailable');
$name = 'wpai-file-lifecycle-' . bin2hex(random_bytes(8)) . '.txt';
$full = realpath($scopes[$scope]) . DIRECTORY_SEPARATOR . $name;
$before = "original file bytes\n";
$after = str_repeat('A', 3 * 1024 * 1024);
$operation_id = 0;
try {
    wflAssert(file_put_contents($full, $before) === strlen($before), 'Could not create original file fixture');
    $request = new WP_REST_Request('POST', '/okean-ai/v1/files/write');
    $request->set_param('scope', $scope);
    $request->set_param('path', $name);
    $request->set_param('content', $after);
    $planned = wflOk(OAI_REST::files_write($request), 'Create file sandbox operation');
    wflAssert(($planned['type'] ?? '') === 'file_write' && ($planned['status'] ?? '') === 'pending',
        'File sandbox operation is not pending');
    $operation_id = (int)($planned['id'] ?? 0);
    wflAssert($operation_id > 0, 'File sandbox operation id is missing');
    wflAssert(file_get_contents($full) === $before, 'Sandbox planning modified the live file before approval');

    wflOk(OAI_AI_Lifecycle_Control::perform($operation_id, 'approve', 'file-lifecycle-ci', $actor_id),
        'Approve file operation');
    wflOk(OAI_AI_Lifecycle_Control::perform($operation_id, 'apply', 'file-lifecycle-ci', $actor_id),
        'Apply 3 MB file operation');
    wflAssert(is_file($full) && filesize($full) === strlen($after), 'Apply did not write the complete 3 MB file');
    wflAssert(hash_equals(hash('sha256', $after), hash_file('sha256', $full)),
        'Apply file bytes differ from the approved 3 MB payload');
    $verify_apply = OAI_Verify::operation($operation_id);
    wflAssert(!empty($verify_apply['verified']), '3 MB file Apply did not verify: ' . wp_json_encode($verify_apply));

    wflOk(OAI_AI_Lifecycle_Control::perform($operation_id, 'rollback', 'file-lifecycle-ci', $actor_id),
        'Rollback 3 MB file operation');
    wflAssert(is_file($full) && file_get_contents($full) === $before,
        'Rollback did not restore the exact original file bytes');
    $verify_rollback = OAI_Verify::operation($operation_id, null, 'rollback');
    wflAssert(!empty($verify_rollback['verified']), 'File rollback did not verify: ' . wp_json_encode($verify_rollback));

    echo "PASS file lifecycle: 3 MB sandbox → approve → atomic apply → verify → rollback → verify\n";
} finally {
    OAI_AI_Lifecycle_Control::disable($actor_id);
    if (is_file($full)) @unlink($full);
    wp_delete_user($actor_id);
}
