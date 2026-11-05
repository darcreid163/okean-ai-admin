<?php
/**
 * Disposable real-WordPress acceptance for a classic (non-block) theme.
 *
 * CI installs and activates Twenty Twenty-One before this file runs. The test
 * covers the classic-theme surface of the plugin only: theme reads, saved
 * customizer values, classic nav-menu locations, classic post content,
 * internal HTML rendering, child/theme file scope and builder detection.
 * It creates a draft page and a temporary menu, then removes them and restores
 * the original theme mods. No publishing, no network access.
 */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))
 throw new RuntimeException('Disposable localhost WordPress required');
final class ClassicThemeFixture {public static int $checks=0;}
function ctAssert($ok,$why){++ClassicThemeFixture::$checks;if(!$ok)throw new RuntimeException($why);}
function ctOk($value,$why){ctAssert(!is_wp_error($value),$why.(is_wp_error($value)?': '.$value->get_error_code():''));return $value;}
function ctReq($params){$req=new WP_REST_Request('GET','/okean-ai/v1/classic-theme-ci');foreach($params as $key=>$value)$req->set_param($key,$value);return $req;}

wp_set_current_user(1);
ctAssert(current_user_can('manage_options')&&current_user_can('edit_theme_options'),'Administrator capabilities missing');

/* 1. The active theme must really be a classic theme. */
$theme=wp_get_theme();
ctAssert(method_exists($theme,'is_block_theme')&&$theme->is_block_theme()===false,'Active theme is not a classic theme: '.$theme->get_stylesheet());
ctAssert(get_stylesheet()==='twentytwentyone','Unexpected active classic theme: '.get_stylesheet());
$locations=get_registered_nav_menus();
ctAssert(is_array($locations)&&isset($locations['primary']),'Classic theme did not register a primary menu location');

/* 2. Theme discovery reports the active classic theme without leakage. */
$themes=OAI_Read::themes();
ctAssert(is_array($themes)&&!empty($themes),'Theme list unavailable');
$active_rows=array_values(array_filter($themes,static function($row){return !empty($row['active']);}));
ctAssert(count($active_rows)===1&&$active_rows[0]['stylesheet']==='twentytwentyone','Active theme not reported once');
ctAssert(!isset($active_rows[0]['template'])||is_string($active_rows[0]['template']),'Theme template is not a string');

/* 3. Saved customizer values keep their types and truncate oversized values. */
$original_mods=get_theme_mods();$original_mods=is_array($original_mods)?$original_mods:[];
set_theme_mod('oai_ci_classic_flag',true);
set_theme_mod('oai_ci_classic_count',17);
set_theme_mod('oai_ci_classic_text','classic-theme-fixture');
try{
 $boost=static function($name){$req=new WP_REST_Request('GET','/okean-ai/v1/read/theme-mods');$req->set_param('name',$name);return OAI_Theme_Mods::get($req);};
 $flag=ctOk($boost('oai_ci_classic_flag'),'Read boolean theme mod');
 ctAssert($flag['exists']===true&&$flag['value']===true&&$flag['theme']==='twentytwentyone','Boolean theme mod type lost');
 $count=ctOk($boost('oai_ci_classic_count'),'Read integer theme mod');
 ctAssert($count['value']===17,'Integer theme mod type lost');
 $missing=ctOk($boost('oai_ci_absent_mod'),'Read absent theme mod');
 ctAssert($missing['exists']===false&&$missing['value']===null,'Absent theme mod invented a value');
 ctAssert(is_wp_error($boost('bad name!')),'Invalid theme mod name accepted');
 $oversized=str_repeat('a',5000);
 set_theme_mod('oai_ci_classic_large',$oversized);
 $large=ctOk($boost('oai_ci_classic_large'),'Read oversized theme mod');
 $expected_json=wp_json_encode($oversized);
 ctAssert(is_array($large['value'])&&$large['value']['truncated']===true&&$large['value']['type']==='string'
  &&$large['value']['bytes']===strlen($expected_json)&&$large['value']['sha256']===hash('sha256',$expected_json),
  'Oversized theme mod was not bounded deterministically');
 $list=ctOk(OAI_Theme_Mods::get(new WP_REST_Request('GET','/okean-ai/v1/read/theme-mods')),'List theme mods');
 ctAssert($list['theme']==='twentytwentyone'&&$list['count']>=$list['returned']&&isset($list['mods']['oai_ci_classic_count']),'Theme mod list is incomplete');
 $route=rest_do_request(new WP_REST_Request('GET','/okean-ai/v1/read/theme-mods'));
 ctAssert($route->get_status()===200&&($route->get_data()['theme']??'')==='twentytwentyone','Theme mod REST route failed');
}finally{
 remove_theme_mod('oai_ci_classic_large');remove_theme_mod('oai_ci_classic_flag');remove_theme_mod('oai_ci_classic_count');remove_theme_mod('oai_ci_classic_text');
}

/* 4. Classic menu location assignment runs through the full lifecycle. */
ctOk(OAI_License::activate_local_owner(1),'Activate disposable owner license');
ctOk(OAI_AI_Lifecycle_Control::enable(1),'Enable AI lifecycle control');
$menu_name='WP AI Classic Menu '.substr(str_replace('-','',wp_generate_uuid4()),0,8);
$menu_id=0;$item_id=0;$location_before=(int)(get_theme_mod('nav_menu_locations',[])[$locations['primary']]??0);
try{
 $created=ctOk(OAI_P0::menu_create(['name'=>$menu_name,'idempotency_key'=>'classic-menu-'.bin2hex(random_bytes(8))]),'Create classic menu');
 $menu_id=(int)$created['target'];
 ctAssert($menu_id>0&&wp_get_nav_menu_object($menu_id),'Created classic menu is missing');
 $item=ctOk(OAI_P0::menu_add_item(['menu_id'=>$menu_id,'title'=>'Classic item','url'=>'/classic-item','idempotency_key'=>'classic-item-'.bin2hex(random_bytes(8))]),'Add classic menu item');
 $item_id=(int)$item['target'];
 $assign=ctOk(OAI_P0::menu_assign_location(['location'=>$locations['primary'],'menu_id'=>$menu_id]),'Plan classic menu location');
 $assign_id=(int)$assign['id'];
 ctAssert($assign['status']==='pending','Location assignment was not a pending sandbox');
 ctAssert((int)(get_theme_mod('nav_menu_locations',[])[$locations['primary']]??0)===$location_before,'Planning wrote the menu location');
 foreach(['approve','apply'] as $action)ctOk(OAI_AI_Lifecycle_Control::perform($assign_id,$action,'classic-theme-ci',1),'Location '.$action);
 ctAssert((int)(get_theme_mod('nav_menu_locations',[])[$locations['primary']]??0)===$menu_id,'Applied menu location is wrong');
 ctAssert(!empty(OAI_Verify::operation($assign_id)['verified']),'Location assignment did not verify');
 ctOk(OAI_AI_Lifecycle_Control::perform($assign_id,'rollback','classic-theme-ci',1),'Rollback menu location');
 ctAssert((int)(get_theme_mod('nav_menu_locations',[])[$locations['primary']]??0)===$location_before,'Rollback did not restore the previous location');
 ctAssert(!empty(OAI_Verify::operation($assign_id)['verified']),'Location rollback did not verify');
}finally{
 if($item_id)wp_delete_post($item_id,true);
 if($menu_id)wp_delete_nav_menu($menu_id);
}

/* 5. Classic post content reads, plans and applies without a builder. */
$marker='classic-theme-marker-'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
$page_id=0;
try{
 $page_id=(int)wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'WP AI Classic Draft','post_content'=>'<p class="classic-lead">'.$marker.'</p><!-- wp:paragraph --><p>Classic body</p><!-- /wp:paragraph -->'],true);
 ctAssert($page_id>0,'Could not create a classic draft page');
 $content=ctOk(OAI_Content::get($page_id),'Read classic content');
 ctAssert($content['type']==='page'&&$content['status']==='draft'&&strpos($content['content'],$marker)!==false,'Classic content read is wrong');
 $plan=ctOk(OAI_Content::patch($page_id,'post_content',$marker,$marker.'-patched'),'Plan classic content patch');
 ctAssert(strpos($plan['after'],$marker.'-patched')!==false&&strpos(get_post($page_id)->post_content,$marker.'-patched')===false,'Content planning wrote the post');
 ctOk(OAI_Content::apply_patch($page_id,'post_content',$plan['after']),'Apply classic content patch');
 ctAssert(strpos(get_post($page_id)->post_content,$marker.'-patched')!==false,'Content apply did not persist');
 $unmatched=OAI_Content::patch($page_id,'post_content','text that is not present','other');
 ctAssert(is_wp_error($unmatched)&&$unmatched->get_error_code()==='oai_match','Unmatched content patch was not refused');
 $blocks=OAI_Gutenberg::blocks($page_id);
 ctAssert(!is_wp_error($blocks),'Classic post block read failed');
 ctAssert(OAI_Builder_Manager::detect($page_id)===null,'Classic post was incorrectly detected as a builder page');
 $html=ctOk(OAI_HTML_Inspector::inspect(ctReq(['post_id'=>$page_id,'allow_draft'=>true,'include_html'=>true])),'Inspect classic draft HTML');
 ctAssert(($html['render_mode']??'')==='authenticated_draft_server_render','Draft inspection did not use the authenticated renderer');
 ctAssert(strpos((string)($html['html']??''),$marker)!==false,'Rendered classic content is missing from inspection');
}finally{
 if($page_id)wp_delete_post($page_id,true);
}

/* 6. Theme file scopes resolve to the active classic theme. */
$files=ctOk(OAI_Files::list_files('child_theme',''),'List active classic theme files');
ctAssert(!empty($files['items']),'Classic theme file listing is empty');
$style=ctOk(OAI_Files::read('child_theme','style.css'),'Read classic style.css');
ctAssert(!empty($style['sha256'])&&strpos($style['content'],'Theme Name')!==false,'Classic style.css read is not authoritative');
$outline=ctOk(OAI_Files::outline('child_theme','style.css',20),'Outline classic style.css');
ctAssert(($outline['sha256']??'')===$style['sha256'],'Outline hash disagrees with the read hash');
$found=ctOk(OAI_Files::search('child_theme','Theme Name','',5),'Search classic theme files');
ctAssert(!empty($found['items']),'Classic theme search found no style.css');

echo 'PASS classic theme acceptance: '.ClassicThemeFixture::$checks.' checks; theme=twentytwentyone, menu location, classic content, file scope'.PHP_EOL;
