<?php
/**
 * Offline contract: Native MCP scope classification must match the Cloud
 * Gateway read/write classification for every catalog tool.
 *
 * Native gates writes with an explicit write list (OAI_MCP::tool_requires_write);
 * Cloud gates writes with an explicit read list (Core::READ_TOOLS). Without this
 * test a new Native read tool silently becomes mcp:write-only through the Cloud
 * Gateway (regression class found in the 2026-11-12 audit: customer/order reads).
 *
 * Runs offline in the Native CI php job; no WordPress runtime is required.
 */
define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
require $root.'/includes/class-oai-mcp.php';
require $root.'/includes/class-oai-platform.php';
require $root.'/wp-ai-admin-cloud/src/AbilitiesPolicy.php';
require $root.'/wp-ai-admin-cloud/src/Core.php';

// Minimal offline stubs. Classification is pure; no WordPress runtime is used.
function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
function get_option($name, $default = false){ return $default; }

function parityAssert($ok, $message){ if (!$ok) { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); } }

// tool_requires_write() is private; the contract inspects it via reflection
// instead of widening the production API.
$requiresWrite = new ReflectionMethod('OAI_MCP', 'tool_requires_write');
$nativeWrite = static function($name, array $args) use ($requiresWrite) {
    return (bool) $requiresWrite->invoke(null, $name, $args);
};

$tools = OAI_Platform::expected_tools();
parityAssert(count($tools) > 100, 'Native catalog unexpectedly small: '.count($tools));

$fail = array();
foreach ($tools as $tool) {
    $name = str_replace('.', '_', $tool);
    $native = $nativeWrite($name, array());
    $cloud = \WPAICloudGateway\Core::writes($name, array());
    if ($native !== $cloud) $fail[] = $tool.' (native='.var_export($native, true).' cloud='.var_export($cloud, true).')';
}
parityAssert(!$fail, "Native/Cloud classification drift:\n  ".implode("\n  ", $fail));

// wordpress_execute is mode-sensitive on both sides and must stay aligned.
foreach (array('read', 'sandbox', 'apply', 'write') as $mode) {
    $n = $nativeWrite('wordpress_execute', array('mode' => $mode));
    $c = \WPAICloudGateway\Core::writes('wordpress_execute', array('mode' => $mode));
    parityAssert($n === $c, 'wordpress_execute mode '.$mode.' classification diverges');
}

// Annotation parity: the MCP readOnlyHint list must equal the scope-read set.
$src = (string) file_get_contents($root.'/includes/class-oai-mcp.php');
parityAssert(preg_match('/\$read=array\((.*?)\);/s', $src, $m) === 1, 'MCP $read annotation list not found');
preg_match_all("/'([a-z0-9_.]+)'/", $m[1], $ann);
$annotated = array_map(static function($t){ return str_replace('.', '_', $t); }, $ann[1]);
$scopeReads = array();
foreach ($tools as $tool) {
    $name = str_replace('.', '_', $tool);
    if ($name === 'wordpress_execute') continue;
    if (!$nativeWrite($name, array())) $scopeReads[] = $name;
}
sort($annotated); sort($scopeReads);
parityAssert($annotated === $scopeReads,
    'readOnlyHint annotations diverge from scope classification: missing=['
    .implode(',', array_diff($scopeReads, $annotated)).'] extra=['.implode(',', array_diff($annotated, $scopeReads)).']');

// Regression pins: these reads must stay reads on both sides (audit 2026-11-12).
foreach (array('woocommerce_search_customers', 'woocommerce_get_customer', 'woocommerce_get_order', 'system_security_fuzz') as $name) {
    parityAssert(!$nativeWrite($name, array()) && !\WPAICloudGateway\Core::writes($name, array()),
        $name.' must be a read on both sides');
}
echo 'PASS: '.count($tools).' tools classified identically by Native and Cloud; annotations aligned; mode-sensitive execute checked'.PHP_EOL;
