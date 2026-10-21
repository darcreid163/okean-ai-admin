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
 'invalid'=>new FixtureAbility('BAD FORMAT',['public'=>true]),
];
$list=OAI_Abilities::discover();
must(count($list)===2,'private or invalid Ability disclosed');
must($list[0]['name']==='older/read' && $list[1]['name']==='public/read','unstable sort');
must($list[1]['execution']==='disabled_until_explicit_policy','execution enabled by discovery');
must(OAI_Abilities::info('private/danger') instanceof WP_Error,'private ability accessible');
must(OAI_Abilities::info('public/read')['name']==='public/read','public ability not discoverable');
$GLOBALS['is_admin']=false;
must(OAI_Abilities::discover()===[],'non-admin leaked');
must(OAI_Abilities::info('public/read') instanceof WP_Error,'non-admin info leaked');
echo "PASS Abilities public-opt-in, metadata, ACL and non-execution contract\n";
