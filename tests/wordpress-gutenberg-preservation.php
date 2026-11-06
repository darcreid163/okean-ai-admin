<?php
/**
 * Real WordPress Gutenberg preservation lifecycle on a disposable site.
 *
 * Proves that gutenberg.patch merges attributes, keeps unrelated block bytes,
 * patches only an explicit innerContent part, stores the raw pre-change
 * post_content and that Apply -> Verify -> Rollback is byte-identical.
 * Disposable CI only; unpublished draft fixtures are deleted afterwards.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false &&
     strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Refusing Gutenberg preservation test outside disposable CI');
}
if (!class_exists('OAI_Gutenberg_Resolver') || !function_exists('parse_blocks')) {
    throw new RuntimeException('Gutenberg resolver is unavailable');
}
function gbOk($value, string $message) {
    if (is_wp_error($value)) {
        throw new RuntimeException($message.': '.$value->get_error_code().' '.$value->get_error_message());
    }
    return $value;
}
function gbErr($value, string $message) {
    if (!is_wp_error($value)) {
        throw new RuntimeException($message.': expected a WP_Error');
    }
    return $value;
}
function gbContent(int $id): string {
    clean_post_cache($id);
    $post = get_post($id);
    return $post ? (string)$post->post_content : '';
}
function gbNode(array $blocks, string $path) {
    $node = null;
    $nodes = $blocks;
    foreach (explode('.', $path) as $index) {
        if (!isset($nodes[(int)$index])) return null;
        $node = $nodes[(int)$index];
        $nodes = isset($node['innerBlocks']) && is_array($node['innerBlocks']) ? $node['innerBlocks'] : array();
    }
    return $node;
}
function gbCreate(string $content): array {
    $created = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => 'Disposable Gutenberg preservation fixture',
        'post_content' => $content,
    ), true);
    $id = gbOk($created, 'Create Gutenberg fixture');
    return array((int)$id, gbContent((int)$id));
}
function gbPlan(int $id, array $selector, ?array $attrs, ?string $html, array $remove = array(), $index = null) {
    $request = new WP_REST_Request('POST', '/okean-ai/v1/gutenberg/patch');
    $request->set_param('post_id', $id);
    $request->set_param('selector', $selector);
    if ($attrs !== null) $request->set_param('attrs', $attrs);
    if ($html !== null) $request->set_param('html', $html);
    if ($remove) $request->set_param('attrs_remove', $remove);
    if ($index !== null) $request->set_param('inner_content_index', $index);
    return OAI_REST::gutenberg_patch($request);
}
function gbLifecycle(int $id, array $plan, string $label): void {
    $operation = (int)($plan['id'] ?? 0);
    if ($operation <= 0 || ($plan['status'] ?? '') !== 'pending') {
        throw new RuntimeException($label.': sandbox did not create a pending operation');
    }
    $before = (string)$plan['before_data'];
    $after = (string)$plan['after_data'];
    if (gbContent($id) !== $before) {
        throw new RuntimeException($label.': planning wrote live content');
    }
    gbOk(OAI_AI_Lifecycle_Control::perform($operation, 'approve', 'disposable-gutenberg-preservation', 1), $label.': approve');
    gbOk(OAI_AI_Lifecycle_Control::perform($operation, 'apply', 'disposable-gutenberg-preservation', 1), $label.': apply');
    if (gbContent($id) !== $after) {
        throw new RuntimeException($label.': apply was not byte-exact');
    }
    $verified = gbOk(OAI_Verify::operation($operation), $label.': verify apply');
    if (empty($verified['verified'])) {
        throw new RuntimeException($label.': apply was not verified');
    }
    gbOk(OAI_AI_Lifecycle_Control::perform($operation, 'rollback', 'disposable-gutenberg-preservation', 1), $label.': rollback');
    if (gbContent($id) !== $before) {
        throw new RuntimeException($label.': rollback was not byte-exact');
    }
    $rolled = gbOk(OAI_Verify::operation($operation), $label.': verify rollback');
    if (empty($rolled['verified'])) {
        throw new RuntimeException($label.': rollback was not verified');
    }
}

wp_set_current_user(1);
$checks = 0;
$ok = function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
};
$created = array();
try {
    gbOk(OAI_License::activate_local_owner(1), 'Activate disposable local owner license');
    gbOk(OAI_AI_Lifecycle_Control::enable(1), 'Enable scoped test lifecycle');

    // 1. Nested blocks: patch one leaf, keep container attributes, sibling
    //    attributes, sibling bytes and a deliberately non-canonical block.
    $nested = <<<'HTML'
<!-- wp:group {"className":"keep-me","acme":{"mode":"strict","threshold":4},"layout":{"type":"constrained"}} -->
<div class="wp-block-group keep-me"><!-- wp:paragraph {"dropCap":false,"acmeMeta":"keep"} -->
<p>Alpha</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Beta</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph  {"className":"legacy-space"}  -->
<p>Vector</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;
    list($nestedId, $nestedRaw) = gbCreate($nested);
    $created[] = $nestedId;
    $plan = gbOk(gbPlan($nestedId, array('path' => '0.0'), null, '<p>Alpha edited</p>'), 'Nested leaf patch');
    $after = (string)$plan['after_data'];
    $ok($plan['before_data'] === $nestedRaw, 'Nested: before is not the raw post_content');
    $ok(($plan['meta_data']['kind'] ?? '') === 'gutenberg_block_patch', 'Nested: operation kind missing');
    $ok(($plan['meta_data']['before_sha256'] ?? '') === hash('sha256', $nestedRaw), 'Nested: raw before hash missing');
    $ok(($plan['meta_data']['after_sha256'] ?? '') === hash('sha256', $after), 'Nested: raw after hash missing');
    $ok(($plan['meta_data']['patch_scope'] ?? '') === 'block_splice', 'Nested: unrelated blocks were re-serialised');
    $group = gbNode(parse_blocks($after), '0');
    $ok(is_array($group) && ($group['attrs']['acme'] ?? null) === array('mode' => 'strict', 'threshold' => 4), 'Nested: container attributes lost');
    $leaves = $group['innerBlocks'] ?? array();
    $ok(count($leaves) === 3, 'Nested: inner block count changed');
    $ok(trim((string)($leaves[0]['innerHTML'] ?? '')) === '<p>Alpha edited</p>', 'Nested: patched leaf not updated');
    $ok((int)count($leaves[0]['innerContent'] ?? array()) === 1, 'Nested: patched leaf innerContent collapsed');
    $ok(trim((string)($leaves[1]['innerHTML'] ?? '')) === '<p>Beta</p>', 'Nested: sibling leaf changed');
    $ok(($leaves[1]['attrs'] ?? array()) === array(), 'Nested: sibling attributes changed');
    $ok((string)($leaves[2]['innerHTML'] ?? '') === "\n<p>Vector</p>\n", 'Nested: non-canonical sibling content changed');
    $ok(strpos($after, '<!-- wp:paragraph  {"className":"legacy-space"}  -->') !== false, 'Nested: non-canonical block markup was normalised');
    $ok(strpos($after, '"acmeMeta":"keep"') !== false, 'Nested: untouched block attributes were dropped');
    gbLifecycle($nestedId, $plan, 'Nested');

    // 2. Third-party attributes: merge one key, then remove one key explicitly.
    $vendor = <<<'HTML'
<!-- wp:acme/widget {"vendor":"acme","mode":"safe","nested":{"ticks":[1,2,3]},"dynamic":{"source":"custom-field","key":"hero"},"className":"hero-widget"} -->
<div class="acme hero-widget">Hero</div>
<!-- /wp:acme/widget -->
HTML;
    list($vendorId, $vendorRaw) = gbCreate($vendor);
    $created[] = $vendorId;
    $plan = gbOk(gbPlan($vendorId, array('block_name' => 'acme/widget'), array('mode' => 'fast'), null), 'Vendor merge patch');
    $node = gbNode(parse_blocks((string)$plan['after_data']), '0');
    $ok(($node['attrs'] ?? array()) === array(
        'vendor' => 'acme',
        'mode' => 'fast',
        'nested' => array('ticks' => array(1, 2, 3)),
        'dynamic' => array('source' => 'custom-field', 'key' => 'hero'),
        'className' => 'hero-widget',
    ), 'Vendor: attributes were replaced instead of merged');
    gbLifecycle($vendorId, $plan, 'Vendor merge');

    $plan = gbOk(gbPlan($vendorId, array('block_name' => 'acme/widget'), null, null, array('dynamic')), 'Vendor removal patch');
    $node = gbNode(parse_blocks((string)$plan['after_data']), '0');
    $ok(!array_key_exists('dynamic', $node['attrs'] ?? array()), 'Vendor: attrs_remove did not remove the key');
    $ok(($node['attrs']['vendor'] ?? null) === 'acme' && ($node['attrs']['mode'] ?? null) === 'safe', 'Vendor: attrs_remove dropped other keys');
    $ok(($node['attrs']['nested'] ?? null) === array('ticks' => array(1, 2, 3)), 'Vendor: nested attribute lost on removal');
    gbLifecycle($vendorId, $plan, 'Vendor removal');

    // 3. Synced pattern: self-closing core/block keeps ref and stays self-closing.
    $patternId = (int)gbOk(wp_insert_post(array(
        'post_type' => 'wp_block',
        'post_status' => 'publish',
        'post_title' => 'Disposable synced pattern',
        'post_content' => '<!-- wp:paragraph --><p>Pattern body</p><!-- /wp:paragraph -->',
    ), true), 'Create synced pattern fixture');
    $created[] = $patternId;
    list($syncedId, $syncedRaw) = gbCreate('<!-- wp:block {"ref":'.$patternId.'} /-->');
    $created[] = $syncedId;
    $plan = gbOk(gbPlan($syncedId, array('block_name' => 'core/block'), array('align' => 'wide'), null), 'Synced pattern attrs patch');
    $after = (string)$plan['after_data'];
    $node = gbNode(parse_blocks($after), '0');
    $ok(($node['attrs']['ref'] ?? null) === $patternId, 'Synced: ref attribute lost');
    $ok(($node['attrs']['align'] ?? null) === 'wide', 'Synced: new attribute missing');
    $ok(($node['innerContent'] ?? null) === array(), 'Synced: self-closing innerContent changed');
    $ok(strpos($after, '{"ref":'.$patternId.',"align":"wide"} /-->') !== false, 'Synced: block is no longer self-closing');
    gbLifecycle($syncedId, $plan, 'Synced pattern');

    // 4. Loss guards: container html, invalid index and multi-part innerContent.
    $container = gbErr(gbPlan($nestedId, array('path' => '0'), null, '<p>replace container</p>'), 'Container html patch');
    $ok($container->get_error_code() === 'oai_block_html_container', 'Container html patch did not fail closed');
    $badIndex = gbErr(gbPlan($nestedId, array('path' => '0.0'), null, '<p>x</p>', array(), 9), 'Out-of-range inner_content_index');
    $ok($badIndex->get_error_code() === 'oai_block_inner_content_index', 'Out-of-range inner_content_index was accepted');
    $target = new ReflectionMethod('OAI_Gutenberg_Resolver', 'inner_content_target');
    $target->setAccessible(true);
    $multi = array('<div class="wrapper">', null, '</div>');
    $multiError = $target->invoke(null, $multi, null);
    $ok(is_wp_error($multiError) && $multiError->get_error_code() === 'oai_block_inner_content_ambiguous', 'Multi-part innerContent without an index was accepted');
    $nullPart = $target->invoke(null, $multi, 1);
    $ok(is_wp_error($nullPart) && $nullPart->get_error_code() === 'oai_block_inner_content_index', 'A null innerContent part was addressable');
    $ok($target->invoke(null, $multi, 0) === 0 && $target->invoke(null, $multi, 2) === 2, 'Explicit replaceable innerContent index was rejected');
    $ok($target->invoke(null, array(), null) === 0, 'Empty innerContent did not accept a first part');
    $ok($target->invoke(null, array('<p>x</p>'), null) === 0, 'Single-part innerContent was not patchable');

    // 5. Freeform HTML: repeated fragments stay ambiguous, single fragment is exact.
    list($repeatId, $repeatRaw) = gbCreate('<div class="repeat">Same fragment</div><div class="repeat">Same fragment</div>');
    $created[] = $repeatId;
    $ambiguous = gbErr(gbPlan($repeatId, array('text' => 'Same fragment'), null, 'Changed'), 'Freeform repeated fragment');
    $ok($ambiguous->get_error_code() === 'oai_block_ambiguous', 'Freeform repeated fragment was patched');
    $ok(gbContent($repeatId) === $repeatRaw, 'Freeform ambiguity wrote live content');

    $freeform = '<section><h2>Unique heading</h2><p>Body copy</p></section>';
    list($freeformId, $freeformRaw) = gbCreate($freeform);
    $created[] = $freeformId;
    $plan = gbOk(gbPlan($freeformId, array('text' => 'Unique heading'), null, 'Changed heading'), 'Freeform single patch');
    $ok($plan['before_data'] === $freeformRaw, 'Freeform: before is not the raw post_content');
    $ok((string)$plan['after_data'] === '<section><h2>Changed heading</h2><p>Body copy</p></section>', 'Freeform: replacement was not exact');
    gbLifecycle($freeformId, $plan, 'Freeform');
    $freeformAttrs = gbErr(gbPlan($freeformId, array('text' => 'Body copy'), array('x' => 1), '<p>y</p>'), 'Freeform attributes');
    $ok($freeformAttrs->get_error_code() === 'oai_freeform_attrs', 'Freeform attributes were accepted');

    // 6. Argument and no-op guards never create a sandbox.
    $empty = gbErr(gbPlan($nestedId, array('path' => '0.0'), null, null), 'Empty patch');
    $ok($empty->get_error_code() === 'oai_block_patch_empty', 'Empty patch was accepted');
    $noop = gbErr(gbPlan($nestedId, array('path' => '0.0'), array('dropCap' => false), null), 'No-op attribute patch');
    $ok($noop->get_error_code() === 'oai_noop', 'No-op attribute patch created an operation');
    $missing = gbErr(gbPlan($nestedId, array('path' => '9.9'), null, '<p>x</p>'), 'Missing block');
    $ok($missing->get_error_code() === 'oai_block_not_found', 'Missing block was not rejected');
    $badRemove = gbErr(gbPlan($nestedId, array('path' => '0.0'), null, null, array('')), 'Invalid attrs_remove');
    $ok($badRemove->get_error_code() === 'oai_block_attrs_remove', 'Invalid attrs_remove was accepted');
    $badJsonRequest = new WP_REST_Request('POST', '/okean-ai/v1/gutenberg/patch');
    $badJsonRequest->set_param('post_id', $nestedId);
    $badJsonRequest->set_param('selector', array('path' => '0.0'));
    $badJsonRequest->set_param('attrs_remove', 'not-json');
    $badJson = gbErr(OAI_REST::gutenberg_patch($badJsonRequest), 'Malformed attrs_remove JSON');
    $ok($badJson->get_error_code() === 'oai_block_attrs_remove', 'Malformed attrs_remove JSON was accepted');

    echo 'PASS '.$checks." Gutenberg preservation assertions; raw before, merged attrs, explicit innerContent, byte-exact apply/rollback\n";
} finally {
    wp_set_current_user(1);
    OAI_AI_Lifecycle_Control::disable(1, 'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    foreach ($created as $postId) {
        if ($postId > 0) wp_delete_post($postId, true);
    }
}
