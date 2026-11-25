<?php
declare(strict_types=1);

namespace Elementor {
    final class Plugin {
        public static $instance;
        public object $kits_manager;
        public function __construct() {
            $this->kits_manager = new class {
                public function get_active_kit(): object {
                    return new class {
                        public function get_id(): int { return 9; }
                        public function get_settings(): array {
                            return ['integration' => ['api_key' => 'elementor-global-key-should-not-leak', 'label' => 'Public global setting']];
                        }
                    };
                }
            };
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('ELEMENTOR_VERSION', 'test');

    final class WP_Error {
        public function __construct(private string $code, private string $message = '', private array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }

    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function absint($value): int { return abs((int)$value); }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
    function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
    function get_post_meta($id, $key, $single = true) { return $GLOBALS['elementor_meta'][(int)$id][$key] ?? ''; }
    function get_post($id) { return (int)$id === 42 ? (object)['ID'=>42,'post_type'=>'page','post_title'=>'Security fixture','post_name'=>'security-fixture','post_status'=>'draft','post_modified'=>'2026-11-25 10:00:00','post_excerpt'=>'','post_content'=>''] : null; }
    function get_the_title($post): string { return $post->post_title; }
    function get_permalink($post): string { return 'https://example.test/'.$post->post_name.'/'; }

    final class TestRequest implements \ArrayAccess {
        public function __construct(private array $params) {}
        public function get_param(string $name) { return $this->params[$name] ?? null; }
        public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset, $this->params); }
        public function offsetGet(mixed $offset): mixed { return $this->params[(string)$offset] ?? null; }
        public function offsetSet(mixed $offset, mixed $value): void { $this->params[(string)$offset] = $value; }
        public function offsetUnset(mixed $offset): void { unset($this->params[(string)$offset]); }
    }

    require dirname(__DIR__).'/includes/class-oai-read.php';
    require dirname(__DIR__).'/includes/class-oai-elementor.php';
    require dirname(__DIR__).'/includes/class-oai-rest.php';
    \Elementor\Plugin::$instance = new \Elementor\Plugin();

    $tree = [[
        'id'=>'container1',
        'elType'=>'container',
        'settings'=>['api_key'=>'elementor-container-key-should-not-leak','label'=>'Public container setting'],
        'elements'=>[[
            'id'=>'widget1',
            'elType'=>'widget',
            'widgetType'=>'html',
            'settings'=>['access_token'=>'elementor-widget-token-should-not-leak','label'=>'Public widget setting','html'=>'<p>Public HTML</p>'],
        ]],
    ]];
    $GLOBALS['elementor_meta'] = [42 => ['_elementor_data'=>wp_json_encode($tree),'_elementor_edit_mode'=>'builder','_elementor_template_type'=>'wp-page']];

    $checks = 0;
    $assert = static function(bool $condition, string $message) use (&$checks): void {
        ++$checks;
        if (!$condition) throw new RuntimeException($message);
    };

    $detail = \OAI_REST::content_detail(new TestRequest(['id'=>42]));
    $rootSettings = $detail['item']['elementor_data'][0]['settings'];
    $assert($rootSettings['api_key']==='[REDACTED]' && $rootSettings['label']==='Public container setting',
        'Content detail must redact nested Elementor API keys while preserving safe settings');
    $assert($detail['item']['elementor_data_redacted']===true,
        'Content detail must report that Elementor data was redacted');

    $widget = \OAI_REST::elementor_node(new TestRequest(['id'=>42,'widget_id'=>'widget1']));
    $assert($widget['settings']['access_token']==='[REDACTED]' && $widget['settings']['label']==='Public widget setting',
        'Elementor widget read must redact nested access tokens');
    $assert($widget['settings_redacted']===true && $widget['html']==='<p>Public HTML</p>',
        'Elementor widget read must report redaction and preserve public HTML');

    $find = \OAI_REST::elementor_find(new TestRequest(['id'=>42,'find'=>'access_token']));
    $assert(!str_contains(json_encode($find), 'elementor-widget-token-should-not-leak'),
        'Elementor search must not return secret values');

    $container = \OAI_Elementor::container(42,'container1');
    $assert($container['settings']['api_key']==='[REDACTED]' && $container['settings_redacted']===true,
        'Elementor container read must redact secrets');

    $globals = \OAI_Elementor::globals();
    $assert($globals['kit']['settings']['integration']['api_key']==='[REDACTED]' && $globals['redacted']===true,
        'Elementor global kit settings must redact nested secrets');
    $assert($globals['kit']['settings']['integration']['label']==='Public global setting',
        'Elementor global kit settings must preserve safe sibling values');

    echo "PASS {$checks} Elementor read-boundary secret-redaction assertions\n";
}
