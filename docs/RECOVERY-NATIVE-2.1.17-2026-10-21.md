# Native 2.1.17 — recovery provenance and release gates (2026-10-21)

**Status: recovered source with reviewed follow-up reconciliation; not a production release.**

## Archived source and proof

- Archived ZIP: `wp-ai-admin-2.1.17.zip`; archived ZIP SHA-256: `4bf534f3a30344fa94cf599d526477887ed89175e494d32b6b35e5a05f1cc1ac`.
- ZIP contains **86** paths beneath the `okean-ai-admin/` directory.
- Git **exact archive-source snapshot**: `5b34f332a8debba048061a8496c95ee722bc3452` (GitHub recovery branch). All **86 of 86** archived file bytes were rechecked against their Git blob SHA-1: **0 mismatches, 0 missing**.
- `okean-ai-admin.php`: version 2.1.17, `OAI_DB_VERSION=24`, build `2.1.17-usage-batch-v1-20261019`. The logged live Native manifest on `wpaiadmin.ru` reports this version/build, but the server's **actual file checksums have not been supplied**. Matching version/build is not byte-identity proof.
- The recovered branch retains **47** historical main-only docs, tests, CI and client/QA paths outside the ZIP. This is intentional **source/CI history**, not part of the 86-file archived release. Do not assume a Git checkout ZIP is the package, and do not delete historical QA without replacing the coverage.

## Deliberate post-recovery changes

Changes were made **after** the exact immutable recovery commit and require independent CI:

1. Add the existing repository's GPL-2.0-or-later license to the WordPress plugin header so the public publication gate passes; the archived header lacked it. This is a metadata change, NOT evidence the installed binary contains it.
2. Preserve three read-only CLI components from more recent public `main`: `includes/class-oai-command-normalizer.php`, `includes/class-oai-command-registry.php` and `includes/class-oai-wp-cli-execution.php`. The original 2.1.17 archive had only the older smaller command catalog, while public `main` CI requires additional bounded/read-only commands. No write/approval bypass is intended; regression tests must confirm it.
3. Public `main` includes a staged, **unregistered** `includes/class-oai-elementor-schema-save.php` absent from the archived ZIP. Existing QA tests require this file. It must not be counted as part of original production 2.1.17 and must be omitted from release packaging until approved separately. Unregistered full-document experimental functionality remains disabled and is NOT included in this recovery as a public MCP write.
4. README and build provenance are historical; no promise that installed Cloud Core equals Git `main`. Cloud Core recovered separately from archived 0.3.11 ZIP in the **private** repository.

## Required acceptance before merging into public main / deployment

- PHP 8.2/8.3/8.4 security + GPL boundary + CLI/OAuth/approval regression gates green.
- WordPress/MySQL smoke plus **schema 23→24** upgrade and preserved site/installation/MCP identity; rollback smoke on disposable staging, never on customer live sites.
- Exact **installable package manifest** excludes old QA/source-only `includes` and unsupported experimental editor writes, preserves 2.1.17 code and already-qualified read-only WP-CLI commands.
- Verify source of installed production 2.1.17 against trusted artifact/fingerprint and later (when possible) server checksums; archive + matching build ID alone isn't proof.
- Coordinate private Cloud 0.3.11 gateway **schema 3→4**, accounts' `legacy_monthly_v1` preservation, OAuth scopes/refesh/revocation, and daily quota assignment.
- Avoid destructive deletion of branches/history for "cleanup"; close/supersede obsolete PRs once unique test coverage is imported and release qualified. No production update or tag without owner approval.

CI: [Native recovery Draft PR #21](https://github.com/darcreid163/wp-ai-admin/pull/21). Cloud counterpart: private Draft PR #12. Independent historical QA remains available in Git history.
