# Minimal public CI harness (draft)

The historical mirror `darcreid163/wp-ai-admin-release-ci` has been switched to PRIVATE. Do not republish it: its git history contains former source-tree copies.

## Target
- Primary source: private `darcreid163/okean-ai-admin` only.
- Build any future public harness as a **new empty repository**, with a new history.
- Public harness contains only generic workflows, independent protocol-contract tests, and synthetic fixtures. No WordPress plugin PHP sources, Cloud Core sources, compiled ZIPs, release archives, internal links, API keys, production configuration, or extracted database samples.
- Code-dependent test suites stay inside private CI or a private/self-hosted runner with appropriate controls.
- No unrestricted `contents: write` token, production secrets, or automatic push from test workflows.

## Export procedure
1. Manually assemble an empty staging directory containing only approved tests and generic CI workflows.
2. Run `python3 scripts/audit-public-ci-export.py /path/to/staging`. **Fail** on disallowed paths, files, text, symlinks or oversized fixtures.
3. Review every allowed file manually; this scanner is not proof of absence of secrets.
4. Create a new public repo and import **only** the verified staging directory, not an existing git clone or its history.
5. Keep private releases and hashes in the private source of truth, and test full artifacts in a private runner.

## Containment checks before republishing
- Review exposure during the prior public period (historical commits, branches, pull requests, Actions logs/artifacts, Releases).
- Revoke/rotate all real credentials that may have leaked, even if they were later removed.
- Assume clones/forks of publicly exposed history may persist. Never assert historical material is unexposed.
- Re-run tenant-isolation, token lifecycle, quota, install/upgrade, lifecycle and rollback gates privately before production acceptance.

Existing `wp-ai-admin-release-ci` stays private and is retained for inspection rather than sanitized with a cosmetic commit.
