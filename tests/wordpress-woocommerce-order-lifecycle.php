<?php
/**
 * WooCommerce order lifecycle (issue #142).
 *
 * Orders are only editable through the normal sandbox lifecycle: planning must
 * not touch live data, Apply requires approval and edit_shop_orders, totals are
 * recalculated by WooCommerce, newer concurrent edits return 409 without any
 * write, and Rollback restores the complete synthetic order snapshot.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run WooCommerce order lifecycle test on real WordPress');
}
function wooOrd(bool $ok,string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function wooOrd_ok($v,string $msg) {
    if (is_wp_error($v)) throw new RuntimeException($msg.': '.$v->get_error_code().' '.wp_json_encode($v->get_error_data()));
    return $v;
}
function wooOrd_snapshot($id) {
    return wooOrd_ok(OAI_WooCommerce::get_order((int)$id,true),'Read order '.(int)$id);
}
function wooOrd_order($product,$quantity=2) {
    $order=wc_create_order(array('status'=>'pending','customer_id'=>0));
    wooOrd($order instanceof WC_Order,'Could not create synthetic order');
    $order->set_currency('USD');
    $order->set_address(array(
        'first_name'=>'Synthetic','last_name'=>'Buyer','company'=>'Fixture Co',
        'address_1'=>'1 Test Lane','address_2'=>'Suite 2','city'=>'Testville',
        'state'=>'TS','postcode'=>'12345','country'=>'US',
        'email'=>'buyer@example.invalid','phone'=>'555-0100'
    ),'billing');
    $order->set_address(array(
        'first_name'=>'Synthetic','last_name'=>'Buyer','company'=>'Fixture Co',
        'address_1'=>'1 Test Lane','address_2'=>'Suite 2','city'=>'Testville',
        'state'=>'TS','postcode'=>'12345','country'=>'US'
    ),'shipping');
    $order->set_customer_note('Original synthetic customer note');
    $order->add_product($product,$quantity);
    $shipping=new WC_Order_Item_Shipping();
    $shipping->set_method_title('Flat rate');
    $shipping->set_method_id('flat_rate');
    $shipping->set_total('5.00');
    $order->add_item($shipping);
    $order->calculate_totals(true);
    $order->save();
    return (int)$order->get_id();
}
function wooOrd_patch($id,$changes) {
    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/order-patch');
    $request->set_param('order_id',(int)$id);
    $request->set_param('changes',$changes);
    return OAI_REST::woocommerce_order_patch($request);
}
function wooOrd_action($id,$action) {
    return OAI_AI_Lifecycle_Control::perform((int)$id,$action,'disposable-order-lifecycle',1);
}

wp_set_current_user(1);
$pid=0;$orderA=0;$orderB=0;$orderC=0;$limited=0;$role='';
try {
    wooOrd_ok(OAI_License::activate_local_owner(1),'Activate disposable local owner license');
    wooOrd_ok(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped test lifecycle');

    $product=new WC_Product_Simple();
    $product->set_status('publish');
    $product->set_name('Disposable order lifecycle');
    $product->set_sku('OAI-ORDER-'.substr(str_replace('-','',wp_generate_uuid4()),0,12));
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $pid=(int)$product->save();
    wooOrd($pid>0,'Could not create disposable product');

    $orderA=wooOrd_order($product,2);
    $baseline=wooOrd_snapshot($orderA);
    $baseline_item=$baseline['line_items'][0];
    wooOrd($baseline['status']==='pending' && (float)$baseline_item['quantity']===2.0,'Unexpected order baseline');
    wooOrd(($baseline['billing']['email']??'')==='buyer@example.invalid','Synthetic billing email missing');

    // 1) Planning is a real sandbox: no live order field may change.
    $planned=wooOrd_ok(wooOrd_patch($orderA,array(
        'status'=>'processing',
        'billing'=>array(
            'first_name'=>'Applied','last_name'=>'Buyer','company'=>'Applied Co',
            'address_1'=>'2 Applied Way','address_2'=>'','city'=>'Appliedville',
            'state'=>'TS','postcode'=>'54321','country'=>'US',
            'email'=>'applied@example.invalid','phone'=>'555-0199'
        ),
        'shipping'=>array(
            'first_name'=>'Applied','last_name'=>'Buyer','company'=>'Applied Co',
            'address_1'=>'2 Applied Way','address_2'=>'','city'=>'Appliedville',
            'state'=>'TS','postcode'=>'54321','country'=>'US'
        ),
        'customer_note'=>'Applied synthetic customer note',
        'line_items'=>array(array('id'=>(int)$baseline_item['id'],'quantity'=>3))
    )),'Create order sandbox');
    $operation=(int)($planned['id']??0);
    wooOrd($operation>0 && ($planned['status']??'')==='pending','Order sandbox was not created as pending');
    $after_plan=wooOrd_snapshot($orderA);
    wooOrd($after_plan['status']===$baseline['status'] &&
        $after_plan['customer_note']===$baseline['customer_note'] &&
        (float)$after_plan['line_items'][0]['quantity']===2.0 &&
        $after_plan['billing']===$baseline['billing'] &&
        $after_plan['shipping']===$baseline['shipping'],'Planning wrote live order data');

    // 2) Apply before approval must be refused and must not write.
    $unapproved=wooOrd_action($operation,'apply');
    wooOrd(is_wp_error($unapproved) && $unapproved->get_error_code()==='oai_state','Unapproved order Apply was not refused');
    $still=wooOrd_snapshot($orderA);
    wooOrd($still['status']===$baseline['status'] &&
        $still['customer_note']===$baseline['customer_note'] &&
        (float)$still['line_items'][0]['quantity']===2.0,'Refused Apply changed the live order');

    // 3) Approved Apply updates status, addresses, note and an existing item quantity.
    wooOrd_ok(wooOrd_action($operation,'approve'),'Approve order sandbox');
    wooOrd_ok(wooOrd_action($operation,'apply'),'Apply order sandbox');
    $applied=wooOrd_snapshot($orderA);
    wooOrd($applied['status']==='processing','Order status was not applied');
    wooOrd($applied['billing']['first_name']==='Applied' && $applied['billing']['email']==='applied@example.invalid','Billing address was not applied');
    wooOrd($applied['shipping']['address_1']==='2 Applied Way' && $applied['shipping']['postcode']==='54321','Shipping address was not applied');
    wooOrd($applied['customer_note']==='Applied synthetic customer note','Customer note was not applied');
    wooOrd((float)$applied['line_items'][0]['quantity']===3.0,'Line item quantity was not applied');
    wooOrd(abs((float)$applied['line_items'][0]['subtotal']-30.0)<0.0001,'Line item subtotal was not scaled by WooCommerce');
    wooOrd(abs((float)$applied['totals']['total']-35.0)<0.0001,'WooCommerce did not recalculate the order total');
    $verify=wooOrd_ok(OAI_Verify::operation($operation),'Verify applied order operation');
    wooOrd(!empty($verify['verified']),'Applied order operation failed verification');
    $row=OAI_DB::get($operation);
    wooOrd(($row['status']??'')==='applied','Applied order operation was not recorded as applied');

    // 4) Reading orders redacts customer PII unless the caller can edit orders.
    $role='oai_order_reader_'.substr(str_replace('-','',wp_generate_uuid4()),0,10);
    add_role($role,'Order Reader',array('read'=>true,'manage_options'=>true));
    $limited=wp_insert_user(array(
        'user_login'=>'oai-order-reader-'.substr(str_replace('-','',wp_generate_uuid4()),0,10),
        'user_pass'=>wp_generate_password(32,true,true),
        'user_email'=>'order-reader-'.substr(str_replace('-','',wp_generate_uuid4()),0,10).'@example.invalid',
        'role'=>$role
    ));
    wooOrd(!is_wp_error($limited) && (int)$limited>0,'Could not create limited administrator');
    wp_set_current_user((int)$limited);
    wooOrd(!current_user_can('edit_shop_orders'),'Limited user unexpectedly holds edit_shop_orders');
    $redacted=wooOrd_ok(OAI_WooCommerce::get_order($orderA,false),'Read redacted order');
    wooOrd(($redacted['billing']['email']??'')==='***' && ($redacted['billing']['phone']??'')==='***','Order contact PII was not redacted');
    wooOrd(($redacted['billing']['address_1']??'')==='' && ($redacted['shipping']['address_1']??'')==='','Order address PII was not redacted');
    wooOrd(($redacted['customer_note']??'')==='','Order customer note was not redacted');
    wooOrd(($redacted['privacy']??'')==='redacted_no_edit_shop_orders','Redacted order lacks a privacy marker');
    $read_request=new WP_REST_Request('GET','/okean-ai/v1/read/orders/'.$orderA);
    $read_request->set_url_params(array('id'=>$orderA));
    $handler_read=wooOrd_ok(OAI_REST::woocommerce_order($read_request),'REST redacted order read');
    wooOrd(($handler_read['billing']['email']??'')==='***','REST order read leaked contact PII');
    $denied_patch=wooOrd_patch($orderA,array('status'=>'on-hold'));
    wooOrd(is_wp_error($denied_patch) && $denied_patch->get_error_code()==='oai_woo_permission','Admin without edit_shop_orders created an order sandbox');
    $denied_apply=OAI_WooCommerce::apply_order($orderA,$applied);
    wooOrd(is_wp_error($denied_apply) && $denied_apply->get_error_code()==='oai_woo_permission','Admin without edit_shop_orders applied an order');
    $denied_restore=OAI_WooCommerce::restore_order($orderA,$baseline);
    wooOrd(is_wp_error($denied_restore) && $denied_restore->get_error_code()==='oai_woo_permission','Admin without edit_shop_orders rolled back an order');
    wp_set_current_user(1);

    // 5) The MCP surface exports both order tools and keeps the write tool gated.
    $names=array_column(OAI_MCP::tool_definitions(),'name');
    wooOrd(in_array('woocommerce.get_order',$names,true),'MCP order read tool is not exported');
    wooOrd(in_array('woocommerce.update_order_sandbox',$names,true),'MCP order write tool is not exported');
    $expected=OAI_Platform::expected_tools();
    wooOrd(in_array('woocommerce.get_order',$expected,true),'Order read tool missing from the authoritative catalog');
    wooOrd(in_array('woocommerce.update_order_sandbox',$expected,true),'Order write tool missing from the authoritative catalog');
    $write_tools=OAI_Platform::write_tools();
    wooOrd(in_array('woocommerce.update_order_sandbox',$write_tools,true),'Order write tool is not write-gated');

    // 6) Rollback restores the complete synthetic order snapshot.
    wooOrd_ok(wooOrd_action($operation,'rollback'),'Rollback order sandbox');
    $rolled=wooOrd_snapshot($orderA);
    wooOrd($rolled['status']===$baseline['status'],'Rollback did not restore the order status');
    wooOrd($rolled['billing']===$baseline['billing'] && $rolled['shipping']===$baseline['shipping'],'Rollback did not restore the order addresses');
    wooOrd($rolled['customer_note']===$baseline['customer_note'],'Rollback did not restore the customer note');
    wooOrd((float)$rolled['line_items'][0]['quantity']===2.0,'Rollback did not restore the line item quantity');
    wooOrd(abs((float)$rolled['totals']['total']-(float)$baseline['totals']['total'])<0.0001,'Rollback did not restore WooCommerce totals');
    $rollback_verify=wooOrd_ok(OAI_Verify::operation($operation),'Verify rolled-back order operation');
    wooOrd(!empty($rollback_verify['verified']),'Order rollback verification failed');
    wooOrd((OAI_DB::get($operation)['status']??'')==='rolled_back','Order rollback was not recorded');

    // 7) A newer concurrent order edit returns 409 and writes nothing.
    $orderC=wooOrd_order($product,1);
    $planned_c=wooOrd_ok(wooOrd_patch($orderC,array('customer_note'=>'Stale approved note')),'Create concurrent order sandbox');
    $operation_c=(int)($planned_c['id']??0);
    wooOrd($operation_c>0,'Concurrent order sandbox was not created');
    wooOrd_ok(wooOrd_action($operation_c,'approve'),'Approve concurrent order sandbox');
    $external=wc_get_order($orderC);
    wooOrd($external instanceof WC_Order,'Could not reopen order for concurrent edit');
    $external->set_customer_note('External concurrent note');
    $external->save();
    $conflict=wooOrd_action($operation_c,'apply');
    wooOrd(is_wp_error($conflict) && $conflict->get_error_code()==='oai_conflict','Concurrent order edit did not return oai_conflict');
    $after_conflict=wooOrd_snapshot($orderC);
    wooOrd($after_conflict['customer_note']==='External concurrent note','Conflict Apply overwrote the newer order edit');
    wooOrd((OAI_DB::get($operation_c)['status']??'')==='apply_failed','Order Apply conflict was not recorded as apply_failed');

    // 8) WooCommerce owns totals: an independent recalculation agrees with Apply.
    $orderB=wooOrd_order($product,2);
    $baseline_b=wooOrd_snapshot($orderB);
    $planned_b=wooOrd_ok(wooOrd_patch($orderB,array(
        'line_items'=>array(array('id'=>(int)$baseline_b['line_items'][0]['id'],'quantity'=>5))
    )),'Create totals order sandbox');
    $operation_b=(int)($planned_b['id']??0);
    wooOrd($operation_b>0,'Totals order sandbox was not created');
    wooOrd_ok(wooOrd_action($operation_b,'approve'),'Approve totals order sandbox');
    wooOrd_ok(wooOrd_action($operation_b,'apply'),'Apply totals order sandbox');
    $applied_b=wooOrd_snapshot($orderB);
    wooOrd((float)$applied_b['line_items'][0]['quantity']===5.0,'Totals fixture quantity was not applied');
    wooOrd(abs((float)$applied_b['line_items'][0]['subtotal']-50.0)<0.0001,'Totals fixture subtotal was not scaled');
    $fresh=wc_get_order($orderB);
    wooOrd($fresh instanceof WC_Order,'Could not reopen order for independent total recalculation');
    $fresh->calculate_totals(true);
    $fresh->save();
    $recalculated=wooOrd_snapshot($orderB);
    wooOrd(OAI_Verify::same($applied_b['totals'],$recalculated['totals']),'Apply totals disagree with an independent WooCommerce recalculation');
    wooOrd(OAI_Verify::same($applied_b['line_items'][0]['subtotal'],$recalculated['line_items'][0]['subtotal']),'Apply line subtotal disagrees with WooCommerce recalculation');

    echo "PASS: WooCommerce orders require approval and edit_shop_orders; status/address/quantity changes, WooCommerce totals, 409 conflicts, PII redaction and exact rollback (issue #142)\n";
} finally {
    wp_set_current_user(1);
    OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    foreach(array($orderA,$orderB,$orderC) as $order_id) {
        if($order_id) { $order=wc_get_order($order_id); if($order) $order->delete(true); }
    }
    if($pid) { $product=wc_get_product($pid); if($product) $product->delete(true); }
    if($limited) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user((int)$limited); }
    if($role!=='') remove_role($role);
}
