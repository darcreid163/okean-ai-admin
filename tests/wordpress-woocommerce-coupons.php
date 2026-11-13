<?php
/**
 * WooCommerce coupon lifecycle (issue #167 / #142 P1-13b).
 *
 * Coupons are only created or changed through the normal sandbox lifecycle.
 * Planning must not touch live data, Apply requires approval, duplicate codes
 * are rejected before any write, newer concurrent edits return 409 without a
 * write, rollback deletes only a coupon created by the operation and restores
 * the complete snapshot for an update. No coupon is applied to an order or a
 * payment path.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run WooCommerce coupon lifecycle test on real WordPress');
}
function wooCpn(bool $ok,string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function wooCpn_ok($v,string $msg) {
    if (is_wp_error($v)) throw new RuntimeException($msg.': '.$v->get_error_code().' '.wp_json_encode($v->get_error_data()));
    return $v;
}
function wooCpn_create(array $coupon) {
    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/coupon-create');
    $request->set_param('coupon',$coupon);
    return OAI_REST::woocommerce_coupon_create($request);
}
function wooCpn_patch(int $id,array $changes) {
    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/coupon-patch');
    $request->set_param('coupon_id',$id);
    $request->set_param('changes',$changes);
    return OAI_REST::woocommerce_coupon_patch($request);
}
function wooCpn_action(int $id,string $action) {
    return OAI_AI_Lifecycle_Control::perform($id,$action,'disposable-coupon-lifecycle',1);
}
function wooCpn_mcp(string $token,string $name,array $arguments) {
    // OAI_MCP::auth() reads the raw server variable, not the REST request header.
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
    $request=new WP_REST_Request('POST','/okean-ai/v1/mcp');
    $request->set_header('content-type','application/json');
    $request->set_body(wp_json_encode(array(
        'jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
        'params'=>array('name'=>$name,'arguments'=>$arguments),
    )));
    $response=OAI_MCP::handle($request);
    $data=$response instanceof WP_REST_Response?$response->get_data():$response;
    if (isset($data['error'])) throw new RuntimeException('MCP '.$name.' error: '.wp_json_encode($data['error']));
    $text=$data['result']['content'][0]['text'] ?? '';
    if (!empty($data['result']['isError'])) throw new RuntimeException('MCP '.$name.' tool error: '.$text);
    $decoded=json_decode((string)$text,true);
    if (!is_array($decoded)) throw new RuntimeException('MCP '.$name.' returned invalid JSON');
    return $decoded;
}

wp_set_current_user(1);
$product_id=0;$category_id=0;$coupon_a=0;$coupon_b=0;$limited=0;$role='';
$mcp_options=array(
    'oai_mcp_enabled'=>get_option('oai_mcp_enabled',null),
    'oai_mcp_token_hash'=>get_option('oai_mcp_token_hash',null),
    'oai_mcp_token_user_id'=>get_option('oai_mcp_token_user_id',null),
);
$mcp_authorization=$_SERVER['HTTP_AUTHORIZATION']??null;
try {
    wooCpn_ok(OAI_License::activate_local_owner(1),'Activate disposable local owner license');
    wooCpn_ok(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped test lifecycle');

    $suffix=strtolower(substr(str_replace('-','',wp_generate_uuid4()),0,10));
    $code_a='OAI-CPN-A-'.strtoupper($suffix);
    $code_b='OAI-CPN-B-'.strtoupper($suffix);
    $code_mcp='OAI-CPN-M-'.strtoupper($suffix);

    $product=new WC_Product_Simple();
    $product->set_status('publish');
    $product->set_name('Disposable coupon fixture');
    $product->set_sku('OAI-COUPON-'.strtoupper($suffix));
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $product_id=(int)$product->save();
    wooCpn($product_id>0,'Could not create disposable product');
    $term=wp_insert_term('Coupon category '.$suffix,'product_cat');
    wooCpn(!is_wp_error($term) && !empty($term['term_id']),'Could not create disposable product category');
    $category_id=(int)$term['term_id'];

    $create_changes=array(
        'code'=>$code_a,
        'description'=>'Synthetic coupon',
        'discount_type'=>'percent',
        'amount'=>'15',
        'date_expires'=>gmdate('Y-m-d',time()+30*DAY_IN_SECONDS),
        'usage_limit'=>25,
        'usage_limit_per_user'=>2,
        'limit_usage_to_x_items'=>3,
        'free_shipping'=>true,
        'product_ids'=>array($product_id),
        'excluded_product_ids'=>array(),
        'product_categories'=>array($category_id),
        'excluded_product_categories'=>array(),
        'exclude_sale_items'=>true,
        'minimum_amount'=>'10',
        'maximum_amount'=>'500',
        'email_restrictions'=>array('coupon@example.invalid'),
        'individual_use'=>true,
    );

    // 1) Planning creates a pending operation and writes no live coupon.
    $planned=wooCpn_ok(wooCpn_create($create_changes),'Create coupon sandbox');
    $operation=(int)($planned['id']??0);
    wooCpn($operation>0 && ($planned['status']??'')==='pending','Coupon sandbox was not created as pending');
    wooCpn(($planned['type']??'')==='woocommerce_coupon','Coupon sandbox has the wrong operation type');
    wooCpn(($planned['target']??'')==='new:'.$code_a,'Coupon sandbox target is not new:<code>');
    wooCpn(OAI_WooCommerce::coupon_id_by_code($code_a)===0,'Planning wrote a live coupon');
    $after_plan=wooCpn_ok(OAI_WooCommerce::coupons(1,20,$code_a,'any'),'Read coupons after planning');
    wooCpn(($after_plan['count']??-1)===0,'Planning exposed a live coupon in the list');

    // 2) Apply before approval is refused and still writes nothing.
    $unapproved=wooCpn_action($operation,'apply');
    wooCpn(is_wp_error($unapproved) && $unapproved->get_error_code()==='oai_state','Unapproved coupon Apply was not refused');
    wooCpn(OAI_WooCommerce::coupon_id_by_code($code_a)===0,'Refused Apply created a live coupon');

    // 3) Approval and Apply create exactly one coupon that verifies.
    wooCpn_ok(wooCpn_action($operation,'approve'),'Approve coupon sandbox');
    wooCpn_ok(wooCpn_action($operation,'apply'),'Apply coupon sandbox');
    $coupon_a=OAI_WooCommerce::coupon_id_by_code($code_a);
    wooCpn($coupon_a>0,'Applied coupon does not exist');
    $created=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read applied coupon');
    wooCpn(($created['code']??'')===$code_a,'Applied coupon code is wrong');
    wooCpn(($created['description']??'')==='Synthetic coupon','Applied coupon description is wrong');
    wooCpn(($created['discount_type']??'')==='percent','Applied coupon discount type is wrong');
    wooCpn((float)$created['amount']===15.0,'Applied coupon amount is wrong');
    wooCpn(($created['usage_limit']??0)===25 && ($created['usage_limit_per_user']??0)===2,'Applied coupon usage limits are wrong');
    wooCpn(($created['limit_usage_to_x_items']??0)===3,'Applied coupon item limit is wrong');
    wooCpn(($created['free_shipping']??false)===true,'Applied coupon free_shipping is wrong');
    wooCpn(($created['product_ids']??array())==array($product_id),'Applied coupon product restriction is wrong');
    wooCpn(($created['product_categories']??array())==array($category_id),'Applied coupon category restriction is wrong');
    wooCpn(($created['exclude_sale_items']??false)===true,'Applied coupon exclude_sale_items is wrong');
    wooCpn((float)$created['minimum_amount']===10.0 && (float)$created['maximum_amount']===500.0,'Applied coupon totals are wrong');
    wooCpn(($created['email_restrictions']??array())==array('coupon@example.invalid'),'Applied coupon email restriction is wrong');
    wooCpn(($created['individual_use']??false)===true,'Applied coupon individual_use is wrong');
    wooCpn(!empty($created['date_expires']),'Applied coupon expiry is missing');
    wooCpn(OAI_WooCommerce::coupon_verification_matches($created,$planned['after_data']),'Applied coupon differs from the approved snapshot');
    $verify=wooCpn_ok(OAI_Verify::operation($operation),'Verify applied coupon operation');
    wooCpn(!empty($verify['verified']),'Applied coupon operation failed verification');
    wooCpn((OAI_DB::get($operation)['status']??'')==='applied','Applied coupon operation was not recorded as applied');

    // 4) A duplicate code is rejected before a new operation or write exists.
    $duplicate=wooCpn_create(array('code'=>$code_a,'discount_type'=>'fixed_cart','amount'=>'5'));
    wooCpn(is_wp_error($duplicate) && $duplicate->get_error_code()==='oai_woo_coupon_duplicate','Duplicate coupon code was not rejected');
    $duplicate_data=is_wp_error($duplicate)?$duplicate->get_error_data():array();
    wooCpn((int)($duplicate_data['coupon_id']??0)===$coupon_a,'Duplicate coupon error did not identify the existing coupon');
    wooCpn(count(get_posts(array('post_type'=>'shop_coupon','post_status'=>'any','title'=>$code_a,'numberposts'=>-1,'fields'=>'ids')))===1,'Duplicate coupon code created a second coupon');

    // 5) Update planning is a real sandbox and a no-op is rejected.
    $baseline=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read coupon baseline');
    $noop=wooCpn_patch($coupon_a,array('description'=>$baseline['description']));
    wooCpn(is_wp_error($noop) && $noop->get_error_code()==='oai_noop','No-op coupon patch was not rejected');
    $patch_changes=array(
        'description'=>'Updated synthetic coupon',
        'amount'=>'20',
        'usage_limit'=>30,
        'minimum_amount'=>'0',
        'maximum_amount'=>'0',
        'free_shipping'=>false,
        'email_restrictions'=>array(),
    );
    $planned_update=wooCpn_ok(wooCpn_patch($coupon_a,$patch_changes),'Create coupon update sandbox');
    $update_operation=(int)($planned_update['id']??0);
    wooCpn($update_operation>0 && ($planned_update['status']??'')==='pending','Coupon update sandbox was not created as pending');
    wooCpn(($planned_update['target']??'')===(string)$coupon_a,'Coupon update target is not the coupon id');
    $still=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read coupon after update planning');
    wooCpn(OAI_WooCommerce::coupon_verification_matches($still,$baseline),'Update planning wrote live coupon data');

    $unapproved_update=wooCpn_action($update_operation,'apply');
    wooCpn(is_wp_error($unapproved_update) && $unapproved_update->get_error_code()==='oai_state','Unapproved coupon update Apply was not refused');
    $still=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read coupon after refused update Apply');
    wooCpn(OAI_WooCommerce::coupon_verification_matches($still,$baseline),'Refused update Apply changed the coupon');

    // 6) Approved update applies, verifies and rolls back to the full snapshot.
    wooCpn_ok(wooCpn_action($update_operation,'approve'),'Approve coupon update sandbox');
    wooCpn_ok(wooCpn_action($update_operation,'apply'),'Apply coupon update sandbox');
    $updated=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read applied coupon update');
    wooCpn(($updated['description']??'')==='Updated synthetic coupon','Coupon description was not applied');
    wooCpn((float)$updated['amount']===20.0,'Coupon amount was not applied');
    wooCpn(($updated['usage_limit']??0)===30,'Coupon usage limit was not applied');
    wooCpn((float)$updated['minimum_amount']===0.0 && (float)$updated['maximum_amount']===0.0,'Coupon totals were not cleared');
    wooCpn(($updated['free_shipping']??true)===false,'Coupon free_shipping was not cleared');
    wooCpn(($updated['email_restrictions']??array())===array(),'Coupon email restrictions were not cleared');
    $update_verify=wooCpn_ok(OAI_Verify::operation($update_operation),'Verify applied coupon update');
    wooCpn(!empty($update_verify['verified']),'Applied coupon update failed verification');
    wooCpn_ok(wooCpn_action($update_operation,'rollback'),'Rollback coupon update');
    $rolled=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read rolled-back coupon');
    wooCpn(OAI_WooCommerce::coupon_verification_matches($rolled,$baseline),'Rollback did not restore the complete coupon snapshot');
    $rollback_verify=wooCpn_ok(OAI_Verify::operation($update_operation),'Verify rolled-back coupon update');
    wooCpn(!empty($rollback_verify['verified']),'Coupon update rollback verification failed');
    wooCpn((OAI_DB::get($update_operation)['status']??'')==='rolled_back','Coupon update rollback was not recorded');

    // 7) A newer concurrent coupon edit returns 409 and writes nothing.
    $planned_conflict=wooCpn_ok(wooCpn_patch($coupon_a,array('description'=>'Stale approved description')),'Create concurrent coupon sandbox');
    $conflict_operation=(int)($planned_conflict['id']??0);
    wooCpn($conflict_operation>0,'Concurrent coupon sandbox was not created');
    wooCpn_ok(wooCpn_action($conflict_operation,'approve'),'Approve concurrent coupon sandbox');
    $external=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read coupon for concurrent edit');
    $external_object=new WC_Coupon($coupon_a);
    $external_object->set_description('External concurrent description');
    $external_object->save();
    clean_post_cache($coupon_a);wp_cache_delete($coupon_a,'coupons');
    $conflict=wooCpn_action($conflict_operation,'apply');
    wooCpn(is_wp_error($conflict) && $conflict->get_error_code()==='oai_conflict','Concurrent coupon edit did not return oai_conflict');
    $after_conflict=wooCpn_ok(OAI_WooCommerce::get_coupon($coupon_a),'Read coupon after conflict');
    wooCpn(($after_conflict['description']??'')==='External concurrent description','Conflict Apply overwrote the newer coupon edit');
    wooCpn((OAI_DB::get($conflict_operation)['status']??'')==='apply_failed','Coupon Apply conflict was not recorded as apply_failed');

    // 8) Rollback of a created coupon deletes only that coupon.
    $planned_b=wooCpn_ok(wooCpn_create(array('code'=>$code_b,'discount_type'=>'fixed_cart','amount'=>'5')),'Create second coupon sandbox');
    $operation_b=(int)($planned_b['id']??0);
    wooCpn($operation_b>0,'Second coupon sandbox was not created');
    wooCpn_ok(wooCpn_action($operation_b,'approve'),'Approve second coupon sandbox');
    wooCpn_ok(wooCpn_action($operation_b,'apply'),'Apply second coupon sandbox');
    $coupon_b=OAI_WooCommerce::coupon_id_by_code($code_b);
    wooCpn($coupon_b>0,'Second applied coupon does not exist');
    wooCpn(!empty(wooCpn_ok(OAI_Verify::operation($operation_b),'Verify second coupon')['verified']),'Second coupon verification failed');
    wooCpn_ok(wooCpn_action($operation_b,'rollback'),'Rollback created coupon');
    wooCpn(OAI_WooCommerce::coupon_id_by_code($code_b)===0,'Rollback did not delete the created coupon');
    wooCpn(get_post($coupon_b)===null,'Rollback left the created coupon post behind');
    wooCpn(OAI_WooCommerce::coupon_id_by_code($code_a)===$coupon_a,'Rollback deleted the wrong coupon');
    $delete_verify=wooCpn_ok(OAI_Verify::operation($operation_b),'Verify deleted coupon rollback');
    wooCpn(!empty($delete_verify['verified']) && !empty($delete_verify['cleanup_effective']),'Deleted coupon rollback verification failed');
    wooCpn((OAI_DB::get($operation_b)['status']??'')==='rolled_back','Created coupon rollback was not recorded');

    // 9) Reads are capability-gated; a manager without coupon caps sees nothing.
    $role='oai_coupon_reader_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
    add_role($role,'Coupon Reader',array('read'=>true,'manage_options'=>true));
    $limited=wp_insert_user(array(
        'user_login'=>'oai-coupon-reader-'.substr(str_replace('-','',wp_generate_uuid4()),0,8),
        'user_pass'=>wp_generate_password(32,true,true),
        'user_email'=>'coupon-reader-'.substr(str_replace('-','',wp_generate_uuid4()),0,8).'@example.invalid',
        'role'=>$role,
    ));
    wooCpn(!is_wp_error($limited) && (int)$limited>0,'Could not create limited administrator');
    wp_set_current_user((int)$limited);
    wooCpn(!OAI_WooCommerce::coupon_read_allowed(),'Limited user unexpectedly has coupon read access');
    wooCpn(!OAI_WooCommerce::coupon_create_allowed(),'Limited user unexpectedly has coupon create access');
    wooCpn(!OAI_WooCommerce::coupon_update_allowed(),'Limited user unexpectedly has coupon update access');
    $denied_read=OAI_WooCommerce::coupons(1,20,'','any');
    wooCpn(is_wp_error($denied_read) && $denied_read->get_error_code()==='oai_forbidden','Limited user read coupons');
    $denied_one=OAI_WooCommerce::get_coupon($coupon_a);
    wooCpn(is_wp_error($denied_one) && $denied_one->get_error_code()==='oai_forbidden','Limited user read one coupon');
    $denied_create=OAI_WooCommerce::coupon_create(array('code'=>'OAI-DENIED-'.$suffix,'discount_type'=>'fixed_cart','amount'=>'1'));
    wooCpn(is_wp_error($denied_create) && $denied_create->get_error_code()==='oai_woo_permission','Limited user created a coupon sandbox');
    $denied_patch=OAI_WooCommerce::coupon_patch($coupon_a,array('description'=>'Denied'));
    wooCpn(is_wp_error($denied_patch) && $denied_patch->get_error_code()==='oai_woo_permission','Limited user patched a coupon');
    $denied_apply=OAI_WooCommerce::apply_coupon($coupon_a,$baseline);
    wooCpn(is_wp_error($denied_apply) && $denied_apply->get_error_code()==='oai_woo_permission','Limited user applied a coupon');
    $denied_restore=OAI_WooCommerce::restore_coupon($coupon_a,$baseline);
    wooCpn(is_wp_error($denied_restore) && $denied_restore->get_error_code()==='oai_woo_permission','Limited user restored a coupon');
    $denied_delete=OAI_WooCommerce::delete_coupon($coupon_a);
    wooCpn(is_wp_error($denied_delete) && $denied_delete->get_error_code()==='oai_woo_permission','Limited user deleted a coupon');
    $rest_read=new WP_REST_Request('GET','/okean-ai/v1/read/coupons');
    $rest_response=rest_do_request($rest_read);
    wooCpn(in_array($rest_response->get_status(),array(401,403),true),'Limited REST coupon list was not denied');
    wp_set_current_user(1);

    // 10) REST, MCP and batch catalogs expose the same coupon surface.
    $rest_list=new WP_REST_Request('GET','/okean-ai/v1/read/coupons');
    $rest_list->set_param('search',$code_a);
    $rest_list_response=rest_do_request($rest_list);
    wooCpn($rest_list_response->get_status()===200,'Admin REST coupon list did not return 200');
    $rest_items=$rest_list_response->get_data();
    wooCpn(($rest_items['count']??0)>=1 && ($rest_items['items'][0]['id']??0)===$coupon_a,'REST coupon list did not return the synthetic coupon');
    $rest_one=new WP_REST_Request('GET','/okean-ai/v1/read/coupons/'.$coupon_a);
    $rest_one->set_url_params(array('id'=>$coupon_a));
    $rest_one_response=rest_do_request($rest_one);
    wooCpn($rest_one_response->get_status()===200 && ($rest_one_response->get_data()['id']??0)===$coupon_a,'REST single-coupon route failed');

    $names=array_column(OAI_MCP::tool_definitions(),'name');
    foreach (array('woocommerce.search_coupons','woocommerce.get_coupon','woocommerce.create_coupon_sandbox','woocommerce.update_coupon_sandbox') as $tool) {
        wooCpn(in_array($tool,$names,true),'MCP coupon tool missing: '.$tool);
        wooCpn(in_array($tool,OAI_Platform::expected_tools(),true),'Authoritative catalog missing: '.$tool);
    }
    wooCpn(count($names)===count(OAI_Platform::expected_tools()),'MCP/catalog count drift');
    wooCpn(in_array('woocommerce.create_coupon_sandbox',OAI_Platform::write_tools(),true),'Coupon create tool is not write-gated');
    wooCpn(in_array('woocommerce.update_coupon_sandbox',OAI_Platform::write_tools(),true),'Coupon update tool is not write-gated');
    foreach (array('woocommerce.search_coupons','woocommerce.get_coupon','woocommerce.create_coupon_sandbox','woocommerce.update_coupon_sandbox') as $tool) {
        wooCpn(in_array($tool,OAI_Batch_Executor::supported_tools(),true),'Batch coupon tool missing: '.$tool);
    }

    $token='oai-coupon-test-'.wp_generate_password(40,false,false);
    update_option('oai_mcp_enabled',true,false);
    update_option('oai_mcp_token_hash',hash('sha256',$token),false);
    update_option('oai_mcp_token_user_id',1,false);
    $mcp_read=wooCpn_mcp($token,'woocommerce.search_coupons',array('search'=>$code_a));
    wooCpn(($mcp_read['count']??0)>=1,'MCP coupon search did not find the synthetic coupon');
    $mcp_create=wooCpn_mcp($token,'woocommerce.create_coupon_sandbox',array('coupon'=>array('code'=>$code_mcp,'discount_type'=>'fixed_cart','amount'=>'7')));
    $mcp_operation=(int)($mcp_create['id']??0);
    wooCpn($mcp_operation>0 && ($mcp_create['status']??'')==='pending','MCP coupon create did not return a pending sandbox');
    wooCpn(OAI_WooCommerce::coupon_id_by_code($code_mcp)===0,'MCP coupon planning wrote a live coupon');
    wooCpn_ok(wooCpn_action($mcp_operation,'reject'),'Reject MCP coupon sandbox');
    wooCpn((OAI_DB::get($mcp_operation)['status']??'')==='rejected','MCP coupon sandbox was not rejected');

    echo 'PASS: WooCommerce coupons require approval and coupon capabilities; duplicate codes, sandbox isolation, 409 conflicts, REST/MCP/batch parity and exact create/update rollback (#167)'."\n";
} finally {
    wp_set_current_user(1);
    OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    foreach ($mcp_options as $name=>$value) {
        if ($value===null) delete_option($name);
        else update_option($name,$value,false);
    }
    if ($mcp_authorization===null) unset($_SERVER['HTTP_AUTHORIZATION']);
    else $_SERVER['HTTP_AUTHORIZATION']=$mcp_authorization;
    foreach (array($coupon_a,$coupon_b) as $coupon_id) {
        if ($coupon_id && get_post($coupon_id)) {
            $deleted=OAI_WooCommerce::delete_coupon($coupon_id);
            if (is_wp_error($deleted) || !$deleted) wp_delete_post($coupon_id,true);
        }
    }
    if ($product_id) { $product=wc_get_product($product_id); if ($product) $product->delete(true); }
    if ($category_id) wp_delete_term($category_id,'product_cat');
    if ($limited) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user((int)$limited); }
    if ($role!=='') remove_role($role);
}
