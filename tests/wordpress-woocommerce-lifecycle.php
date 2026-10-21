<?php
/** Native WooCommerce end-to-end lifecycle on disposable WordPress only. */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run Woo lifecycle test on real WordPress');
}
function wooLife(bool $ok,string $msg): void {
    if (!$ok) throw new RuntimeException($msg);
}
function wooOk($v,string $msg) {
    if (is_wp_error($v)) throw new RuntimeException($msg.': '.$v->get_error_code());
    return $v;
}
wp_set_current_user(1);
$pid=0;$opid=0;
try {
    // A real Native Woo write sandbox requires licensing. Use only its
    // documented local-owner activation on an isolated disposable WP site;
    // never bypass OAI_License::can() or forge subscription signatures.
    $unlicensed=OAI_License::can('woocommerce');
    wooLife(is_wp_error($unlicensed) &&
        $unlicensed->get_error_code()==='oai_license_required',
        'Unlicensed Woo operation unexpectedly authorized');
    wooOk(OAI_License::activate_local_owner(1),'Activate disposable local owner license');
    wooOk(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped test lifecycle');
    $product=new WC_Product_Simple();
    $product->set_status('draft');
    $product->set_name('Disposable sandbox lifecycle');
    $product->set_sku('OAI-APPROVAL-'.substr(str_replace('-','',wp_generate_uuid4()),0,12));
    $product->set_regular_price('17.25');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(4);
    $pid=(int)$product->save();
    wooLife($pid>0,'Could not create disposable test product');
    $before=wooOk(OAI_WooCommerce::get_full($pid),'Read initial product');

    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');
    $request->set_param('product_id',$pid);
    $request->set_param('changes',[
        'regular_price'=>'18.75',
        'stock_quantity'=>'9.5'
    ]);
    $planned=wooOk(OAI_REST::woocommerce_patch($request),'Create real pending Woo sandbox');
    $opid=(int)($planned['id']??0);
    wooLife($opid>0 && ($planned['status']??'')==='pending',
        'Sandbox did not create a pending operation');
    wooLife((float)wc_get_product($pid)->get_regular_price()===17.25 &&
        (int)wc_get_product($pid)->get_stock_quantity()===4,
        'Sandbox planning modified live product');

    wooOk(OAI_AI_Lifecycle_Control::perform($opid,'approve','disposable-woocommerce-lifecycle',1),
        'Approve scoped operation');
    wooOk(OAI_AI_Lifecycle_Control::perform($opid,'apply','disposable-woocommerce-lifecycle',1),
        'Apply scoped operation');
    $verified=wooOk(OAI_Verify::operation($opid),'Verify Woo Apply');
    wooLife(!empty($verified['verified']),'Woo Apply was not verified');
    $applied=wooOk(OAI_WooCommerce::get_full($pid),'Read after Apply');
    wooLife((float)$applied['regular_price']===18.75 &&
        $applied['stock']===10 && $applied['status']==='draft',
        'Apply did not persist expected price/rounded stock while retaining draft');

    wooOk(OAI_AI_Lifecycle_Control::perform($opid,'rollback','disposable-woocommerce-lifecycle',1),
        'Rollback scoped operation');
    $rolled=wooOk(OAI_Verify::operation($opid),'Verify Woo Rollback');
    wooLife(!empty($rolled['verified']),'Woo Rollback was not verified');
    $final=wooOk(OAI_WooCommerce::get_full($pid),'Read rollback state');
    wooLife((float)$final['regular_price']===(float)$before['regular_price'] &&
        $final['stock']===$before['stock'] &&
        $final['status']==='draft',
        'Rollback did not restore original price and stock');
    echo "PASS: Woo real pending → scoped approval → apply → verify → rollback → verify; draft untouched outside test\n";
} finally {
    wp_set_current_user(1);
    OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    if ($pid) {
        $p=wc_get_product($pid);
        if ($p) $p->delete(true);
    }
}
