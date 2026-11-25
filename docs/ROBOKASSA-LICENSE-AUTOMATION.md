# Robokassa → WooCommerce → Cloud licence entitlement

Status: implementation and automated lifecycle coverage are present on PR #253. Public payment enablement remains gated. Real Robokassa Test Mode checkout/callback/email-provider E2E and operational acceptance are still required.

## Commercial catalogue (single source: config/plans.json)

The Cloud catalogue uses the current five plans and UTC daily action quotas:

| Plan | Cloud slug | Actions/day | Sites | Monthly | Annual |
|---|---|---:|---:|---:|---:|
| Free | free | 50 | 1 | 0 ₽ lifetime | — |
| Daily | daily | 500 | 1 | 899 ₽ | 9 999 ₽ |
| Pro | pro | 2,000 | 1 | 1,499 ₽ | 16,999 ₽ |
| PRO+ | business | 5,000 | 5 | 3,999 ₽ | 46,999 ₽ |
| PRO++ | businesspro | 10,000 | 50 | 8,999 ₽ | 105,999 ₽ |

WooCommerce product mapping is maintained in `commerce.products` in plans.json. Product ID and SKU must both match. Never infer a plan from a client-submitted slug or product title. Free (product 62) is lifetime and is not a paid order.

## Payment event source

The Robokassa gateway is responsible for callback signature verification. The Cloud adapter listens to WooCommerce `woocommerce_payment_complete` and requires all of:
- order payment method begins with the Robokassa gateway prefix (including its SBP method IDs);
- WooCommerce reports the order paid;
- order has a non-zero registered customer ID; guest orders are held and do not receive a licence;
- exactly one supported WP AI Admin product is present, with quantity 1; reject mixed/unknown products and duplicate lines;
- product ID and SKU match the catalogue mapping;
- order currency is RUB and the immutable Woo order amount matches the approved offer price; shipping, fees, discounts, mixed carts and multiple licence lines are rejected;
- a Robokassa transaction reference is hashed and stored where available; Woo order ID is the primary idempotency key.

The classic ResultURL path verifies the configured Result password before WooCommerce marks the order paid. The adapter performs an additional amount preflight against the immutable order total. Browser SuccessURL/FailURL redirects do not prove payment and never grant a licence. ResultUrl2 metadata is not treated as an entitlement trigger.

## Entitlement issuance

Cloud Core, not the browser and not the WordPress client plugin, is authoritative for plan and expiry. Resolve the owner from the Woo order's registered customer ID. Provision the Cloud account for that WordPress user if necessary, then issue a bound paid licence with:
- source `automatic`;
- quota scheme `tariffs_v3`;
- plan from exact product mapping;
- activation limit equal to the offer's site allowance;
- starts_at and expires_at computed by the Cloud server;
- audit event referencing Woo order ID and a hash of the payment reference (never card data or secrets).

Term policy:
- monthly products grant one UTC calendar month;
- annual products grant twelve UTC calendar months;
- date arithmetic clamps to the last valid day of the target month;
- renewal of the same plan extends from max(server now, current paid licence expiry);
- a different plan starts at server now; no implicit proration or carry-over across upgrades/downgrades;
- no automatic recurring charges. Customer initiates each renewal.

A paid licence must never be created through `ensureBound()`: that method intentionally creates a non-expiring account entitlement. Use a term-bearing paid issuance path.

## Idempotency and atomicity

The Cloud Store creates an InnoDB payment ledger with unique constraints on gateway + Woo order ID and, when present, gateway transaction reference.

Within the Store transaction:
1. lock/check the payment row;
2. if already granted, return the prior receipt without extending again;
3. insert/claim the verified order;
4. issue/extend the licence and update the account's current licence link;
5. persist licence ID, mapped offer, start/end timestamps, amount/currency and evidence hashes;
6. mark the payment granted.

A failed transaction rolls back both entitlement and payment claim. Concurrent duplicate callbacks are fenced by the unique order key (and transaction hash where available). A committed `processing` row is treated as an error, never blindly replayed.

## Retry and reconciliation

- Transient settlement failures use a bounded retry queue; permanent validation failures are surfaced for operator review.
- After the retry budget is exhausted, the order is flagged for reconciliation rather than silently issuing access.
- The administrator reconciliation report is read-only and does not mutate orders or licences.
- Any repair is a separate explicit, narrowly scoped, audited operator action.
- Public Plans API must continue reporting `payments_enabled=false` until all release gates pass.

## Refunds and chargebacks

The Cloud lifecycle and automated tests cover full, partial, cumulative and out-of-order refund events, stale-order protection and approved repair paths. These automated tests use test doubles and do not replace real gateway E2E. Do not revoke access on a refund request or transient/pending status. Apply the approved policy only after a confirmed financial event, idempotently and with an audit trail. Preserve payment and licence history.

## Automated coverage vs manual acceptance

The dedicated runner is included in Cloud CI and exercises SQLite/MySQL lifecycle paths, including settlement, duplicate callbacks, encrypted one-time reveal/replay denial, registered owner email, retry/exhaustion, renewal, refunds, reconciliation and approved repair. It uses test doubles for WordPress/WooCommerce APIs.

It does **not** prove:
- a real Robokassa Test Mode browser checkout;
- a real signed ResultURL callback through the installed gateway plugin;
- actual email-provider delivery and retry behavior;
- end-to-end activation in the installed Native plugin on the disposable paid test site.

## Acceptance tests before enabling sales

1. Valid Robokassa test payment grants exactly one bound licence to the order customer.
2. Success URL hit without Result notification grants nothing.
3. Invalid signature and amount mismatch grant nothing.
4. Wrong order ID, currency, amount, SKU, product ID, quantity or mixed cart grants nothing.
5. Guest checkout is held without licence issuance.
6. Replayed/delayed Result notification and concurrent duplicate callbacks do not issue/extend twice.
7. One-time reveal is shown once; replay is denied; registered account email receives the key without exposing it in logs.
8. Native activation binds to the correct owner/site and applies the correct daily quota and site allowance.
9. Monthly/annual term boundaries, leap year, month-end clamping and same-plan renewal are correct.
10. Confirmed full/partial refunds follow the approved policy; pending refunds do not revoke.
11. Mail retry, retry exhaustion, read-only reconciliation and approved repair are auditable.
12. Robokassa settings and passwords remain unchanged; use Test Mode only.

## Release gate

Keep `payments_enabled=false`. Do not enable public sales until disposable test-shop E2E, tenant isolation, OAuth lifecycle, staging operation acceptance and legal/product gates are signed off.
