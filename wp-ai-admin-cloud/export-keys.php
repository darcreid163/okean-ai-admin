<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php export-keys.php /absolute/path/to/wordpress /absolute/private/path/gateway-keys.php\n");
    exit(2);
}
$root = realpath($argv[1]);
$target = $argv[2];
if (!$root || !is_file($root . DIRECTORY_SEPARATOR . 'wp-load.php') || !str_starts_with($target, DIRECTORY_SEPARATOR) || file_exists($target)) {
    fwrite(STDERR, "Invalid WordPress root or output path; existing files are never overwritten.\n");
    exit(2);
}
$targetDir = realpath(dirname($target));
$rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!$targetDir || $targetDir === $root || str_starts_with($targetDir . DIRECTORY_SEPARATOR, $rootPrefix)) {
    fwrite(STDERR, "Output must be in an existing directory outside the WordPress web root.\n");
    exit(2);
}
$created = false;
$createdStat = null;
try {
    require_once $root . DIRECTORY_SEPARATOR . 'wp-load.php';
    if (!class_exists(\WPAICloudGateway\Settings::class)) {
        throw new RuntimeException('Cloud gateway plugin is not active');
    }
    // Export the exact effective keys. Never generate replacements here.
    $keys = \WPAICloudGateway\Settings::keys();
    $vault = base64_encode($keys['vault']);
    $signing = base64_encode($keys['signing']);
    $snippet = "<?php\n"
        . "// Preserve the existing WP AI Admin Cloud keys. Keep this file private; do not commit it.\n"
        . "define('WP_AI_CLOUD_VAULT_KEY', '" . $vault . "');\n"
        . "define('WP_AI_CLOUD_SIGNING_KEY', '" . $signing . "');\n";
    $oldUmask = umask(0077);
    $handle = @fopen($target, 'x');
    if (!$handle) {
        umask($oldUmask);
        throw new RuntimeException('Could not create private output file');
    }
    $created = true;
    $createdStat = fstat($handle);
    $written = fwrite($handle, $snippet);
    $flushed = fflush($handle);
    fclose($handle);
    umask($oldUmask);
    if ($written !== strlen($snippet) || !$flushed || !chmod($target, 0600)) {
        throw new RuntimeException('Could not securely write private output file');
    }
    fwrite(STDOUT, "Exported the existing gateway keys to a private 0600 snippet. Values were not printed.\n");
    fwrite(STDOUT, "Add both definitions to wp-config.php before changing AUTH_KEY or AUTH_SALT.\n");
} catch (Throwable $error) {
    if (isset($handle) && is_resource($handle)) fclose($handle);
    if (isset($oldUmask)) umask($oldUmask);
    // Never remove a path that this process did not create. The destination
    // may have appeared after the initial existence check (another exporter
    // won the race), or may have been replaced after our exclusive create.
    if ($created && is_array($createdStat)) {
        $pathStat = @lstat($target);
        if ($pathStat && (int)$pathStat['dev'] === (int)$createdStat['dev'] && (int)$pathStat['ino'] === (int)$createdStat['ino']) {
            @unlink($target);
        }
    }
    fwrite(STDERR, "Could not export the current gateway keys. No key material was printed.\n");
    exit(1);
}
