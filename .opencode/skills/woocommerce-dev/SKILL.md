---
name: woocommerce-dev
description: WooCommerce products, variations, inventory, orders, customers, coupons, taxes, shipping and store workflows.
---
# WooCommerce Engineering
Inspect installed version and existing adapters. Use WooCommerce CRUD objects/public APIs, not direct post/meta writes for commerce entities. Preserve product types, variation links, SKU uniqueness, attributes, stock/backorder semantics, tax and extension metadata. Use currency precision and avoid floating-point money authority. Protect order/customer data and enforce least privilege. Order/payment/refund/shipping mutations require READ → PLAN/SANDBOX → DIFF → explicit approval → APPLY → VERIFY → ROLLBACK. Return per-object outcomes; Cloud ledger charges successful objects only. Test CRUD round trips, SKU collision, stock/price boundaries, partial batches, permissions and receipts. Fail safely when WooCommerce is absent.
