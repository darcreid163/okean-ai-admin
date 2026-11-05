<?php
/** Isolated Abilities discovery contract: no WordPress instance or external tools. */
define('ABSPATH', __DIR__.'/fixtures/');
class WP_Error { public function __construct(public $code, public $message=''){} }
function current_user_can($cap){return !empty($GLOBALS['is_admin']) && $cap==='manage_options';}
function wp_get_abilities(){return $GLOBALS['abilities'];}
class FixtureAbility {
 public function __construct(private $name,private $meta){}
 public function get_name(){return $this->name;}
 public function get_meta(){return $this->meta;}
 public function get_label(){return 'Fixture';}
 public function get_description(){return 'Safe test ability';}
}
require dirname(__DIR__).'/includes/class-oai-abilities.php';
function must($value,$label){if(!$value)throw new RuntimeException($label);}
$GLOBALS['is_admin']=true;
$GLOBALS['abilities']=[
 'private/danger'=>new FixtureAbility('private/danger',[]),
 'public/read'=>new FixtureAbility('public/read',['mcp'=>['public'=>true]]),
 'older/read'=>new FixtureAbility('older/read',['public'=>true]),
 'rest/exposed'=>new FixtureAbility('rest/exposed',['show_in_rest'=>true]),
 'invalid'=>new FixtureAbility('BAD FORMAT',['public'=>true]),
];
$list=OAI_Abilities::discover();
must(count($list)===3,'private or invalid Ability disclosed');
must($list[0]['name']==='older/read' && $list[1]['name']==='public/read' && $list[2]['name']==='rest/exposed','unstable sort');
must($list[1]['execution']==='disabled_until_explicit_policy','execution enabled by discovery');
must(OAI_Abilities::info('private/danger') instanceof WP_Error,'private ability accessible');
must(OAI_Abilities::info('rest/exposed')['name']==='rest/exposed','WP 6.9 show_in_rest ability not discoverable');
must(OAI_Abilities::info('public/read')['name']==='public/read','public ability not discoverable');
$GLOBALS['is_admin']=false;
must(OAI_Abilities::discover()===[],'non-admin leaked');
must(OAI_Abilities::info('public/read') instanceof WP_Error,'non-admin info leaked');

// MCP adapter source contract: both tools are read-only, and arbitrary
// execution must never be exposed without explicit lifecycle authorization.
$mcp=(string)file_get_contents(dirname(__DIR__).'/includes/class-oai-mcp.php');
must(str_contains($mcp,"'ability.list'=>array("),'MCP list schema missing');
must(str_contains($mcp,"'ability.get'=>array("),'MCP get schema missing');
must(str_contains($mcp,"'ability.list','ability.get','gutenberg.get_blocks'"),'MCP read catalog missing');
must(str_contains($mcp,"case 'ability_list':"),'MCP list dispatcher missing');
must(str_contains($mcp,"case 'ability_get':"),'MCP get dispatcher missing');
must(!str_contains($mcp,"case 'ability_run':"),'Unreviewed Ability execute exposed');
echo "PASS Abilities public-opt-in, metadata, ACL and non-execution contract\n";
