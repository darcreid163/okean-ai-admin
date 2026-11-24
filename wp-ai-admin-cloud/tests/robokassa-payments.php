<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/WooCommercePayments.php';
require_once dirname(__DIR__).'/src/LicenseDelivery.php';
if (!function_exists('is_email')) { function is_email(string $email): string|false { return filter_var($email,FILTER_VALIDATE_EMAIL)?$email:false; } }
if (!function_exists('get_user_by')) { function get_user_by(string $field,mixed $value): object|false { return (string)$value==='991'?(object)['ID'=>991,'user_email'=>'buyer@example.com']:false; } }
$GLOBALS['licenseDeliveryMail']=['to'=>null,'message'=>null,'sent'=>true];
if (!function_exists('wp_mail')) { function wp_mail($to,$subject,$message,$headers=[]): bool { $GLOBALS['licenseDeliveryMail']=['to'=>$to,'message'=>$message,'sent'=>$GLOBALS['licenseDeliveryMail']['sent']]; return (bool)$GLOBALS['licenseDeliveryMail']['sent']; } }
if (!function_exists('home_url')) { function home_url(string $path=''): string { return 'https://wpaiadmin.test'.$path; } }
require_once dirname(__DIR__).'/src/Reconciliation.php';
require_once dirname(__DIR__).'/src/ReconciliationRepair.php';
if (!class_exists('WooCommerce')) { class WooCommerce {} }
$GLOBALS['robokassaReconciliationOrders']=[];
if (!function_exists('wc_get_orders')) {
    function wc_get_orders(array $args=[]): array { return $GLOBALS['robokassaReconciliationOrders']; }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public function __construct(private int $productId,private string $sku,private float $total,private float $tax=0.0,private int $quantity=1) {}
        public function get_product_id(): int { return $this->productId; }
        public function get_variation_id(): int { return 0; }
        public function get_quantity(): int { return $this->quantity; }
        public function get_product(): object { return new class($this->sku) { public function __construct(private string $sku) {} public function get_sku(): string { return $this->sku; } }; }
        public function get_total(): float { return $this->total; }
        public function get_total_tax(): float { return $this->tax; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order {
        public function __construct(
            private int $id,private int $customerId,private array $items,
            private float $total,private string $currency='RUB',
            private string $method='robokassa',private string $transaction='rk-op-test',
            private array $meta=[],private bool $paid=true
        ) {}
        public function is_paid(): bool { return $this->paid; }
        public function get_currency(): string { return $this->currency; }
        public function get_customer_id(): int { return $this->customerId; }
        public function get_payment_method(): string { return $this->method; }
        public function get_items(string $type='line_item'): array { return $type==='line_item'?$this->items:[]; }
        public function get_shipping_total(): float { return 0.0; }
        public function get_discount_total(): float { return 0.0; }
        public function get_total(): float { return $this->total; }
        public function get_id(): int { return $this->id; }
        public function get_transaction_id(): string { return $this->transaction; }
        public function get_billing_email(): string { return 'buyer@example.com'; }
        public function get_order_key(): string { return 'wc_order_test_'.$this->id; }
        public function get_meta(string $key,bool $single=true): mixed { return $this->meta[$key]??''; }
        public function set_meta(string $key,mixed $value): void { $this->meta[$key]=$value; }
    }
}
$GLOBALS['robokassaRefundFixtureOrders']=[];
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) { return $GLOBALS['robokassaRefundFixtureOrders'][(int)$id]??false; }
}

function robokassaPaymentCheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('Robokassa payment test failed: '.$message);
}
function runRobokassaPaymentsTests(\WPAICloudGateway\Store $store,\WPAICloudGateway\Core $core): void {
    $owner='991';
    $baseOrderId=random_int(100000000,999999999);
    $transactionSeed=bin2hex(random_bytes(8));
    $core->ensureAccount($owner);
    $policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
    $store->ensureLicenseSchema();
    $store->ensureCommerceSchema();

    $order=new WC_Order($baseOrderId+1,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-first');
    $first=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$order,new \WPAICloudGateway\Vault(str_repeat('z',32)));
    robokassaPaymentCheck($first['settled']===true && $first['idempotent']===false,'first verified order must issue a licence');
    $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$first['license_id']]);
    robokassaPaymentCheck($license!==null && $license['owner']===$owner && $license['plan']==='pro' && $license['quota_scheme']==='tariffs_v3','licence must be bound to the registered Woo customer');
    robokassaPaymentCheck($license['expires_at']!==null && (int)$license['expires_at']>(int)$license['starts_at'],'paid licence must have a finite expiry');
    $delivery=$store->row('SELECT * FROM '.$store->table('license_deliveries').' WHERE order_id=?',[(string)$order->get_id()]);
    robokassaPaymentCheck($delivery!==null && $delivery['owner']===$owner && $delivery['license_id']===$first['license_id'],'paid settlement must create an order-scoped delivery row');
    robokassaPaymentCheck($delivery['reveal_ciphertext']!=='' && $delivery['email_ciphertext']!=='' && !str_contains($delivery['reveal_ciphertext'],'wpai_lic_'),'delivery copies must be encrypted');
    $deliveryVault=new \WPAICloudGateway\Vault(str_repeat('z',32));
    $revealed=\WPAICloudGateway\LicenseDelivery::reveal($store,$deliveryVault,(string)$order->get_id(),$owner,time());
    robokassaPaymentCheck(preg_match(\WPAICloudGateway\Licenses::KEY_PATTERN,$revealed)===1,'one-time reveal must return the generated key');
    try { \WPAICloudGateway\LicenseDelivery::reveal($store,$deliveryVault,(string)$order->get_id(),$owner,time()); throw new RuntimeException('Robokassa payment test failed: reveal replay returned key'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='license_reveal_already_consumed','reveal replay must be rejected'); }
    $consumed=$store->row('SELECT reveal_ciphertext,reveal_consumed,email_ciphertext FROM '.$store->table('license_deliveries').' WHERE order_id=?',[(string)$order->get_id()]);
    robokassaPaymentCheck($consumed['reveal_ciphertext']==='' && (int)$consumed['reveal_consumed']===1 && $consumed['email_ciphertext']!=='','reveal consumption must erase only its own encrypted copy');
    robokassaPaymentCheck(\WPAICloudGateway\LicenseDelivery::sendEmail($store,$deliveryVault,(string)$order->get_id())===true,'email delivery should succeed independently of reveal');
    $mailed=$store->row('SELECT email_status,email_ciphertext FROM '.$store->table('license_deliveries').' WHERE order_id=?',[(string)$order->get_id()]);
    robokassaPaymentCheck($GLOBALS['licenseDeliveryMail']['to']==='buyer@example.com' && str_contains((string)$GLOBALS['licenseDeliveryMail']['message'],$revealed),'email must deliver the same key to the registered account email');
    robokassaPaymentCheck($mailed['email_status']==='sent' && $mailed['email_ciphertext']==='','successful email must erase its encrypted copy');

    $duplicate=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$order,new \WPAICloudGateway\Vault(str_repeat('z',32)));
    robokassaPaymentCheck($duplicate['idempotent']===true && $duplicate['license_id']===$first['license_id'],'replayed order must not issue or extend twice');

    $renewal=new WC_Order($baseOrderId+2,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-renewal');
    $second=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$renewal,new \WPAICloudGateway\Vault(str_repeat('z',32)));
    robokassaPaymentCheck((int)$second['expires_at']>(int)$first['expires_at'],'same-plan renewal must extend from the existing expiry');


    // A confirmed full refund of the current order revokes only the matching
    // current licence. Replays are idempotent and restore the account to Free.
    $fullRefund=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$renewal,'rk_refund_full_'.$transactionSeed,1499.0,true);
    robokassaPaymentCheck($fullRefund['entitlement_revoked']===true && $fullRefund['outcome']==='license_revoked','confirmed full refund must revoke current paid licence');
    $account=$store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
    $revokedLicense=$store->row('SELECT status FROM '.$store->table('licenses').' WHERE id=?',[$second['license_id']]);
    robokassaPaymentCheck($account['plan']==='free' && $account['license_id']===null && $revokedLicense['status']==='revoked','full refund must atomically fall back to Free');
    $fullReplay=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$renewal,'rk_refund_full_'.$transactionSeed,1499.0,true);
    robokassaPaymentCheck($fullReplay['idempotent']===true && $fullReplay['entitlement_revoked']===true,'duplicate full refund must be idempotent');
    try {
        \WPAICloudGateway\WooCommercePayments::refund($store,$policy,$order,'rk_refund_full_'.$transactionSeed,1499.0,true);
        throw new RuntimeException('Robokassa payment test failed: conflicting refund request ID accepted');
    } catch (\WPAICloudGateway\Fault $e) {
        robokassaPaymentCheck($e->reason==='refund_replay_conflict','request ID reused for another order must be rejected');
    }

    // Partial refunds are recorded but must never reduce or revoke the active plan.
    $thirdOrder=new WC_Order($baseOrderId+5,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-third');
    $third=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$thirdOrder,new \WPAICloudGateway\Vault(str_repeat('z',32)));
    $partial=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$thirdOrder,'rk_refund_partial_'.$transactionSeed,300.0,false);
    robokassaPaymentCheck($partial['outcome']==='partial_recorded' && $partial['entitlement_revoked']===false,'partial refund must be ledger-only');
    $activeAfterPartial=$store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
    robokassaPaymentCheck($activeAfterPartial['plan']==='pro' && $activeAfterPartial['license_id']===$third['license_id'],'partial refund changed active entitlement');
    $partialReplay=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$thirdOrder,'rk_refund_partial_'.$transactionSeed,300.0,false);
    robokassaPaymentCheck($partialReplay['idempotent']===true && $partialReplay['entitlement_revoked']===false,'duplicate partial refund must be idempotent');

    // A delayed refund of an older replaced purchase must not revoke a newer order.
    $stale=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$order,'rk_refund_stale_'.$transactionSeed,1499.0,true);
    $activeAfterStale=$store->row('SELECT plan,license_id FROM '.$store->table('accounts').' WHERE owner=?',[$owner]);
    robokassaPaymentCheck($stale['outcome']==='stale_payment_refund' && !$stale['entitlement_revoked'],'old refunded order must be marked stale');
    robokassaPaymentCheck($activeAfterStale['plan']==='pro' && $activeAfterStale['license_id']===$third['license_id'],'old refund revoked a newer purchase');

    // Out-of-order partial callbacks are accumulated only after each is confirmed.
    // The entitlement is revoked only when confirmed refunds sum to the paid total.
    $fourthOrder=new WC_Order($baseOrderId+6,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-fourth');
    $fourth=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$fourthOrder,new \WPAICloudGateway\Vault(str_repeat('z',32)));
    // The larger "final" request arrives first; its local full flag alone is insufficient.
    $partialTwo=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$fourthOrder,'rk_refund_part2_'.$transactionSeed,1198.75,true);
    robokassaPaymentCheck($partialTwo['refund_kind']==='partial' && !$partialTwo['entitlement_revoked'],'pending earlier refund means the later callback is not yet cumulatively full');
    $partialOne=\WPAICloudGateway\WooCommercePayments::refund($store,$policy,$fourthOrder,'rk_refund_part1_'.$transactionSeed,300.25,false);
    robokassaPaymentCheck($partialOne['refund_kind']==='full' && $partialOne['entitlement_revoked'],'cumulative confirmed refunds must revoke after reaching paid total');
    $fourthLicense=$store->row('SELECT status FROM '.$store->table('licenses').' WHERE id=?',[$fourth['license_id']]);
    robokassaPaymentCheck($fourthLicense['status']==='revoked','out-of-order partial completion did not revoke current licence');

    // Read-only reconciliation must detect no inconsistencies in the valid
    // fixture set and must not mutate either payment or refund ledgers.
    $GLOBALS['robokassaRefundFixtureOrders'][$order->get_id()]=$order;
    $GLOBALS['robokassaRefundFixtureOrders'][$renewal->get_id()]=$renewal;
    $GLOBALS['robokassaRefundFixtureOrders'][$thirdOrder->get_id()]=$thirdOrder;
    $GLOBALS['robokassaRefundFixtureOrders'][$fourthOrder->get_id()]=$fourthOrder;
    $GLOBALS['robokassaReconciliationOrders']=[$order,$renewal,$thirdOrder,$fourthOrder];
    $paymentsBefore=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_payments'))['n'];
    $refundsBefore=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_refunds'))['n'];
    $report=\WPAICloudGateway\Reconciliation::report($store,$policy,100);
    robokassaPaymentCheck($report['mode']==='read_only' && $report['mutations_performed']===0,'reconciliation must declare read-only mode');
    robokassaPaymentCheck($report['orders_scanned']===4 && $report['paid_robokassa_orders']===4,'reconciliation must inspect all supplied Robokassa orders');
    robokassaPaymentCheck($report['issue_count']===0,'valid payment/licence fixtures must reconcile cleanly: '.json_encode($report['issues']));
    robokassaPaymentCheck((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_payments'))['n']===$paymentsBefore,'reconciliation modified payment ledger');
    robokassaPaymentCheck((int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_refunds'))['n']===$refundsBefore,'reconciliation modified refund ledger');

    // Operator repair is narrowly scoped to a paid Robokassa order with a
    // transaction reference and no Cloud payment row. It uses canonical
    // settlement and verifies the resulting payment and licence records.
    $repairOrder=new WC_Order($baseOrderId+9,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-repair');
    $repairResult=\WPAICloudGateway\ReconciliationRepair::repairMissingPaymentLedger($store,$policy,$repairOrder);
    robokassaPaymentCheck($repairResult['repaired']===true && $repairResult['already_settled']===false,'approved repair must settle the missing paid order');
    $repairPayment=$store->row('SELECT status,owner,plan,license_id FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?',[(string)$repairOrder->get_id()]);
    $repairLicense=$store->row('SELECT owner,plan,quota_scheme FROM '.$store->table('licenses').' WHERE id=?',[$repairResult['license_id']]);
    robokassaPaymentCheck($repairPayment && $repairPayment['status']==='settled' && $repairPayment['owner']===$owner && $repairPayment['plan']==='pro','repair must persist the exact Cloud payment identity');
    robokassaPaymentCheck($repairLicense && $repairLicense['owner']===$owner && $repairLicense['plan']==='pro' && $repairLicense['quota_scheme']==='tariffs_v3','repair must verify the issued paid licence');

    $emptyTransactionOrder=new WC_Order($baseOrderId+10,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','');
    try { \WPAICloudGateway\ReconciliationRepair::repairMissingPaymentLedger($store,$policy,$emptyTransactionOrder); throw new RuntimeException('Robokassa payment test failed: repair accepted missing transaction reference'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='repair_transaction_reference_required','repair must require a Robokassa transaction reference'); }

    $unpaidOrder=new WC_Order($baseOrderId+11,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-unpaid',[],false);
    try { \WPAICloudGateway\ReconciliationRepair::repairMissingPaymentLedger($store,$policy,$unpaidOrder); throw new RuntimeException('Robokassa payment test failed: repair accepted unpaid order'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='repair_payment_not_confirmed','repair must reject unpaid order'); }

    try { \WPAICloudGateway\ReconciliationRepair::repairMissingPaymentLedger($store,$policy,$repairOrder); throw new RuntimeException('Robokassa payment test failed: repair accepted an existing ledger row'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='repair_payment_ledger_exists','repair must not reissue an order already present in Cloud ledger'); }

    $badAmount=new WC_Order($baseOrderId+3,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1200)],1200.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-bad');
    try { \WPAICloudGateway\WooCommercePayments::settle($store,$policy,$badAmount,new \WPAICloudGateway\Vault(str_repeat('z',32))); throw new RuntimeException('Robokassa payment test failed: wrong amount accepted'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='paid_product_line_total_mismatch','wrong amount must fail closed'); }

    $guest=new WC_Order($baseOrderId+4,0,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-'.$transactionSeed.'-guest');
    try { \WPAICloudGateway\WooCommercePayments::settle($store,$policy,$guest,new \WPAICloudGateway\Vault(str_repeat('z',32))); throw new RuntimeException('Robokassa payment test failed: guest order accepted'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='registered_customer_required','guest order must not receive a licence'); }

    // Unconfirmed/pending/canceled Robokassa states must not create refund ledger rows.
    $unconfirmedBefore=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_refunds'))['n'];
    $pendingOrderId=$baseOrderId+7;
    $pendingOrder=new WC_Order($pendingOrderId,991,[],1499.0);
    $pendingOrder->set_meta('_robokassa_refund_requests',['pending-'.$transactionSeed=>['status'=>'processing','amount'=>1499.0,'is_full'=>true]]);
    $GLOBALS['robokassaRefundFixtureOrders'][$pendingOrderId]=$pendingOrder;
    \WPAICloudGateway\WooCommercePayments::refundStatusChecked($pendingOrderId,'pending-'.$transactionSeed);
    $deferredStatus=\WPAICloudGateway\WooCommercePayments::deferOrderRefundUntilAllRequestsTerminal('refunded',$pendingOrderId,1);
    robokassaPaymentCheck($deferredStatus===false,'WooCommerce full-refund status must be deferred while any refund is processing');
    $canceledOrderId=$baseOrderId+8;
    $canceledOrder=new WC_Order($canceledOrderId,991,[],1499.0);
    $canceledOrder->set_meta('_robokassa_refund_requests',['canceled-'.$transactionSeed=>['status'=>'canceled','amount'=>1499.0,'is_full'=>true]]);
    $GLOBALS['robokassaRefundFixtureOrders'][$canceledOrderId]=$canceledOrder;
    \WPAICloudGateway\WooCommercePayments::refundStatusChecked($canceledOrderId,'canceled-'.$transactionSeed);
    $unconfirmedRows=(int)$store->row('SELECT COUNT(*) AS n FROM '.$store->table('commerce_refunds'))['n'];
    robokassaPaymentCheck($unconfirmedRows===$unconfirmedBefore,'pending/canceled refunds must not be recorded or revoke access');

    $monthEnd=(new ReflectionMethod(\WPAICloudGateway\WooCommercePayments::class,'addCalendarTerm'));
    $monthEnd->setAccessible(true);
    $jan31=strtotime('2027-01-31T12:34:56Z');
    robokassaPaymentCheck(gmdate('Y-m-d H:i:s',$monthEnd->invoke(null,$jan31,1))==='2027-02-28 12:34:56','month-end calendar clamp');
    $leap=strtotime('2024-02-29T12:00:00Z');
    robokassaPaymentCheck(gmdate('Y-m-d H:i:s',$monthEnd->invoke(null,$leap,12))==='2025-02-28 12:00:00','leap-day annual clamp');
}
