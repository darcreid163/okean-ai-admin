<?php
// Disposable WordPress only. Uses the candidate's real QA lifecycle.
if(getenv('WPAI_DISPOSABLE_TEST')!=='1')throw new RuntimeException('Explicit disposable test environment required');
wp_set_current_user(1);
function qa_ok($x,$label){if(is_wp_error($x))throw new RuntimeException($label.': '.$x->get_error_code());return $x;}
$out=getenv('QA_OUTPUT')?:sys_get_temp_dir().'/wpai-qa37';wp_mkdir_p($out);
foreach(OAI_Read::post_types() as $type)if($type['supports']!==array_keys(get_all_post_type_supports($type['name'])))throw new RuntimeException('Post type support mismatch');
global $wpdb;
$wpdb->last_error='';OAI_DB::install();if($wpdb->last_error!=='')throw new RuntimeException('dbDelta install error: '.$wpdb->last_error);
$wpdb->last_error='';OAI_DB::install();if($wpdb->last_error!=='')throw new RuntimeException('dbDelta repeat install error: '.$wpdb->last_error);
foreach(array(OAI_DB::table()=>array('PRIMARY','operation_id','status','created_at'),OAI_DB::audit_table()=>array('PRIMARY','operation_id','event','created_at'),OAI_DB::quota_events_table()=>array('PRIMARY','created_at','channel')) as $table=>$expected){$actual=array_unique($wpdb->get_col('SHOW INDEX FROM '.$table,2));if(array_diff($expected,$actual))throw new RuntimeException('Missing indexes');}

qa_ok(OAI_License::activate_local_owner(1),'license');
$content=qa_ok(wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'QA37 isolated content','post_excerpt'=>'Baseline excerpt'),true),'content');
$elementor=qa_ok(wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'QA37 isolated Elementor'),true),'elementor');
$data=array(array('id'=>'a1b2c3d','elType'=>'container','settings'=>array('content_width'=>'full'),'elements'=>array(array('id'=>'f1e2d3c','elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>'<section><h1>QA37 baseline</h1><p>Unique baseline fragment.</p></section>'),'elements'=>array()),array('id'=>'h1e2d3c','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'QA37 heading','header_size'=>'h1'),'elements'=>array()))));
update_post_meta($elementor,'_elementor_data',wp_slash(wp_json_encode($data)));update_post_meta($elementor,'_elementor_edit_mode','builder');update_post_meta($elementor,'_elementor_version',ELEMENTOR_VERSION);
$p=new WC_Product_Simple();$p->set_name('QA37 isolated product');$p->set_status('draft');$p->set_catalog_visibility('hidden');$p->set_regular_price('19');$p->set_manage_stock(true);$p->set_stock_quantity(3);$product=$p->save();
$before=array('excerpt'=>get_post_field('post_excerpt',$content),'elementor'=>get_post_meta($elementor,'_elementor_data',true),'stock'=>wc_get_product($product)->get_stock_quantity());
$menus_before=get_terms(array('taxonomy'=>'nav_menu','hide_empty'=>false,'fields'=>'ids'));$locations_before=get_theme_mod('nav_menu_locations',array());
$qa_options_before=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wp_ai_admin_test_matrix_%' ORDER BY option_name");
$existing=get_posts(array('post_type'=>array('product','product_variation','page','attachment','nav_menu_item'),'post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
try{
 qa_ok(OAI_Test_Automation::enable(30,array($content,$elementor),array($product),1),'enable QA');
 $managed_ids=array_column(OAI_Test_Automation::status()['managed_fixtures'],'id');
 foreach($managed_ids as $managed_id)foreach(get_posts(array('post_type'=>'product_variation','post_parent'=>$managed_id,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids')) as $child)$managed_ids[]=$child;
 $self=OAI_Platform::self_test();if(!empty($self['failed']))throw new RuntimeException('Self test failed');file_put_contents($out.'/self-test.json',wp_json_encode($self,JSON_PRETTY_PRINT));
 $args=array('content_post_id'=>$content,'elementor_post_id'=>$elementor,'elementor_widget_id'=>'f1e2d3c','elementor_container_id'=>'a1b2c3d','product_id'=>$product,'include_p0'=>true,'include_file'=>false);
 $report=qa_ok(OAI_Regression_Runner::run($args,'qa37-isolated-client',1),'regression');
 file_put_contents($out.'/qa37-regression.json',wp_json_encode($report,JSON_PRETTY_PRINT));
 echo 'QA37 regression '.wp_json_encode($report['summary']).' baseline_restored='.wp_json_encode($report['baseline_restored'])."\n";
 foreach($report['scenarios'] as $s)echo $s['name'].' '.$s['status'].' '.wp_json_encode($s['error']??$s['reason']??'')."\n";
 $after=array('excerpt'=>get_post_field('post_excerpt',$content),'elementor'=>get_post_meta($elementor,'_elementor_data',true),'stock'=>wc_get_product($product)->get_stock_quantity());
 file_put_contents($out.'/qa37-snapshots.json',wp_json_encode(array('before'=>$before,'after'=>$after),JSON_PRETTY_PRINT));
 $raw_storage_equal=$before===$after;
 $before['elementor']=json_decode($before['elementor'],true);$after['elementor']=json_decode($after['elementor'],true);
 if($before!==$after)throw new RuntimeException('Decoded input fixture snapshot mismatch');
 $now=get_posts(array('post_type'=>array('product','product_variation','page','attachment','nav_menu_item'),'post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
 $extra=array_values(array_diff($now,$existing,$managed_ids,array(OAI_Test_Automation::managed_gutenberg_fixture_id())));file_put_contents($out.'/qa37-residue.json',wp_json_encode(array('extra_post_ids'=>$extra,'snapshots_equal'=>$before===$after,'raw_storage_equal'=>$raw_storage_equal),JSON_PRETTY_PRINT));
 if($extra)throw new RuntimeException('Regression left new post artifacts');
 if($report['status']!=='PASS')throw new RuntimeException('QA37 regression has failed scenarios');
 $matrix=qa_ok(OAI_Regression_Matrix::run($args,'qa37-isolated-client',1),'matrix');file_put_contents($out.'/qa37-matrix.json',wp_json_encode($matrix,JSON_PRETTY_PRINT));
 if(!empty($matrix['summary']['FAIL'])||!empty($matrix['summary']['BLOCKED'])||empty($matrix['baseline_restored']))throw new RuntimeException('Matrix failed or baseline not restored');
 $menus_after=get_terms(array('taxonomy'=>'nav_menu','hide_empty'=>false,'fields'=>'ids'));$qa_options_after=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wp_ai_admin_test_matrix_%' ORDER BY option_name");
 if($menus_before!==$menus_after||$locations_before!==get_theme_mod('nav_menu_locations',array())||$qa_options_before!==$qa_options_after)throw new RuntimeException('Menu/location/option residue');
 echo 'Matrix '.wp_json_encode($matrix['summary']??array())."\n";
 echo "PASS baseline values/types/HTML and no new post artifacts; JSON slash escaping may normalize\n";
}finally{OAI_Test_Automation::disable('isolated-run-finished',1);wp_delete_post($content,true);wp_delete_post($elementor,true);wp_delete_post($product,true);}
