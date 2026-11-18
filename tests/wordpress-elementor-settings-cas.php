<?php
/**
 * Elementor widget settings optimistic-concurrency regression.
 * A settings snapshot that becomes stale after planning must never overwrite the newer editor value.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) {
    throw new RuntimeException('Disposable loopback WordPress required');
}
if (!class_exists('OAI_Elementor')) throw new RuntimeException('Elementor adapter is unavailable');
function ewCasAssert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$postId = (int) wp_insert_post(array(
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Disposable Elementor settings CAS fixture',
    'post_content' => '',
), true);
if ($postId <= 0 || is_wp_error($postId)) throw new RuntimeException('Could not create Elementor CAS fixture');
try {
    $nodeId = 'casnode01';
    $initialSettings = array('title' => 'Initial title', 'align' => 'left');
    $initialData = array(array(
        'id' => $nodeId,
        'elType' => 'widget',
        'widgetType' => 'heading',
        'settings' => $initialSettings,
        'elements' => array(),
    ));
    update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    $approvedJson = wp_json_encode($initialSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $approvedHash = hash('sha256', $approvedJson);

    // Simulate a human editing the same widget after sandbox creation/approval.
    $newerSettings = array('title' => 'Human editor title', 'align' => 'center');
    $newerData = $initialData;
    $newerData[0]['settings'] = $newerSettings;
    update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($newerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    $attempt = OAI_Elementor::save_settings($postId . ':' . $nodeId, array('title' => 'AI overwrite'), $approvedHash);
    ewCasAssert(is_wp_error($attempt) && $attempt->get_error_code() === 'oai_snapshot_mismatch',
        'Stale Elementor settings snapshot was not rejected');
    $stored = OAI_Elementor::find_node($postId, $nodeId);
    ewCasAssert(!is_wp_error($stored) && $stored['node']['settings'] === $newerSettings,
        'Stale Apply overwrote the newer Elementor editor settings');

    $missingHash = OAI_Elementor::save_settings($postId . ':' . $nodeId, array('title' => 'No snapshot'));
    ewCasAssert(is_wp_error($missingHash) && $missingHash->get_error_code() === 'oai_snapshot_required',
        'Direct Elementor settings save without approved snapshot was accepted');
    echo "PASS Elementor settings CAS: stale snapshot and missing snapshot rejected without overwriting newer editor state\n";
} finally {
    wp_delete_post($postId, true);
}
