<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$entry = file_get_contents(dirname(__DIR__).'/wp-ai-admin-cloud.php');
if (!is_string($entry) || !preg_match('/Version: ([0-9]+\.[0-9]+\.[0-9]+)/', $entry, $m)) {
    throw new RuntimeException('Plugin header version is missing');
}
if ($m[1] !== WPAI_CLOUD_VERSION) {
    throw new RuntimeException('Bootstrap and plugin header versions disagree');
}
echo "PASS cloud version contract: ".WPAI_CLOUD_VERSION.PHP_EOL;
