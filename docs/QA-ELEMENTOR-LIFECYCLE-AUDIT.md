# Independent QA audit — Elementor Schema/Save boundary (Issue #3, pass 1)

Role: independent reviewer (secondary agent)
Issue: #3 — [Native QA] Independent review and adversarial Elementor lifecycle tests
Reviewed: Draft PR #1 (`test/native-mcp-approval-regression`) and `includes/class-oai-elementor-schema-save.php` on `main`
Base SHA: `914081a` (`main` when this branch was created)
Scope of this pass: **test-only** — `tests/`, `docs/` and CI. No `includes/` change, no MCP/REST exposure, no production action.

This document records what the staged schema/save boundary does **today**. Every finding below is reproducible on PHP 8.3.35 and 8.4.26 without WordPress, MySQL or Elementor:

```bash
php tests/elementor-schema-save-gap-repro.php
```

The reproducer exits `0` while the documented gaps are present and fails only if a *safe* assertion regresses (approval flag ignored, stale snapshot accepted, no-op applied, replay double-write). When a gap is fixed, the matching `GAP-n` case must be inverted or removed.

## 1. Summary

| # | Severity | Finding | Status |
|---|---|---|---|
| GAP-1 | **High** | `apply_approved()` never validates the payload against the prepared/approved `after_sha256` → a caller can persist a different tree under an approval that was never granted for it | reproduced |
| GAP-2a | **High** | `rollback_approved()` accepts a caller-supplied `before` with no hash binding → rollback writes attacker-chosen content | reproduced |
| GAP-2b | **High** | Residual after PR #6: a *self-consistent* forged `before`/`after` pair passes integrity checks but has no provenance — the immutable original snapshot is never loaded | reproduced |
| GAP-3 | **High** | Authorization is the boolean `$approved` parameter; no user, capability, owner, site, client or operation check exists in this class | reproduced |
| GAP-4 | **Medium** | No authoritative post-write compare: `save_document()` only proves "non-empty", so a normalising meta filter yields `true` while the approved tree did not land | reproduced |
| GAP-5 | **Medium** | Tree equality uses strict `!==`, so an identical document with different array key order is refused as `oai_stale_target` (false conflict) | reproduced |
| GAP-6 | **Medium** | `schema()` / `snapshot()` perform no capability or ownership check → private content of another author is readable once wired to any read route | reproduced |
| GAP-7 | **Medium** | No operation id, receipt or proof reference is persisted by apply/rollback | reproduced |
| GAP-8 | **Low** | A non-string `before_sha256` triggers a PHP "Array to string conversion" warning instead of a typed rejection | reproduced |
| GAP-9 | **Medium** | Check-then-act with no lease/lock: concurrent applies both pass the snapshot read; only serialisation saves the second one | reproduced (single-process evidence only) |

Safe boundaries that already hold and are locked by the reproducer:

- apply without `approved === true` → `oai_approval_required`, document untouched;
- wrong `before_sha256` → `oai_stale_target`, document untouched;
- `after === before` → `oai_noop`;
- replaying an already applied change → `oai_stale_target`, no second write.

## 2. Why GAP-1 and GAP-2 matter most

Issue #2 acceptance says: *"Approved change recorded as immutable before/after … Server-generated approval verified at apply"* and *"Stale document/hash conflicts fail closed"*.

`validate_replace()` returns `before_sha256` **and** `after_sha256`, but `apply_approved()` reads only `before`, `after` and `before_sha256`. The approved `after_sha256` is dead data. Any code path that can reach `apply_approved()` — a future REST route, an ability, another plugin, an MCP bridge — can pass an arbitrary `after` and the class will write it, provided the current tree still matches `before`.

Minimal reproducer (from the test file):

```php
$approved = OAI_Elementor_Schema_Save::validate_replace($A, $safe, sha256($A)); // after_sha256 = sha256($safe)
OAI_Elementor_Schema_Save::apply_approved($post_id, [
    'before'        => $approved['before'],
    'after'         => $attacker_tree,          // never approved
    'before_sha256' => $approved['before_sha256'],
    'after_sha256'  => $approved['after_sha256'], // ignored
], true);
// => true, stored tree === $attacker_tree
```

The same shape applies to `rollback_approved()`: it verifies only that the live tree equals `change['after']`, then writes `change['before']`, which the caller fully controls.

**Impact:** the class cannot be a boundary on its own. It is safe only as an internal helper called by an engine that has already persisted and re-verified the exact byte-level diff (the existing `OAI_DB` operation + `OAI_Verify` + receipts path). That must be stated in the code, not only in the docblock.

## 3. Recommended fixes for the Issue #2 implementer

1. `apply_approved()`: require `after_sha256` and compare `hash('sha256', wp_json_encode($change['after']))` to it with `hash_equals` **before** saving. Reject when absent.
2. `rollback_approved()`: do not accept `before` from the caller. Load the original snapshot from the persisted operation record, compare its hash with the caller's `before_sha256`, and write only that.
3. Replace the boolean with an authorization object: operation id + persisted actor/site/client binding + server-side approval state. Keep `approved=true` only as a test fixture hook, and make it unreachable from any request path (a `private`/`final` constructor flag or a `wp_doing_cron()`-style guard is not enough — the class is `public static` today).
4. After writing, call `verify($post_id, $change['after'])` and fail the apply when `ok !== true`. Emit receipt + proof through the existing engine.
5. Canonicalise before comparing: recursive `ksort` on associative arrays (lists keep order) or compare canonical JSON. Precedent: the 2.1.8 Yoast map-order conflict fix.
6. Add `current_user_can('edit_post', $post_id)`, authored-post and post-status checks to `snapshot()`/`schema()`, and return the same `WP_Error` shape for denied and missing targets.
7. Wrap apply/rollback in the existing operation lease so two concurrent calls cannot both pass the snapshot read.
8. Validate `before_sha256` with `is_string()` + `preg_match('/^[a-f0-9]{64}$/D')` before casting.

## 4. Issue #3 required scenarios — coverage in this pass

| Required scenario | This pass | Note |
|---|---|---|
| Unauthorized apply / rollback, missing approval | covered (isolated) | GAP-3 shows the flag is the only gate |
| Stale SHA and simultaneous edits | partly | stale SHA covered; simultaneity needs two real PHP processes + MySQL (GAP-9 is single-process evidence only) |
| Wrong post, wrong widget, unpublished/other author's document, capability mismatch | partly | GAP-6 proves no capability check; per-post ownership needs a real WordPress user context |
| Failed apply is terminal; fresh operation required | **not covered** | needs the operation engine, not this class |
| No-op / repeat apply / idempotency | covered (isolated) | no-op and replay refused; double-charge needs the quota layer |
| Persisted receipt/proof and exact rollback target | partly | GAP-7 proves nothing is persisted; exact target blocked by GAP-2 |
| WP/Elementor pinned and latest regression | **not covered** | requires real WordPress + Elementor + MySQL; PR #1 `wordpress-smoke` covers activation only |

Everything marked "not covered" needs the `wordpress-smoke` path (MySQL 8, WP 7.0.6 and latest, Elementor 4.3.3 and latest). This pass deliberately does not claim those results.

## 5. Review notes on Draft PR #1

- PR #1 is test-only and its CI is green (5/5 checks on `69a79da`). It extends consent, upgrade-smoke and lifecycle coverage; it does not touch `includes/`.
- `tests/wordpress-lifecycle-smoke.php` and `tests/wordpress-elementor-schema-save.php` exercise the happy path through the real stack. They do **not** assert the negative cases GAP-1/GAP-2 describe, because the staged class currently has no negative path to assert — the missing check is the finding.
- Baseline to keep: PR #1 is a test baseline to review, not a merge request. Nothing in this audit asks for a merge.

## 5a. Cross-check against Draft PR #6 (Issue #2, ChatGPT)

The same reproducer was executed against two heads of `agent/chatgpt/2-elementor-operation-binding`. The reproducer now uses `OAI_Elementor_Schema_Save::tree_hash()` when present, because PR #6 replaced the raw JSON hash with a canonical document digest.

| # | on `main` (`914081a`) | PR #6 `8deab87` | PR #6 `9731fda` |
|---|---|---|---|
| GAP-1 payload not bound | reproduced | **closed** | closed |
| GAP-2a unbound rollback | reproduced | **closed** | closed |
| GAP-2b forged self-consistent pair | reproduced | open | **open** — integrity without provenance |
| GAP-3 boolean authorization | reproduced | open | open |
| GAP-4 no post-save compare | reproduced | **closed** | closed |
| GAP-5 key-order false conflict | reproduced | open | **closed** — canonical digest |
| GAP-6 unscoped snapshot/schema | reproduced | open | open |
| GAP-7 no receipt/proof/id | reproduced | open | open |
| GAP-8 malformed SHA warning | reproduced | **closed** | closed |
| GAP-9 check-then-act race | reproduced | open | open |
| safe assertions (7) | all pass | all pass | all pass |

Reproduced: `main` 10/10, `8deab87` 6/10, `9731fda` 5/10. The reproducer asserts only *safe* behaviour fatally, so it stays green on every build and the table is the deliverable.

Note on GAP-5: `tree_hash()` sorts associative keys recursively while preserving list order, which is the correct contract for Elementor trees. The canonical digest also means old raw-JSON digests are no longer accepted — any operator-side tooling that computed hashes itself must use `tree_hash()`.

## 6. Files in this branch
- `tests/elementor-schema-save-gap-repro.php` — isolated reproducer (new).
- `docs/QA-ELEMENTOR-LIFECYCLE-AUDIT.md` — this report (new).
- `.github/workflows/native-validate.yml` — adds the reproducer to the PHP job (1 step).

No `includes/` file was modified. No endpoint, merge, release or production action was performed.

## 7. Handoff

```text
Role: independent reviewer
Issue / PR: #3 / this Draft PR
Base SHA / head SHA: 914081a / 9446b89 (+ doc-only follow-up commit)
Files changed / files reviewed: tests/elementor-schema-save-gap-repro.php, docs/QA-ELEMENTOR-LIFECYCLE-AUDIT.md, .github/workflows/native-validate.yml / includes/class-oai-elementor-schema-save.php, includes/class-oai-elementor.php, PR #1 diff
Change summary: isolated adversarial reproducer for the staged Elementor schema/save boundary, 9 documented gaps, 7 locked safe behaviours
Tests executed: php tests/elementor-schema-save-gap-repro.php on PHP 8.3.35 and 8.4.26 (local); CI run 39403136088 — php 8.2/8.3/8.4 and wordpress-smoke all pass
Unverified scenarios and risks: real WordPress/Elementor integration, cross-process concurrency, capability/owner enforcement, receipt/proof emission, failed-apply terminality
Suggested next action: implement GAP-1 and GAP-2 first (payload binding and rollback target binding), then re-run this reproducer and invert those cases
```
