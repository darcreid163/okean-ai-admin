# Catalog failure before site-tool dispatch

The 0.3.13-dev candidate corrects a single-call settlement bug: failure of
`tools/list` used to enter the unknown-write catch even though the requested
`tools/call` had never been sent. This unnecessarily held one quota reservation
and required an operator receipt that could not exist for a never-dispatched tool.

A failed catalog lookup now produces a sanitized JSON-RPC error in a normal failed
receipt. The existing atomic settlement releases its reservation and charges zero.
The failed receipt remains bound to the same owner/site/fingerprint/idempotency key;
a same-key retry returns that receipt without another catalog or tool request.
After service recovery a separate logical request can execute normally.

This preserves the existing failed-call accounting policy and Batch's existing
catalog-failure behavior. It is not a tariff change, migration, blanket timeout
refund or permission to retry an uncertain write. Once a site tool is dispatched,
timeout, malformed reply or settlement failure continues to hold the reservation,
mark the request uncertain and require evidence-based reconciliation. Settlement
failure also remains uncertain even when catalog acquisition had failed.

`tests/catalog-failure-settlement.php` uses the actual Store/Core/Gateway with a
synthetic transport and checks zero-charge failed catalog receipts, same-key cache,
recovery via a separate logical request, no transport-detail disclosure, unchanged
settled balance, tenant/scope denials, and retained unknown-outcome reservations.
It runs in Cloud CI on SQLite/MySQL and PHP 8.2/8.3/8.4. No installed runtime,
identity, credential, quota balance or schema is migrated by this source change.
