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
