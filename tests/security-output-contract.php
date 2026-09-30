<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'p0' => file_get_contents($root . '/includes/class-oai-p0.php'),
    'read' => file_get_contents($root . '/includes/class-oai-read.php'),
    'diagnostics' => file_get_contents($root . '/includes/class-oai-diagnostics.php'),
    'rest' => file_get_contents($root . '/includes/class-oai-rest.php'),
    'lifecycle' => file_get_contents($root . '/includes/class-oai-lifecycle.php'),
];
foreach ($files as $name => $source) {
    if ($source === false) {
        fwrite(STDERR, "Unable to read source: {$name}\n");
        exit(1);
    }
}

$checks = [
    'media snapshot has no attached file path' => strpos($files['p0'], 'get_attached_file(') === false,
    'health has no uploads absolute path field' => strpos($files['read'], "'path'=>wp_upload_dir()['basedir']") === false,
    'diagnostics has no uploads_path field' => strpos($files['diagnostics'], "'uploads_path'") === false,
    'diagnostics has no stylesheet_directory field' => strpos($files['diagnostics'], "'stylesheet_directory'") === false,
    'status has no plugin_dir field' => strpos($files['rest'], "'plugin_dir'=>OAI_DIR") === false,
    'user list does not project user email' => strpos($files['read'], "'email'=>$u->user_email") === false,
    'user list does not project login' => strpos($files['read'], "'login'=>$u->user_login") === false,
    'comment list does not project email' => strpos($files['read'], "'email'=>$c->comment_author_email") === false,
    'legacy operation responses use public redaction' => strpos($files['lifecycle'], 'redact_public') !== false,
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($failed) {
    fwrite(STDERR, "Security output contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo "Security output contract PASS (" . count($checks) . " checks)\n";
