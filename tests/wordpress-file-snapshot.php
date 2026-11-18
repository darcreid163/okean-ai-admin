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
try {
    $absent = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $missing);
    fileSnapshotAssert(is_array($absent) && $absent === array(false, null), 'A validated missing nested target must be represented as a new file');
    $small = "snapshot fixture\n";
    fileSnapshotAssert(file_put_contents($full, $small) === strlen($small), 'Could not create small file fixture');
    $present = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $relative);
    fileSnapshotAssert(is_array($present) && $present[0] === true && $present[1] === $small, 'Existing file snapshot must preserve exact bytes');
    $large = str_repeat('L', 2 * 1024 * 1024 + 1);
    fileSnapshotAssert(file_put_contents($full, $large) === strlen($large), 'Could not create large file fixture');
    $oversized = OAI_REST::snapshot_for_verify('file_write', $scope . ':' . $relative);
    fileSnapshotAssert(is_wp_error($oversized) && $oversized->get_error_code() === 'oai_file_large', 'An existing file above the read limit must fail closed, not masquerade as a missing file');
    echo "PASS file snapshot: absent nested target, exact existing snapshot, oversized target fail-closed\n";
} finally {
    if (is_file($full)) @unlink($full);
}
