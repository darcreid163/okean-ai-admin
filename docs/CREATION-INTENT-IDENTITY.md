# Creation intent identity (canonical fingerprint)

Status: Native source contract for issue #122 (split from #103). This document
does not authorize reconciliation, retention changes or a release.

Creation paths reserve a unique WordPress option **before** they write an
artifact. The option name is the atomic admission barrier. `OAI_Creation_Intent`
only answers one question: do two requests describe the same logical creation?
It never releases, rewrites or expires a reservation.

## Canonical payload

`OAI_Creation_Intent::fingerprint($payload)` is the SHA-256 of:

- associative array keys sorted with `SORT_STRING` at every depth;
- list order preserved (Elementor element order, product variations and other
  lists are semantic, not a reorderable map);
- UTF-8 JSON with `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES` and
  `JSON_PRESERVE_ZERO_FRACTION`;
- scalar types preserved (integer, float, boolean, null and string are
  different payloads);
- bounded recursion depth (`128`); input that cannot be encoded is rejected
  before a claim is written.

The scheme marker is `oai/creation-intent/v1`. New reservation records store
`payload_scheme`, `payload_fingerprint` and, for compatibility with older
readers, `payload_hash` (the same canonical value).

## Families

| Family | Canonical payload | Documented aliases/equivalences |
|---|---|---|
| workspace | stylesheet, template, clone flag | `clone_theme` strings `0`/`false`/`no` are one boolean |
| menu | case-normalized menu name | name case is not a new menu intent |
| menu_item | menu id + sanitized item fields | field order is irrelevant; sanitized title is the title |
| product | sanitized product draft fields | map key order is irrelevant; variations stay ordered |
| elementor_page | title, excerpt, slug, status, template, template type, source id, caller element tree | `title`/`title_b64`, `excerpt`/`excerpt_b64`, `slug`/`slug_b64`, `data`/`data_b64` decode to the same payload |
| media | existing media intent journal | identity name is unchanged; canonical fields are additive |

The element tree is canonicalized **before** normalizing legacy root widgets,
so randomly generated wrapper IDs cannot make a retry look like a new request.

## Identity and boundaries

- An explicit `idempotency_key` identifies one request for that actor, even if
  the payload changes. A reused key with a different canonical payload returns
  the existing `*_intent_conflict` error.
- Without a key, identity is the canonical payload plus the site and actor.
  A different payload, actor or site is an independent intent.
- The client/OAuth connection is not part of the creation identity: retries from
  another client of the same owner must still deduplicate.
- Approval, Verify, signed Receipt/Proof, Rollback, target policy and Gateway
  Ledger boundaries are unchanged.

## Historical records

Records written before `payload_scheme`/`payload_fingerprint` existed cannot be
compared safely: their legacy `payload_hash` was produced from a different,
non-canonical payload.

`OAI_Creation_Intent::classify()` therefore returns:

- `same` — canonical record with the identical normalized payload; keep it
  pending for the normal lifecycle;
- `conflict` — canonical record for a deliberately different payload;
- `legacy_unverifiable` — no canonical fingerprint; **hold** and require
  evidence-based reconciliation. Never guess that it matches and never release
  it by age, missing operation link or absent current object;
- `unreadable` — not a decodable record; hold as unknown.

The read-only administrator inventory reports `with_canonical_fingerprint`,
`without_canonical_fingerprint` and `legacy_unverifiable` per family. These are
aggregates, not a reconciliation queue.

## Acceptance

Disposable WordPress/MySQL tests cover:

- map-order/Unicode canonicalization and semantic list order;
- REST `*_b64` aliases and MCP plain aliases producing one pending intent;
- a different payload and a different actor producing independent intents;
- legacy menu-item claims staying `pending` instead of being judged a conflict;
- audit counters for canonical and legacy records.
