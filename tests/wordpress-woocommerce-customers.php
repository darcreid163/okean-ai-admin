<?php
/**
 * WooCommerce customers read surface (issue #166 / #142 P1-13a).
 *
 * Registered customers are readable through REST, MCP and batch reads. Access
 * requires edit_shop_orders, manage_woocommerce or list_users; contact/address
 * PII requires manage_woocommerce or list_users. Email lookup is not available
 * to redacted callers. No mutation, order or payment path is exercised.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run WooCommerce customers test on real WordPress');
}

$wooCust_checks = 0;
function wooCust_check($ok,string $msg): void {
    global $wooCust_checks;
    $wooCust_checks++;
    if (!$ok) throw new RuntimeException($msg);
}
function wooCust_ok($value,string $msg) {
    if (is_wp_error($value)) {
        throw new RuntimeException($msg.': '.$value->get_error_code().' '.wp_json_encode($value->get_error_data()));
    }
    return $value;
}
function wooCust_user(string $email,string $first,string $last,string $city): int {
    $id = wp_insert_user(array(
        'user_login' => 'oai_cust_'.strtolower(wp_generate_password(10,false,false)),
        'user_email' => $email,
        'user_pass' => wp_generate_password(24),
        'first_name' => $first,
        'last_name' => $last,
        'display_name' => $first.' '.$last,
        'role' => 'customer',
    ));
    if (is_wp_error($id)) throw new RuntimeException('Create synthetic customer: '.$id->get_error_message());
    update_user_meta($id,'first_name',$first);
    update_user_meta($id,'last_name',$last);
    update_user_meta($id,'billing_first_name',$first);
    update_user_meta($id,'billing_last_name',$last);
    update_user_meta($id,'billing_company','Synthetic Fixture Co');
    update_user_meta($id,'billing_address_1','1 Test Lane');
    update_user_meta($id,'billing_address_2','Suite 2');
    update_user_meta($id,'billing_city',$city);
    update_user_meta($id,'billing_state','TS');
    update_user_meta($id,'billing_postcode','12345');
    update_user_meta($id,'billing_country','US');
    update_user_meta($id,'billing_email',$email);
    update_user_meta($id,'billing_phone','555-0101');
    update_user_meta($id,'shipping_first_name',$first);
    update_user_meta($id,'shipping_last_name',$last);
    update_user_meta($id,'shipping_address_1','1 Test Lane');
    update_user_meta($id,'shipping_city',$city);
    update_user_meta($id,'shipping_state','TS');
    update_user_meta($id,'shipping_postcode','12345');
    update_user_meta($id,'shipping_country','US');
    update_user_meta($id,'paying_customer','1');
    update_user_meta($id,'wc_last_active',time());
    return (int)$id;
}

wp_set_current_user(1);
$created = array();
$subscriber = 0;
try {
    $suffix = strtolower(substr(str_replace('-','',wp_generate_uuid4()),0,12));
    $lastA = 'CustA'.$suffix;
    $lastB = 'CustB'.$suffix;
    $emailA = 'oai-cust-a-'.$suffix.'@example.invalid';
    $emailB = 'oai-cust-b-'.$suffix.'@example.invalid';
    $a = wooCust_user($emailA,'Synthetic',$lastA,'Alpha City');
    $b = wooCust_user($emailB,'Synthetic',$lastB,'Beta City');
    $created[] = $a;
    $created[] = $b;

    // 1) Exact email lookup returns the complete synthetic projection.
    $byEmail = wooCust_ok(OAI_WooCommerce::customers('',$emailA,1,20,true),'Customer email lookup');
    wooCust_check(($byEmail['count'] ?? -1) === 1,'Email lookup did not return exactly one customer');
    $row = $byEmail['items'][0] ?? array();
    wooCust_check(($row['id'] ?? 0) === $a,'Email lookup returned the wrong customer');
    wooCust_check(($row['email'] ?? '') === $emailA,'Customer email missing from full projection');
    wooCust_check(($row['billing']['phone'] ?? '') === '555-0101','Billing phone missing from full projection');
    wooCust_check(($row['shipping']['city'] ?? '') === 'Alpha City','Shipping city missing from full projection');
    wooCust_check(($row['privacy'] ?? '') === 'full_customer_pii','Full projection privacy marker is wrong');
    wooCust_check(($row['is_paying_customer'] ?? false) === true,'Paying-customer flag missing');
    wooCust_check(!empty($row['last_active']),'Last-active timestamp missing');

    // 2) Text search is bounded and finds the synthetic last name.
    $byName = wooCust_ok(OAI_WooCommerce::customers($lastA,'',1,20,true),'Customer text search');
    wooCust_check(($byName['count'] ?? 0) >= 1,'Text search did not find the synthetic customer');
    $found = false;
    foreach ((array)$byName['items'] as $item) {
        if (($item['id'] ?? 0) === $a) $found = true;
    }
    wooCust_check($found,'Text search did not include the synthetic customer');

    // 3) Pagination totals and has_more are coherent.
    $page1 = wooCust_ok(OAI_WooCommerce::customers('','',1,1,true),'Customer page 1');
    wooCust_check(($page1['count'] ?? -1) === 1,'Page 1 did not return one row');
    wooCust_check(($page1['total'] ?? 0) >= 2,'Customer total is smaller than the synthetic fixture');
    wooCust_check(($page1['total_pages'] ?? 0) >= 2,'Customer total_pages is too small');
    wooCust_check(($page1['has_more'] ?? false) === true,'Page 1 did not report has_more');
    $page2 = wooCust_ok(OAI_WooCommerce::customers('','',2,1,true),'Customer page 2');
    wooCust_check(($page2['count'] ?? -1) === 1,'Page 2 did not return one row');

    // 4) Redacted callers cannot use email as an existence oracle and see no PII.
    $denied = OAI_WooCommerce::customers('',$emailA,1,20,false);
    wooCust_check(is_wp_error($denied) && $denied->get_error_code() === 'oai_woo_customer_pii','Redacted email lookup was not denied');
    $redacted = wooCust_ok(OAI_WooCommerce::customers('','',1,1,false),'Redacted customer list');
    wooCust_check(($redacted['privacy'] ?? '') === 'redacted_customer_pii','Redacted list privacy marker is wrong');
    $redactedRow = $redacted['items'][0] ?? array();
    foreach (array('email','username','display_name','first_name','last_name','billing','shipping','phone') as $key) {
        wooCust_check(!array_key_exists($key,$redactedRow),'Redacted projection leaked '.$key);
    }

    // 5) Single-customer read, 404s and invalid email validation.
    $full = wooCust_ok(OAI_WooCommerce::get_customer($a,true),'Full customer read');
    wooCust_check(($full['id'] ?? 0) === $a && ($full['email'] ?? '') === $emailA,'Full customer read returned the wrong record');
    $red = wooCust_ok(OAI_WooCommerce::get_customer($a,false),'Redacted customer read');
    wooCust_check(($red['privacy'] ?? '') === 'redacted_customer_pii' && !array_key_exists('email',$red),'Redacted customer read leaked PII');
    $missing = OAI_WooCommerce::get_customer(99999999,true);
    wooCust_check(is_wp_error($missing) && $missing->get_error_code() === 'oai_not_found','Unknown customer did not return 404');
    $admin = OAI_WooCommerce::get_customer(1,true);
    wooCust_check(is_wp_error($admin) && $admin->get_error_code() === 'oai_not_found','Non-customer user was exposed');
    $badEmail = OAI_WooCommerce::customers('','not-an-email',1,20,true);
    wooCust_check(is_wp_error($badEmail) && $badEmail->get_error_code() === 'oai_woo_customer_email','Invalid email was not rejected');

    // 6) Sensitive authentication fields never appear in the projection.
    $json = wp_json_encode($full);
    foreach (array('user_pass','user_activation_key','session_tokens','user_email_hash') as $secret) {
        wooCust_check(strpos((string)$json,$secret) === false,'Projection leaked '.$secret);
    }

    // 7) REST parity: admin succeeds; subscriber and anonymous are denied.
    $req = new WP_REST_Request('GET','/okean-ai/v1/read/customers');
    $req->set_param('email',$emailA);
    $res = rest_do_request($req);
    wooCust_check($res->get_status() === 200,'Admin REST customer list did not return 200');
    $data = $res->get_data();
    wooCust_check(($data['items'][0]['email'] ?? '') === $emailA,'REST customer list did not return the synthetic email');

    $one = new WP_REST_Request('GET','/okean-ai/v1/read/customers/'.$a);
    $one->set_url_params(array('id'=>$a));
    $oneRes = rest_do_request($one);
    wooCust_check($oneRes->get_status() === 200 && ($oneRes->get_data()['id'] ?? 0) === $a,'REST single-customer route failed');

    $subscriber = wp_insert_user(array(
        'user_login' => 'oai_cust_sub_'.strtolower(wp_generate_password(10,false,false)),
        'user_email' => 'oai-cust-sub-'.$suffix.'@example.invalid',
        'user_pass' => wp_generate_password(24),
        'role' => 'subscriber',
    ));
    if (is_wp_error($subscriber)) { $subscriber = 0; throw new RuntimeException('Create synthetic subscriber'); }
    wp_set_current_user((int)$subscriber);
    wooCust_check(!OAI_WooCommerce::customer_access_allowed(),'Subscriber unexpectedly has customer access');
    $subRes = rest_do_request($req);
    wooCust_check(in_array($subRes->get_status(),array(401,403),true),'Subscriber REST customer list was not denied');
    wp_set_current_user(0);
    $anonRes = rest_do_request($req);
    wooCust_check(in_array($anonRes->get_status(),array(401,403),true),'Anonymous REST customer list was not denied');

    // 8) MCP catalog and batch parity.
    wp_set_current_user(1);
    $names = array_column(OAI_MCP::tool_definitions(),'name');
    wooCust_check(in_array('woocommerce.search_customers',$names,true),'MCP customer search tool missing');
    wooCust_check(in_array('woocommerce.get_customer',$names,true),'MCP customer read tool missing');
    wooCust_check(count($names) === count(OAI_Platform::expected_tools()),'MCP/catalog count drift');
    wooCust_check(in_array('woocommerce.search_customers',OAI_Batch_Executor::supported_tools(),true),'Batch customer search missing');
    wooCust_check(in_array('woocommerce.get_customer',OAI_Batch_Executor::supported_tools(),true),'Batch customer read missing');

    echo 'PASS: WooCommerce customers read: '.$wooCust_checks.' checks, capability PII, pagination, REST/MCP/batch parity'."\n";
} finally {
    wp_set_current_user(1);
    if (!function_exists('wp_delete_user')) require_once ABSPATH.'wp-admin/includes/user.php';
    foreach ($created as $id) wp_delete_user((int)$id);
    if ($subscriber) wp_delete_user((int)$subscriber);
}
