# Creation claim inventory — read-only operator preflight (#103)

Before planning retention or reconciliation of creation-intent reservations, a
site administrator can inspect **aggregate counts only**:

`GET /wp-json/okean-ai/v1/diagnostics/creation-intents?limit=50`

Requires WordPress administrator authorization; the native callback enforces
`manage_options` independently of REST permissions. This route is **not**
advertised as an MCP tool and has no release, retry, delete or rollback action.
An authenticated Cloud/Gateway identity without administrator capability is
not sufficient. It performs six bounded, indexed option-prefix reads (default
50, maximum 100 + one sentinel row per family) for:

- media
- workspace
- menu
- menu_item
- product
- elementor_page

Each group returns only sampled/truncation counts, declared reservation/other
states, unreadable metadata count, count of self-declared operation references,
count of usable timestamps and count of claims older than 24 hours. A
`complete=false` response means at least one family exceeded its **sample
cap**. This is **not** a total row count, a full scan, a cleanup queue or an
automated judgement of which records can be released. Older-than-24h is merely
an observation. The actual existence of artifacts and ownership of related
operations have **not** been independently verified.

## Preliminary operation-reference checks

For claims that declare a positive numeric operation ID, the auditor performs
one bounded batch lookup against the Native operation ledger per family.
It distinguishes: a reference to an operation of the expected creation type,
a reference to a different type, an absent operation and malformed reference
values. The expected classes are media_upload, menu_create, menu_add_item,
woocommerce_product_create and elementor_page_create; workspaces have no
corresponding operation type in this lifecycle. Only aggregated counters are
returned. A same-type reference is **not proof of ownership, payload match,
approval or successful application**. Nor does an absent reference prove
nothing was created: a crash could have happened before storing it. Actual
reconciliation requires independent corroboration and is not implemented.
Batch lookup is bounded by the same per-family limit; it does not run a query
per claim or mutate the ledger.

No option names, claim values, keys, request payloads, WordPress user IDs,
operation IDs, paths, media filenames or customer fields are returned.
Stored legacy PHP arrays are decoded with object deserialization disabled;
invalid/oversized values contribute only to unreadable-metadata counters.
Database errors fail closed without leaking SQL or underlying messages.
There are no site writes from this endpoint.

Disposable WordPress/MySQL acceptance uses synthetic claims (including a fake
secret) for all six families and confirms aggregate classification, no raw
secret/ID disclosure, bounded/invalid limits, actual REST administrator-only
access, anonymous denial and cleanup of **fixture-owned options only**.

## Remaining operator reconciliation gate

To actually release a blocked creation intent requires a **separate,
explicitly approved** reconciliation design using original actor/site
identity, option record, requested artifact (including filesystem or Woo
variations), operation lifecycle, approval, Verify and signed proof/receipt.
Old reservations can predate payload hashes or lack a saved artifact pointer.
An expired timestamp, absent operation link, missing current WordPress object
or a stale browser response cannot by itself justify claim deletion. A
reconciliation action must not be added to this read-only route.

No production site, tariff, Cloud ledger or customer data has been modified.
This does not complete issue #103 or justify release #40.
