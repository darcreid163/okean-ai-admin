<?php
declare(strict_types=1);

$bootstrap = file_get_contents(dirname(__DIR__) . '/wp-ai-admin-cloud.php');
if (!is_string($bootstrap)) {
    fwrite(STDERR, "Cannot read Cloud Core bootstrap.\n");
    exit(1);
}

$checks = [
    'HPOS declaration is registered on before_woocommerce_init' =>
        strpos($bootstrap, "add_action('before_woocommerce_init'") !== false,
    'FeaturesUtil is guarded for WooCommerce-absent installs' =>
        strpos($bootstrap, 'class_exists(') !== false && strpos($bootstrap, 'FeaturesUtil::class') !== false,
    'custom_order_tables compatibility is explicitly declared' =>
        strpos($bootstrap, "declare_compatibility('custom_order_tables', __FILE__, true)") !== false,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
