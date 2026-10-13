# WP AI Admin Cloud Core 0.3.8 rebuilt — qualification

Date: 2026-10-13

## Origin and controls
- Rebuilt from retained Cloud Core 0.3.7 QA37 package, not recovered from Work.
- GitHub review branch: `codex/cloud-account-0.3.8-rebuilt`, PR #41; `main` is not advanced to 0.3.8.
- Package: `wp-ai-admin-cloud-0.3.8-rebuilt.zip`.
- SHA-256: `fa4cafb72696328bf552822e9d0c2cc3d4926a2993119bc07e4c5d6a6a967fec`.

## Tests completed on rebuilt ZIP
- PHP syntax: 17/17 PHP files PASS (installed PHP CLI).
- ZIP CRC: PASS, no corrupt members.
- Path inspection: no absolute paths or `..` path traversals.
- Static disconnect review: owner-scoped `Core::disconnect`, owner-scoped `ownedSite`, nonce checks, site disconnect audit and sealed token revocation logic present.
- Candidate README aligned with GitHub branch.

## Pending release gates
- Full GitHub source-vs-ZIP byte parity for every file; integrated WordPress runtime tests.
- Actual tenant separation with two users, OAuth grant/revoke, browser navigation and site pairing.
- Database upgrade/recovery and tenant scoped disconnect acceptance.
- QA38 Native cross-compatibility and UI verification.
- Install, backup/restore drill and external ChatGPT acceptance.
- PR #35 / #37 / #41 currently should not be merged indiscriminately into main.

This report is evidence for static qualification, **not production readiness**.
