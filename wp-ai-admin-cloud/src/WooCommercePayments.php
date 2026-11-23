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
        add_action('wpai_cloud_retry_paid_order', [self::class, 'retryPaidOrder'], 10, 1);
        // The official Robokassa GetState handler runs at priority 10; process its persisted terminal result afterwards.
        add_action('robokassa_refund_check_event', [self::class, 'refundStatusChecked'], 20, 2);
        add_action('wpai_cloud_retry_refund', [self::class, 'retryConfirmedRefund'], 10, 2);
        // Keep WooCommerce from marking an order fully refunded while any gateway refund is still pending.
        add_filter('woocommerce_order_fully_refunded_status', [self::class, 'deferOrderRefundUntilAllRequestsTerminal'], 20, 3);
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
        if (!$order instanceof \WC_Order || !$order->is_paid()) return;
        $method=(string)$order->get_payment_method();
        if ($method==='' || !str_starts_with($method,self::GATEWAY_PREFIX)) return;
        try {
            WordPress::settlePaidWooOrder($order);
            self::clearRetryState($order);
        } catch (\Throwable $e) {
            if (self::isPermanentFailure($e)) {
                self::noteOnce($order,'permanent','WP AI Admin: платёж подтверждён, но лицензия не выдана. Заказ требует проверки администратором: '.self::safeReason($e));
                error_log('WP AI Admin Cloud billing permanent rejection for order '.$order->get_id().': '.self::safeReason($e));
                return;
            }
            self::scheduleRetry($order,60,$e);
        }
    }

    /** Durable retry for transient Cloud/database failures after Woo marks the order paid. */
    public static function retryPaidOrder($orderId): void {
        if (!function_exists('wc_get_order')) return;
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \WC_Order || !$order->is_paid()) return;
        if (!str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX)) return;
        try {
            WordPress::settlePaidWooOrder($order);
            self::clearRetryState($order);
            self::noteOnce($order,'recovered','WP AI Admin: лицензия успешно активирована после повторной проверки платежа.');
        } catch (\Throwable $e) {
            if (self::isPermanentFailure($e)) {
                self::noteOnce($order,'permanent','WP AI Admin: автоматическая активация остановлена; заказ требует проверки администратором: '.self::safeReason($e));
                error_log('WP AI Admin Cloud billing permanent rejection for order '.$order->get_id().': '.self::safeReason($e));
                return;
            }
            self::scheduleRetry($order,300,$e);
        }
    }

    /**
     * Runs after the official Robokassa GetState handler. Pending/canceled
     * requests never change Cloud entitlements.
     */
    public static function refundStatusChecked($orderId,$requestId): void {
        self::processConfirmedRefund((int)$orderId,(string)$requestId);
    }

    public static function deferOrderRefundUntilAllRequestsTerminal($status,$orderId,$refundId) {
        if (!function_exists('wc_get_order')) return $status;
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \\WC_Order || !str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX)) return $status;
        $requests=$order->get_meta('_robokassa_refund_requests',true);
        if (!is_array($requests)) return $status;
        foreach ($requests as $request) {
            if (is_array($request) && ($request['status']??'')==='processing') return false;
        }
        return $status;
    }

    public static function retryConfirmedRefund($orderId,$requestId): void {
        self::processConfirmedRefund((int)$orderId,(string)$requestId);
    }

    private static function processConfirmedRefund(int $orderId,string $requestId): void {
        if ($orderId<=0 || $requestId==='' || !function_exists('wc_get_order')) return;
        $order=wc_get_order($orderId);
        if (!$order instanceof \WC_Order || !str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX)) return;
        $requests=$order->get_meta('_robokassa_refund_requests',true);
        if (!is_array($requests) || !isset($requests[$requestId]) || !is_array($requests[$requestId])) return;
        $request=$requests[$requestId];
        if (($request['status']??'')!=='finished') return;
        $amount=(float)($request['amount']??0);
        $isFull=!empty($request['is_full']);
        if (!is_finite($amount) || $amount<=0 || $amount>(float)$order->get_total()+0.005) return;
        try {
            $result=WordPress::settleConfirmedWooRefund($order,$requestId,$amount,$isFull);
            // If out-of-order partial callbacks complete the cumulative full amount,
            // the final callback may itself have is_full=false in Woo's local metadata.
            if (($result['refund_kind']??'')==='full' && $order->get_status()!=='refunded') {
                $order->update_status('refunded');
            }
            $order->delete_meta_data('_wpai_cloud_refund_retry_count_'.$requestId);
            $order->save_meta_data();
            if ($result['entitlement_revoked']) {
                $message='WP AI Admin: подтверждён полный возврат Robokassa; соответствующая лицензия отозвана, тариф аккаунта возвращён к Free.';
            } elseif ($isFull) {
                $message='WP AI Admin: полный возврат подтверждён, но лицензия уже не является текущей для этого платежа. Новая покупка не отключена; требуется сверка.';
            } else {
                $message='WP AI Admin: частичный возврат Robokassa подтверждён. Лицензия и доступ сохранены.';
            }
            self::refundNoteOnce($order,$requestId,$message);
        } catch (\Throwable $e) {
            $meta='_wpai_cloud_refund_retry_count_'.$requestId;
            $attempt=(int)$order->get_meta($meta,true)+1;
            if ($attempt<=12) {
                $order->update_meta_data($meta,$attempt);
                $order->save_meta_data();
                $args=[$orderId,$requestId];
                if (!wp_next_scheduled('wpai_cloud_retry_refund',$args)) {
                    wp_schedule_single_event(time()+min(3600,60*$attempt),'wpai_cloud_retry_refund',$args);
                }
            } else {
                self::refundNoteOnce($order,$requestId,'WP AI Admin: возврат Robokassa подтверждён, но Cloud не смог обработать событие после 12 попыток. Лицензия не изменена; требуется ручная сверка.');
            }
            error_log('WP AI Admin Cloud refund processing failed for order '.$orderId.': '.self::safeReason($e));
        }
    }

    private static function refundNoteOnce(\WC_Order $order,string $requestId,string $message): void {
        $meta='_wpai_cloud_refund_note_'.hash('sha256',$requestId);
        if ((string)$order->get_meta($meta,true)==='yes') return;
        $order->add_order_note($message);
        $order->update_meta_data($meta,'yes');
        $order->save_meta_data();
    }

    private static function scheduleRetry(\WC_Order $order,int $delay,\Throwable $error): void {
        $attempt=(int)$order->get_meta('_wpai_cloud_entitlement_retry_count',true)+1;
        if ($attempt>12) {
            self::noteOnce($order,'exhausted','WP AI Admin: не удалось автоматически активировать лицензию после 12 попыток. Платёж сохранён; требуется ручная сверка.');
            error_log('WP AI Admin Cloud billing retries exhausted for order '.$order->get_id().': '.self::safeReason($error));
            return;
        }
        $order->update_meta_data('_wpai_cloud_entitlement_retry_count',$attempt);
        $order->save_meta_data();
        self::noteOnce($order,'retry','WP AI Admin: платёж принят. Активация лицензии временно задерживается; система повторит попытку автоматически.');
        $args=[(int)$order->get_id()];
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpai_cloud_retry_paid_order',$args)) return;
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time()+max(60,$delay),'wpai_cloud_retry_paid_order',$args);
        } else {
            error_log('WP AI Admin Cloud billing retry scheduler unavailable for order '.$order->get_id().': '.self::safeReason($error));
        }
    }

    private static function clearRetryState(\WC_Order $order): void {
        if (method_exists($order,'delete_meta_data')) {
            $order->delete_meta_data('_wpai_cloud_entitlement_retry_count');
            $order->delete_meta_data('_wpai_cloud_entitlement_note_retry');
            $order->delete_meta_data('_wpai_cloud_entitlement_note_permanent');
            $order->delete_meta_data('_wpai_cloud_entitlement_note_exhausted');
            $order->save_meta_data();
        }
    }

    private static function noteOnce(\WC_Order $order,string $key,string $message): void {
        $meta='_wpai_cloud_entitlement_note_'.$key;
        if ((string)$order->get_meta($meta,true)==='yes') return;
        $order->add_order_note($message);
        $order->update_meta_data($meta,'yes');
        $order->save_meta_data();
    }

    private static function safeReason(\Throwable $error): string {
        return $error instanceof Fault ? preg_replace('/[^a-z0-9_]/i','', $error->reason) : 'temporary_settlement_error';
    }

    private static function isPermanentFailure(\Throwable $error): bool {
        if (!$error instanceof Fault) return false;
        return in_array($error->reason,[
            'payment_not_confirmed','payment_currency_mismatch','registered_customer_required',
            'payment_gateway_mismatch','paid_prices_not_approved','unrecognized_paid_product',
            'invalid_order_line','paid_product_quantity_invalid','paid_product_missing',
            'paid_product_sku_mismatch','paid_product_line_total_mismatch','paid_order_total_mismatch',
            'exactly_one_license_offer_required','order_adjustments_not_supported','paid_product_period_invalid',
            'paid_license_expiry_invalid','payment_order_replay_conflict','cloud_account_unavailable',
        ],true);
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
    /**
     * Apply a terminal Robokassa refund state to the Cloud payment ledger.
     * The caller must be the post-GetState hook and supply the stored request.
     * Partial refunds are recorded but never change an entitlement.
     */
    public static function refund(Store $store,array $policy,\WC_Order $order,string $requestId,float $amount,bool $isFull): array {
        if ($requestId==='' || strlen($requestId)>128 || !preg_match('/^[A-Za-z0-9_-]+$/D',$requestId)) throw new Fault('refund_request_id_invalid',409);
        if (!is_finite($amount) || $amount<=0) throw new Fault('refund_amount_invalid',409);
        $amountKopecks=(int)round($amount*100);
        if ($amountKopecks<=0 || abs($amount-($amountKopecks/100))>0.00001) throw new Fault('refund_amount_invalid',409);
        if ((string)$order->get_currency()!=='RUB' || !str_starts_with((string)$order->get_payment_method(),self::GATEWAY_PREFIX)) throw new Fault('refund_payment_mismatch',409);
        $owner=(string)(int)$order->get_customer_id();
        if ((int)$owner<=0) throw new Fault('registered_customer_required',409);
        $store->ensureLicenseSchema();
        $store->ensureCommerceSchema();
        $now=time();
        $payloadHash=hash('sha256',implode('|',[(string)$order->get_id(),$owner,(string)$amountKopecks,$isFull?'full':'partial']));
        return $store->transaction(function() use($store,$policy,$order,$requestId,$amountKopecks,$isFull,$owner,$payloadHash,$now): array {
            $payment=$store->row('SELECT * FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?'.$store->lock(),[(string)$order->get_id()]);
            if (!$payment || $payment['status']==='processing' || $payment['status']==='') throw new Fault('refund_paid_order_not_settled',409);
            if ($payment['owner']!==$owner || $payment['currency']!=='RUB') throw new Fault('refund_payment_owner_mismatch',409);
            $existing=$store->row('SELECT * FROM '.$store->table('commerce_refunds').' WHERE request_id=?'.$store->lock(),[$requestId]);
            if ($existing) {
                if ($existing['gateway_order_id']!==(string)$order->get_id() || !hash_equals((string)$existing['payload_hash'],$payloadHash)) throw new Fault('refund_replay_conflict',409);
                return ['recorded'=>true,'idempotent'=>true,'refund_kind'=>$existing['refund_kind'],'outcome'=>$existing['outcome'],'entitlement_revoked'=>$existing['outcome']==='license_revoked'];
            }

            // Determine a full refund from the cumulative confirmed amount, not
            // the gateway's local is_full flag. WooCommerce may include pending
            // refunds when it calculates that flag, and callbacks can finish out of order.
            $confirmedBefore=(int)($store->row('SELECT COALESCE(SUM(amount_kopecks),0) AS total FROM '.$store->table('commerce_refunds').' WHERE gateway_order_id=?',[(string)$order->get_id()])['total']??0);
            $confirmedTotal=$confirmedBefore+$amountKopecks;
            $paidTotal=(int)$payment['amount_rub']*100;
            if ($paidTotal<=0 || $confirmedTotal>$paidTotal) throw new Fault('refund_total_exceeds_payment',409);
            $isActuallyFull=$confirmedTotal===$paidTotal;
            $kind=$isActuallyFull?'full':'partial';
            $outcome='partial_recorded';
            $revoked=false;
            if ($isActuallyFull) {
                $account=$store->row('SELECT license_id FROM '.$store->table('accounts').' WHERE owner=?'.$store->lock(),[$owner]);
                $licenseId=(string)($payment['license_id']??'');
                if ($account && $licenseId!=='' && (string)($account['license_id']??'')===$licenseId) {
                    $license=$store->row('SELECT status FROM '.$store->table('licenses').' WHERE id=? AND owner=?'.$store->lock(),[$licenseId,$owner]);
                    if ($license && in_array((string)$license['status'],[Licenses::ACTIVE,Licenses::SUSPENDED],true)) {
                        Licenses::revoke($store,$policy,self::OPERATOR,$licenseId,$now);
                        $outcome='license_revoked';
                        $revoked=true;
                    } else {
                        $outcome='stale_payment_refund';
                    }
                } else {
                    // A later renewal or another purchase now owns the account.
                    // Never revoke that newer entitlement because an older order was refunded.
                    $outcome='stale_payment_refund';
                }
                $store->execute('UPDATE '.$store->table('commerce_payments').' SET status=? WHERE gateway_order_id=?',['refunded',(string)$order->get_id()]);
            } else {
                $store->execute('UPDATE '.$store->table('commerce_payments').' SET status=? WHERE gateway_order_id=? AND status IN (?,?)',['partially_refunded',(string)$order->get_id(),'settled','partially_refunded']);
            }

            $store->execute('INSERT INTO '.$store->table('commerce_refunds').' (request_id,gateway_order_id,owner,license_id,amount_kopecks,refund_kind,outcome,payload_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[
                $requestId,(string)$order->get_id(),$owner,(string)($payment['license_id']??''),$amountKopecks,$kind,$outcome,$payloadHash,$now
            ]);
            return ['recorded'=>true,'idempotent'=>false,'refund_kind'=>$kind,'outcome'=>$outcome,'entitlement_revoked'=>$revoked];
        });
    }

}
