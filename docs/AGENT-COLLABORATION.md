# Multi-agent development protocol (Native)

## Goal
Make independent coding agents collaborate asynchronously through GitHub issues, scoped feature branches, draft pull requests, code review and GitHub Actions. No agent-to-agent hidden communication or unsupervised production actions are assumed.

## Boundaries
- Repository: public Native WordPress plugin only. The hosted Cloud Core source stays in a separate private repository and is not provided to external agents by default.
- `main` is protected conceptually; agents **must never push directly, force-push, merge, create releases or deploy**. Repository administrators should enable enforceable branch rules in GitHub settings.
- No secrets, active tokens, customer data, private server source, production database dumps or deployment credentials in prompts, commits, PRs, CI artifacts or external model context.
- Do not directly expose staged Elementor save methods as MCP endpoints. A fixture boolean `approved=true` is NOT server authorization.
- All write operations must pass authoritative operation persistence, scoped owner/tenant/client checks, explicit human approval, apply/verify/proof/receipt and guarded rollback, with conflict detection.

## Task ownership
1. Maintainer creates one GitHub issue per coherent deliverable and assigns a single **code owner agent** and a different **review agent** in the issue body.
2. Implementer makes `agent/<role>/<issue-number>-<slug>` from a known base SHA, edits only its assigned files, commits in small changes, opens a **Draft PR** linking `Refs #<issue>`.
3. Reviewer comments on that PR, checks security boundaries, existing architecture, failure scenarios and tests. Reviewer does not rewrite the implementer's branch; fixes belong in follow-up commits by owner or a separate PR.
4. Both agents must inspect the current PR SHA and test results before recommending next steps. Do not report planned tests as passed.
5. Maintainer explicitly authorizes merge only after CI passes, independent review, no secrets/license regressions, and a resolved risk summary.

## Work partition for the current release
- **Implementation agent:** integrate staged Elementor Schema/Save with the **existing** Native operation/approval engine; work under `includes/` in a new branch; do not bypass OAI_DB/OAI_AI_Lifecycle_Control/OAI_Verify/OAI_Receipts.
- **Independent QA agent:** inspect existing Draft PR #1 and develop additional regression cases or a review report under `tests/` and `docs/`; focus on unauthorized writes, stale hashes, cross-document access, repeat calls, idempotency, receipt integrity, conflict-guarded rollback, editor/concurrency races.
- Never let both agents independently change the same production PHP file in parallel.
- The current Draft PR #1 (native MCP + lifecycle tests) is a baseline to review, **not a request to merge**.

## PR acceptance checklist
- Scoped diff; source issue; base SHA; no binary artifacts or unreviewed upstream source; no new production endpoint.
- PHP lint 8.2/8.3/8.4 and relevant isolated regressions.
- Real disposable WordPress/Elementor tests in pinned and latest compatibility profiles where applicable.
- Negative tests: permission/tenant/owner mismatch, stale snapshot, no-op, replay/double-charge, apply/rollback conflicts, denied API calls and unexpected failure.
- Record links to exact GitHub Actions run and commit SHA; keep Draft while required coverage is absent.
- Never change production without a separate, explicit maintainer approval.

## Handoff format
On each Issue/PR comment include:
```text
Role: implementer / independent reviewer
Issue / PR:
Base SHA / head SHA:
Files changed / files reviewed:
Change summary:
Tests executed (run URL + status):
Unverified scenarios and risks:
Suggested next action:
```

## Secure access
External agents on GitHub should use narrowly scoped, revocable GitHub App or fine-grained credentials limited to this public repo, not the private Cloud Core. Read access is sufficient for review; write access, if needed, should be limited to PR branches and Issues where GitHub permissions allow. Never paste access tokens into model chats. Turn off automatic merge and automatic production deployment.

## Release control
Publication of the public Native repository does not authorize disclosure of the private backend or changes to production. A green CI demonstrates only the tested contract and versions, not full end-to-end OAuth, billing, quotas or tenant isolation.
