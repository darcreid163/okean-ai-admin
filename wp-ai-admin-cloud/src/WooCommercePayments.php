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

    private static function addCalendarTerm(int $timestamp,int $months): int {
        $utc=new \DateTimeZone('UTC');
        $date=(new \DateTimeImmutable('@'.$timestamp))->setTimezone($utc);
        $year=(int)$date->format('Y');
        $month=(int)$date->format('n')+$months;
        $year+=intdiv($month-1,12);
        $month=(($month-1)%12)+1;
        $first=new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00',$year,$month),$utc);
        $day=min((int)$date->format('j'),(int)$first->format('t'));
        return $first->setDate($year,$month,$day)
            ->setTime((int)$date->format('G'),(int)$date->format('i'),(int)$date->format('s'))
            ->getTimestamp();
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
            $quantity=(float)$item->get_quantity();
            if (!is_finite($quantity) || abs($quantity-1.0)>0.00001) throw new Fault('paid_product_quantity_invalid',409);
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
                    $transactionHash=(string)$order->get_transaction_id()!==''?hash('sha256',(string)$order->get_transaction_id()):null;
                    $payloadHash=hash('sha256',$order->get_id().'|'.$order->get_order_key().'|'.$order->get_total().'|'.$offer['sku'].'|'.($transactionHash??''));
                    if ($existing['owner']!==$owner || $existing['plan']!==$offer['plan'] || (int)$existing['product_id']!==(int)$offer['product_id'] || $existing['sku']!==$offer['sku'] || $existing['period']!==$offer['period'] || (int)$existing['amount_rub']!==(int)$offer['price_rub'] || $existing['currency']!=='RUB' || !hash_equals((string)$existing['payload_hash'],$payloadHash)) throw new Fault('payment_order_replay_conflict',409);
                    return ['settled'=>true,'idempotent'=>true,'license_id'=>$existing['license_id'],'expires_at'=>(int)$existing['expires_at']];
                }
                throw new Fault('payment_settlement_in_progress',503);
            }
            $transactionHash=(string)$order->get_transaction_id()!==''?hash('sha256',(string)$order->get_transaction_id()):null;
            $payloadHash=hash('sha256',$order->get_id().'|'.$order->get_order_key().'|'.$order->get_total().'|'.$offer['sku'].'|'.($transactionHash??''));
            $store->execute('INSERT INTO '.$store->table('commerce_payments').' (gateway_order_id,transaction_hash,owner,product_id,sku,plan,period,amount_rub,currency,status,license_id,starts_at,expires_at,payload_hash,created_at,settled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                (string)$order->get_id(),$transactionHash,$owner,(int)$offer['product_id'],(string)$offer['sku'],(string)$offer['plan'],(string)$offer['period'],(int)$offer['price_rub'],'RUB','processing','',0,0,$payloadHash,$now,0
            ]);

            $account=$store->row('SELECT * FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
            if (!$account || (int)$account['disabled']!==0) throw new Fault('cloud_account_unavailable',403);
            $currentExpiry=0;
            if (!empty($account['license_id'])) {
                $current=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=? AND owner=?',[$account['license_id'],$owner]);
                if ($current && $current['status']===Licenses::ACTIVE && $current['plan']===(string)$offer['plan'] && $current['quota_scheme']==='tariffs_v3' && $current['expires_at']!==null) $currentExpiry=(int)$current['expires_at'];
            }
            $startsAt=max($now,$currentExpiry);
            $period=(string)$offer['period'];
            if ($period!=='month' && $period!=='year') throw new Fault('paid_product_period_invalid',409);
            $expiresAt=self::addCalendarTerm($startsAt,$period==='month'?1:12);
            if ($expiresAt<=$startsAt) throw new Fault('paid_license_expiry_invalid',503);

            $license=Licenses::issue($store,$policy,self::OPERATOR,$owner,(string)$offer['plan'],'tariffs_v3',$now,[
                'starts_at'=>$now,'expires_at'=>$expiresAt,'source'=>Licenses::SOURCE_AUTOMATIC,
                'activation_limit'=>(int)($offer['sites']??1),
                'comment'=>'WooCommerce order #'.$order->get_id().' / Robokassa'
            ]);
            $settled=$store->execute('UPDATE '.$store->table('commerce_payments').' SET status=?,license_id=?,starts_at=?,expires_at=?,settled_at=? WHERE gateway_order_id=? AND status=?',[
                'settled',(string)$license['id'],$now,$expiresAt,$now,(string)$order->get_id(),'processing'
            ])->rowCount();
            if ($settled!==1) throw new Fault('payment_settlement_write_failed',503);
            return ['settled'=>true,'idempotent'=>false,'license_id'=>(string)$license['id'],'expires_at'=>$expiresAt];
        });
    }
}
