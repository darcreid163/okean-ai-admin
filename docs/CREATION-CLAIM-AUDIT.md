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

The canonical-fingerprint counters are:

- `with_canonical_fingerprint` — valid `oai/creation-intent/v1` record;
- `without_canonical_fingerprint` — decodable record with no valid canonical
  scheme/fingerprint;
- `legacy_unverifiable` — a subset of the latter that carries a legacy
  `payload_hash`. It looks comparable but was produced from an older payload
  shape, so it must never be reported as a verified match.

See `CREATION-INTENT-IDENTITY.md`. A legacy or unreadable record stays held;
this route still has no release, retry or reconciliation action.

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
reconciliation requires independent corroboration; the administrator-only,
exact-generation action is documented in `CREATION-CLAIM-RECONCILIATION.md`.
This inventory route remains read-only.
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

## Workspace filesystem footprints (read-only)

For workspace claims with an `id` matching the strict generated format
`ws-YYYYMMDD-HHMMSS-32hex`, the report counts whether its directory and
`workspace.json` metadata file **exist**. The scan uses
`wp_upload_dir(null, false)` to avoid creating upload directories and does
not read any metadata bytes. A missing directory, missing metadata file,
invalid/legacy ID, unavailable root or symlink is reported only as an
aggregate count. It must **not** trigger automatic recreation, deletion,
rollback or releasing the held claim: a crash could have happened at any
point, and older workspaces may use different ID formats.

The auditor never concatenates an unvalidated option value into a path,
never follows a claimed directory symlink, and never exposes actual
filesystem paths or IDs. Real disposable WordPress tests seed a safe
workspace.json containing a synthetic secret, a missing directory, a
symlink and path traversal input; none are disclosed or followed. Only
test-created resources are removed in fixture teardown.

## Operator reconciliation

A separate administrator-only action is implemented in #123; see
`CREATION-CLAIM-RECONCILIATION.md` for the exact-generation rule, required
artifact/operation evidence, explicit absence confirmation, audit event and
retention policy. It never releases a claim by age, a missing response or a
single failed lookup, and never deletes a claim or artifact. The read-only
route above is unchanged and must not be turned into a reconciliation action.

Old reservations can predate payload hashes or lack a saved artifact pointer;
the operator must supply independent evidence. An expired timestamp, absent
operation link, missing current WordPress object or stale browser response
cannot by itself justify a decision.

No production site, tariff, Cloud ledger or customer data has been modified.
This does not complete issue #103 or justify release #40.
