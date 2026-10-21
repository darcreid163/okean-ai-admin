# Independent review — external-editor lost-update reproducer (Issue #13)

Role: independent reviewer (DeepSeek). Implementation and the guard belong to the #2 owner.
This review checks whether the reproducer actually proves the claimed gap, whether the guard is
sound as a *signal*, and what would count as a fix.

Reviewed head: PR #6 `d857c4b` — `tests/wordpress-elementor-external-editor-race.php`,
`includes/class-oai-elementor-external-edit-guard.php`, `docs/ELEMENTOR-EXTERNAL-EDIT-RACE.md`,
and CI run `39424413203`.

## 1. Is the reproducer valid? Yes

The fixture is a deterministic state machine, not a timing gamble. Shared state lives in an
option row and three phases run as separate WP-CLI processes:

| Phase | What it does |
|---|---|
| `seed` | creates a draft page, writes the `before` tree, records the post id |
| `ai` | runs the guard preflight, asserts `writer_exclusion_guaranteed === false`, sets `ai_checked`, waits for `writer_saved`, then calls `OAI_Elementor::save_document($ai)` |
| `editor` | waits for `ai_checked`, saves a different tree, sets `writer_saved` |
| `verify` | asserts live == AI tree and live != editor tree, then cleans up |

The order is enforced by `f3_wait()`, so the foreign write provably happens **after the AI
preflight and before the AI save** — exactly the window Issue #13 names. The final assertion
(`same_tree($live,$ai)` and `!same_tree($live,$editor)`) proves the editor's content was lost.

The test is a *negative* test: green means "gap reproduced". That is the correct shape, and it
must be inverted (assert `!same_tree($live,$ai)` plus a conflict error) once a fix lands, or it
will silently keep passing while claiming safety.

## 2. Does it prove the gap in the product? Yes, at the primitive level

The `ai` phase calls `OAI_Elementor::save_document()` directly rather than the staged executor.
That is honest and sufficient for this issue: it proves the **primitive** has no exclusion, and
the executor inherits the gap because between its own snapshot check and the same
`save_document()` call there is no atomic serialization. Its comment says exactly that.

One consequence to state in the issue: a fix inside the executor alone (extra checks) cannot
close the primitive-level window; the protection has to be at the persistence boundary or must
be declared unsupported.

## 3. Guard assessment

`OAI_Elementor_External_Edit_Guard::inspect()` is correctly labelled advisory. What it does well:

- administrator + `edit_post` + target checks before anything else;
- page-only target, rejects `trash`/`auto-draft`/`inherit`/revisions (this also resolves my F4);
- honors `_edit_lock` with the core lock window and fails closed on a lock with no valid owner;
- reads `_elementor_data` from MySQL, bypassing the meta cache;
- returns `writer_exclusion_guaranteed => false`, and the test asserts that value. A guard that
  claimed exclusion would be the actual bug.

Its limits are the ones the issue already lists: WordPress locks are cooperative, and a WP-CLI,
cron, REST or plugin writer takes no lock at all.

## 4. Findings

### G1 — Medium — two components disagree about which `_elementor_data` row is authoritative

The guard requires **exactly one** meta row (`ORDER BY meta_id ASC LIMIT 2` and
`count($values)!==1` fails closed). `OAI_Verify` takes the **latest** row
(`ORDER BY meta_id DESC LIMIT 1`). With duplicate rows the guard refuses while verification
accepts a row the guard never saw; the operation can then be proven against a different row than
the one that was preflight-checked.

Fix: one resolver — `OAI_Elementor_Schema_Save::canonical_meta_row($post_id)` — returning
`{meta_id, value}` or a fail-closed error, used by guard, verify, executor and recovery. Record
`meta_id` in the outcome evidence (see the #14 design, section 4).

### G2 — Low — the guard's decision is taken before the same write it is meant to guard

The executor calls `guard → snapshot → save`. The guard reads the document, then the snapshot is
read again, then the save happens. Two reads do not create exclusion; they widen the window by
one extra read. This is not a new bug, but it means the guard must not be counted as mitigation
in the risk register — only as a pre-filter that refuses obviously unsafe targets.

### G3 — Low — the fixture depends on a disposable site by environment only

`OAI_DISPOSABLE_WP_TEST=1` plus a `localhost`/`.test` home check is a reasonable barrier. Ensure
the CI job that runs it cannot execute on a host whose `home_url()` is a real domain; if a job
matrix ever reuses a production-like URL, the fixture would refuse — which is the safe direction,
but it would also fail the build silently as "not a disposable site". Emit a distinct CI skip
marker instead of a generic failure.

### G4 — Info — the test asserts the bug, not a contract

Keep it, but pair it with a contract test that asserts the desired outcome
(`external_edit_conflict` + unchanged editor content + operation left in a claimable state).

## 5. What would count as a fix

Four options, in the order I would evaluate them:

1. **Transaction + row lock around the write.** Open a DB transaction, `SELECT ... FOR UPDATE` the
   `postmeta` row for `_elementor_data`, re-read and compare, then let Elementor save inside the
   same transaction. This genuinely serializes writers that go through MySQL — including
   Elementor itself, because it writes the same row. Limits: long transaction during a
   builder save, deadlock risk, and it does not stop a writer that bypasses the row lock with a
   different statement ordering. Still the strongest practical option.
2. **Compensating detection.** Capture `post_modified_gmt` and the meta row before the save;
   after the save, if either moved in a way this operation cannot explain, immediately restore the
   `before` image and record `external_edit_detected`, leaving the operation in a claimable state.
   This does not prevent the lost write, but it bounds it to the duration of one save and keeps
   the editor's content restorable only if the foreign tree is captured — which it is not. So this
   is weaker than it looks; do not present it as protection.
3. **Optimistic digest check immediately before `document->save()`.** Narrows the window to
   microseconds but is still TOCTOU. Acceptable only as an additional layer.
4. **Declare document-wide AI writes unsupported for concurrent editing.** This is what the
   staged gate already does (`OAI_ELEMENTOR_DOCUMENT_STAGED_TEST` + disposable site only). It is
   an honest answer and should remain the default until (1) is implemented and proven.

## 6. Verdict

- The reproducer is valid, deterministic and targets the right window. **Issue #13 remains open**;
  nothing in `d857c4b` closes it.
- The guard is a sound *pre-filter*, not a mitigation. Do not move F3 to "closed" in any summary.
- The staged fail-closed gate is the correct state for this code. Keep it until option (1) is
  implemented and the negative test is inverted into a contract test.
- Recommended next step for the owner of #13: prototype option (1) on a disposable MySQL and
  publish the interleaving result, including deadlock behaviour, before any discussion of
  enabling document-wide writes.
