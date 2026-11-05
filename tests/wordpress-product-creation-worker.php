<?php
/** Disposable worker: six separate WP processes race to create one product. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Disposable WordPress only');
$actor=(int)getenv('OAI_PRODUCT_TEST_ACTOR');$token=(string)getenv('OAI_PRODUCT_TEST_TOKEN');
if($actor<1||!preg_match('/^[a-f0-9]{16}$/D',$token))throw new RuntimeException('Invalid fixture');
wp_set_current_user($actor);
$created=OAI_WooCommerce::create_draft(['name'=>'WP AI Product Concurrent '.$token,'type'=>'simple','regular_price'=>'12.90'],'product-parallel-'.$token);
if(is_wp_error($created)){echo wp_json_encode(['error'=>$created->get_error_code()]);return;}
$operation=OAI_P0::op_for_created_product($created);
echo wp_json_encode(is_wp_error($operation)?['error'=>$operation->get_error_code()]:['id'=>(int)$operation['id'],'product'=>(int)$created['id']]);
