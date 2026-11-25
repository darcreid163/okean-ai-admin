# Paid licence delivery and one-time post-purchase reveal

Status: implementation contract for the Robokassa paid-licence flow. This document is not evidence that the feature is implemented or deployed.

## Product decision

After a verified Robokassa payment, the customer receives:
1. A dedicated post-purchase page named **Your licence key**.
2. A one-time reveal action on that page. The full key is returned only after an explicit button press and can be revealed once.
3. An email containing the same key as a recovery copy. Email delivery is retried independently and must not issue a second licence.

The reveal page is separate from the generic WooCommerce order-received content. It must not display the full key in the URL, HTML before the reveal action, page source, analytics events, logs, order notes, or REST diagnostics.

## Payment authority and timing

- Only the verified Robokassa Classic ResultURL path that passes signature and amount preflight and calls WooCommerce `payment_complete()` can create the paid entitlement.
- SuccessURL, FailURL, browser redirects, thank-you page views, and user-submitted order data are never payment evidence.
- The reveal page is available only after the WooCommerce order is paid and the Cloud payment ledger is `settled` with the exact order, owner, product, amount, currency, plan and licence identity.
- If the customer returns before the ResultURL callback has settled the payment, show a neutral “payment confirmation is in progress” state and allow a safe refresh. Do not show or generate a key from the browser return.

## One-time reveal page

- Use a dedicated virtual page/endpoint, e.g. `/license-key/`, linked from the successful order-received page after settlement.
- Link authorization must be bound to the WooCommerce order key and authenticated customer account. Require HTTPS, a valid WooCommerce order, `is_paid()`, matching customer ID, Robokassa gateway, and a matching settled Cloud ledger row.
- Do not trust order ID alone. Do not expose the key in the URL or query string.
- Initial GET renders only plan, order reference, expiry, activation allowance and a “Show licence key once” button. It does not consume the reveal.
- The button submits a nonce-protected POST. The server revalidates the order, account and ledger, then atomically claims the reveal record under a row lock / compare-and-set.
- Exactly one concurrent request may receive the plaintext key. In the same transaction, mark the reveal consumed and erase the encrypted reveal copy. Replays and refreshes show “The key has already been displayed; check your email” and never return the key again.
- Apply `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`; disable page caching and third-party analytics/scripts on the reveal response. Do not put key material into logs or order notes.
- Render the key only in the POST response, with a copy button and a clear instruction to save it. Do not auto-copy or send it to external scripts.

## Durable delivery and encryption

- Extend the Cloud Store with an additive, idempotent `license_deliveries` table keyed by WooCommerce order ID. Include owner, licence ID, plan, email destination, encrypted reveal secret, encrypted email secret, reveal-token hash (if an opaque link token is used), email status/attempts, created/sent/revealed timestamps.
- Use the existing Cloud `Vault` (XChaCha20-Poly1305) and a purpose-specific binding containing order ID, owner and licence ID. Never store plaintext licence keys or plaintext reveal tokens.
- Create the delivery row inside the same database transaction as payment settlement and licence issuance. If the transaction fails, the payment settlement and delivery record must roll back together.
- A replayed ResultURL or retry for an already-settled order must return the existing delivery state and must never mint a second licence, extend the term twice, or create a second reveal.
- Keep separate encrypted copies for reveal and email so consuming the one-time reveal does not prevent email retry. Delete each ciphertext immediately after its respective successful use.
- If the email fails, retain only the encrypted email copy and schedule bounded retries. Record sanitized failure codes only. After the retry ceiling, add a non-sensitive operator note and expose a resend action restricted to an administrator; never expose the key in the admin list.
- The customer email must go only to the WooCommerce order's registered customer email, checked against the WordPress account that owns the paid entitlement. Do not send to an arbitrary address supplied by the browser.

## Customer page content

Show:
- Product/tariff name and billing period.
- Paid order number and payment status.
- Licence expiry date and allowed number of site activations.
- One-time “Show licence key” action.
- After reveal: full key once, copy control, and activation instructions for WP AI Admin → Tariff and licence.
- After the reveal is consumed: a masked key prefix and a message to use the email copy or contact support. Never offer a second reveal.

Do not show a successful-payment state until WooCommerce and the Cloud ledger both confirm settlement.

## Refund and renewal behavior

- A confirmed full Robokassa refund revokes only the licence tied to that order, following the existing refund lifecycle. A pending/canceled refund does not change access.
- A partial refund does not revoke the licence under the existing policy.
- A refund of an older replaced order must not revoke a newer renewal.
- A new renewal produces its own order-scoped one-time reveal and email delivery. It must not reset or duplicate unrelated site activations.
- The reveal record is immutable after consumption; resend email must not reset the one-time reveal.

## Required tests

1. Valid verified payment creates one licence and one delivery row atomically.
2. Invalid signature, amount mismatch, unpaid order, guest order and wrong gateway create neither licence nor delivery.
3. Replayed ResultURL / settlement retry does not create a second licence, delivery row, reveal token or term extension.
4. Dedicated page GET never returns plaintext key and does not consume the reveal.
5. Unauthenticated visitor, different logged-in account, wrong order key, unpaid order and mismatched Cloud ledger are denied.
6. Correct owner can reveal the key once; response contains key only after explicit POST.
7. Concurrent reveal requests: exactly one receives plaintext; all others see consumed state.
8. Refresh, browser back, repeated POST and expired/invalid nonce never return the key again.
9. Key ciphertext is erased after reveal; plaintext key is absent from DB rows, order meta, logs and HTML before reveal.
10. Email send success erases its encrypted copy; temporary mail failure retries without issuing another licence; permanent exhaustion is visible to the operator without exposing the key.
11. Email destination is the registered owner email; browser-supplied email is ignored.
12. Full/partial/stale refund and renewal tests preserve the existing entitlement rules.
13. Cache headers and page-cache bypass are verified on the dedicated page.
14. HPOS and legacy WooCommerce order storage both pass.
15. Free accounts and existing manual licence activation continue to work unchanged.

## Release gates

- Keep this change as a Draft PR stacked on Robokassa PR #238–#242.
- No production deployment, live payment, Robokassa credential change, or order mutation is part of this work.
- Do not merge or enable public sales until exact-head Cloud and Native CI, disposable WooCommerce/HPOS tests, and a test-shop end-to-end purchase/reveal/email/activation/refund run pass.
