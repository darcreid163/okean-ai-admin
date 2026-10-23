<?php
/** Offline regression for the shared classification and stored-risk audit. */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-oai-operations.php';
require dirname(__DIR__).'/includes/class-oai-operation-schema.php';
require dirname(__DIR__).'/includes/class-oai-audit.php';
function riskAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$expected=array(
 'woodmart_theme_setting'=>'high','option_update'=>'high','post_title'=>'low','post_excerpt'=>'low',
 'post_content'=>'medium','content_patch'=>'medium','workspace_write'=>'medium',
 'woo_product_price'=>'medium','woo_product_stock'=>'medium','woocommerce_product'=>'medium','woocommerce_patch'=>'medium','woocommerce_product_create'=>'medium',
 'elementor_widget_html'=>'medium','elementor_widget_settings'=>'medium','elementor_page_create'=>'medium','elementor_page_layout'=>'medium',
 'media_alt'=>'medium','media_title'=>'medium','media_featured'=>'medium','media_upload'=>'medium',
 'menu_create'=>'medium','menu_add_item'=>'medium','menu_remove_item'=>'medium','menu_move_item'=>'medium','menu_assign_location'=>'medium',
 'file_write'=>'high','theme_file_write'=>'high','workspace_file_write'=>'high'
);
foreach($expected as $type=>$risk)riskAssert(OAI_Operations::risk($type,'synthetic-target')===$risk,'Wrong risk: '.$type);
foreach(OAI_Operation_Schema::catalog() as $type=>$schema)riskAssert($schema['risk']===$expected[$type],'Catalog divergence: '.$type);
foreach(array('',null,123,array(),'POST_TITLE','post_title_extra','media_unknown','unknown_menu','future_type') as $type)riskAssert(OAI_Operations::risk($type,'post_title')==='high','Unknown type default is not conservative');
class OAI_DB {public static $audit;public static function audit_insert($row){self::$audit=$row;}}
function sanitize_key($v){return $v;}
function get_current_user_id(){return 1;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function current_time($type,$gmt){return '2026-10-23 12:00:00';}
// An old stored classification remains historical evidence, even if current
// policy differs. New audit events copy it; reads never silently reclassify it.
$row=array('id'=>1,'operation_id'=>'synthetic','type'=>'post_content','risk'=>'low');
OAI_Audit::log('verified',$row,array('verified'=>true));
$details=json_decode(OAI_DB::$audit['details'],true);
riskAssert($details['risk']==='low'&&$details['verified']===true&&$row['risk']==='low','Audit rewrote historical classification/evidence');
OAI_Audit::log('created',array_replace($row,array('risk'=>'medium')),array('risk'=>'low','fixture'=>true));
$details=json_decode(OAI_DB::$audit['details'],true);
riskAssert($details['risk']==='medium'&&$details['fixture']===true,'Audit risk disagrees with stored row');
echo "PASS: 28 operation classes, catalog agreement, conservative unknown types and original stored-risk audit semantics\n";
