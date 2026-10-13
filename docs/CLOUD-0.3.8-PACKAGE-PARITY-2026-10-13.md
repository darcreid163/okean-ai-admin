# Cloud Core 0.3.8 — verified package parity (2026-10-13)

**Scope:** integration PR #43, source HEAD `ef4774720c59f9f118a108a7986b182555e82c1c` when verified; no production installation.

- Artifact: `wp-ai-admin-cloud-0.3.8-git-matched.zip`
- SHA-256: `c927b191d03f8f4f0c89491197fd47e3866c0c290bd048e422dbd01dab2c6d28`
- Package: 19 regular tracked files (plus directory entries), 17 PHP.
- PHP CLI syntax: 17/17 PASS.
- ZIP CRC PASS; no unsafe archive paths.
- **19/19 ZIP regular file Git blob SHA-1 values matched exact GitHub `fetch_file` blob SHAs on integration branch.**
- Previously mismatched `src/Surface.php`: corrected packaged Git blob SHA `e5d3772c70d16d092ce9c087fb581f7fb34807b2`.
- Original rebuilt ZIP SHA `fa4cafb72696328bf552822e9d0c2cc3d4926a2993119bc07e4c5d6a6a967fec` is **superseded** and must not be installed.
- Matched artifact retained in ChatGPT Library: `/WP AI Admin/Current candidates/wp-ai-admin-cloud-0.3.8-git-matched.zip`.

**Limits:** This closes the specific mismatch evidence in issue #42. It is not a full signed release or end-to-end deterministic builder qualification; WordPress install/upgrade, OAuth A/B, revocation, site lifecycle, and Native QA38 cross-version acceptance remain P0 before `main` merge or production deployment.
