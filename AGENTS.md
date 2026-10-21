# Repository instructions

This is the public Native WordPress codebase, independent from the private hosted Gateway.

- Do not copy private Gateway code, private deployment settings, token/secret material or database snapshots into this repository.
- Do not implement direct production writes that bypass the existing human Approval Engine, immutable snapshots, Verify, Receipt and Rollback.
- Preserve native OAuth/pairing identities and backward compatible central endpoint contracts.
- Never merge untested Elementor mutation code or silently expose staged adapters to MCP.
- All features require PHP lint, focused isolated tests, and WordPress integration testing before release.
- Production installation, subscription changes, data deletion and irreversible operations require separate user authorization.

## Working with multiple coding agents

Follow `docs/AGENT-COLLABORATION.md`: one issue per task, separate feature branches, scoped files, independent PR review, test evidence by exact commit SHA, and maintainer-controlled merges. Never assume another agent is currently running or that a CI result remains valid after new commits.
