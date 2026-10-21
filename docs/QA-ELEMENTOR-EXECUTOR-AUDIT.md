# Independent audit — staged Elementor document executor (PR #6 head `2dce6a6`)

Role: independent reviewer (DeepSeek). Scope: `class-oai-elementor-document-executor.php`,
`-operation-lease.php`, `-operation-binding.php`, `-trusted-planner.php`, `-document-recovery.php`,
plus the `OAI_Verify` and `OAI_Proof` changes on `agent/chatgpt/2-elementor-operation-binding`.

Method: code-level review of the exact head, plus the existing adversarial reproducer re-run
against that head. No write to the branch, no merge, no production action.

Reproducer status on `2dce6a6` (unchanged from `9731fda`): **5 of 10 gaps remain**
(GAP-2b provenance, GAP-3 boolean authorization in the staged helper, GAP-6 scoping,
GAP-7 receipt/proof linkage, GAP-9 lease). GAP-1/2a/4/5/8 stay closed.

The findings below are **new** and specific to the executor layer that ChatGPT asked to be
reviewed. They are ordered by how much damage the failure mode can cause.

## F1 — High — a verified write can be recorded as `apply_failed` with no rollback path

`apply_human()` saves the document, then verifies. If verification fails, it calls
`self::fail($id,'apply','verify_failed')`, which transitions the row to `apply_failed`
(`class-oai-elementor-document-executor.php` lines 61–71 and 128–139).

At that point:

- the Elementor document **has been written**;
- `applied_data` and `applied_at` are never persisted, because they are only set in the
  success branch (lines 79–84);
- `Operation_Binding::inspect()` permits rollback only for `applied|proved` **and**
  `applied_at IS NOT NULL` (binding lines 32–42), and the CAS adds
  `applied_at IS NOT NULL` (lease line 86).

So the product state says "nothing was applied", the site says the opposite, and the one
mechanism that could restore the previous tree is unreachable. Recovery is read-only by design,
so there is no automated or operator path back.

**Required fix.** After a successful `save_document()`, persist the applied image and timestamp
*before* verifying (status `applied_unverified` or keep `applying` with `applied_data`), so a
failed verification still leaves a claimable rollback. Alternatively, on verification failure,
immediately restore `before` and record `restore_after_failed_verify` plus its own proof.

## F2 — High — the execution proof is reproducible from stored data, so it is not proof of execution

`OAI_Proof::make($id)` is called *after* the write and derives the proof from `after_data` /
`applied_data` and the current row. Nothing binds it to the actual save call: no return value,
no monotonic counter, no seal over a server-side event. `proof_kind=execution` is a label, not
evidence. Anyone able to write the operation row can regenerate a "verified" execution proof at
any later time, and the rollback-phase fix in the same commit does not change that.

There is also a crash window with no repair: the executor transitions to `applied` (line 85)
and only then makes the proof (line 88) and the receipt (line 96). A crash between them leaves
`status=applied` with no receipt; `OAI_Elementor_Document_Recovery` classifies document state
only and never emits or detects the missing receipt.

**Required fix.** Seal the execution *before* saving: HMAC over (operation id, actor, post id,
expected after hash, plugin build, started_at) with the site signing key, persisted with the
claim; store the save result and verification digests alongside; make the receipt and proof
reference that seal. Add a reconciliation query that reports `status=applied` rows without a
receipt as an incident, not as success.

## F3 — Medium-High — lost update between the snapshot check and the save

Lines 52–61 read the live tree, compare it to the expected image, and then call
`OAI_Elementor::save_document()`. Elementor's save performs no compare-and-swap on the
document, so a concurrent editor save inside that window is silently overwritten. The CAS covers
the *operation row*, not the document, and the inline comment "Never overwrite a concurrent
editor change, even after CAS" therefore overstates what the code guarantees.

**Required fix.** Capture `post_modified_gmt` and Elementor's edit lock before saving, re-read
immediately before the write, and after saving compare the previously captured
`post_modified_gmt` with the post-write value. If it advanced for a reason other than this save,
record `conflict_suspected` and require human review instead of returning `verified=true`.
Acquiring Elementor's own document lock would remove most of the window.

## F4 — Medium — the target post type is not constrained

`Operation_Binding::inspect()` rejects only `trash` and `auto-draft` (lines 67–70). A revision,
attachment or `nav_menu_item` passes `get_post()` and `edit_post()`. An operation planned
against a revision would "apply", "verify" against revision meta and produce a proof/receipt
while the published page never changes — a billed no-op reported as verified.

**Required fix.** Allowlist Elementor-capable content types (`page`, `post` and types registered
with Elementor document support), reject `wp_is_post_revision()`, `attachment`,
`nav_menu_item`, and any type whose Elementor document is not a supported document type.

## F5 — Medium — a failed status transition leaves the row stuck in the in-flight state

`fail()` calls `transition()` and ignores the result (lines 133–138). If the transition loses a
race or the DB write fails, the row stays in `applying` / `rolling_back`, which no claim accepts
(expected states are `approved`, `applied`, `proved`). Recovery is read-only, so there is no
product path out of that state.

**Required fix.** On a false transition, re-read the row, record the observed status in a
`manual_review` receipt and log the mismatch; provide an operator-only transition that clears a
stuck claim with explicit evidence.

## F6 — Low-Medium — an error after a confirmed write is reported as a failed action

`oai_document_proof` and `oai_document_receipt` return HTTP 500 after the document was saved and
verified (lines 92, 101). The gateway charges a successful call only, so the change can be
applied and unbilled, and the caller sees "failed" for work that happened. A retry with a new
idempotency key creates a second operation that the CAS refuses, so *double* charging is
unlikely; "applied but reported failed" and "applied but unbilled" are the real risks.

**Required fix.** Once the write is confirmed, return a distinct result
(`status=applied, verified=true, reconciliation=required`) rather than a bare 500, and let the
billing layer decide with an explicit rule.

## F7 — Low — two different clocks for expiry

Preflight compares `strtotime($row['expires_at']) < time()` (PHP) while the CAS compares
`expires_at >= UTC_TIMESTAMP()` (MySQL). With any clock or timezone skew the two disagree;
the failure direction is safe (CAS refuses), but the preflight result becomes unreliable.

**Required fix.** Use one source of truth — evaluate expiry inside the CAS only, or store an
integer epoch.

## F8 — Info — the nonce is not operation-scoped

`claim_human()` verifies the standard `wp_rest` nonce; it is not bound to the operation id or
post. Authorization rests on `owner === current user` + `manage_options` + `edit_post` +
the persisted approval audit, which is defensible. It should be stated in the contract so that a
later integration does not treat the nonce as operation-scoped.

## What is correct and should be kept

- canonical tree digest (`tree_hash`/`same_tree`) used consistently;
- independent MySQL read of `_elementor_data` that bypasses the meta cache, plus API↔DB
  consistency check in `OAI_Verify`;
- CAS SQL that hashes the stored `before_data`/`after_data`/`meta_data` columns, so a row edited
  between preflight and claim cannot be applied;
- `owner === current user` check in the binding — an administrator cannot execute another
  administrator's operation;
- rollback requires `applied_at`, so an unapplied operation cannot be rolled back;
- proof phase separation (apply proof is not reused for rollback);
- recovery class is read-only and never retries.

## Suggested order

1. F1 (applied-but-failed) — data-safety first.
2. F3 (lost update) — the only finding that can lose a human edit.
3. F2 (proof semantics + crash window).
4. F4 (target type), F5 (stuck claim), F6 (billing semantics), F7 (clock), F8 (documentation).

Each fix should land with a test that fails on `2dce6a6`: a verify-failure fixture that asserts
`applied_data` is present and rollback is claimable; a concurrent-editor fixture that asserts
`conflict_suspected`; a revision-target fixture that asserts refusal; a forced-transition-failure
fixture that asserts a `manual_review` receipt.

## Evidence

- Reviewed head: `2dce6a6c836488d53c25a45aecab004d7644b937` (PR #6, branch reconciled with main).
- Reproducer: `tests/elementor-schema-save-gap-repro.php` on that head — 5/10 gaps, 7 safe
  behaviours pass.
- Line references are to that head and will drift after the next commit; the finding text is
  what matters, not the numbers.
