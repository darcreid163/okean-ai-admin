<?php
/**
 * Real WordPress post/page lifecycle acceptance (#138).
 *
 * Disposable CI ONLY. Drives OAI_REST::post_create_draft / post_update_draft
 * through the sandbox approve -> apply -> verify -> rollback lifecycle with
 * byte-identical post_content, plus reject cleanup, creation-intent idempotency
 * and signed receipt/proof evidence. Only disposable localhost fixtures are
 * touched; all created posts are deleted in a finally block.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false &&
     strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Refusing post lifecycle test outside disposable CI');
}
final class PostLifecycleChecks {public static int $count = 0;}
function plcAssert($ok, $msg) { ++PostLifecycleChecks::$count; if (!$ok) throw new RuntimeException($msg); }
function plcOk($value, $msg) {
    if (is_wp_error($value)) throw new RuntimeException($msg.': '.$value->get_error_code());
    plcAssert(true, $msg);
    return $value;
}
function plcErr($value, $code, $msg) {
    plcAssert(is_wp_error($value) && $value->get_error_code() === $code,
        $msg.' (expected '.$code.', got '.(is_wp_error($value) ? $value->get_error_code() : gettype($value)).')');
}
function plcPostCount($type = 'post') {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type));
}
function plcCreatePost($title, $excerpt, $content, $key, $post_type = 'post', $final_status = 'draft') {
    $req = new WP_REST_Request('POST', '/okean-ai/v1/posts/create');
    $req->set_param('title_b64', base64_encode($title));
    $req->set_param('excerpt_b64', base64_encode($excerpt));
    $req->set_param('content_b64', base64_encode($content));
    $req->set_param('post_type', $post_type);
    $req->set_param('final_status', $final_status);
    $req->set_param('idempotency_key', $key);
    return OAI_REST::post_create_draft($req);
}
function plcUpdatePost($id, $title, $excerpt, $content) {
    $req = new WP_REST_Request('POST', '/okean-ai/v1/posts/update');
    $req->set_param('post_id', $id);
    $req->set_param('title_b64', base64_encode($title));
    $req->set_param('excerpt_b64', base64_encode($excerpt));
    $req->set_param('content_b64', base64_encode($content));
    return OAI_REST::post_update_draft($req);
}
function plcAction($id, $action, $client = 'post-lifecycle-ci') {
    $actor = (int)get_current_user_id();
    return OAI_AI_Lifecycle_Control::perform($id, $action, $client, $actor ?: 1);
}
function plcContent($text) { return '<p>'.esc_html($text).'</p>'; }

$actor = wp_create_user('post-lifecycle-ci', 'ci-local-only-password', 'post-lifecycle-ci@example.invalid');
plcAssert(!is_wp_error($actor), 'Cannot provision test actor');
plcOk($actor, 'Test actor provisioned');
$actor_id = (int)$actor;
(new WP_User($actor_id))->set_role('administrator');
wp_set_current_user($actor_id);
plcAssert(current_user_can('edit_posts'), 'Actor cannot edit posts');
// The full approve/apply/rollback lifecycle requires the AI lifecycle control.
$licensed = OAI_License::activate_local_owner($actor_id);
plcOk($licensed, 'Activate disposable owner license');
$enabled = OAI_AI_Lifecycle_Control::enable($actor_id);
plcOk($enabled, 'Enable AI lifecycle control');

$start_posts = plcPostCount('post');
$start_ops = (int)OAI_DB::count_all();
$cleaned = array();
try {
    /* ---------------------------------------------------------------- *
     * post_create: creation-intent idempotency, apply, rollback, reject *
     * ---------------------------------------------------------------- */
    $title1 = 'WP AI Post Lifecycle First';
    $excerpt1 = 'first excerpt';
    $content1 = plcContent('first post body one');
    $created = plcOk(plcCreatePost($title1, $excerpt1, $content1, 'post-create-first-123456'), 'Create first draft post');
    plcAssert(($created['type'] ?? '') === 'post_create' && ($created['status'] ?? '') === 'pending',
        'First post is not a pending post_create operation');
    $op_id = (int)$created['id'];
    $post_id = (int)$created['post_id'];
    plcAssert($post_id > 0 && $op_id > 0, 'First post/operation ids missing');
    $cleaned[] = $post_id;
    plcAssert(plcPostCount('post') === $start_posts + 1 && (int)OAI_DB::count_all() === $start_ops + 1,
        'First post/operation row missing');
    $p = get_post($post_id);
    plcAssert($p && (string)$p->post_status === 'draft' && (string)$p->post_title === $title1
        && (string)$p->post_excerpt === $excerpt1
        && hash_equals(hash('sha256', (string)$p->post_content), hash('sha256', $content1)),
        'Sandbox post was not persisted as a byte-identical draft');
    plcAssert((array)get_post_meta($post_id, '_oai_sandbox_artifact', true) !== array(),
        'Sandbox post artifact marker missing');

    // The same key with the same payload is pending (reconciliation), not duplicated.
    plcErr(plcCreatePost($title1, $excerpt1, $content1, 'post-create-first-123456'), 'oai_post_creation_pending',
        'Duplicate idempotency was not pending');
    // Reusing the key with a different payload is an explicit conflict.
    plcErr(plcCreatePost($title1, $excerpt1, plcContent('tampered re-use'), 'post-create-first-123456'),
        'oai_post_intent_conflict', 'Key reuse with another payload was not a conflict');
    // A too-short key is rejected before any reservation.
    plcErr(plcCreatePost($title1, $excerpt1, $content1, 'short'), 'oai_post_request_key',
        'Invalid idempotency key was accepted');
    plcAssert(plcPostCount('post') === $start_posts + 1 && (int)OAI_DB::count_all() === $start_ops + 1,
        'Idempotency/conflict created a duplicate post or operation');

    // Approve + apply commits the disposable draft to its final status.
    plcOk(plcAction($op_id, 'approve'), 'Approve first post');
    plcOk(plcAction($op_id, 'apply'), 'Apply first post');
    $applied_post = get_post($post_id);
    plcAssert($applied_post && (string)$applied_post->post_status === 'draft'
        && hash_equals(hash('sha256', (string)$applied_post->post_content), hash('sha256', $content1)),
        'Applied first post lost its byte-identical content');
    $verify_after = OAI_Verify::operation($op_id);
    plcAssert(!empty($verify_after['verified']), 'First post apply did not verify: '.wp_json_encode($verify_after));
    $proof_after = OAI_Proof::make($op_id);
    plcAssert(is_array($proof_after) && ($proof_after['proof_kind'] ?? '') === 'execution'
        && !empty($proof_after['verified']), 'First post apply proof missing or unsigned');
    plcAssert(!empty(OAI_DB::get($op_id)['receipt']), 'First post apply receipt missing');
    plcAssert((int)OAI_DB::count_all() === $start_ops + 1, 'Apply of first post created extra operation rows');

    // A change made after Apply must block the destructive rollback.
    $tampered = plcContent('first post body one TAMPERED');
    wp_update_post(array('ID' => $post_id, 'post_content' => $tampered));
    plcErr(plcAction($op_id, 'rollback'), 'oai_rollback_conflict',
        'Rollback proceeded over a post changed after Apply');
    // Restore the applied snapshot so the retried rollback is safe.
    wp_update_post(array('ID' => $post_id, 'post_content' => $content1));
    plcOk(plcAction($op_id, 'rollback'), 'Rollback first post after restoring applied snapshot');
    plcAssert(!get_post($post_id), 'Rollback did not delete the created post');
    plcAssert(plcPostCount('post') === $start_posts, 'Rollback left the created post behind');
    $verify_rollback = OAI_Verify::operation($op_id);
    plcAssert(!empty($verify_rollback['verified']) && !empty($verify_rollback['cleanup_effective']),
        'First post rollback did not verify absence: '.wp_json_encode($verify_rollback));
    $proof_rollback = OAI_Proof::make($op_id);
    plcAssert(is_array($proof_rollback) && ($proof_rollback['phase'] ?? '') === 'rollback',
        'First post rollback proof phase is wrong');

    // A final_status of publish commits to publish on Apply (still disposable).
    $contentPub = plcContent('published disposable body');
    $pub = plcOk(plcCreatePost('WP AI Post Lifecycle Published', 'pub excerpt', $contentPub,
        'post-create-publish-123456', 'post', 'publish'), 'Create publish-target draft post');
    plcAssert(($pub['status'] ?? '') === 'pending' && (string)get_post_status((int)$pub['post_id']) === 'draft',
        'Publish-target post was not held as a draft before apply');
    $cleaned[] = (int)$pub['post_id'];
    plcOk(plcAction((int)$pub['id'], 'approve'), 'Approve publish-target post');
    plcOk(plcAction((int)$pub['id'], 'apply'), 'Apply publish-target post');
    plcAssert(get_post_status((int)$pub['post_id']) === 'publish', 'Applied post did not reach final_status publish');
    // Dispose the published fixture via rollback deletion (created artifact contract).
    plcOk(plcAction((int)$pub['id'], 'rollback'), 'Rollback publish-target post');
    plcAssert(!get_post((int)$pub['post_id']), 'Publish-target rollback did not delete the post');

    // Reject cleans the sandbox artifact up without an operation in flight.
    $rej = plcOk(plcCreatePost('WP AI Post Reject', 'reject excerpt', plcContent('reject body'),
        'post-create-reject-123456'), 'Create reject-target draft post');
    $rej_id = (int)$rej['post_id'];
    plcAssert(get_post($rej_id) !== null, 'Reject-target draft missing');
    plcOk(plcAction((int)$rej['id'], 'reject'), 'Reject target post');
    plcAssert(!get_post($rej_id), 'Reject left an orphan post behind');
    plcAssert((string)OAI_DB::get((int)$rej['id'])['status'] === 'rejected', 'Operation was not recorded as rejected');
    plcAssert(plcPostCount('post') === $start_posts, 'Reject path left post rows behind');

    // Cross-actor reuse of the same request key is scoped per actor: it creates
    // an independent draft instead of reusing the first actor's reservation.
    $other = wp_create_user('post-lifecycle-other-ci', 'ci-local-only-password', 'post-lifecycle-other@example.invalid');
    plcAssert(!is_wp_error($other), 'Cannot provision second actor');
    $other_id = (int)$other;
    (new WP_User($other_id))->set_role('administrator');
    wp_set_current_user($other_id);
    $cross = plcOk(plcCreatePost($title1, $excerpt1, $content1, 'post-create-first-123456'), 'Cross-actor draft with same key');
    $cross_id = (int)$cross['post_id'];
    $cleaned[] = $cross_id;
    plcAssert(get_post($cross_id) && get_post_field('post_author', $cross_id) == $other_id,
        'Cross-actor draft is not owned by the second actor');
    wp_set_current_user($actor_id);
    // Dispose cross-actor draft directly (no lifecycle needed for cleanup).
    wp_delete_post($cross_id, true);

    /* ---------------------------------------------------------------- *
     * post_update: atomic title/excerpt/content bundle lifecycle        *
     * ---------------------------------------------------------------- */
    $orig_title = 'WP AI Update Original';
    $orig_excerpt = 'original excerpt';
    $orig_content = plcContent('original update body');
    $target_post = wp_insert_post(array(
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => $orig_title, 'post_excerpt' => $orig_excerpt, 'post_content' => $orig_content,
    ), true);
    plcAssert(!is_wp_error($target_post) && $target_post > 0, 'Cannot create update target post');
    $target_post = (int)$target_post;
    $cleaned[] = $target_post;

    $upd_title = 'WP AI Update Changed';
    $upd_excerpt = 'changed excerpt';
    $upd_content = plcContent('changed update body with a longer sentence for byte-exactness');
    $pre_update_ops = (int)OAI_DB::count_all();
    $update = plcOk(plcUpdatePost($target_post, $upd_title, $upd_excerpt, $upd_content), 'Plan atomic post update');
    plcAssert(($update['type'] ?? '') === 'post_update' && ($update['status'] ?? '') === 'pending'
        && ($update['target'] ?? '') === $target_post.':post', 'Post update is not a pending post_update operation');
    $update_id = (int)$update['id'];
    plcAssert((int)OAI_DB::count_all() === $pre_update_ops + 1, 'Post update created unexpected operation rows');
    $still = get_post($target_post);
    plcAssert((string)$still->post_title === $orig_title && (string)$still->post_content === $orig_content,
        'Planning an update wrote the target before Apply');

    plcOk(plcAction($update_id, 'approve'), 'Approve post update');
    plcOk(plcAction($update_id, 'apply'), 'Apply post update');
    $applied = get_post($target_post);
    plcAssert((string)$applied->post_title === $upd_title && (string)$applied->post_excerpt === $upd_excerpt
        && hash_equals(hash('sha256', (string)$applied->post_content), hash('sha256', $upd_content)),
        'Applied bundle was not persisted byte-identically');
    $upd_verify = OAI_Verify::operation($update_id);
    plcAssert(!empty($upd_verify['verified']), 'Post update apply did not verify: '.wp_json_encode($upd_verify));
    $found_byte = false;
    foreach ($upd_verify['checks'] as $check) {
        if (($check['name'] ?? '') === 'byte_exact_post_content' && !empty($check['ok'])) $found_byte = true;
    }
    plcAssert($found_byte, 'Post update lacked a passing byte-exact post_content check');

    // Update rollback must restore the exact original bundle, byte-identical.
    plcOk(plcAction($update_id, 'rollback'), 'Rollback post update');
    $restored = get_post($target_post);
    plcAssert((string)$restored->post_title === $orig_title && (string)$restored->post_excerpt === $orig_excerpt
        && hash_equals(hash('sha256', (string)$restored->post_content), hash('sha256', $orig_content)),
        'Rollback did not restore the exact original bundle');
    $upd_rollback_verify = OAI_Verify::operation($update_id);
    plcAssert(!empty($upd_rollback_verify['verified']), 'Post update rollback did not verify: '.wp_json_encode($upd_rollback_verify));

    // A no-op update (identical to the current live state) is refused.
    plcErr(plcUpdatePost($target_post, $orig_title, $orig_excerpt, $orig_content), 'oai_noop',
        'Identical post update was accepted');

    // A change after Apply must block a destructive update rollback as well.
    $b2 = plcOk(plcUpdatePost($target_post, 'Bundle Two', 'bundle two excerpt', plcContent('bundle two body')),
        'Plan second post update');
    $b2_id = (int)$b2['id'];
    plcOk(plcAction($b2_id, 'approve'), 'Approve second post update');
    plcOk(plcAction($b2_id, 'apply'), 'Apply second post update');
    wp_update_post(array('ID' => $target_post, 'post_content' => plcContent('post-apply mutation')));
    plcErr(plcAction($b2_id, 'rollback'), 'oai_rollback_conflict',
        'Update rollback proceeded over a post changed after Apply');
    wp_update_post(array('ID' => $target_post, 'post_content' => plcContent('bundle two body')));
    plcOk(plcAction($b2_id, 'rollback'), 'Rollback second post update after restoring snapshot');
    $restored2 = get_post($target_post);
    plcAssert((string)$restored2->post_title === $orig_title && (string)$restored2->post_excerpt === $orig_excerpt
        && hash_equals(hash('sha256', (string)$restored2->post_content), hash('sha256', $orig_content)),
        'Second rollback did not restore the exact pre-update bundle');

    // The target still verifies at its restored state.
    plcAssert(!empty(OAI_Verify::operation($b2_id)['verified']), 'Second post update rollback did not verify');

    echo 'PASS post/page lifecycle: '.PostLifecycleChecks::$count.
        " assertions (create idempotency, apply/verify, rollback conflict+cleanup, reject, atomic update byte-exact, proof/receipt)\n";
} finally {
    wp_set_current_user($actor_id);
    OAI_AI_Lifecycle_Control::disable($actor_id);
    foreach ($cleaned as $pid) {
        if ($pid > 0 && get_post($pid)) wp_delete_post($pid, true);
    }
}
