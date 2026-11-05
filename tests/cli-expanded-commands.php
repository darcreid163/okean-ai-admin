<?php
/**
 * Tier B slice 1: expanded read-only WP-CLI command registry.
 *
 * Verifies command resolution for two- and three-token commands, argument and
 * flag validation, every new handler, redaction of credential-like meta, and
 * absence of PII in comment output. No WordPress, MySQL or network.
 *
 * Usage: php tests/cli-expanded-commands.php
 */

define('ABSPATH', __DIR__ . '/fixtures/');

class WP_Error
{
    private $code;
    public function __construct($code, $message = '', $data = array()) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return array(); }
}

function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function absint($v) { return abs((int) $v); }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function get_bloginfo($what) { return '7.0.6'; }

$GLOBALS['theme_mods'] = array(
    'custom_logo' => 12,
    'background_color' => '#ffffff',
    'custom_css' => 'body{color:red}',
    'nav_menu_locations' => array('primary' => 3),
);
$GLOBALS['posts'] = array(
    42 => array('ID' => 42, 'post_type' => 'page', 'post_status' => 'draft'),
);
$GLOBALS['post_meta'] = array(
    42 => array(
        '_thumbnail_id' => array('7'),
        '_wp_page_template' => array('default'),
        '_elementor_data' => array('[{"id":"a"}]'),
        '_wp_old_slug' => array('old-page'),
    ),
    43 => array(),
);
$GLOBALS['users'] = array(
    1 => array('ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.test'),
);
$GLOBALS['user_meta'] = array(
    1 => array(
        'nickname' => array('admin'),
        'session_tokens' => array('{"abc":"secret"}'),
        'api_key' => array('sk-should-not-leak'),
    ),
);

function get_site_transient($key) { return (object) array('updates' => array((object) array('current' => '7.1.3', 'locale' => 'en_US', 'php_version' => '8.3'))); }
function get_plugins() {
    return array(
        'akismet/akismet.php' => array('Name' => 'Akismet', 'Version' => '5.0', 'RequiresPHP' => '7.2', 'RequiresWP' => '6.0'),
        'woocommerce/woocommerce.php' => array('Name' => 'WooCommerce', 'Version' => '11.1.2', 'RequiresPHP' => '8.0', 'RequiresWP' => '6.7'),
    );
}
function is_plugin_active($file) { return $file === 'woocommerce/woocommerce.php'; }
function get_theme_mod($name, $default = false) { return $GLOBALS['theme_mods'][$name] ?? $default; }
function get_theme_mods() { return $GLOBALS['theme_mods']; }
function get_option($name, $default = false) { return $name === 'stylesheet' ? 'storefront' : $default; }
function get_post($id) { return isset($GLOBALS['posts'][(int) $id]) ? (object) $GLOBALS['posts'][(int) $id] : null; }
function metadata_exists($type, $id, $key) {
    if ($type === 'post') return isset($GLOBALS['post_meta'][(int) $id][$key]);
    if ($type === 'user') return isset($GLOBALS['user_meta'][(int) $id][$key]);
    return false;
}
function get_post_meta($id, $key = '', $single = false) {
    $id = (int) $id;
    $all = $GLOBALS['post_meta'][$id] ?? array();
    if ($key === '') return $all;
    if (!isset($all[$key])) return $single ? '' : array();
    return $single ? $all[$key][0] : $all[$key];
}
function get_user_by($field, $value) {
    if ($field !== 'id') return false;
    return isset($GLOBALS['users'][(int) $value]) ? (object) $GLOBALS['users'][(int) $value] : false;
}
function get_user_meta($id, $key = '', $single = false) {
    $all = $GLOBALS['user_meta'][(int) $id] ?? array();
    if ($key === '') return $all;
    if (!isset($all[$key])) return $single ? '' : array();
    return $single ? $all[$key][0] : $all[$key];
}
function get_post_types($args = array(), $output = 'names') {
    $objects = array(
        'page' => (object) array('public' => true, 'hierarchical' => true, 'has_archive' => false, 'labels' => (object) array('name' => 'Pages')),
        'product' => (object) array('public' => true, 'hierarchical' => false, 'has_archive' => true, 'labels' => (object) array('name' => 'Products')),
    );
    return $output === 'objects' ? $objects : array_keys($objects);
}
function get_taxonomies($args = array(), $output = 'names') {
    $objects = array(
        'category' => (object) array('public' => true, 'hierarchical' => true, 'object_type' => array('post'), 'labels' => (object) array('name' => 'Categories')),
        'product_cat' => (object) array('public' => true, 'hierarchical' => true, 'object_type' => array('product'), 'labels' => (object) array('name' => 'Product categories')),
    );
    return $output === 'objects' ? $objects : array_keys($objects);
}
function get_term($id, $taxonomy = '') {
    if ((int) $id !== 5) return null;
    return (object) array('term_id' => 5, 'taxonomy' => $taxonomy ?: 'category', 'name' => 'News', 'slug' => 'news', 'parent' => 0, 'count' => 4);
}
function get_comments($args) {
    return array(
        (object) array('comment_ID' => 10, 'comment_post_ID' => 42, 'comment_author' => 'Visitor', 'comment_author_email' => 'visitor@example.test', 'comment_author_IP' => '203.0.113.9', 'comment_approved' => '1', 'comment_date_gmt' => '2026-10-20 10:00:00', 'comment_content' => 'Nice page'),
    );
}
function wp_count_comments($post = 0) {
    return (object) array('approved' => 3, 'moderated' => 1, 'spam' => 0, 'trash' => 0, 'total_comments' => 4);
}
function wp_roles() {
    return (object) array('roles' => array(
        'administrator' => array('name' => 'Administrator', 'capabilities' => array('manage_options' => true, 'edit_posts' => true, 'activate_plugins' => false)),
        'editor' => array('name' => 'Editor', 'capabilities' => array('edit_posts' => true, 'publish_posts' => true)),
    ));
}
function wp_get_sidebars_widgets() {
    return array('sidebar-1' => array('text-1', 'search-2'), 'wp_inactive_widgets' => array());
}
function wp_get_nav_menu_items($menu) {
    if ((int) $menu !== 3) return false;
    return array((object) array('ID' => 21, 'title' => 'Home', 'type' => 'post_type', 'object' => 'page', 'menu_item_parent' => 0, 'menu_order' => 1, 'url' => 'https://example.test/'));
}
function wp_get_nav_menu_object($id) {
    return (is_numeric($id) && (int) $id === 3) || $id === 'main' ? (object) array('term_id' => 3) : false;
}

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $blogid = 1;
    public function get_col($sql) { return array('wp_posts', 'wp_postmeta', 'wp_options', 'wp_comments'); }
};

/** Only used by plan(); this test asserts the execution model, not permissions. */
class OAI_Permission_Resolver {
    public static function resolve($resolved) { return array('allowed' => true, 'capability' => 'edit_posts'); }
}

foreach (array('class-oai-argument-parser.php', 'class-oai-command-normalizer.php', 'class-oai-command-registry.php', 'class-oai-wp-cli-execution.php') as $file) {
    require dirname(__DIR__) . '/includes/' . $file;
}

/* ------------------------------------------------------------------ harness --- */

$checks = 0;
function check($ok, $message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
/** WordPress returns WP_Error instead of throwing, so both shapes are accepted. */
function rejects($fn, $code) {
    try {
        $result = $fn();
    } catch (WP_Error $e) {
        check($e->get_error_code() === $code, 'Expected ' . $code . ', got ' . $e->get_error_code());
        return;
    }
    if ($result instanceof WP_Error) {
        check($result->get_error_code() === $code, 'Expected ' . $code . ', got ' . $result->get_error_code());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function run($command) {
    $resolved = OAI_Command_Registry::resolve($command);
    if (is_wp_error($resolved)) return $resolved;
    return OAI_WP_CLI_Execution::execute($resolved);
}
function ok_result($command) {
    $r = run($command);
    if (is_wp_error($r)) throw new RuntimeException('Command failed: ' . $command . ' -> ' . $r->get_error_code());
    return $r;
}

/* --------------------------------------------------------------- resolution --- */

$r = OAI_Command_Registry::resolve('post meta get 42 _thumbnail_id');
check(!is_wp_error($r), 'three-token command must resolve');
check($r['canonical'] === 'post meta get', 'canonical key for three-token command');
check($r['args'] === array('42', '_thumbnail_id'), 'arguments must be sliced after the matched key');

$r = OAI_Command_Registry::resolve('post get 42');
check(!is_wp_error($r) && $r['canonical'] === 'post get' && $r['args'] === array('42'), 'two-token command regression');

$r = OAI_Command_Registry::resolve('core version');
check(!is_wp_error($r) && $r['canonical'] === 'core version', 'zero-arg command regression');

rejects(fn() => OAI_Command_Registry::resolve('post meta get 42'), 'oai_cli_args');
rejects(fn() => OAI_Command_Registry::resolve('post meta get 42 key --bogus=1'), 'oai_cli_unknown_flag');
rejects(fn() => OAI_Command_Registry::resolve('plugin get'), 'oai_cli_args');
rejects(fn() => OAI_Command_Registry::resolve('rm -rf /'), 'oai_cli_not_allowed');
rejects(fn() => OAI_Command_Registry::resolve('post list 42'), 'oai_cli_args');
rejects(fn() => OAI_Command_Registry::resolve('shell exec --cmd=ls'), 'oai_cli_not_allowed');

/* ------------------------------------------------------------------ handlers --- */

$r = ok_result('core check-update');
check($r['current'] === '7.0.6' && $r['offers_count'] === 1 && $r['network_call'] === false, 'core check-update is static');

$r = ok_result('plugin get woocommerce/woocommerce.php');
check($r['active'] === true && $r['version'] === '11.1.2', 'plugin get by file');
$r = ok_result('plugin get Akismet');
check($r['active'] === false && $r['plugin'] === 'akismet/akismet.php', 'plugin get by name');
rejects(fn() => run('plugin get nope/nope.php'), 'oai_plugin_not_found');

$r = ok_result('theme mod get custom_logo');
check($r['exists'] === true && $r['value'] === 12, 'theme mod get scalar');
$r = ok_result('theme mod get custom_css');
check($r['value'] === 'body{color:red}', 'theme mod get css');
$r = ok_result('theme mod get missing_mod');
check($r['exists'] === false && $r['value'] === null, 'theme mod get missing');
$r = ok_result('theme mod list');
check($r['count'] === 4 && $r['truncated'] === false, 'theme mod list');
check(isset($r['mods'][0]['name']) && !isset($r['mods'][0]['value']), 'theme mod list returns metadata only');

$r = ok_result('post meta get 42 _wp_page_template');
check($r['exists'] === true && $r['value'] === 'default' && $r['redacted'] === false, 'post meta get value');
$r = ok_result('post meta get 42 _wp_old_slug');
check($r['exists'] === true && $r['value'] === 'old-page', 'post meta get legacy key is not redacted');
rejects(fn() => run('post meta get 999 _thumbnail_id'), 'oai_post_not_found');
rejects(fn() => run('post meta get 42'), 'oai_cli_args');

$r = ok_result('post meta list 42');
check($r['count'] === 4 && $r['values_returned'] === false, 'post meta list returns keys only');
check(is_array($r['keys']) && isset($r['keys'][0]['key']), 'post meta list shape');

$r = ok_result('post-type list');
check(count($r) === 2 && $r[0]['name'] === 'page' && $r[1]['public'] === true, 'post-type list');
$r = ok_result('taxonomy list');
check(count($r) === 2 && $r[1]['objects'] === array('product'), 'taxonomy list');

$r = ok_result('term get category 5');
check($r['term_id'] === 5 && $r['slug'] === 'news' && $r['count'] === 4, 'term get');
rejects(fn() => run('term get category 6'), 'oai_term_not_found');

$r = ok_result('user meta get 1 nickname');
check($r['redacted'] === false && $r['value'] === 'admin', 'user meta get plain');
$r = ok_result('user meta get 1 session_tokens');
check($r['redacted'] === true && $r['value'] === null, 'session meta must be redacted');
$r = ok_result('user meta get 1 api_key');
check($r['redacted'] === true && $r['value'] === null, 'api key meta must be redacted');
rejects(fn() => run('user meta get 404 nickname'), 'oai_user_not_found');

$r = ok_result('user meta list 1');
check($r['count'] === 3 && $r['values_returned'] === false, 'user meta list returns keys only');
$redacted_keys = array_column($r['keys'], 'redacted', 'key');
check($redacted_keys['session_tokens'] === true && $redacted_keys['nickname'] === false, 'user meta list flags redacted keys');

$r = ok_result('comment list');
check(count($r) === 1, 'comment list count');
$row = $r[0];
foreach (array('email', 'author_email', 'ip', 'author_ip', 'url') as $forbidden) {
    check(!array_key_exists($forbidden, $row) && strpos(json_encode($row), 'visitor@example.test') === false && strpos(json_encode($row), '203.0.113.9') === false, 'comment list must not leak PII: ' . $forbidden);
}
check($row['content_length'] === 9, 'comment list content length');

$r = ok_result('comment count --post_id=42');
check($r['total'] === 4 && $r['counts']['approved'] === 3 && $r['pii_returned'] === false, 'comment count');

$r = ok_result('role list');
check(count($r) === 2 && $r[0]['cap_count'] === 3, 'role list');
$r = ok_result('cap list administrator');
check($r['count'] === 2 && in_array('manage_options', $r['capabilities'], true), 'cap list filters false capabilities');
rejects(fn() => run('cap list nonexistent'), 'oai_role_not_found');
$r = ok_result('cap list');
check(isset($r['roles']) && count($r['roles']) === 2, 'cap list without a role lists roles');

$r = ok_result('sidebar list');
check(count($r) === 2 && $r[0]['widget_count'] === 2, 'sidebar list');
$r = ok_result('widget list sidebar-1');
check($r['count'] === 2 && $r['widgets'] === array('text-1', 'search-2'), 'widget list one sidebar');
rejects(fn() => run('widget list nope'), 'oai_sidebar_not_found');
$r = ok_result('widget list');
check(count($r) === 2, 'widget list all sidebars');

$r = ok_result('menu item list 3');
check($r['count'] === 1 && $r['items'][0]['title'] === 'Home', 'menu item list by id');
$r = ok_result('menu item list main');
check($r['menu_id'] === 3, 'menu item list by name');
rejects(fn() => run('menu item list 99'), 'oai_menu_not_found');

$r = ok_result('db tables');
check($r['count'] === 4 && $r['tables'][0] === 'wp_posts' && $r['rows_returned'] === false, 'db tables');
$r = ok_result('db prefix');
check($r['prefix'] === 'wp_' && $r['base_prefix'] === 'wp_', 'db prefix');

/* ------------------------------ Abilities read-only bridge contract --- */
require_once dirname(__DIR__).'/includes/class-oai-abilities.php';
check(!is_wp_error(OAI_Command_Registry::resolve('ability list')), 'Ability catalog command must resolve');
check(!is_wp_error(OAI_Command_Registry::resolve('ability get example/read')), 'Ability info command must resolve');
rejects(fn() => OAI_Command_Registry::resolve('ability get'), 'oai_cli_args');
check(ok_result('ability list') === array(), 'Abilities fail closed if API is unavailable');
rejects(fn() => run('ability get example/read'), 'oai_ability_unavailable');
rejects(fn() => OAI_Command_Registry::resolve('ability run example/read'), 'oai_cli_not_allowed');

/* ------------------------------------------------------------------- catalog --- */

// Registry security contract: every canonical command has a stable name and
// a matching read/write execution policy before future MCP tool expansion.
$seenCanonical = array();
foreach (OAI_Command_Registry::all() as $command => $entry) {
    $canonical = implode(' ', array_filter(explode(' ', strtolower(trim($command))), 'strlen'));
    check($command === $canonical, 'noncanonical registry name: ' . $command);
    check(!isset($seenCanonical[$canonical]), 'duplicate canonical command: ' . $command);
    $seenCanonical[$canonical] = true;
    check(is_string($entry['handler'] ?? null) && $entry['handler'] !== '', 'missing handler: ' . $command);
    if (!empty($entry['read'])) {
        check(($entry['execution_mode'] ?? '') === 'read_only', 'read command with unsafe mode: ' . $command);
    } else {
        check(($entry['execution_mode'] ?? '') === 'plan_only' && !empty($entry['approval']),
            'write command without mandatory plan and approval: ' . $command);
    }
}

$audit = OAI_Command_Registry::handler_audit();
check($audit['ok'] === true, 'every read command must have a handler: ' . implode(',', $audit['missing_handlers']));
check($audit['registered'] >= 38, 'expanded catalog size: ' . $audit['registered']);
check(in_array('cache purge', $audit['planned_write_commands'], true), 'writes stay plan-only');
check($audit['write_commands_require_sandbox_lifecycle'] === true, 'writes must require the lifecycle');

$plan = OAI_WP_CLI_Execution::plan(array('canonical' => 'post create', 'handler' => 'post_create', 'args' => array(), 'flags' => array(), 'read' => false));
check($plan['execution_model'] === 'sandbox_then_approval' && $plan['executable_now'] === false, 'planner must refuse direct writes');

echo 'PASS ' . $checks . ' expanded CLI assertions; commands=' . $audit['registered'] . '; handlers=' . $audit['handlers'] . PHP_EOL;
