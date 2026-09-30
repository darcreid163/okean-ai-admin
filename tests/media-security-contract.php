<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../includes/class-oai-p0.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read class-oai-p0.php\n");
    exit(1);
}

$checks = [
    'public media snapshot does not expose attached filesystem path'
        => strpos($source, 'get_attached_file(') === false,
    'declared MIME must match filename extension'
        => strpos($source, 'oai_media_mime_mismatch') !== false,
    'uploaded bytes are inspected as an image'
        => strpos($source, 'wp_get_image_mime') !== false,
    'invalid image bytes are rejected'
        => strpos($source, 'oai_media_content_type') !== false,
    'media snapshot has centralized redaction'
        => strpos($source, 'redact_media_snapshot') !== false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "Media security contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Media security contract PASS (" . count($checks) . " checks)\n";
