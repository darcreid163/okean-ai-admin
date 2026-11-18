# Repository boundaries — reviewed 2026-11-18

| Repository | Current access | Role |
|---|---|---|
| `darcreid163/okean-ai-admin` | PUBLIC | Unified Native 2.1.18 / Cloud 0.3.13-dev candidate, hosted CI; public source exposure is intentional and must not contain secrets |
| `darcreid163/wp-ai-admin` | PUBLIC | Native-only public release destination, recovery PR #21 |
| `darcreid163/wp-ai-admin-cloud-core` | PRIVATE | Final private Cloud-only release destination, recovery PR #12 |
| `darcreid163/universal-mcp-platform` | PRIVATE | Separate product, no release dependency |

This public combined source **does disclose** Cloud Core logic and implementation details. The repository has not been proven secret-free by a complete history/artifact scan. Pattern searches on the indexed default-branch code found no matches for selected common secret markers, but that is not a clean bill of health. Any suspected credential must be revoked/rotated first. Later visibility changes cannot retract clones, public forks, cached commit views, PR references or downloaded artifacts.

Current Cloud candidate is `0.3.13-dev` under `wp-ai-admin-cloud`, derived from the SHA-pinned 0.3.11 recovery archive. The recovery hash gate now explicitly allows three changed historical paths (Core, Gateway, bootstrap), while all 17 other historical files remain SHA-identical. Added candidate modules are qualified separately. Historic duplicate pilots 0.1.0, 0.3.6 and committed ZIPs are removed in the branch working tree, not purged from Git history.

**Build/export fences:** separate PHP/WordPress/Gateway CI; only Native files in Native ZIP; Cloud-to-private export only after staging passes; no auto-deploy/no automatic payment. No release or branch cleanup without checking unique work. Secrets and production data must not enter public Actions logs. Historical branches are retained until each branch's unique commits, associated PR status and release evidence are reviewed; do not mass-delete by age.
