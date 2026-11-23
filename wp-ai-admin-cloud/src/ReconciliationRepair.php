<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Explicit operator-approved recovery for a paid Robokassa order whose
 * Cloud payment ledger row is missing. All normal settlement validation,
 * transaction locking, replay protection and entitlement issuance stay in
 * WooCommercePayments::settle().
 */
final class ReconciliationRepair {
    public static function repairMissingPaymentLedger(Store $store,array $policy,\WC_Order $order): array {
        if (!$order->is_paid()) throw new Fault('repair_payment_not_confirmed',409);
        if (!str_starts_with((string)$order->get_payment_method(),'robokassa')) throw new Fault('repair_gateway_mismatch',409);
        if ((int)$order->get_customer_id()<=0) throw new Fault('repair_registered_customer_required',409);
        $transactionId=trim((string)$order->get_transaction_id());
        if ($transactionId==='') throw new Fault('repair_transaction_reference_required',409);

        $store->ensureLicenseSchema();
        $store->ensureCommerceSchema();
        $orderId=(string)$order->get_id();
        if ($store->row('SELECT gateway_order_id FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?',[$orderId])) {
            throw new Fault('repair_payment_ledger_exists',409);
        }

        // Re-run the canonical payment settlement, not a bespoke licence grant.
        // It revalidates approved product, SKU, amount, currency, customer,
        // transaction identity and applies the same atomic idempotency rules.
        $result=WooCommercePayments::settle($store,$policy,$order);
        if (!empty($result['idempotent'])) {
            return ['repaired'=>false,'already_settled'=>true,'order_id'=>$orderId];
        }

        $payment=$store->row('SELECT status,owner,plan,license_id FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?',[$orderId]);
        if (!$payment || (string)$payment['status']!=='settled' || (string)$payment['license_id']!==(string)($result['license_id']??'')) {
            throw new Fault('repair_settlement_verification_failed',503);
        }
        $license=$store->row('SELECT owner,plan,quota_scheme,status FROM '.$store->table('licenses').' WHERE id=?',[(string)$payment['license_id']]);
        if (!$license
            || (string)$license['owner']!==(string)(int)$order->get_customer_id()
            || (string)$license['owner']!==(string)$payment['owner']
            || (string)$license['plan']!==(string)$payment['plan']
            || (string)$license['quota_scheme']!=='tariffs_v3') {
            throw new Fault('repair_license_verification_failed',503);
        }

        return [
            'repaired'=>true,
            'already_settled'=>false,
            'order_id'=>$orderId,
            'license_id'=>(string)$payment['license_id'],
            'plan'=>(string)$payment['plan'],
            'license_status'=>(string)$license['status'],
        ];
    }
}
