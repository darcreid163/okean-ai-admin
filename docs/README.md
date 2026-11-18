# Documentation index

This directory contains both current operating documents and dated historical evidence. A dated audit is a snapshot of the state at its recorded date; it is not an instruction to use that old version or a statement of current status.

## Current release authority

1. [Release Control — 18 November 2026](RELEASE-CONTROL-2026-11-18.md) — candidate versions, exact-head CI, artifacts, open release gates and safe release sequence.
2. [ChatGPT connection contract addendum — 18 November 2026](CHATGPT-CONNECTION-CONTRACT-ADDENDUM-2026-11-18.md) — current Native 2.1.18 / Cloud Core 0.3.13-dev compatibility and OAuth/security invariants.
3. [ChatGPT token renewal qualification](CHATGPT-TOKEN-RENEWAL-QUALIFICATION.md) — owner-run live expiry/reconnect procedure; remains blocked until real external-client evidence is recorded.
4. [Repository security audit — 18 November 2026](REPOSITORY-SECURITY-AUDIT-2026-11-18.md) — current visibility, secret-scan scope/limitations and repository hygiene actions.
5. [Data flow](DATA-FLOW.md) and [data retention](DATA-RETENTION.md) — data boundaries, retention and uninstall behavior.
6. [Public release readiness](PUBLIC-RELEASE-READINESS.md) — release gate reference; use Release Control and issue #40 for the newest status.

## Canonical GitHub tracking

- [Issue #40](https://github.com/darcreid163/okean-ai-admin/issues/40) — canonical release tracker.
- [Issue #92](https://github.com/darcreid163/okean-ai-admin/issues/92) — ChatGPT token expiry/renewal qualification.
- [Issue #212](https://github.com/darcreid163/okean-ai-admin/issues/212) — release freeze checklist.
- [Issue #203](https://github.com/darcreid163/okean-ai-admin/issues/203) — paid checkout-to-license fulfillment.
- [Issue #233](https://github.com/darcreid163/okean-ai-admin/issues/233) — Windows file replacement and durability qualification.
- [PR #232](https://github.com/darcreid163/okean-ai-admin/pull/232) — current Draft integration candidate; not merged.

## Historical evidence and superseded plans

Files named with dates, old Native/Cloud version numbers, or historical audit/recovery labels are retained for traceability and incident review. This includes old capability snapshots, beta-readiness snapshots, recovery notes, old release plans, competitor audits, and QA investigations.

Do not delete historical evidence merely because it describes an old version. If a document is materially misleading, update it with a prominent historical/superseded notice and a link to the current authority. Do not duplicate current release status across multiple dated reports.

## Documentation maintenance rules

- Update Release Control and issue #40 when exact candidate head, CI, artifact, or release gate changes.
- Keep protocol/security invariants in the historical connection contract and versioned addenda; do not silently overwrite old contract history.
- Never store tokens, passwords, private keys, production database dumps, customer data, or unredacted OAuth callback data in documentation or issues.
- Candidate branch documents are not effective on `main` until PR #232 is reviewed and merged. PR #232 remains Draft pending owner acceptance.
