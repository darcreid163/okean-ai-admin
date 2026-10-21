# Repository boundaries — updated 2026-10-21

1. **`darcreid163/okean-ai-admin` — PUBLIC.** Current development coordination, Native 2.1.17 integration and open GitHub-hosted CI. No private Cloud 0.3.11 runtime, live SQL, tokens, secrets, private customer information or unpublished entitlement code.
2. **`darcreid163/wp-ai-admin` — PUBLIC.** Final distribution of validated Native WordPress plugin only. PR #21 is current Native recovery candidate; its full-document Elementor experiment stays unregistered.
3. **`darcreid163/wp-ai-admin-cloud-core` — PRIVATE.** Authoritative Cloud Core 0.3.11, OAuth, tenant/ledger, billing, Gateway schema 4. Its isolated CI/staging remains mandatory; do not misrepresent public Native CI as private Cloud validation.
4. **`darcreid163/universal-mcp-platform` — PRIVATE.** Independent future platform; not part of WP AI Admin release gates.

Historical monorepo `main` combined Native 2.1.14, Cloud Core 0.3.6 and old pilot 0.1.0. Duplicate Cloud directories and ZIPs were removed only from the integration tree and remain in public history. A future change to private will not erase public forks/clones/snapshots.

Single current status document: `docs/RELEASE-STATE-2026-10-21.md`. Preserve unmerged older PRs as audit evidence; do not delete unique fixes, overwrite production or force-push historical `main`.

CI must use synthetic fixtures, minimal permissions, no long-lived secrets on PRs, no privileged `pull_request_target` for untrusted code. Release ZIPs belong in ephemeral Actions artifacts and approved GitHub Releases, not commits. Production update and tariff activation are separately approved.
