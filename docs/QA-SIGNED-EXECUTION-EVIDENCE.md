# Signed execution evidence and crash-safe reconciliation (Issue #14 — independent design)

Role: independent QA/design (DeepSeek). Implementation belongs to the #2 owner. This document
defines the evidence model, the crash matrix, the reconciliation algorithm, quota semantics and
the test matrix that an implementation must satisfy. It proposes no production write path and
no new endpoint.

Baseline reviewed: PR #6 head `d857c4b`, which detects a missing terminal Proof/Receipt and
allows an explicit no-save human reconciliation when an applied marker and the approved
after-image exist. That closes the *reporting* hole for one boundary; it does not make the
evidence tamper-evident and does not cover crashes before the applied marker.

## 1. Three things that must stop being called "proof"

| Class | Created | Proves | Does **not** prove |
|---|---|---|---|
| **Proof of intent** | before any write | this exact operation, actor, site, post, before/after digests and approval were authorized at time T | that anything was written |
| **Proof of persisted state** | after a readback | the document currently contains tree X, as read from two independent sources | who wrote it, or when, or that it was this operation |
| **Proof of execution** | around the write | this plugin observed its own write under a valid intent and recorded the outcome in an append-only chain | that no other writer acted, unless the pre/post state and timestamps are consistent |

Today `OAI_Proof::make()` produces class 2 and labels it `proof_kind=execution`. Renaming is not
enough: an execution witness needs a field that only the execution path can produce (a
write token committed before the save and observed after it) and an append-only chain so that
deleting or reordering evidence is detectable.

**Honest limit to state in the contract:** an attacker with arbitrary read/write access to the
WordPress files and database can forge anything, including the chain, because the signing key
lives in `wp-config.php`. The guarantee is: tamper-evidence against database-only edits, plugin
code without the key, and accidental corruption — not against a compromised host.

## 2. Event model (append-only, hash-chained, signed)

New table `{prefix}oai_operation_evidence`, insert-only (no UPDATE/DELETE in plugin code):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `operation_id` | varchar(64) | indexed with `seq` |
| `seq` | int unsigned | 1,2,3… per operation, unique together with `operation_id` |
| `kind` | varchar(24) | `intent`, `write_attempt`, `applied`, `verified`, `proved`, `receipted`, `rollback_intent`, `rolled_back`, `reconciled`, `failed` |
| `payload` | longtext | canonical JSON, see below |
| `prev_hash` | char(64) | hash of the previous event of the same operation; `GENESIS` for seq 1 |
| `hash` | char(64) | `sha256(prev_hash || canonical(payload))` |
| `sig` | char(64) | `hmac_sha256(site_key, hash)` |
| `created_at` | datetime | **UTC from the database clock**, not PHP |

`site_key` is derived from the existing site secret (`AUTH_KEY`/`AUTH_SALT` via HKDF), never stored.

`verify_chain(operation_id)` walks `seq` ascending and fails on: missing sequence number,
`prev_hash` mismatch, `hash` mismatch, `sig` mismatch, or an event whose `operation_id`/`post_id`
does not match the operation row.

## 3. Intent (before save)

Payload fields, all covered by the signature:

```
operation_id, action=apply|rollback, actor_user_id, site_id, post_id,
before_sha256, after_sha256, approved_at, approval_event_sha256,
expires_at_epoch, build_id, plugin_version, write_token_sha256, created_at_utc
```

- `write_token` is 32 random bytes generated at intent time; only its hash is stored here and the
  raw token is committed to a separate option `oai_write_token_{operation_id}` immediately
  before the save call. Its presence after a crash proves the process reached the write phase.
- The intent is written **before** `claim` changes status to `applying` (or in the same
  transaction), so a crash right after the claim still leaves evidence of why.

## 4. Outcome (after save)

`applied` event payload:

```
intent_hash, write_token_sha256, action,
elementor_api_sha256, mysql_meta_sha256, meta_row_id,
post_modified_gmt_before, post_modified_gmt_after,
verification_ok, readback_source=elementor_api+mysql, observed_at_utc
```

- `meta_row_id` is required: the verification must name the exact `meta_id` it read, so a later
  duplicate-row insertion cannot silently change which row is authoritative.
- `post_modified_gmt_before/after` are captured around the save. If `after` differs from what the
  save itself would produce (e.g. advanced by a foreign write), the outcome is `conflict_suspected`
  even when the tree matches.

## 5. Execution witness

The witness is the signed tuple `(intent_hash, write_token_sha256, applied_hash, meta_row_id,
post_modified_gmt_after)`. It is created by the executor, chained after `applied`, and referenced
by the Receipt. It proves the plugin's own view of its write; it does **not** prove absence of a
foreign write between the pre-check and the save — that is Issue #13's scope and must be stated
in the receipt text, not implied by the word "verified".

## 6. Crash matrix — required behaviour at each boundary

| # | Crash point | Durable facts | Readback at recovery | Allowed action | Final status | Quota |
|---|---|---|---|---|---|---|
| C1 | after claim, before `write_attempt` | status `applying`, intent | equals `before` | finalize as not applied, no write attempted | `apply_failed_unwritten` | 0 |
| C2 | after `write_attempt`, before save returns | status `applying`, intent, write token | equals `before` | finalize as not applied; token proves intent only | `apply_failed_unwritten` | 0 |
| C3 | save returned, before `applied` event | status `applying`, token | equals `after` | verify from both sources, append `applied`, continue | `applied_verified` | 1 |
| C4 | `applied` written, before Verify | status `applying`/`applied`, event chain | equals `after` | verify, append `verified` | `applied_verified` | 1 |
| C5 | `verified`, before Proof | chain has `verified` | equals `after` | append `proved` from chain + readback | `applied_verified` | 1 |
| C6 | Proof, before Receipt | chain has `proved` | equals `after` | append `receipted` | `applied_verified` | 1 |
| C7 | readback is neither `before` nor `after` | any of the above | foreign tree | **no write**, no automatic choice | `conflict_unresolved` | hold, never auto-release |

Rules that apply to every row: reconciliation **never writes the document**, never changes
`before_data`/`after_data`, never retries the save, and must be idempotent per
`(operation_id, reconcile_key)`.

## 7. Operator reconciliation algorithm

1. `reconcile_key` is supplied by the operator; a unique index `(operation_id, reconcile_key)`
   makes a replay a no-op that returns the previous result.
2. Verify the chain and the intent signature. Any break ⇒ refuse with `evidence_tampered`.
3. Verify the caller: `manage_options`, `edit_post`, actor equals the operation owner, fresh
   nonce bound to `reconcile:{operation_id}`.
4. Acquire a reconciliation lease (CAS on `reconcile_token`, 60 s, single winner).
5. Readback both sources **and** `post_modified_gmt`; classify C1–C7.
6. Refuse if a later human edit is detected: `post_modified_gmt` newer than the applied window,
   or a foreign `_edit_lock` older than the lock window. Protection of later human changes is
   the priority over completing the operation.
7. Append `reconciled` with the classification and the digests actually read, then set the final
   status with a CAS from the observed in-flight status.
8. Durably log to `OAI_Audit` with the reconcile key, operator and outcome.

## 8. Quota semantics

| Final status | Charge | Rule |
|---|---|---|
| `applied_verified` | 1 | the change is live and proven |
| `applied_unverified` (write likely, evidence incomplete, operator has not confirmed) | 1 + `billing_disputed` | never silently 0: the site was modified |
| `apply_failed_unwritten` | 0 | proven no write |
| `conflict_unresolved` | 0, reservation held | must never auto-release; operator resolves |
| `rolled_back` | rollback is free per the existing policy | apply remains charged |

A retried or reconciled action must never produce a second charge: the charge key is the
`operation_id`, not the request.

## 9. Time source (F7)

Store and compare **integer epoch seconds taken from `UTC_TIMESTAMP()`** for `expires_at`,
`approved_at`, the write window and the chain timestamps. PHP `time()` may only render values,
never decide expiry, so the preflight and the CAS cannot disagree.

## 10. Test matrix the implementation must satisfy

Positive: C1–C6 each simulated by aborting at the exact point; reconciliation completes and
produces one charge.

Negative and tamper:

1. edit an `intent` payload in the database → `evidence_tampered`;
2. delete a middle event → chain break at the next `seq`;
3. duplicate `seq` → unique index rejection surfaced as `evidence_tampered`;
4. replace `sig` with a valid signature of a different payload → mismatch;
5. replay `reconcile_key` → identical response, no new event, no new charge;
6. reconcile as a different administrator → `evidence_owner`;
7. reconcile with a stale/absent nonce → `evidence_nonce`;
8. readback `before` but an `applied` event exists → `conflict_unresolved`, no write;
9. readback `after` but no `write_attempt` → suspicious, require operator confirmation;
10. foreign `_edit_lock` active during reconciliation → refuse;
11. `post_modified_gmt` advanced after the applied window → refuse, protect the human edit;
12. two processes reconcile concurrently → one winner, one no-op;
13. duplicate `_elementor_data` rows → the recorded `meta_row_id` decides; disagreement fails closed;
14. quota: C3 completes, then a second reconciliation → one charge total.

Matrix: pinned WordPress 7.0.6 and latest, MySQL 8, PHP 8.2/8.3/8.4.

## 11. Open questions for the implementation owner

1. Is a new table acceptable, or must evidence live in the existing operation row + receipts?
   A separate append-only table is what makes the chain meaningful; storing it in one `proof`
   column cannot detect deletion of intermediate facts.
2. Where does the site key come from so that rotating `AUTH_KEY` fails closed rather than
   silently invalidating history? Proposal: derive with the existing HKDF label and store a
   `key_id` in each event; a rotation makes old events unverifiable, which must be reported, not
   hidden.
3. Should `applied_unverified` be visible to the AI client, or only to the operator? Proposal:
   the client receives `status=applied_unverified, chargeable=true, requires_reconciliation=true`
   and must not report success to the end user.
