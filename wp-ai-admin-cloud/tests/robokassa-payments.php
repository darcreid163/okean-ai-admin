<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/WooCommercePayments.php';

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
            private string $method='robokassa',private string $transaction='rk-op-test'
        ) {}
        public function is_paid(): bool { return true; }
        public function get_currency(): string { return $this->currency; }
        public function get_customer_id(): int { return $this->customerId; }
        public function get_payment_method(): string { return $this->method; }
        public function get_items(string $type='line_item'): array { return $type==='line_item'?$this->items:[]; }
        public function get_shipping_total(): float { return 0.0; }
        public function get_discount_total(): float { return 0.0; }
        public function get_total(): float { return $this->total; }
        public function get_id(): int { return $this->id; }
        public function get_transaction_id(): string { return $this->transaction; }
        public function get_order_key(): string { return 'wc_order_test_'.$this->id; }
    }
}

function robokassaPaymentCheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('Robokassa payment test failed: '.$message);
}
function runRobokassaPaymentsTests(\WPAICloudGateway\Store $store,\WPAICloudGateway\Core $core): void {
    $owner='991';
    $core->ensureAccount($owner);
    $policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
    $store->ensureLicenseSchema();
    $store->ensureCommerceSchema();

    $order=new WC_Order(9001,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0);
    $first=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$order);
    robokassaPaymentCheck($first['settled']===true && $first['idempotent']===false,'first verified order must issue a licence');
    $license=$store->row('SELECT * FROM '.$store->table('licenses').' WHERE id=?',[$first['license_id']]);
    robokassaPaymentCheck($license!==null && $license['owner']===$owner && $license['plan']==='pro' && $license['quota_scheme']==='tariffs_v3','licence must be bound to the registered Woo customer');
    robokassaPaymentCheck($license['expires_at']!==null && (int)$license['expires_at']>(int)$license['starts_at'],'paid licence must have a finite expiry');

    $duplicate=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$order);
    robokassaPaymentCheck($duplicate['idempotent']===true && $duplicate['license_id']===$first['license_id'],'replayed order must not issue or extend twice');

    $renewal=new WC_Order(9002,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0,'RUB','robokassa','rk-op-renewal');
    $second=\WPAICloudGateway\WooCommercePayments::settle($store,$policy,$renewal);
    robokassaPaymentCheck((int)$second['expires_at']>(int)$first['expires_at'],'same-plan renewal must extend from the existing expiry');

    $badAmount=new WC_Order(9003,991,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1200)],1200.0,'RUB','robokassa','rk-op-bad');
    try { \WPAICloudGateway\WooCommercePayments::settle($store,$policy,$badAmount); throw new RuntimeException('Robokassa payment test failed: wrong amount accepted'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='paid_product_line_total_mismatch','wrong amount must fail closed'); }

    $guest=new WC_Order(9004,0,[new WC_Order_Item_Product(296,'WPAI-PRO-1M',1499)],1499.0);
    try { \WPAICloudGateway\WooCommercePayments::settle($store,$policy,$guest); throw new RuntimeException('Robokassa payment test failed: guest order accepted'); }
    catch (\WPAICloudGateway\Fault $e) { robokassaPaymentCheck($e->reason==='registered_customer_required','guest order must not receive a licence'); }

    $monthEnd=(new ReflectionMethod(\WPAICloudGateway\WooCommercePayments::class,'addCalendarTerm'));
    $monthEnd->setAccessible(true);
    $jan31=strtotime('2027-01-31T12:34:56Z');
    robokassaPaymentCheck(gmdate('Y-m-d H:i:s',$monthEnd->invoke(null,$jan31,1))==='2027-02-28 12:34:56','month-end calendar clamp');
    $leap=strtotime('2024-02-29T12:00:00Z');
    robokassaPaymentCheck(gmdate('Y-m-d H:i:s',$monthEnd->invoke(null,$leap,12))==='2025-02-28 12:00:00','leap-day annual clamp');
}
