# Current boundaries — temporary unified development, 2026-10-22

| Repository | Current access | Role |
|---|---|---|
| `darcreid163/okean-ai-admin` | PUBLIC | Owner-requested unified Native 2.1.17 / Cloud 0.3.13-dev development and free hosted CI |
| `darcreid163/wp-ai-admin` | PUBLIC | Native-only public release destination, recovery PR #21 |
| `darcreid163/wp-ai-admin-cloud-core` | PRIVATE | Final private Cloud-only release destination, recovery PR #12 |
| `darcreid163/universal-mcp-platform` | PRIVATE | Separate product, no release dependency |

This temporary public combined source **does disclose** Cloud Core logic. No secrets were intentionally copied; regex-based scanning is not a full credentials audit. Later visibility change cannot remove downloaded source or already public Git history.

Current Cloud candidate is `0.3.13-dev` under `wp-ai-admin-cloud`, derived from the SHA-pinned 0.3.11 recovery archive. The recovery hash gate now explicitly allows three changed historical paths (Core, Gateway, bootstrap), while all 17 other historical files remain SHA-identical. Added candidate modules are qualified separately. Historic duplicate pilots 0.1.0, 0.3.6 and committed ZIPs are removed in the branch working tree, not purged from Git history.

**Build/export fences:** separate PHP/WordPress/Gateway CI; only Native files in Native ZIP; Cloud-to-private export only after staging passes; no auto-deploy/no automatic payment. No release or branch cleanup without checking unique work. Secrets and production data must not enter public Actions logs.
