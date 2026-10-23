<?php
/** Actual creators, SQL rows, audit and receipt on disposable WordPress only. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||(strpos(home_url(),'localhost')===false&&strpos(home_url(),'.test')===false))throw new RuntimeException('Disposable WordPress required');
function riskWPAssert($ok,$why){if(!$ok)throw new RuntimeException($why);}
function riskWPOk($v,$why){if(is_wp_error($v))throw new RuntimeException($why.': '.$v->get_error_code());return $v;}
function riskWPRequest($args){$r=new WP_REST_Request('POST','/okean-ai/v1/sandbox');foreach($args as $k=>$v)$r->set_param($k,$v);return $r;}
function riskWPRow($op,$risk){
 global $wpdb;
 $op=riskWPOk($op,'Create sandbox');$id=(int)$op['id'];$GLOBALS['riskWPOps'][]=$id;
 $row=OAI_DB::get($id);
 riskWPAssert($row['risk']===$risk&&$op['risk']===$risk&&$row['status']==='pending','Stored/public risk mismatch: '.$row['type']);
 riskWPAssert(OAI_Receipts::build($id)['risk']===$risk,'Receipt risk mismatch');
 $events=$wpdb->get_results($wpdb->prepare('SELECT details FROM '.OAI_DB::audit_table().' WHERE operation_db_id=%d',$id),ARRAY_A);
 riskWPAssert(count($events)>0,'Missing audit event');
 foreach($events as $event)riskWPAssert((json_decode($event['details'],true)['risk']??null)===$risk,'Audit risk mismatch');
 return $op;
}
wp_set_current_user(1);global $wpdb;
$riskWPOps=[];$posts=[];$menu=0;$file='';$workspace=null;
$option='wp_ai_admin_test_matrix_risk';$activeWorkspace=get_option('oai_active_workspace',null);
try{
 riskWPOk(OAI_License::activate_local_owner(1),'Activate disposable owner');
 riskWPOk(OAI_AI_Lifecycle_Control::enable(1),'Enable lifecycle');
 $content='<!-- wp:paragraph --><p>Risk fixture alpha</p><!-- /wp:paragraph -->';
 $post=riskWPOk(wp_insert_post(['post_title'=>'Risk fixture','post_type'=>'page','post_status'=>'draft','post_content'=>$content],true),'Create draft');$posts[]=$post;
 foreach(['post_title'=>['new title','low'],'post_excerpt'=>['new excerpt','low'],'post_content'=>[$content.' changed','medium']] as $type=>$change)riskWPRow(OAI_REST::sandbox_create(riskWPRequest(['type'=>$type,'target'=>(string)$post,'value'=>$change[0]])),$change[1]);
 riskWPRow(OAI_REST::content_patch(riskWPRequest(['post_id'=>$post,'field'=>'post_content','old_content'=>'alpha','new_content'=>'beta'])),'medium');
 riskWPRow(OAI_REST::gutenberg_patch(riskWPRequest(['post_id'=>$post,'selector'=>['block_name'=>'core/paragraph','text'=>'Risk fixture alpha'],'html'=>'<p>Risk fixture beta</p>'])),'medium');
 riskWPAssert(get_post_field('post_content',$post)===$content,'Planning wrote content');
 add_option($option,'before');
 $safe=riskWPRow(OAI_REST::sandbox_create(riskWPRequest(['type'=>'option_update','target'=>$option,'value'=>'after'])),'high');
 $count=OAI_DB::count_all();
 $denied=OAI_REST::sandbox_create(riskWPRequest(['type'=>'option_update','target'=>'siteurl','value'=>'https://forbidden.invalid']));
 riskWPAssert(is_wp_error($denied)&&$denied->get_error_code()==='oai_option_protected'&&OAI_DB::count_all()===$count,'Risk policy bypassed protected option guard');
 $product=new WC_Product_Simple();$product->set_status('draft');$product->set_name('Risk fixture product');$product->set_regular_price('12');$product->set_manage_stock(true);$product->set_stock_quantity(3);$pid=$product->save();$posts[]=$pid;
 riskWPRow(OAI_REST::sandbox_create(riskWPRequest(['type'=>'woo_product_price','target'=>(string)$pid,'value'=>'13'])),'medium');
 riskWPRow(OAI_REST::sandbox_create(riskWPRequest(['type'=>'woo_product_stock','target'=>(string)$pid,'value'=>['stock'=>4,'manage_stock'=>true,'stock_status'=>'instock']])),'medium');
 riskWPRow(OAI_REST::woocommerce_patch(riskWPRequest(['product_id'=>$pid,'changes'=>['regular_price'=>'14']])),'medium');
 $elementor=riskWPRow(OAI_REST::elementor_create_page(riskWPRequest(['title'=>'Risk fixture Elementor','final_status'=>'draft','data'=>[['id'=>'abc1234','elType'=>'container','settings'=>[],'elements'=>[['id'=>'def5678','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Risk heading'],'elements'=>[]]]]]])),'medium');
 $posts[]=(int)$elementor['preview_page_id'];
 $media=riskWPRow(OAI_P0::media_upload(['filename'=>'risk-fixture.png','content_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGPkiephYGBgYmBgYGBgAAALAgD2hsU36QAAAABJRU5ErkJggg==']),'medium');$attachment=(int)$media['target'];$posts[]=$attachment;
 riskWPRow(OAI_P0::media_update_alt(['attachment_id'=>$attachment,'alt'=>'risk fixture']),'medium');
 $menuOp=riskWPRow(OAI_P0::menu_create(['name'=>'Risk fixture '.wp_generate_uuid4()]),'medium');$menu=(int)$menuOp['target'];
 $name='wpai-disposable-risk.txt';$file=trailingslashit(get_stylesheet_directory()).$name;riskWPAssert(!file_exists($file),'File fixture already exists');file_put_contents($file,'before');
 riskWPRow(OAI_REST::sandbox_create(riskWPRequest(['type'=>'file_write','target'=>'child_theme:'.$name,'value'=>'after'])),'high');
 riskWPAssert(file_get_contents($file)==='before','Planning wrote live theme file');
 $workspace=riskWPOk(OAI_Workspace::create(false),'Create isolated workspace');
 riskWPRow(OAI_REST::workspace_write(riskWPRequest(['workspace_id'=>$workspace['id'],'path'=>$name,'content'=>'workspace after'])),'high');
 // Verify a corrected classification still uses the normal approved lifecycle.
 foreach(['approve','apply'] as $action)riskWPOk(OAI_AI_Lifecycle_Control::perform((int)$safe['id'],$action,'disposable-risk',1),'Safe option '.$action);
 riskWPAssert(!empty(riskWPOk(OAI_Verify::operation((int)$safe['id']),'Verify apply')['verified']),'Apply not verified');
 riskWPOk(OAI_AI_Lifecycle_Control::perform((int)$safe['id'],'rollback','disposable-risk',1),'Safe rollback');
 riskWPAssert(!empty(riskWPOk(OAI_Verify::operation((int)$safe['id']),'Verify rollback')['verified'])&&get_option($option)==='before','Rollback not restored');
 echo 'PASS: '.count($riskWPOps)." real generic/content/Gutenberg/Woo/Elementor/media/menu/file/workspace operations agree with SQL/audit/receipt risk; protected guard and approved lifecycle retained\n";
}finally{
 foreach($riskWPOps as $id){$wpdb->delete(OAI_DB::audit_table(),['operation_db_id'=>$id]);$wpdb->delete(OAI_DB::table(),['id'=>$id]);}
 foreach(array_reverse($posts) as $id){if(get_post_type($id)==='attachment')wp_delete_attachment($id,true);else wp_delete_post($id,true);}
 if($menu)wp_delete_nav_menu($menu);
 if($file&&is_file($file))unlink($file);
 if($workspace){$method=new ReflectionMethod('OAI_Workspace','remove_tree');$method->invoke(null,OAI_Workspace::root().'/'.$workspace['id']);}
 if($activeWorkspace===null)delete_option('oai_active_workspace');else update_option('oai_active_workspace',$activeWorkspace,false);
 delete_option($option);
 OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');OAI_License::deactivate_local_owner();
}
