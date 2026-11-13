<?php
/**
 * Regression tests for local quota authority.
 *
 * A site paired with Cloud Core must never fall back to the unlimited
 * local-owner plan. This is the guard that keeps a direct Native OAuth call
 * from bypassing the account-wide cloud quota.
 *
 * Run: php tests/quota-authority.php
 */
define('ABSPATH', __DIR__);

function get_option($key, $default = false) { return $GLOBALS['test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['test_options'][$key]); return true; }

class TestWpdb {
    public $options = 'wp_options';
    public function esc_like($value) { return addcslashes((string) $value, '_%\\'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        foreach (array_keys($GLOBALS['test_options']) as $name) {
            if (str_starts_with($name, 'oai_pair_credential_')) return $name;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['test_options'] = array();

class OAI_License {
    const LOCAL_MODE = 'local-owner';
    public static function status() {
        return array('active' => true, 'mode' => self::LOCAL_MODE, 'local_valid' => true, 'plan' => 'local-owner');
    }
}

require dirname(__DIR__) . '/includes/class-oai-gateway-pairing.php';
require dirname(__DIR__) . '/includes/class-oai-quota.php';

function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}

$reset = static function () {
    $reflection = new ReflectionClass('OAI_Gateway_Pairing');
    $reflection->setStaticPropertyValue('connected', null);
};

$reset();
check(!OAI_Gateway_Pairing::is_connected(), 'standalone site is not marked as paired');
check(OAI_Quota::is_engineering_access(), 'standalone local-owner license keeps its historical unlimited mode');

$GLOBALS['test_options']['oai_pair_credential_' . str_repeat('a', 64)] = array('user_id' => 7);
$reset();
check(OAI_Gateway_Pairing::is_connected(), 'legacy pairing credential is detected without a flag option');
check(!OAI_Quota::is_engineering_access(), 'paired site cannot use the unlimited engineering plan');
check(!empty($GLOBALS['test_options']['oai_gateway_paired']), 'pairing detection persists the connection flag');

unset($GLOBALS['test_options']['oai_pair_credential_' . str_repeat('a', 64)]);
$reset();
check(!OAI_Gateway_Pairing::is_connected(), 'site without credentials is not marked as paired');
check(OAI_Quota::is_engineering_access(), 'local-owner mode is restored after the last credential is removed');

echo "All quota authority regression tests passed.\n";
