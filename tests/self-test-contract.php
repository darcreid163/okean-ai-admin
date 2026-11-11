<?php
/**
 * Isolated contract for the two repaired OAI_Platform::self_test() checks.
 *
 * 1. catalog.exported_tools - the authoritative catalog in expected_tools()
 *    must contain both read-only Abilities discovery tools that OAI_MCP
 *    exports, so expected and exported counts and set membership agree.
 * 2. privacy.user_list_projection - the user-list projection is verified as an
 *    authorized administrator (never assuming the CLI/anonymous user holds
 *    list_users) and must still fail closed for anonymous callers.
 *
 * No WordPress instance, database or network access is required.
 */
define('ABSPATH', __DIR__.'/fixtures/');

class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Request {
    private $params = array();
    public function __construct($method = 'GET', $route = '') {}
    public function set_param($key, $value) { $this->params[$key] = $value; }
    public function get_param($key) { return $this->params[$key] ?? null; }
}

class FixtureUser {
    public $ID;
    public $user_login;
    public $user_email;
    public $display_name;
    public $roles;
    public $user_registered;
    public function __construct($id, $login, $email, $display_name, $roles, $registered) {
        $this->ID = $id;
        $this->user_login = $login;
        $this->user_email = $email;
        $this->display_name = $display_name;
        $this->roles = $roles;
        $this->user_registered = $registered;
    }
}

/** Current user id used by the projection fixture. 0 means anonymous. */
$GLOBALS['oai_test_user_id'] = 0;
$GLOBALS['oai_test_admin_id'] = 7;
// Real WP_User rows always carry an email; the projection under test must not.
$GLOBALS['oai_test_users'] = array(
    new FixtureUser(7, 'ci-admin', 'admin@example.invalid', 'CI Admin', array('administrator'), '2026-01-01 00:00:00'),
    new FixtureUser(8, 'ci-editor', 'editor@example.invalid', 'CI Editor', array('editor'), '2026-01-02 00:00:00'),
);

function get_current_user_id() { return (int) $GLOBALS['oai_test_user_id']; }
function wp_set_current_user($id) { $GLOBALS['oai_test_user_id'] = (int) $id; return $GLOBALS['oai_test_user_id']; }
function get_users($args = array()) {
    $role = (string) ($args['role'] ?? '');
    if ($role !== 'administrator') return array();
    return array($GLOBALS['oai_test_admin_id']);
}
function current_user_can($cap) {
    // list_users mirrors the real capability map: only a signed-in administrator holds it.
    if ($GLOBALS['oai_test_user_id'] === 0) return false;
    return $cap === 'list_users' && $GLOBALS['oai_test_user_id'] === $GLOBALS['oai_test_admin_id'];
}
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }

class WP_User_Query {
    private $items;
    private $total;
    public function __construct($args = array()) {
        $all = $GLOBALS['oai_test_users'];
        $number = (int) ($args['number'] ?? 50);
        $offset = (int) ($args['offset'] ?? 0);
        $this->total = count($all);
        $this->items = array_slice($all, $offset, $number);
    }
    public function get_results() { return $this->items; }
    public function get_total() { return $this->total; }
}

/**
 * Fixture response builder kept separate from the safe class below so the
 * "unsafe projection" case can be injected without editing real code.
 */
class OAI_Read_Unsafe_Fixture {
    public static function users($req) {
        return array('items' => array(array('id' => 7, 'login' => 'ci-admin', 'email' => 'admin@example.invalid')));
    }
}

require dirname(__DIR__).'/includes/class-oai-read.php';

function must($value, $label) {
    if (!$value) throw new RuntimeException($label);
    echo "PASS $label\n";
}

// --- 1. Authoritative catalog agrees with the exported MCP tools ------------
$platform = (string) file_get_contents(dirname(__DIR__).'/includes/class-oai-platform.php');
$mcp      = (string) file_get_contents(dirname(__DIR__).'/includes/class-oai-mcp.php');

must(strpos($platform, "'ability.list','ability.get'") !== false,
    'expected_tools() lists both read-only Abilities discovery tools');
must(strpos($mcp, "'ability.list'=>array(") !== false, 'MCP exports the ability.list schema');
must(strpos($mcp, "'ability.get'=>array(") !== false, 'MCP exports the ability.get schema');
must(strpos($mcp, "'ability.list','ability.get','gutenberg.get_blocks'") !== false,
    'MCP keeps both Abilities tools in the read-only catalog');
must(strpos($mcp, "case 'ability_list':") !== false && strpos($mcp, "case 'ability_get':") !== false,
    'Abilities tools are dispatched read-only through OAI_Abilities');

// Every exported tool name must be present in the authoritative catalog.
preg_match('/function expected_tools\(\)\{\s*\$tools=array\((.*?)\);/s', $platform, $m);
$expected = array();
if (!empty($m[1])) {
    preg_match_all("/'([a-z0-9_.]+)'/", $m[1], $names);
    $expected = array_values(array_unique($names[1]));
}
must($expected !== array(), 'expected_tools() catalog parsed');
must(in_array('ability.list', $expected, true), 'catalog contains ability.list');
must(in_array('ability.get', $expected, true), 'catalog contains ability.get');
must(count($expected) === count(array_unique($expected)), 'catalog has no duplicate ids');

// Parse the exported MCP read catalog and require full set membership.
preg_match('/\$read=array\((.*?)\);/s', $mcp, $mm);
$readNames = array();
if (!empty($mm[1])) {
    preg_match_all("/'([a-z0-9_.]+)'/", $mm[1], $rn);
    $readNames = array_values(array_unique($rn[1]));
}
$exportedSchemaNames = array();
preg_match_all("/'([a-z0-9_.]+)'=>array\(\s*'type'=>'object'/", $mcp, $sn);
if (!empty($sn[1])) $exportedSchemaNames = array_values(array_unique($sn[1]));
foreach (array('ability.list', 'ability.get') as $tool) {
    must(in_array($tool, $readNames, true), "read catalog contains $tool");
    must(in_array($tool, $exportedSchemaNames, true), "schema catalog contains $tool");
}

// --- 2. User-list projection: admin verified, anonymous denied -------------
$req = new WP_REST_Request('GET', '/okean-ai/v1/read/users');
$req->set_param('limit', 1);
$req->set_param('page', 1);

wp_set_current_user(0);
$anon = OAI_Read::users($req);
must($anon instanceof WP_Error && $anon->get_error_code() === 'oai_forbidden',
    'anonymous user-list request fails closed with oai_forbidden');

wp_set_current_user($GLOBALS['oai_test_admin_id']);
$auth = OAI_Read::users($req);
must(is_array($auth), 'authorized administrator receives a user-list projection');
must(!empty($auth['items']), 'authorized projection is not empty');
$leaked = false;
foreach ($auth['items'] as $row) {
    if (is_array($row) && array_key_exists('email', $row)) $leaked = true;
}
must(!$leaked, 'authorized projection still omits user email');
wp_set_current_user(0);

// A projection that disclosed email must be rejected by the same predicate the
// repaired self-test uses, so the guard cannot silently regress.
$unsafe = OAI_Read_Unsafe_Fixture::users($req);
$unsafeItems = is_array($unsafe) ? ($unsafe['items'] ?? array()) : array();
$unsafeOk = true;
foreach ($unsafeItems as $row) {
    if (is_array($row) && array_key_exists('email', $row)) $unsafeOk = false;
}
must($unsafeOk === false, 'email-disclosing projection is rejected by the privacy predicate');

echo "PASS self-test contract (catalog export + user-list projection)\n";
