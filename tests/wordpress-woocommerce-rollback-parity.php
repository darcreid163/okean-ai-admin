<?php
/**
 * WooCommerce rollback conflict parity (issue #141).
 *
 * Rollback restores the complete product snapshot written by Apply, including
 * child variations, taxonomy terms, media, attributes, descriptions and SEO.
 * Any newer edit to any of those fields must block destructive rollback with
 * 409 instead of being silently overwritten. Only WooCommerce-derived parent
 * price/stock aggregates for variable products are normalized.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Cannot run WooCommerce rollback parity test on real WordPress');
}
function wooRP(bool $ok,string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function wooRP_ok($v,string $msg) {
    if (is_wp_error($v)) throw new RuntimeException($msg.': '.$v->get_error_code().' '.wp_json_encode($v->get_error_data()));
    return $v;
}

wp_set_current_user(1);
$pid=0;$vid=0;$term1=0;$term2=0;$opid1=0;$opid2=0;
try {
    wooRP_ok(OAI_License::activate_local_owner(1),'Activate disposable local owner license');
    wooRP_ok(OAI_AI_Lifecycle_Control::enable(1),'Enable scoped test lifecycle');

    // Variable product with one variation (Color=Red), price 25.00.
    $product=new WC_Product_Variable();
    $product->set_status('draft');
    $product->set_name('Disposable rollback parity');
    $product->set_sku('OAI-RBP-'.substr(str_replace('-','',wp_generate_uuid4()),0,12));
    $pid=(int)$product->save();
    wooRP($pid>0,'Could not create variable product');

    $attr=new WC_Product_Attribute();
    $attr->set_id(0);
    $attr->set_name('Color');
    $attr->set_options(['Red']);
    $attr->set_variation(true);
    $product->set_attributes([$attr]);
    $product->save();

    $v=new WC_Product_Variation();
    $v->set_parent_id($pid);
    $v->set_status('publish');
    $v->set_sku('OAI-RBCHILD-'.substr(str_replace('-','',wp_generate_uuid4()),0,12));
    $v->set_regular_price('25.00');
    $v->set_attributes(['Color'=>'Red']);
    $vid=(int)$v->save();
    wooRP($vid>0,'Could not create variation');

    // Isolated taxonomy fixture: rollback snapshots include term IDs.
    $tax=wp_insert_term('OAI rollback parity '.wp_generate_uuid4(),'product_cat');
    wooRP(!is_wp_error($tax),'Could not create rollback-parity category');
    $term1=(int)$tax['term_id'];
    wp_set_object_terms($pid,[$term1],'product_cat');

    // 1) The parity comparator ignores only WooCommerce-derived parent aggregates.
    $snapshot=wooRP_ok(OAI_WooCommerce::get_full($pid),'Read baseline snapshot');
    wooRP(($snapshot['type']??'')==='variable','Fixture is not a variable product');
    $derived=$snapshot;
    $derived['price']='999.00';
    $derived['stock_status']='outofstock';
    wooRP(OAI_WooCommerce::rollback_snapshot_matches($snapshot,$derived),
        'Derived parent price/stock aggregates were not normalized');
    $variationEdit=$snapshot;
    $variationEdit['variations'][0]['regular_price']='26.00';
    wooRP(!OAI_WooCommerce::rollback_snapshot_matches($variationEdit,$snapshot),
        'A newer child variation edit was ignored by rollback parity');
    $taxonomyEdit=$snapshot;
    $taxonomyEdit['categories'][]=999999;
    wooRP(!OAI_WooCommerce::rollback_snapshot_matches($taxonomyEdit,$snapshot),
        'A newer taxonomy edit was ignored by rollback parity');

    // 2) A newer child variation edit blocks a real approved Rollback.
    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');
    $request->set_param('product_id',$pid);
    $request->set_param('changes',['name'=>'Disposable rollback parity applied']);
    $planned=wooRP_ok(OAI_REST::woocommerce_patch($request),'Create rollback-parity operation');
    $opid1=(int)($planned['id']??0);
    wooRP($opid1>0 && ($planned['status']??'')==='pending','Rollback-parity sandbox operation was not created');
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid1,'approve','disposable-rollback-parity',1),'Approve operation');
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid1,'apply','disposable-rollback-parity',1),'Apply operation');
    $verify=wooRP_ok(OAI_Verify::operation($opid1),'Verify applied operation');
    wooRP(!empty($verify['verified']),'Applied operation failed verification');
    $applied=wooRP_ok(OAI_WooCommerce::get_full($pid),'Read applied snapshot');

    $newer=wc_get_product($vid);
    $newer->set_regular_price('31.00');
    $newer->save();

    $blocked=OAI_AI_Lifecycle_Control::perform($opid1,'rollback','disposable-rollback-parity',1);
    wooRP(is_wp_error($blocked) && $blocked->get_error_code()==='oai_rollback_conflict',
        'Rollback overwrote a newer child variation edit');
    $row=OAI_DB::get($opid1);
    wooRP(($row['status']??'')==='rollback_failed','Rollback conflict was not recorded as rollback_failed');

    // Restoring the exact applied child state makes the same Rollback safe.
    $restore=wc_get_product($vid);
    $restore->set_regular_price($applied['variations'][0]['regular_price']);
    $restore->save();
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid1,'rollback','disposable-rollback-parity',1),
        'Rollback after restoring the child state');
    $rolled=wooRP_ok(OAI_WooCommerce::get_full($pid),'Read rolled-back snapshot');
    wooRP($rolled['name']==='Disposable rollback parity','Rollback did not restore the pre-operation name');
    wooRP((string)$rolled['variations'][0]['regular_price']===(string)$applied['variations'][0]['regular_price'],
        'Rollback did not preserve the applied variation price');

    // 3) A newer taxonomy edit also blocks Rollback; restoring it unblocks.
    $request=new WP_REST_Request('POST','/okean-ai/v1/woocommerce/patch');
    $request->set_param('product_id',$pid);
    $request->set_param('changes',['name'=>'Disposable rollback parity second']);
    $planned2=wooRP_ok(OAI_REST::woocommerce_patch($request),'Create second rollback-parity operation');
    $opid2=(int)($planned2['id']??0);
    wooRP($opid2>0 && ($planned2['status']??'')==='pending','Second rollback-parity operation was not created');
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid2,'approve','disposable-rollback-parity',1),'Approve second operation');
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid2,'apply','disposable-rollback-parity',1),'Apply second operation');
    $verify2=wooRP_ok(OAI_Verify::operation($opid2),'Verify second operation');
    wooRP(!empty($verify2['verified']),'Second applied operation failed verification');
    $applied2=wooRP_ok(OAI_WooCommerce::get_full($pid),'Read second applied snapshot');

    $tax2=wp_insert_term('OAI rollback newer '.wp_generate_uuid4(),'product_cat');
    wooRP(!is_wp_error($tax2),'Could not create newer rollback-parity category');
    $term2=(int)$tax2['term_id'];
    wp_set_object_terms($pid,array_merge([$term2],(array)$applied2['categories']),'product_cat');
    $blocked2=OAI_AI_Lifecycle_Control::perform($opid2,'rollback','disposable-rollback-parity',1);
    wooRP(is_wp_error($blocked2) && $blocked2->get_error_code()==='oai_rollback_conflict',
        'Rollback overwrote a newer taxonomy edit');
    wp_set_object_terms($pid,(array)$applied2['categories'],'product_cat');
    wooRP_ok(OAI_AI_Lifecycle_Control::perform($opid2,'rollback','disposable-rollback-parity',1),
        'Rollback after restoring taxonomy');
    $rolled2=wooRP_ok(OAI_WooCommerce::get_full($pid),'Read second rolled-back snapshot');
    wooRP($rolled2['name']==='Disposable rollback parity','Second rollback did not restore the pre-operation name');

    echo "PASS: WooCommerce rollback compares the complete product snapshot; newer variation and taxonomy edits block rollback (issue #141)\n";
} finally {
    wp_set_current_user(1);
    OAI_AI_Lifecycle_Control::disable(1,'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
    if ($pid) { $p=wc_get_product($pid); if ($p) $p->delete(true); }
    if ($term1) wp_delete_term($term1,'product_cat');
    if ($term2) wp_delete_term($term2,'product_cat');
}
