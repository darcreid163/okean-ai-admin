<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Settles verified WooCommerce payments from the Robokassa gateway.
 * The Robokassa plugin owns callback signature verification and only calls
 * WooCommerce payment_complete() after its Result URL handler accepts a
 * notification. This adapter never trusts SuccessURL/FailURL browser returns.
 */
final class WooCommercePayments {
    private const GATEWAY_PREFIX = 'robokassa';
    private const OPERATOR = 'robokassa';

    public static function boot(): void {
        if (!class_exists('WooCommerce')) return;
        add_action('woocommerce_payment_complete', [self::class, 'paymentComplete'], 30, 1);
        // Reject a correctly signed classic callback if its signed amount differs from the immutable Woo order total.
        add_action('parse_request', [self::class, 'preflightClassicResult'], 0, 1);
        // Robokassa 1.9.2 ResultUrl2 stores a verified OK state but does not call payment_complete().
        add_action('woocommerce_after_order_object_save', [self::class, 'resultUrl2Complete'], 30, 2);
    }

    /**
     * Robokassa 1.9.2 verifies the classic ResultURL signature but its
     * notification handler does not compare OutSum with the Woo order total.
     * Run before its parse_request handler and fail closed on a signed mismatch.
     */
    public static function preflightClassicResult($wp): void {
        if (($_REQUEST['robokassa']??'')!=='result' || !function_exists('robokassa_payment_is_result_url2_request') || robokassa_payment_is_result_url2_request()) return;
        if (!function_exists('robokassa_payment_is_valid_callback_signature') || !function_exists('robokassa_payment_get_callback_password')) return;
        $request=wp_unslash($_REQUEST);
        if (!robokassa_payment_is_valid_callback_signature($request,robokassa_payment_get_callback_password(2))) return;
        $invoice=(string)($request['InvId']??'');
        if ($invoice==='' || !ctype_digit($invoice) || (int)$invoice<=0) return;
        $order=wc_get_order((int)$invoice);
        if (!$order instanceof \WC_Order) return;
        if (!str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX) || (string)$order->get_currency()!=='RUB') {
            status_header(409); echo 'ORDER PAYMENT POLICY MISMATCH'; exit;
        }
        $signedAmount=(float)($request['OutSum']??-1);
        if (!is_finite($signedAmount) || abs($signedAmount-(float)$order->get_total())>0.005) {
            status_header(409); echo 'PAYMENT AMOUNT MISMATCH'; exit;
        }
    }

    /**
     * ResultUrl2 in Robokassa 1.9.2 verifies RS256 and persists the operation
     * state before saving the order. Its OK branch does not mark WooCommerce
     * paid, so complete only that verified, server-persisted state here.
     */
    public static function resultUrl2Complete($order, $dataStore): void {
        if (!$order instanceof \WC_Order || $order->is_paid()) return;
        $method=(string)$order->get_payment_method();
        if ($method==='' || !str_starts_with($method,self::GATEWAY_PREFIX)) return;
        if ((string)$order->get_meta('_robokassa_result2_state',true)!=='OK') return;
        $operationKey=(string)$order->get_meta('_robokassa_operation_key',true);
        $timestamp=(int)$order->get_meta('_robokassa_result2_timestamp',true);
        if ($operationKey==='' || $timestamp<=0 || $timestamp>time()+300 || $timestamp<time()-(7*86400)) return;
        // The ResultUrl2 verifier has already checked RS256, shop identity,
        // timestamp and allowed state before writing these metadata fields.
        $order->payment_complete($operationKey);
    }

    public static function paymentComplete($orderId): void {
        if (!function_exists('wc_get_order')) return;
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \WC_Order) return;
        $method=(string)$order->get_payment_method();
        if ($method==='' || !str_starts_with($method,self::GATEWAY_PREFIX)) return;
        try {
            WordPress::settlePaidWooOrder($order);
        } catch (\Throwable $e) {
            // Do not acknowledge a Result URL callback when entitlement settlement
            // failed: Robokassa will retry, and the unique order ledger is idempotent.
            $order->add_order_note('WP AI Admin: автоматическая активация лицензии не завершена. Платёж сохранён; сервер повторит обработку уведомления.');
            error_log('WP AI Admin Cloud billing settlement failed for order '.$order->get_id().': '.get_class($e).' '.$e->getMessage());
            throw new \RuntimeException('WP AI Admin paid entitlement settlement failed',0,$e);
        }
    }

    public static function settle(Store $store, array $policy, \WC_Order $order): array {
        if (!$order->is_paid()) throw new Fault('payment_not_confirmed',409);
        if ((string)$order->get_currency()!=='RUB') throw new Fault('payment_currency_mismatch',409);
        if ((int)$order->get_customer_id()<=0) throw new Fault('registered_customer_required',409);
        if ((string)$order->get_payment_method()==='' || !str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX)) throw new Fault('payment_gateway_mismatch',409);
        if (($policy['prices_approved']??false)!==true || ($policy['schemes']['tariffs_v3']['prices_approved']??false)!==true) throw new Fault('paid_prices_not_approved',503);
        $catalog=(array)($policy['commerce']['products']??[]);
        if (!$catalog) throw new Fault('paid_product_catalog_unavailable',503);

        $matched=[];
        $expected=0.0;
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) throw new Fault('invalid_order_line',409);
            $productId=(int)$item->get_product_id();
            $variationId=(int)$item->get_variation_id();
            $quantity=(int)$item->get_quantity();
            if ($quantity!==1) throw new Fault('paid_product_quantity_invalid',409);
            $entry=null;
            foreach ($catalog as $offer) {
                if ((int)($offer['product_id']??0)===$productId || ($variationId>0 && (int)($offer['product_id']??0)===$variationId)) { $entry=$offer; break; }
            }
            if (!$entry || ($entry['plan']??'')==='free') throw new Fault('unrecognized_paid_product',409);
            $product=$item->get_product();
            if (!$product) throw new Fault('paid_product_missing',409);
            $sku=(string)($entry['sku']??'');
            if ($sku==='' || (string)$product->get_sku()!==$sku) throw new Fault('paid_product_sku_mismatch',409);
            $lineExpected=(int)($entry['price_rub']??-1);
            if ($lineExpected<=0 || abs(((float)$item->get_total()+(float)$item->get_total_tax())-(float)$lineExpected)>0.00001) throw new Fault('paid_product_line_total_mismatch',409);
            $expected+=(float)$lineExpected;
            $matched[]=$entry;
        }
        if (count($matched)!==1) throw new Fault('exactly_one_license_offer_required',409);
        if (count($order->get_items('fee'))>0 || abs((float)$order->get_shipping_total())>0.00001 || abs((float)$order->get_discount_total())>0.00001) throw new Fault('order_adjustments_not_supported',409);
        $offer=$matched[0];
        if (abs((float)$order->get_total()-$expected)>0.00001) throw new Fault('paid_order_total_mismatch',409);

        $owner=(string)(int)$order->get_customer_id();
        $now=time();
        $store->ensureLicenseSchema();
        $store->ensureCommerceSchema();
        return $store->transaction(function() use($store,$policy,$order,$offer,$owner,$now): array {
            $existing=$store->row('SELECT * FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?'.$store->lock(),[(string)$order->get_id()]);
            if ($existing) {
                if ($existing['status']==='settled') {
                    if ($existing['owner']!==$owner || $existing['plan']!==$offer['plan'] || (int)$existing['product_id']!==(int)$offer['product_id']) throw new Fault('payment_order_replay_conflict',409);
                    return ['settled'=>true,'idempotent'=>true,'license_id'=>$existing['license_id'],'expires_at'=>(int)$existing['expires_at']];
                }
                throw new Fault('payment_settlement_in_progress',503);
            }
            $store->execute('INSERT INTO '.$store->table('commerce_payments').' (gateway_order_id,transaction_id,owner,product_id,sku,plan,period,amount_rub,currency,status,license_id,starts_at,expires_at,payload_hash,created_at,settled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                (string)$order->get_id(),(string)$order->get_transaction_id(),$owner,(int)$offer['product_id'],(string)$offer['sku'],(string)$offer['plan'],(string)$offer['period'],(int)$offer['price_rub'],'RUB','processing','',0,0,hash('sha256',$order->get_id().'|'.$order->get_order_key().'|'.$order->get_total().'|'.$offer['sku']),$now,0
            ]);

            $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
            if (!$account || (int)$account['disabled']!==0) throw new Fault('cloud_account_unavailable',403);
            $currentExpiry=0;
            if (!empty($account['license_id'])) {
                $current=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=? AND owner=?',[$account['license_id'],$owner]);
                if ($current && $current['status']===Licenses::ACTIVE && $current['plan']===(string)$offer['plan'] && $current['quota_scheme']==='tariffs_v3' && $current['expires_at']!==null) $currentExpiry=(int)$current['expires_at'];
            }
            $startsAt=max($now,$currentExpiry);
            $base=(new \DateTimeImmutable('@'.$startsAt))->setTimezone(new \DateTimeZone('UTC'));
            $period=(string)$offer['period'];
            if ($period==='month') $expiresAt=$base->modify('+1 month')->getTimestamp();
            elseif ($period==='year') $expiresAt=$base->modify('+1 year')->getTimestamp();
            else throw new Fault('paid_product_period_invalid',409);
            if ($expiresAt<=$startsAt) throw new Fault('paid_license_expiry_invalid',503);

            $license=Licenses::issue($store,$policy,self::OPERATOR,$owner,(string)$offer['plan'],'tariffs_v3',$now,[
                'starts_at'=>$now,'expires_at'=>$expiresAt,'source'=>Licenses::SOURCE_AUTOMATIC,
                'activation_limit'=>(int)($offer['sites']??1),
                'comment'=>'WooCommerce order #'.$order->get_id().' / Robokassa'
            ]);
            $store->execute('UPDATE '.$store->table('commerce_payments').' SET status=?,license_id=?,starts_at=?,expires_at=?,settled_at=? WHERE gateway_order_id=? AND status=?',[
                'settled',(string)$license['id'],$now,$expiresAt,$now,(string)$order->get_id(),'processing'
            ]);
            return ['settled'=>true,'idempotent'=>false,'license_id'=>(string)$license['id'],'expires_at'=>$expiresAt];
        });
    }
}
