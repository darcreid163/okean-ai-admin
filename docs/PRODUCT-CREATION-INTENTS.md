# New WooCommerce product creation intent — bounded #103 slice

`woocommerce.create_product_sandbox` can write an entire draft product (and
variations) **before** an operation row is inserted. The Native
`OAI_WooCommerce::create_draft` method now reserves a durable actor/blog/request
identity before the first `WC_Product::save()` call. The claim lives in a
non-autoloaded `oai_product_creation_*` option protected by WordPress' unique
`option_name` and the same MySQL autocommit preflight used by other creators.
Only authenticated actors holding `edit_products` may reserve an intent.

Clients may send a 16–128-character `idempotency_key` on the top-level MCP
creation tool. The same key plus an equivalent payload is rejected with 409
(`oai_product_creation_pending`); the same key with a changed payload is
rejected as `oai_product_intent_conflict`. Object-key ordering is canonical
while array/list order and value types remain significant. A new key is a
deliberately new creation and may make an identical additional draft. Legacy
callers without a key are conservatively scoped by a canonical payload hash,
so a retry cannot silently create another equal draft. Error responses contain
no product IDs or claim details.

The claim **precedes** any product/variation save and is never silently
released on a timeout, PHP exception, failed operation insertion, missing
attachment or elapsed duration. Stored records include the original payload
hash and a server-generated claim, but no customer data. A failed creation may
have left product rows or variations; the claim must be correlated with the
WordPress inventory and operation ledger during a separately reviewed
operator-only reconciliation. Deleting a row just because the HTTP response
failed is unsafe. No data migration/backfill or automatic claim retention job
is part of this PR; the owner must agree on a recovery process before rollout.

Disposable WordPress CI exercises two explicit keys, reordered object keys,
same-key changed-payload conflicts, admin permissions, independent actors,
transaction rejection and six simultaneous PHP/WordPress processes targeting a
single new product/operation. Existing WooCommerce lifecycle and rollback
acceptance remain active. Cloud quotas, batch accounting, payment and live
site configuration are unchanged. #103 and the beta release gate remain open.
