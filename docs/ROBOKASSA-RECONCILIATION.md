# Robokassa payment and licence reconciliation

Status: read-only operational report, implemented on `codex/robokassa-reconciliation-report`.

## Purpose

Compare recent Robokassa WooCommerce orders with the Cloud Core payment, refund and licence registries. The report is intended to identify financial-to-entitlement inconsistencies before an operator attempts any repair.

## Access

WordPress administrator only (`manage_options`). Open **WP AI Admin Cloud → Сверка платежей** and run the report for the latest 100, 250 or 500 orders.

## Checks

- Paid Robokassa order without a Cloud payment ledger row.
- Cloud payment whose WooCommerce order cannot be found, or whose payment method is not Robokassa.
- Payment left in `processing`.
- WooCommerce customer ID vs Cloud payment owner mismatch.
- Currency and paid amount mismatch.
- Ambiguous order line or product not present in the current offer catalogue.
- Product ID, SKU or plan mismatch.
- Missing linked licence, owner/plan/quota-scheme mismatch.
- Settled payment with an inactive licence.
- Refunded payment whose licence remains active.
- WooCommerce refunded status vs Cloud payment status mismatch.
- Refund total exceeding the original payment or refund ledger/status mismatch.

## Safety properties

- Report runs in read-only mode.
- It does not issue, renew, suspend, reactivate or revoke licences.
- It does not alter WooCommerce order/payment/refund statuses.
- It does not retry settlement or refund callbacks.
- It reports at most 100 detailed findings while retaining aggregate counts.
- It scans a bounded recent WooCommerce order window and a bounded recent Cloud payment ledger window. A Cloud ledger order is checked by direct WooCommerce order lookup, so a valid older order is not falsely marked missing merely because it is outside the recent order list.

## Interpretation

A finding is an investigation lead, not permission to modify an entitlement. In particular, `paid_order_missing_ledger` requires verifying the Robokassa callback and immutable order data before any retry. A future repair action must be separate, explicit, idempotent, auditable and require operator confirmation.

## Remaining release gates

- Run the automated Cloud Core and Native CI on the exact PR head.
- Verify the report on a dedicated Robokassa test shop with representative paid, refunded, partial-refund and replayed orders.
- Add a separate operator-approved repair workflow only after the report has been validated.
- Keep public payments disabled until all release gates, including test-shop E2E and a verified chargeback policy, pass.


## Operator-approved repair: missing Cloud payment row

The report exposes a repair form only for a paid Robokassa WooCommerce order that has a non-empty gateway transaction reference and no matching Cloud payment ledger row.

Before submitting, the operator must verify the successful operation and matching amount/order in the Robokassa merchant cabinet, then type the exact order-specific phrase shown in the form. The action is protected by `manage_options`, a WordPress nonce and the typed confirmation.

The repair service:
- rechecks that the WooCommerce order is paid, uses a Robokassa gateway, belongs to a registered customer and has a gateway transaction reference;
- refuses to run if a Cloud payment row already exists;
- invokes the canonical `WooCommercePayments::settle()` flow, not a separate licence-issuance shortcut;
- revalidates the current approved tariff, product, SKU, amount, currency, owner and transaction-derived payload;
- uses the existing atomic Cloud transaction and replay protection;
- verifies the resulting settled payment and linked `tariffs_v3` licence before reporting success.

The action does not repair a stuck `processing` row, missing licence on an already-settled payment, amount/product/owner mismatches, refunds, or chargebacks. Those remain manual-review cases. A concurrent callback that settles the order first results in a no-op response rather than a second licence.

## Repair acceptance tests

- Valid paid Robokassa order with a transaction reference and no Cloud ledger row is settled through the canonical flow.
- The new payment row and linked licence are checked for owner, plan and quota scheme.
- Missing transaction reference is rejected.
- Unpaid order is rejected.
- Existing Cloud payment ledger row is rejected; no second licence is issued.
