# Robokassa → WooCommerce → Cloud licence entitlement

Status: payment issuance is implemented on `codex/robokassa-paid-entitlements`; confirmed refund handling is implemented on stacked draft `codex/robokassa-refund-lifecycle`. Public sales remain disabled pending CI, test-shop acceptance, chargeback handling and reconciliation.

## Commercial catalogue (single source: config/plans.json)

The Cloud catalogue uses the current five plans and UTC daily action quotas:

| Plan | Cloud slug | Actions/day | Sites | Monthly | Annual |
|---|---|---:|---:|---:|---:|
| Free | free | 50 | 1 | 0 ₽ lifetime | — |
| Daily | daily | 500 | 1 | 899 ₽ | 9 999 ₽ |
| Pro | pro | 2 000 | 1 | 1 499 ₽ | 16 999 ₽ |
| PRO+ | business | 5 000 | 5 | 3 999 ₽ | 46 999 ₽ |
| PRO++ | businesspro | 10 000 | 50 | 8 999 ₽ | 105 999 ₽ |

WooCommerce product mapping is maintained in `commerce.products` in plans.json. Product ID and SKU must both match. Never infer a plan from a client-submitted slug or product title. Free (product 62) is lifetime and is not a paid order.

## Payment event source

The Robokassa 1.9.2 archive was inspected. Its classic Result notification verifies `SignatureValue` with the configured Result password before accepting an invoice; ResultUrl2 validates an RS256 JWS using the bundled Robokassa certificate. The WooCommerce gateway calls the Woo order payment-completion lifecycle after a valid classic Result notification. The buyer-facing Success URL is not proof of payment and must never grant a licence.

The Cloud adapter now listens to WooCommerce `woocommerce_payment_complete` and requires all of:
- order payment method begins with the Robokassa gateway prefix (including its SBP method IDs);
- WooCommerce reports the order paid;
- order has a non-zero registered customer ID (guest orders must be linked to an account before checkout or held for support; do not auto-create an account from an unverified email);
- exactly one supported WP AI Admin product is present, with quantity 1; reject mixed/unknown products and duplicate lines;
- product ID and SKU match the catalogue mapping;
- order currency is RUB; the signed classic ResultURL `OutSum` is checked against the immutable Woo order total before the Robokassa handler runs; the item gross total and order total must equal the mapped price; shipping, fees, discounts, mixed carts and multiple licence lines are rejected;
- a Robokassa transaction reference is SHA-256 hashed and stored where ResultUrl2 provides it; order ID remains the primary idempotency key.

The Robokassa gateway remains responsible for callback signature verification. The adapter performs an additional preflight amount comparison for a correctly signed classic ResultURL. Robokassa 1.9.2 ResultUrl2 persists signed payment-state metadata but does not itself complete the WooCommerce payment; it is deliberately not treated as an entitlement trigger. A licence is granted only after the classic ResultURL handler has successfully called WooCommerce `payment_complete()`. The adapter does not modify Robokassa credentials/settings.

## Entitlement issuance

The Cloud Core, not the browser and not the WordPress client plugin, is the authority for plan and expiry. Resolve the owner from the Woo order's registered customer ID. Provision the Cloud account for that WordPress user if necessary, then issue a bound active licence with:
- source `automatic`;
- quota scheme `tariffs_v3`;
- plan from the exact product mapping;
- activation limit equal to the offer's site allowance;
- starts_at and expires_at computed by the Cloud server;
- audit event referencing only the Woo order ID and a hash of the payment reference (never card data or secrets).

Term policy:
- monthly products grant one UTC calendar month;
- annual products grant twelve UTC calendar months;
- date arithmetic clamps to the last valid day of the target month (e.g. Jan 31 → Feb 28/29), rather than overflowing into March;
- renewal of the same plan extends from max(server now, current paid licence expiry);
- a different plan starts at server now; no implicit proration or carry-over across upgrades/downgrades;
- no automatic recurring charges. Customer initiates each renewal.

A paid licence must never be created through `ensureBound()`: that method intentionally creates a non-expiring account entitlement. Use a term-bearing paid issuance path.

## Idempotency and atomicity (release blocker)

The Cloud Store now creates an InnoDB payment ledger with unique constraints on:
- gateway + Woo order ID;
- gateway transaction reference (when present).

In the same PDO transaction as licence issuance and account plan update:
1. lock/check the payment row;
2. if already granted, return the prior receipt without extending again;
3. insert/claim the verified order;
4. issue/extend the licence and update the account's current licence link;
5. persist the licence ID, mapped offer, start/end timestamps, amount/currency and evidence hashes;
6. mark the payment granted.

A failed transaction rolls back both the entitlement and payment claim. The ledger insert, paid licence issue/account link and final `settled` marker execute in one Store transaction. Concurrent duplicate callbacks are fenced by the unique order key (and transaction hash where available). A committed `processing` row is treated as an error, never blindly replayed.

The browser Success URL, a Woo order status changed manually by an administrator, and a customer-supplied receipt are not sufficient to issue a paid entitlement.

## Current implementation boundaries\n\n- `WooCommercePayments.php` implements classic ResultURL preflight, registered-customer ownership, exact offer validation, one-time issue and same-plan calendar-term extension. ResultUrl2 is not used to mark an order paid or grant a licence.
- If the payment is already confirmed but Cloud settlement fails transiently, a bounded WP-Cron retry queue retries the entitlement without relying on Robokassa replaying a callback after WooCommerce has already marked the order paid. Permanent validation failures are surfaced for manual review; after 12 transient attempts the order is flagged for reconciliation.
- `Settings::eligible()` now admits a normal WordPress user only while the authoritative Cloud licence registry shows an active, unexpired paid plan; administrators and explicitly approved beta users retain their existing access.\n- The paid-price catalogue must be explicitly approved; otherwise settlement fails closed.\n- Public Plans API still reports `payments_enabled=false`. No production sales switch was enabled.\n- Automated tests, test-shop end-to-end verification, confirmed refund lifecycle is implemented in a draft PR; chargeback lifecycle and operational reconciliation tooling are not yet complete.\n\n## Refund lifecycle

The integrated adapter subscribes to `robokassa_refund_check_event` at priority 20, after the official Robokassa GetState handler at priority 10 has persisted the result. Only a request whose stored status is exactly `finished` is processed. Pending, canceled, failed and unconfirmed requests do not touch Cloud entitlements.

Confirmed refunds are written to the additive `commerce_refunds` ledger, keyed by Robokassa `requestId`. The ledger stores order/customer/licence references, amount, refund kind, outcome and a payload hash; it never stores Robokassa credentials or raw callback data. Replays are idempotent; a reused request ID with different order or payload is rejected.

The full-refund decision is based on the cumulative sum of *confirmed* refund amounts compared with the paid order amount, not WooCommerce's local `is_full` flag. This handles callbacks completing out of order and prevents a pending partial refund from making a later request look like a completed full refund.

- Partial confirmed refund: record it; do not change licence status, expiry, quota or site limits.
- Confirmed refunds whose cumulative total is below the paid amount: keep the licence active.
- When confirmed refunds reach the full paid amount: revoke the licence only if that exact order's licence is still the account's current active/suspended entitlement. The Cloud account falls back to Free in the same database transaction.
- If the refunded order's licence has already been replaced by a later purchase/renewal, record `stale_payment_refund` and preserve the newer entitlement.
- Transient Cloud/database failures retry up to 12 times. Exhaustion creates an order note for manual reconciliation; it does not guess or revoke access.

Chargeback/dispute events are **not implemented yet**. They must be integrated only from a verified terminal Robokassa/acquirer status, with an idempotent dispute ledger and a separate suspension/reinstatement policy. Do not infer a chargeback from a refund status or generic WooCommerce order status.

## Refund and chargeback acceptance tests

1. Pending, canceled and failed refund states do not alter a licence.
2. Confirmed partial refund is recorded and leaves entitlement unchanged.
3. Multiple confirmed partial refunds revoke only when their cumulative amount equals the original paid amount.
4. Out-of-order refund callbacks produce the same final result as ordered callbacks.
5. Duplicate `requestId` is idempotent; conflicting replay is rejected.
6. A full refund of an old replaced order does not revoke a newer paid licence.
7. Transient Cloud failure retries are bounded and end in a manual-review note.
8. Chargeback tests are added only after a verified gateway dispute signal is selected and implemented.

## Acceptance tests (must pass before enabling sales)

1. Valid Robokassa test payment grants exactly one bound licence to the order customer.
2. Success URL hit without Result notification grants nothing.
3. Invalid classic MD5 signature grants nothing.
4. Invalid ResultUrl2 JWS/certificate grants nothing.
5. Wrong order ID, currency, amount, SKU, product ID, quantity or mixed cart grants nothing.
6. Guest checkout is held without licence issuance.
7. Replayed Result notification and concurrent duplicate callbacks do not issue/extend twice.
8. Monthly and annual term boundaries, leap year, month-end clamping and renewal extension are correct.
9. A different-plan purchase follows the no-proration policy.
10. Confirmed cumulative full refund follows the revocation policy; pending/partial refund does not revoke. Chargeback handling remains a separate release gate.
11. Robokassa settings and passwords are unchanged.
12. No production payment is used for QA; run only with Robokassa test credentials/test mode and test orders.

## Release gate

The catalogue update and adapter code do not enable public payments. Keep `payments_enabled=false` until the automated test matrix, refund/chargeback policy, operational reconciliation and test-shop end-to-end flow pass.