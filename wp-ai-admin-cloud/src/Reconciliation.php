<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Read-only comparison of recent Robokassa WooCommerce orders and Cloud ledgers.
 * This class never issues, revokes, extends or changes a licence/order.
 */
final class Reconciliation {
    public static function report(Store $store, array $policy, int $limit=500): array {
        if (!function_exists('wc_get_orders') || !class_exists('WooCommerce')) {
            throw new Fault('woocommerce_unavailable',503);
        }
        $limit=max(1,min(500,$limit));
        $store->ensureLicenseSchema();
        $store->ensureCommerceSchema();

        $orders=wc_get_orders([
            'limit'=>$limit,
            'status'=>['wc-processing','wc-completed','wc-on-hold','wc-refunded','wc-failed','wc-cancelled'],
            'orderby'=>'date',
            'order'=>'DESC',
            'return'=>'objects',
        ]);
        if (!is_array($orders)) throw new Fault('woocommerce_order_query_failed',503);

        $catalog=[];
        foreach ((array)($policy['commerce']['products']??[]) as $offer) {
            if (is_array($offer) && isset($offer['product_id'])) {
                $catalog[(int)$offer['product_id']]=$offer;
            }
        }

        $issues=[];
        $seen=[];
        $checked=0;
        $paid=0;
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) continue;
            $method=(string)$order->get_payment_method();
            if (!str_starts_with($method,'robokassa')) continue;
            $orderId=(string)$order->get_id();
            $seen[$orderId]=true;
            $checked++;
            $isPaid=$order->is_paid();
            if ($isPaid) $paid++;

            $payment=$store->row('SELECT * FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?',[$orderId]);
            if (!$payment) {
                if ($isPaid) self::add($issues,'paid_order_missing_ledger',$orderId,'Заказ отмечен оплаченным, но записи в Cloud commerce_payments нет.');
                continue;
            }
            if (method_exists($order,'get_status') && $order->get_status()==='refunded' && (string)$payment['status']!=='refunded') {
                self::add($issues,'woo_refund_cloud_status_mismatch',$orderId,'WooCommerce пометил заказ как возвращённый, но Cloud-платёж не имеет статуса refunded.');
            }
            if (($payment['status']??'')==='processing') {
                self::add($issues,'payment_stuck_processing',$orderId,'Платёжная запись осталась в processing; требуется проверка транзакции.');
            }
            $owner=(string)(int)$order->get_customer_id();
            if ($owner==='0' || (string)$payment['owner']!==$owner) {
                self::add($issues,'payment_owner_mismatch',$orderId,'ID покупателя WooCommerce не совпадает с владельцем Cloud-платежа.');
            }
            if ((string)$order->get_currency()!==(string)$payment['currency'] || (string)$payment['currency']!=='RUB') {
                self::add($issues,'payment_currency_mismatch',$orderId,'Валюта заказа и платёжного реестра не совпадает или не равна RUB.');
            }
            $amount=(int)round((float)$order->get_total()*100);
            if ($amount!==(int)$payment['amount_rub']*100) {
                self::add($issues,'payment_amount_mismatch',$orderId,'Сумма WooCommerce не совпадает с суммой Cloud-платежа.');
            }

            $items=$order->get_items('line_item');
            if (count($items)!==1) {
                self::add($issues,'order_offer_ambiguous',$orderId,'В заказе не ровно одна товарная позиция; автоматическое сопоставление тарифа невозможно.');
            } else {
                $item=reset($items);
                if ($item instanceof \WC_Order_Item_Product) {
                    $productId=(int)$item->get_product_id();
                    $variationId=(int)$item->get_variation_id();
                    $offer=$catalog[$productId]??($variationId>0?($catalog[$variationId]??null):null);
                    if (!$offer) {
                        self::add($issues,'order_product_unmapped',$orderId,'Товар заказа отсутствует в текущем каталоге платных предложений.');
                    } else {
                        if ((string)$payment['plan']!==(string)$offer['plan'] || (string)$payment['sku']!==(string)$offer['sku'] || (int)$payment['product_id']!==(int)$offer['product_id']) {
                            self::add($issues,'payment_offer_mismatch',$orderId,'Тариф, SKU или ID товара в Cloud не совпадает с текущим предложением.');
                        }
                    }
                }
            }

            $licenseId=(string)($payment['license_id']??'');
            $license=$licenseId!==''?$store->row('SELECT id,owner,plan,status,quota_scheme,expires_at,replaced_by FROM '.$store->table('licenses').' WHERE id=?',[$licenseId]):null;
            if (!$license) {
                self::add($issues,'paid_order_license_missing',$orderId,'Cloud-платёж существует, но связанная лицензия не найдена.');
            } else {
                if ((string)($license['owner']??'')!==$owner || (string)$license['owner']!==(string)$payment['owner']) {
                    self::add($issues,'license_owner_mismatch',$orderId,'Владелец лицензии не совпадает с владельцем оплаченного заказа.');
                }
                if ((string)$license['plan']!==(string)$payment['plan'] || (string)$license['quota_scheme']!=='tariffs_v3') {
                    self::add($issues,'license_plan_mismatch',$orderId,'План или схема лицензии не совпадает с платёжной записью.');
                }
                if (in_array((string)$payment['status'],['settled','partially_refunded'],true)
                    && !in_array((string)$license['status'],[Licenses::ACTIVE,Licenses::SUSPENDED],true)
                    && empty($license['replaced_by'])) {
                    self::add($issues,'settled_payment_license_inactive',$orderId,'Оплаченный платёж числится действующим, но лицензия не active/suspended.');
                }
                if ((string)$payment['status']==='refunded' && (string)$license['status']===Licenses::ACTIVE) {
                    self::add($issues,'refunded_payment_license_active',$orderId,'Полный возврат записан в Cloud, но связанная лицензия остаётся активной.');
                }
            }

            $refundTotal=(int)($store->row('SELECT COALESCE(SUM(amount_kopecks),0) AS total FROM '.$store->table('commerce_refunds').' WHERE gateway_order_id=?',[$orderId])['total']??0);
            if ($refundTotal>(int)$payment['amount_rub']*100) {
                self::add($issues,'refund_total_exceeds_payment',$orderId,'Сумма подтверждённых возвратов превышает исходную сумму платежа.');
            }
            $paidTotal=(int)$payment['amount_rub']*100;
            if ($refundTotal>0 && $refundTotal<$paidTotal && !in_array((string)$payment['status'],['partially_refunded','refunded'],true)) {
                self::add($issues,'refund_ledger_status_mismatch',$orderId,'В реестре есть возврат, но статус платежа не отражает частичный/полный возврат.');
            }
            if ($refundTotal===$paidTotal && $paidTotal>0 && (string)$payment['status']!=='refunded') {
                self::add($issues,'full_refund_payment_status_mismatch',$orderId,'Сумма подтверждённых возвратов равна оплате, но статус Cloud-платежа не refunded.');
            }
        }

        $ledgerRows=$store->rows('SELECT gateway_order_id,status,owner,plan,amount_rub,license_id FROM '.$store->table('commerce_payments').' ORDER BY created_at DESC LIMIT '.$limit);
        foreach ($ledgerRows as $row) {
            $orderId=(string)$row['gateway_order_id'];
            // Resolve each ledger order directly so a recent-order scan limit
            // cannot mislabel an older, valid WooCommerce order as missing.
            $wooOrder=function_exists('wc_get_order')?wc_get_order((int)$orderId):false;
            if (!$wooOrder instanceof \WC_Order) {
                self::add($issues,'cloud_payment_order_missing',$orderId,'Платёж есть в Cloud, но заказ с таким ID не найден в WooCommerce.');
            } elseif (!str_starts_with((string)$wooOrder->get_payment_method(),'robokassa')) {
                self::add($issues,'cloud_payment_gateway_mismatch',$orderId,'Платёж Cloud привязан к заказу, который не использует Robokassa.');
            }
        }

        $counts=[];
        foreach ($issues as $issue) $counts[$issue['code']]=($counts[$issue['code']]??0)+1;
        return [
            'mode'=>'read_only',
            'generated_at'=>gmdate('c'),
            'orders_scanned'=>$checked,
            'paid_robokassa_orders'=>$paid,
            'cloud_payments_scanned'=>count($ledgerRows),
            'issue_count'=>count($issues),
            'issue_counts'=>$counts,
            'truncated'=>count($issues)>100,
            'issues'=>array_slice($issues,0,100),
            'mutations_performed'=>0,
        ];
    }

    private static function add(array &$issues,string $code,string $orderId,string $message): void {
        $issues[]=['code'=>$code,'order_id'=>$orderId,'message'=>$message];
    }
}
