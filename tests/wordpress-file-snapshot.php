<?php
/**
 * File snapshot regression: distinguish absent targets from unreadable existing files.
 * Disposable WordPress only; creates and removes a temporary file in the theme scope.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) {
    throw new RuntimeException('Disposable loopback WordPress required');
}
if (!class_exists('OAI_Files') || !class_exists('OAI_Security') || !method_exists('OAI_REST', 'snapshot_for_verify')) {
    throw new RuntimeException('Native file snapshot components are unavailable');
}
function fileSnapshotAssert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$scope = 'child_theme';
$scopes = OAI_Security::scopes();
if (empty($scopes[$scope]) || !is_dir($scopes[$scope])) throw new RuntimeException('Theme scope is unavailable');
$base = realpath($scopes[$scope]);
$name = 'wpai-file-snapshot-' . bin2hex(random_bytes(8)) . '.txt';
$relative = $name;
$full = $base . DIRECTORY_SEPARATOR . $name;
$missing = 'wpai-missing-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR . 'new-file.txt';
$new_full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $missing);
$link_name = 'wpai-file-link-' . bin2hex(random_bytes(8)) . '.txt';
$link_full = $base . DIRECTORY_SEPARATOR . $link_name;
$outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpai-outside-' . bin2hex(random_bytes(8)) . '.txt';
$dir_link_name = 'wpai-dir-link-' . bin2hex(random_bytes(8));
$dir_link_full = $base . DIRECTORY_SEPARATOR . $dir_link_name;
$outside_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpai-outside-dir-' . bin2hex(random_bytes(8));
try {
    $absent = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $missing);
    fileSnapshotAssert(is_array($absent) && $absent === array(false, null), 'A validated missing nested target must be represented as a new file');
    $new_payload = "new nested file\n";
    $new_write = OAI_Files::write($scope, $missing, $new_payload, '', false);
    fileSnapshotAssert(!is_wp_error($new_write) && !empty($new_write['ok']), 'New nested file write failed after locked path revalidation');
    fileSnapshotAssert(is_file($new_full) && file_get_contents($new_full) === $new_payload, 'New nested file bytes do not match');
    $new_delete = OAI_Files::delete($scope, $missing, hash('sha256', $new_payload));
    fileSnapshotAssert(!is_wp_error($new_delete) && !file_exists($new_full), 'New nested file delete failed after path revalidation');
    @rmdir(dirname($new_full));
    $small = "snapshot fixture\n";
    fileSnapshotAssert(file_put_contents($full, $small) === strlen($small), 'Could not create small file fixture');
    $present = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $relative);
    fileSnapshotAssert(is_array($present) && $present[0] === true && $present[1] === $small, 'Existing file snapshot must preserve exact bytes');
    $replacement_payload = "atomically replaced file\n";
    $temp_before = glob($base . DIRECTORY_SEPARATOR . '.wpai-atomic-*');
    if (!is_array($temp_before)) $temp_before = array();
    $replaced = OAI_Files::write($scope, $relative, $replacement_payload, hash('sha256', $small), true);
    fileSnapshotAssert(!is_wp_error($replaced) && !empty($replaced['ok']), 'Existing file atomic replacement failed');
    fileSnapshotAssert(file_get_contents($full) === $replacement_payload, 'Existing file replacement did not publish the complete payload');
    fileSnapshotAssert(hash_equals(hash('sha256', $replacement_payload), hash_file('sha256', $full)), 'Existing file replacement hash mismatch');
    $temp_after = glob($base . DIRECTORY_SEPARATOR . '.wpai-atomic-*');
    if (!is_array($temp_after)) $temp_after = array();
    fileSnapshotAssert(count(array_diff($temp_after, $temp_before)) === 0, 'Atomic replacement left a staged temporary file behind');
    $large = str_repeat('L', 2 * 1024 * 1024 + 1);
    fileSnapshotAssert(file_put_contents($full, $large) === strlen($large), 'Could not create large file fixture');
    $oversized = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $relative);
    fileSnapshotAssert(is_wp_error($oversized) && $oversized->get_error_code() === 'oai_file_large', 'An existing file above the read limit must fail closed, not masquerade as a missing file');
    // Symlink file targets and symlinked parent directories must fail closed.
    fileSnapshotAssert(file_put_contents($outside, "outside sentinel\n") !== false, 'Could not create outside sentinel');
    fileSnapshotAssert(symlink($outside, $link_full), 'Symlink fixture creation is unavailable');
    $file_link = OAI_Security::resolve_path_for_write($scope, $link_name);
    fileSnapshotAssert(is_wp_error($file_link) && $file_link->get_error_code() === 'oai_path_denied', 'Symlink file target was accepted');
    $write_link = OAI_Files::write($scope, $link_name, 'overwrite attempt');
    fileSnapshotAssert(is_wp_error($write_link) && $write_link->get_error_code() === 'oai_path_denied', 'Write followed a symlink file target');
    $delete_link = OAI_Files::delete($scope, $link_name);
    fileSnapshotAssert(is_wp_error($delete_link) && $delete_link->get_error_code() === 'oai_path_denied', 'Delete accepted a symlink file target');

    fileSnapshotAssert(mkdir($outside_dir), 'Could not create outside directory');
    fileSnapshotAssert(symlink($outside_dir, $dir_link_full), 'Directory symlink fixture creation is unavailable');
    $dir_path = $dir_link_name . DIRECTORY_SEPARATOR . 'escaped.txt';
    $dir_result = OAI_Security::resolve_path_for_write($scope, $dir_path);
    fileSnapshotAssert(is_wp_error($dir_result) && $dir_result->get_error_code() === 'oai_path_denied', 'Symlink parent directory was accepted');
    $dir_write = OAI_Files::write($scope, $dir_path, 'escaped write');
    fileSnapshotAssert(is_wp_error($dir_write) && $dir_write->get_error_code() === 'oai_path_denied', 'Write traversed a symlink parent directory');
    echo "PASS file snapshot/security: absent/existing/oversized snapshots, symlink target and parent rejection\n";
} finally {
    if (is_file($full)) @unlink($full);
    if (is_file($new_full)) @unlink($new_full);
    if (is_dir(dirname($new_full))) @rmdir(dirname($new_full));
    if (is_link($link_full)) @unlink($link_full);
    if (is_link($dir_link_full)) @unlink($dir_link_full);
    if (is_file($outside)) @unlink($outside);
    if (is_dir($outside_dir)) @rmdir($outside_dir);
}
