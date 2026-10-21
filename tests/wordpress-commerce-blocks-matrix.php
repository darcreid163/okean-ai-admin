<?php
/**
 * Real WordPress + WooCommerce + Gutenberg read/plan acceptance.
 *
 * Disposable CI ONLY. Creates draft fixtures, never publishes them, does not
 * call the plugin's live mutation endpoint, and deletes all fixtures.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false &&
     strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Refusing commerce/Gutenberg test outside disposable CI');
}
function oai_matrix_assert($yes, $message) {
    if (!$yes) throw new RuntimeException($message);
}
function oai_matrix_ok($value, $message) {
    if (is_wp_error($value)) {
        throw new RuntimeException($message.': '.$value->get_error_code());
    }
    return $value;
}
wp_set_current_user(1);
oai_matrix_assert(class_exists('OAI_WooCommerce') &&
    class_exists('OAI_Gutenberg_Resolver') &&
    OAI_WooCommerce::available(), 'Native WooCommerce and Gutenberg unavailable');

$simple=0;$variable=0;$variation=0;$post=0;
$sku='OAI-CI-'.substr(str_replace('-','',wp_generate_uuid4()),0,12);
try {
    // Create only draft products through the isolated Native model adapter.
    $created=oai_matrix_ok(OAI_WooCommerce::create_draft([
        'type'=>'simple', 'name'=>'Disposable Simple CI',
        'sku'=>$sku.'-S', 'regular_price'=>'12.50',
        'manage_stock'=>true, 'stock_quantity'=>'6.5',
        'description'=>'Temporary integration fixture',
    ]),'Create draft simple product');
    $simple=(int)$created['id'];
    oai_matrix_assert($simple>0 && $created['status']==='draft' &&
        $created['type']==='simple' && (float)$created['regular_price']===12.5 &&
        $created['stock']===7,'Simple draft price/rounded stock not persisted');
    oai_matrix_assert(get_post_status($simple)==='draft','Simple fixture was published');

    // Planning never changes the draft. Store/verify are separate lifecycle.
    $planned=oai_matrix_ok(OAI_WooCommerce::patch($simple,[
        'regular_price'=>'14.70', 'stock_quantity'=>'8.5'
    ]),'Plan simple product edit');
    oai_matrix_assert($planned['before']['stock']===7 &&
        $planned['after']['stock']===9 &&
        (float)$planned['after']['regular_price']===14.7,
        'Simple patch did not normalize stock/price');
    $unchanged=oai_matrix_ok(OAI_WooCommerce::get_full($simple),'Re-read draft');
    oai_matrix_assert($unchanged['stock']===7 &&
        (float)$unchanged['regular_price']===12.5,
        'Sandbox planning unexpectedly wrote product');

    $found=oai_matrix_ok(OAI_WooCommerce::search('', $sku.'-S', 10),
        'Find draft by exact SKU');
    oai_matrix_assert($found['count']>=1 &&
        in_array($simple,array_column($found['items'],'id'),true),
        'WooCommerce SKU search failed');

    oai_matrix_assert(is_wp_error(OAI_WooCommerce::patch($simple,[
        'regular_price'=>'-1'
    ])),'Negative price accepted');
    $saleTooHigh=OAI_WooCommerce::patch($simple,[
        'regular_price'=>'5.00','sale_price'=>'6.00'
    ]);
    oai_matrix_assert(is_wp_error($saleTooHigh) &&
        $saleTooHigh->get_error_code()==='oai_woo_sale_price',
        'Sale price above regular price accepted');

    // Build a draft variable product with one real variation.
    $variant=oai_matrix_ok(OAI_WooCommerce::create_draft([
        'type'=>'variable', 'name'=>'Disposable Variable CI',
        'sku'=>$sku.'-V',
        'variations'=>[[
            'sku'=>$sku.'-CHILD',
            'regular_price'=>'19.50',
            'manage_stock'=>true,
            'stock_quantity'=>2
        ]]
    ]),'Create draft variable with child');
    $variable=(int)$variant['id'];
    oai_matrix_assert($variable>0 && $variant['type']==='variable' &&
        $variant['status']==='draft','Variable product not a draft');
    $details=oai_matrix_ok(OAI_WooCommerce::get_full($variable),
        'Read full variable product');
    oai_matrix_assert(count($details['variations'])===1,
        'Variable child not included in full read');
    $variation=(int)$details['variations'][0]['id'];
    oai_matrix_assert($variation>0 &&
        (float)$details['variations'][0]['regular_price']===19.5,
        'Variation price missing');
    $pages=oai_matrix_ok(OAI_WooCommerce::variations($variable,1,10),
        'Paginate variations');
    oai_matrix_assert($pages['total']===1 && $pages['count']===1 &&
        (int)$pages['items'][0]['id']===$variation,
        'Variation pagination disagrees with full read');
    $change=oai_matrix_ok(OAI_WooCommerce::patch($variable,[
        'variations'=>[['id'=>$variation,'regular_price'=>'21.00']]
    ]),'Plan variation price change');
    oai_matrix_assert((float)$change['after']['variations'][0]['regular_price']===21.0,
        'Variation update plan failed');
    oai_matrix_assert((float)wc_get_product($variation)->get_regular_price()===19.5,
        'Variation plan wrote to WordPress');
    $cross=OAI_WooCommerce::patch($simple,[
        'variations'=>[['id'=>$variation,'regular_price'=>'25.00']]
    ]);
    oai_matrix_assert(is_wp_error($cross) &&
        $cross->get_error_code()==='oai_woo_variations',
        'Accepted variation patch on wrong product type');

    // Gutenberg: parse, locate, and plan an exact block change with no write.
    $content='<!-- wp:paragraph --><p>AI Admin block one</p><!-- /wp:paragraph -->'.
        "\n".'<!-- wp:paragraph --><p>AI Admin block two</p><!-- /wp:paragraph -->';
    $post=oai_matrix_ok(wp_insert_post([
        'post_title'=>'Disposable Gutenberg CI',
        'post_content'=>$content, 'post_type'=>'page', 'post_status'=>'draft'
    ],true),'Create Gutenberg draft');
    $resolved=oai_matrix_ok(OAI_Gutenberg_Resolver::resolve_post($post),
        'Resolve Gutenberg document');
    oai_matrix_assert($resolved['content_mode']==='gutenberg' &&
        $resolved['has_native_blocks']===true,
        'Gutenberg post incorrectly classified');
    $locate=oai_matrix_ok(OAI_Gutenberg_Resolver::locate($post,[
        'block_name'=>'core/paragraph','text'=>'block one'
    ]),'Locate exact paragraph');
    oai_matrix_assert($locate['count']===1 &&
        $locate['matches'][0]['path']==='0',
        'Could not locate first Gutenberg paragraph');
    $patch=oai_matrix_ok(OAI_Gutenberg_Resolver::patch($post,[
        'path'=>'0'
    ],null,'<p>New first paragraph</p>'),'Plan Gutenberg patch');
    oai_matrix_assert(!empty($patch['validation']['round_trip']) &&
        str_contains($patch['after'],'New first paragraph') &&
        get_post($post)->post_content===$content,
        'Gutenberg patch plan mutated draft or lost round-trip');

    $ambiguous=OAI_Gutenberg_Resolver::patch($post,[
        'block_name'=>'core/paragraph'
    ],null,'<p>Ambiguous</p>');
    oai_matrix_assert(is_wp_error($ambiguous) &&
        $ambiguous->get_error_code()==='oai_block_ambiguous',
        'Ambiguous Gutenberg block selector accepted');

    oai_matrix_assert(get_post_status($post)==='draft' &&
        get_post_status($variable)==='draft',
        'Temporary product or page published unexpectedly');
    echo "PASS: disposable WooCommerce simple/variable, SKU, prices/stock, variation patch, Gutenberg locate/roundtrip and ambiguity\n";
} finally {
    wp_set_current_user(1);
    if ($variation) {
        $v=wc_get_product($variation);
        if ($v) $v->delete(true);
    }
    if ($variable) {
        $p=wc_get_product($variable);
        if ($p) $p->delete(true);
    }
    if ($simple) {
        $p=wc_get_product($simple);
        if ($p) $p->delete(true);
    }
    if ($post) wp_delete_post($post,true);
}
