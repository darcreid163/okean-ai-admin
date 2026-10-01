<?php
// Reproduce the update path with an active, managed Gutenberg fixture.
// A save listener that is unavailable before wp_loaded must never be called
// during plugins_loaded; a later listener exception must not crash bootstrap.
define('ABSPATH',__DIR__.'/');
define('OAI_BUILD_ID','2.1.6-safe-upgrade-20261001');
$options=array();$hooks=array();$actions=array();$writes=0;$throw_on_save=false;
$fixture=(object)array('ID'=>7,'post_status'=>'draft');
$meta=array('_wpai_test_fixture'=>'gutenberg_regression','_wpai_test_session_id'=>'old-session');
function absint($v){return abs((int)$v);}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=null){global $options;$options[$k]=$v;return true;}
function did_action($k){global $actions;return $actions[$k]??0;}
function add_action($k,$callback,$priority=10){global $hooks;$hooks[$k][$priority][]=$callback;}
function do_action($k){global $actions,$hooks;$actions[$k]=($actions[$k]??0)+1;$group=$hooks[$k]??array();ksort($group);foreach($group as $callbacks)foreach($callbacks as $cb)call_user_func($cb);}
function get_post($id){global $fixture;return $id===7?$fixture:false;}
function get_post_meta($id,$k,$single=true){global $meta;return $id===7?($meta[$k]??''):'';}
function update_post_meta($id,$k,$v){global $meta;$meta[$k]=$v;return true;}
function delete_post_meta($id,$k){global $meta;unset($meta[$k]);return true;}
function wp_update_post($data,$wp_error=false){global $writes,$throw_on_save;if(!did_action('wp_loaded'))throw new Error('Post saved before dependent plugins initialized');$writes++;if($throw_on_save)throw new Error('Simulated third-party save listener failure');return $data['ID'];}
function wp_untrash_post($id){return $id;}
function is_wp_error($value){return false;}
function user_can($id,$cap){return true;}
function expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
require getenv('OAI_TEST_IMPLEMENTATION')?:dirname(__DIR__).'/includes/class-oai-test-automation.php';
function seed_old_session(){global $options;$options[OAI_Test_Automation::OPTION]=array('enabled'=>true,'session_id'=>'old-session','managed_gutenberg_fixture_id'=>7,'build_id'=>'2.1.4-old','owner_user_id'=>1,'expires_at'=>time()+600);}
seed_old_session();
expect(OAI_Test_Automation::reset_stale_build()===true,'old build must reset');
expect($writes===0,'upgrade must not save a post during plugins_loaded');
$cfg=get_option(OAI_Test_Automation::OPTION);
expect($cfg['enabled']===false && $cfg['build_id']===OAI_BUILD_ID,'permissions must be disabled before deferred cleanup');
expect(OAI_Test_Automation::is_active()===false,'old session cannot remain authorized');
expect(count($hooks['wp_loaded'][99])===1,'one deferred cleanup hook');
do_action('wp_loaded');
expect($writes===1,'fixture releases after wp_loaded');
expect(!isset($meta['_wpai_test_session_id']),'old fixture session tag must be removed');
expect(OAI_Test_Automation::reset_stale_build()===false,'matching build must not reset again');
OAI_Test_Automation::release_pending_fixtures();
expect($writes===1,'deferred queue must not replay');
seed_old_session();$meta['_wpai_test_session_id']='old-session';$throw_on_save=true;
expect(OAI_Test_Automation::reset_for_build_change()===true,'listener exception must be contained');
expect(get_option(OAI_Test_Automation::OPTION)['enabled']===false,'permissions must remain disabled after cleanup failure');
expect($meta['_wpai_test_session_id']==='old-session','failed cleanup must not claim tag removal');
$throw_on_save=false;seed_old_session();$meta['_wpai_test_session_id']='new-session';$previous=$writes;
expect(OAI_Test_Automation::reset_for_build_change()===true,'session mismatch still resets permission');
expect($writes===$previous && $meta['_wpai_test_session_id']==='new-session','stale cleanup must preserve a newer fixture session');
$fixture->post_status='publish';seed_old_session();$meta['_wpai_test_session_id']='old-session';
OAI_Test_Automation::reset_for_build_change();
expect($writes===$previous,'published fixture must not be rewritten');
echo "Upgrade bootstrap safety tests passed\n";
