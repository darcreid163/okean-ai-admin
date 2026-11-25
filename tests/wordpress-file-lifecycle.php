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

// PHP files must be rejected during sandbox planning, before any operation or
// filesystem mutation is created. The guard is extension-based, not content-based.
$blocked_name = 'wpai-file-write-blocked-' . bin2hex(random_bytes(8)) . '.php';
$blocked_path = realpath($scopes[$scope]) . DIRECTORY_SEPARATOR . $blocked_name;
$blocked_request = new WP_REST_Request('POST', '/okean-ai/v1/files/write');
$blocked_request->set_param('scope', $scope);
$blocked_request->set_param('path', $blocked_name);
$blocked_request->set_param('content', "<?php \$fn='system'; \$fn('id');");
$blocked_result = OAI_REST::files_write($blocked_request);
wflAssert(is_wp_error($blocked_result) && $blocked_result->get_error_code() === 'oai_executable_file_denied',
    'PHP file target was not rejected during sandbox planning');
wflAssert(!file_exists($blocked_path), 'Rejected PHP file target created a filesystem artifact');
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

    // Simulate process interruption at three deterministic points. These fixtures
    // set the ledger to "applying" only after approval and deliberately leave
    // no execution lease, exactly as a crashed PHP request would.
    $make_recovery_operation = function ($payload) use ($scope, $name, $actor_id) {
        $request = new WP_REST_Request('POST', '/okean-ai/v1/files/write');
        $request->set_param('scope', $scope);
        $request->set_param('path', $name);
        $request->set_param('content', $payload);
        $row = wflOk(OAI_REST::files_write($request), 'Create interrupted Apply fixture');
        $id = (int)($row['id'] ?? 0);
        wflAssert($id > 0 && ($row['status'] ?? '') === 'pending', 'Interrupted Apply fixture was not pending');
        wflOk(OAI_AI_Lifecycle_Control::perform($id, 'approve', 'file-lifecycle-ci', $actor_id),
            'Approve interrupted Apply fixture');
        wflAssert(OAI_DB::update($id, array('status' => 'applying', 'phase' => 'applying')) !== false,
            'Could not simulate interrupted applying ledger state');
        return $id;
    };

    // Crash after atomic rename but before ledger finalization: recovery must
    // verify and finalize, without issuing a second write.
    $recovery_after = "recovered after atomic rename\\n";
    $recovery_id = $make_recovery_operation($recovery_after);
    wflAssert(file_put_contents($full, $recovery_after) === strlen($recovery_after),
        'Could not simulate target already committed before process interruption');
    $recovered = wflOk(OAI_AI_Lifecycle_Control::perform($recovery_id, 'apply', 'file-lifecycle-ci', $actor_id),
        'Recover committed interrupted Apply');
    wflAssert(($recovered['status'] ?? '') === 'applied', 'Committed interrupted Apply was not finalized as applied');
    wflAssert(file_get_contents($full) === $recovery_after, 'Committed Apply recovery changed the target bytes');

    // Crash before the filesystem mutation: recovery must return to approved
    // and require a fresh explicit Apply, never replay the write automatically.
    $recovery_before = "must not be replayed automatically\\n";
    $recovery_id = $make_recovery_operation($recovery_before);
    $not_replayed = OAI_AI_Lifecycle_Control::perform($recovery_id, 'apply', 'file-lifecycle-ci', $actor_id);
    wflAssert(is_wp_error($not_replayed) && $not_replayed->get_error_code() === 'oai_apply_recovery_ready',
        'Uncommitted interrupted Apply must return to approved without replay');
    wflAssert((OAI_DB::get($recovery_id)['status'] ?? '') === 'approved', 'Uncommitted Apply recovery did not restore approved status');
    wflAssert(file_get_contents($full) === $recovery_after, 'Recovery replayed an uncommitted write unexpectedly');

    // An unrelated external write during interruption is ambiguous: recovery
    // must preserve it and leave the operation applying for manual inspection.
    $recovery_uncertain = "uncertain external writer\\n";
    $recovery_id = $make_recovery_operation("planned but not committed\\n");
    wflAssert(file_put_contents($full, $recovery_uncertain) === strlen($recovery_uncertain),
        'Could not simulate an unrelated external write');
    $uncertain = OAI_AI_Lifecycle_Control::perform($recovery_id, 'apply', 'file-lifecycle-ci', $actor_id);
    wflAssert(is_wp_error($uncertain) && $uncertain->get_error_code() === 'oai_apply_outcome_uncertain',
        'Ambiguous interrupted Apply must fail closed');
    wflAssert(file_get_contents($full) === $recovery_uncertain, 'Ambiguous recovery overwrote an external change');
    wflAssert((OAI_DB::get($recovery_id)['status'] ?? '') === 'applying', 'Ambiguous recovery changed the ledger state');

    echo "PASS file lifecycle: atomic apply/rollback plus committed, uncommitted and ambiguous interruption recovery\\n";
} finally {
    OAI_AI_Lifecycle_Control::disable($actor_id);
    if (is_file($full)) @unlink($full);
    wp_delete_user($actor_id);
}
