<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$platform = file_get_contents($root . '/includes/class-oai-platform.php');
$mcp = file_get_contents($root . '/includes/class-oai-mcp.php');
if ($platform === false || $mcp === false) {
    fwrite(STDERR, "Unable to read write-classification sources\n");
    exit(1);
}

$checks = [
    'platform classifies Elementor container patch as write'
        => strpos($platform, "'elementor.patch_container_settings'") !== false,
    'platform classifies Elementor page-layout patch as write'
        => strpos($platform, "'elementor.patch_page_layout'") !== false,
    'platform classifies option sandbox as write'
        => strpos($platform, "'wp.option_update_sandbox'") !== false,
    'MCP requires write scope for Elementor container patch'
        => strpos($mcp, "'elementor_patch_container_settings'") !== false,
    'MCP requires write scope for Elementor page-layout patch'
        => strpos($mcp, "'elementor_patch_page_layout'") !== false,
    'MCP requires write scope for option sandbox'
        => strpos($mcp, "'wp_option_update_sandbox'") !== false,
    'MCP exposes a write-scope invariant contract'
        => strpos($mcp, 'function write_scope_contract') !== false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Write classification contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo "Write classification contract PASS (" . count($checks) . " checks)\n";
