<?php
/**
 * Adversarial gap reproducer for OAI_Elementor_Schema_Save (Issue #3, QA pass 1).
 *
 * Isolated: no WordPress, no MySQL, no Elementor, no network.
 * It documents what the staged schema/save boundary does *today*.
 *
 * Two kinds of assertions:
 *   - GOOD  : behaviour that must stay safe (a failure here is a real regression -> exit 1)
 *   - GAP-n : currently unsafe behaviour that is reproduced on purpose (reported, not failed)
 *
 * When Issue #2 closes a gap, the matching GAP assertion must be inverted or removed.
 *
 * Usage: php tests/elementor-schema-save-gap-repro.php
 */

define('ABSPATH', __DIR__ . '/');

/* ---------------------------------------------------------------- stubs --- */

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code, $message = '', $data = array())
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_slash($value) { return $value; }

$GLOBALS['oai_posts'] = array();
$GLOBALS['oai_meta'] = array();
$GLOBALS['oai_cap_calls'] = 0;
$GLOBALS['oai_meta_filter'] = null;

function get_post_meta($post_id, $key, $single = true)
{
    $post_id = (int) $post_id;
    if (!isset($GLOBALS['oai_meta'][$post_id][$key])) {
        return $single ? '' : array();
    }
    return $GLOBALS['oai_meta'][$post_id][$key];
}

function update_post_meta($post_id, $key, $value)
{
    $post_id = (int) $post_id;
    if (is_callable($GLOBALS['oai_meta_filter'])) {
        $value = call_user_func($GLOBALS['oai_meta_filter'], $value, $key, $post_id);
    }
    $GLOBALS['oai_meta'][$post_id][$key] = $value;
    return true;
}

function get_post_field($field, $post_id)
{
    $post_id = (int) $post_id;
    return isset($GLOBALS['oai_posts'][$post_id][$field]) ? $GLOBALS['oai_posts'][$post_id][$field] : '';
}

function get_post($post_id)
{
    $post_id = (int) $post_id;
    return isset($GLOBALS['oai_posts'][$post_id]) ? (object) $GLOBALS['oai_posts'][$post_id] : null;
}

function wp_update_post($data, $wp_error = false)
{
    $post_id = (int) ($data['ID'] ?? 0);
    foreach ($data as $key => $value) {
        if ($key !== 'ID') {
            $GLOBALS['oai_posts'][$post_id][$key] = $value;
        }
    }
    return $post_id;
}

function clean_post_cache($post_id) {}
function wp_cache_delete($key, $group = '') {}
function do_action(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($tag, $value) { return $value; }
function current_user_can($capability) { $GLOBALS['oai_cap_calls']++; return true; }
function get_current_user_id() { return 1; }

require dirname(__DIR__) . '/includes/class-oai-elementor.php';
require dirname(__DIR__) . '/includes/class-oai-elementor-schema-save.php';

function digest($tree)
{
    // PR #6 introduced a canonical document digest (associative key order is
    // irrelevant, list order and scalar types are significant). On older builds
    // only the raw JSON hash exists.
    if (method_exists('OAI_Elementor_Schema_Save', 'tree_hash')) {
        return OAI_Elementor_Schema_Save::tree_hash($tree);
    }
    return hash('sha256', wp_json_encode($tree));
}


/* ------------------------------------------------------------- fixtures --- */

/**
 * Seed a post with Elementor data. $ordered controls key insertion order so the
 * same logical document can be produced with two different array orders.
 */
function seed_post($post_id, array $tree, $author = 1, $status = 'draft')
{
    $GLOBALS['oai_posts'][$post_id] = array(
        'post_content' => '',
        'post_author' => $author,
        'post_status' => $status,
        'post_title' => 'Elementor fixture',
    );
    $GLOBALS['oai_meta'][$post_id] = array(
        '_elementor_data' => wp_json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
}

function stored_tree($post_id)
{
    $raw = $GLOBALS['oai_meta'][(int) $post_id]['_elementor_data'] ?? '';
    return json_decode((string) $raw, true);
}

function tree_with_html($html)
{
    return array(
        array(
            'id' => 'container1',
            'elType' => 'container',
            'settings' => array('flex_direction' => 'column'),
            'elements' => array(
                array(
                    'id' => 'widget1',
                    'elType' => 'widget',
                    'widgetType' => 'html',
                    'settings' => array('html' => $html),
                ),
            ),
        ),
    );
}

/* ---------------------------------------------------------------- harness --- */

$good = 0;
$good_fail = 0;
$gaps = array();

function good($condition, $label)
{
    global $good, $good_fail;
    if ($condition) {
        $good++;
        echo "  GOOD ok    {$label}\n";
        return;
    }
    $good_fail++;
    echo "  GOOD FAIL  {$label}\n";
}

function gap($id, $severity, $label, $reproduced)
{
    global $gaps;
    $gaps[] = array($id, $severity, $label, $reproduced);
    printf("  %-6s %-6s %s%s\n", $id, $severity, $label, $reproduced ? '' : '  [NOT REPRODUCED]');
}

function error_code($value)
{
    return is_wp_error($value) ? $value->get_error_code() : '(no error)';
}

echo "== OAI_Elementor_Schema_Save gap reproducer ==\n\n";

/* --- 1. sanctioned boundaries that must keep working -------------------- */

echo "[1] sanctioned boundaries\n";
seed_post(101, tree_with_html('<p>one</p>'));
$one = tree_with_html('<p>one</p>');
$two = tree_with_html('<p>two</p>');

$prepared = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
good(is_array($prepared) && isset($prepared['after_sha256']), 'validate_replace prepares an ordered change');

$result = OAI_Elementor_Schema_Save::apply_approved(101, $prepared, false);
good(error_code($result) === 'oai_approval_required', 'apply without the approval flag is refused');
good(stored_tree(101) === $one, 'refused apply did not touch the document');

$stale = array(
    'before' => $one,
    'after' => $two,
    'before_sha256' => str_repeat('0', 64),
    'after_sha256' => digest($two),
);
$result = OAI_Elementor_Schema_Save::apply_approved(101, $stale, true);
good(in_array(error_code($result), array('oai_stale_target', 'oai_change_integrity', 'oai_invalid_change'), true), 'wrong snapshot hash is refused (stale or integrity)');
good(stored_tree(101) === $one, 'stale apply did not touch the document');

$result = OAI_Elementor_Schema_Save::validate_replace($one, $one, digest($one));
good(error_code($result) === 'oai_noop', 'identical replacement is refused as a no-op');

seed_post(102, tree_with_html('<p>one</p>'));
$change = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
OAI_Elementor_Schema_Save::apply_approved(102, $change, true);
$replay = OAI_Elementor_Schema_Save::apply_approved(102, $change, true);
good(error_code($replay) === 'oai_stale_target', 'replaying an applied change is refused (no double write)');

echo "\n[2] staged gaps\n";

/* --- GAP-1: approved payload is not bound to the applied payload -------- */

seed_post(110, tree_with_html('<p>one</p>'));
$evil = tree_with_html('<script>document.location="https://attacker.example"</script>');
$approved = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
$substituted = array(
    'before' => $approved['before'],
    'after' => $evil,
    'before_sha256' => $approved['before_sha256'],
    'after_sha256' => $approved['after_sha256'],
);
$result = OAI_Elementor_Schema_Save::apply_approved(110, $substituted, true);
gap(
    'GAP-1',
    'HIGH',
    'apply_approved ignores after_sha256: a payload different from the prepared/approved diff is persisted',
    $result === true && stored_tree(110) === $evil
);

/* --- GAP-2: rollback target is not bound to the immutable original ------ */

seed_post(111, tree_with_html('<p>two</p>'));
$forged_rollback = array(
    'before' => $evil,
    'after' => tree_with_html('<p>two</p>'),
);
$result = OAI_Elementor_Schema_Save::rollback_approved(111, $forged_rollback, true);
gap(
    'GAP-2a',
    'HIGH',
    'rollback_approved accepts a caller-supplied "before" instead of the stored original snapshot',
    $result === true && stored_tree(111) === $evil
);

// Residual case: PR #6 adds self-consistent hash validation, which proves
// integrity but not provenance. A caller can still forge a complete pair.
seed_post(118, tree_with_html('<p>two</p>'));
$forged_bound = array(
    'before' => $evil,
    'after' => tree_with_html('<p>two</p>'),
    'before_sha256' => digest($evil),
    'after_sha256' => digest(tree_with_html('<p>two</p>')),
);
$result = OAI_Elementor_Schema_Save::rollback_approved(118, $forged_bound, true);
gap(
    'GAP-2b',
    'HIGH',
    'self-consistent forged rollback pair still restores attacker content (integrity without provenance)',
    $result === true && stored_tree(118) === $evil
);

/* --- GAP-3: authorization is a caller-supplied boolean ------------------ */

seed_post(112, tree_with_html('<p>one</p>'));
$GLOBALS['oai_cap_calls'] = 0;
$change = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
$result = OAI_Elementor_Schema_Save::apply_approved(112, $change, true);
gap(
    'GAP-3',
    'HIGH',
    'apply_approved writes with only approved=true and performs no capability/user/owner/site check',
    $result === true && $GLOBALS['oai_cap_calls'] === 0 && stored_tree(112) === $two
);

/* --- GAP-4: success can be reported while the approved tree did not land - */

seed_post(113, tree_with_html('<p>one</p>'));
$GLOBALS['oai_meta_filter'] = static function ($value, $key) {
    if ($key === '_elementor_data') {
        // Simulates a third-party update_post_metadata filter normalising the payload.
        return '[{"id":"container1","elType":"container","settings":[],"elements":[]}]';
    }
    return $value;
};
$change = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
$result = OAI_Elementor_Schema_Save::apply_approved(113, $change, true);
$GLOBALS['oai_meta_filter'] = null;
$verify = OAI_Elementor_Schema_Save::verify(113, $two);
gap(
    'GAP-4',
    'MEDIUM',
    'apply_approved returns success without an authoritative post-write compare (save_document only checks non-empty)',
    $result === true && stored_tree(113) !== $two && is_array($verify) && $verify['ok'] === false
);

/* --- GAP-5: strict tree compare is key-order sensitive ------------------ */

$ordered_before = array(array('id' => 'container1', 'elType' => 'container', 'settings' => array('flex_direction' => 'column'), 'elements' => array()));
$reordered_before = array(array('elType' => 'container', 'id' => 'container1', 'elements' => array(), 'settings' => array('flex_direction' => 'column')));
seed_post(114, $ordered_before);
$change = array(
    'before' => $reordered_before,
    'after' => $two,
    'before_sha256' => digest($ordered_before),
    'after_sha256' => digest($two),
);
$result = OAI_Elementor_Schema_Save::apply_approved(114, $change, true);
gap(
    'GAP-5',
    'MEDIUM',
    'identical document with different array key order is refused as a false conflict (stale or integrity)',
    in_array(error_code($result), array('oai_stale_target', 'oai_change_integrity'), true)
);

/* --- GAP-6: read paths are not capability or ownership scoped ---------- */

seed_post(115, tree_with_html('<p>private</p>'), 99, 'private');
$GLOBALS['oai_cap_calls'] = 0;
$snapshot = OAI_Elementor_Schema_Save::snapshot(115);
gap(
    'GAP-6',
    'MEDIUM',
    'snapshot() returns private content of another author and performs no capability check',
    is_array($snapshot) && isset($snapshot['tree']) && $GLOBALS['oai_cap_calls'] === 0
);

/* --- GAP-7: no receipt/proof/operation record is emitted --------------- */

$keys = array_keys($GLOBALS['oai_meta'][112] ?? array());
$operation_keys = array_values(array_filter($keys, static function ($key) {
    return strpos($key, 'oai_operation') === 0 || strpos($key, '_oai_receipt') === 0 || strpos($key, '_oai_proof') === 0;
}));
gap(
    'GAP-7',
    'MEDIUM',
    'apply_approved persists no operation id, receipt or proof reference',
    $operation_keys === array()
);

/* --- GAP-8: hash guard casts an array to string instead of validating --- */

seed_post(116, tree_with_html('<p>one</p>'));
$warning = null;
set_error_handler(static function ($severity, $message) use (&$warning) {
    $warning = $message;
    return true;
});
$bad_change = array('before' => $one, 'after' => $two, 'before_sha256' => array('not-a-hash'));
$result = OAI_Elementor_Schema_Save::apply_approved(116, $bad_change, true);
restore_error_handler();
gap(
    'GAP-8',
    'LOW',
    'non-string before_sha256 raises a PHP "Array to string conversion" warning instead of a typed rejection',
    $warning !== null && strpos((string) $warning, 'Array to string') !== false
);

/* --- GAP-9: check-then-act between two concurrent applies -------------- */

seed_post(117, tree_with_html('<p>one</p>'));
$change = OAI_Elementor_Schema_Save::validate_replace($one, $two, digest($one));
$first = OAI_Elementor_Schema_Save::apply_approved(117, $change, true);
$second = OAI_Elementor_Schema_Save::apply_approved(117, $change, true);
gap(
    'GAP-9',
    'MEDIUM',
    'stateless snapshot check + save offers no lease/lock; only the second read happens to fail',
    $first === true && error_code($second) === 'oai_stale_target'
);

/* ------------------------------------------------------------------ report --- */

echo "\n== summary ==\n";
printf("safe assertions: %d passed, %d failed\n", $good, $good_fail);
$reproduced = 0;
foreach ($gaps as $entry) {
    printf("%-6s %-6s %s\n", $entry[0], $entry[1], $entry[3] ? 'reproduced' : 'NOT reproduced');
    if ($entry[3]) {
        $reproduced++;
    }
}
printf("gaps documented: %d, reproduced on this build: %d\n", count($gaps), $reproduced);
echo "\nThis file records current behaviour. GAP rows must be inverted when fixed.\n";

exit($good_fail === 0 ? 0 : 1);
