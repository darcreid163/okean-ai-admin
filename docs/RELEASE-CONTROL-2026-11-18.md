# Release control — 18 November 2026

**Canonical release tracker:** [GitHub issue #40](https://github.com/darcreid163/okean-ai-admin/issues/40)  
**Release freeze checklist:** [issue #212](https://github.com/darcreid163/okean-ai-admin/issues/212)  
**Integration PR:** [#232 — Native 2.1.18 / DB25](https://github.com/darcreid163/okean-ai-admin/pull/232)

## 1. Authoritative source state

| Component | Current candidate | State |
|---|---|---|
| Native plugin | 2.1.18, DB schema 25, build `2.1.18-native-rate-limit-hpos-v1-20261117` | Candidate in PR #232 |
| Cloud Core | 0.3.13-dev, Gateway Store schema 4 (recovered 0.3.11 baseline) | Candidate source; not a production release |
| Main | `1e459dc6fd6ede2db6e15145c6aa66c9b4d81f5a` | Unchanged |
| Integration branch | `release/native-2.1.18-integration-20261118` | Native 2.1.18 + Cloud Store/key-preservation merge candidate; see current PR #232 head |
| PR #232 | Draft, open, base `main` | Not merged |

Exact combined integration SHA `fea7f8d2e4f2517fa0c5221b13df0a80f0e83162` passed:

- Native workflow [44257146243](https://github.com/darcreid163/okean-ai-admin/actions/runs/44255676940): PASS — PHP 8.2/8.3/8.4, all three WordPress smoke profiles, Native artifact build and package installation on WordPress 7.0.6/latest. Release job is skipped on PRs by design.
- Cloud workflow [44257146236](https://github.com/darcreid163/okean-ai-admin/actions/runs/44255676930): PASS — PHP 8.2/8.3/8.4, SQLite/MySQL contracts including SSE queue/index and stream-close races, browser contracts, WordPress 7.0.6/latest upgrade/restore and Cloud artifact.
- The file lifecycle suite verifies 3 MB atomic Apply/Verify/Rollback and interrupted Apply recovery in three cases: committed write, no write yet, and ambiguous external change.
- Cloud upgrade/restore includes SSE schema coexistence and exact vault/signing key preservation/export checks.

This exact combined tree passed Native and Cloud CI. Stream close now retains pending reservations and undrained replies while revoking transient auth context. Legacy SSE still has no client acknowledgement/replay after socket disconnect and remains best-effort; use Streamable HTTP when replay is required. CI does not prove installed production files, live Cloud configuration, external ChatGPT expiry behavior, or owner acceptance. Documentation commits after this code SHA require their own exact-head CI.

## 2. Integrated in PR #232

- Native 2.1.18 / DB25 atomic persistent rate-limit admission and WooCommerce HPOS declaration/acceptance.
- Uninstall data preservation by default, with explicit opt-in cleanup policy.
- OAuth client registry schema v2, bounded registration and safe expiry retention.
- REST Bearer hash-at-rest migration; plaintext legacy options are removed only after verified hash persistence.
- Strict media-creation single-winner claim.
- Read-only WordPress Abilities discovery/schema compatibility. Arbitrary Ability execution remains disabled.
- File path/symlink/snapshot hardening; public reads capped at 2 MB, internal Apply/Verify supports the 5 MB write limit.
- Staged same-directory file replacement with complete write/hash verification and rename; no delete-before-rename or copy/unlink fallback.
- Editor/content snapshot conflict guards.
- File lifecycle and interrupted-Apply recovery regression coverage.

The staged rename strategy intentionally fails closed if the platform cannot replace an existing file. Windows replacement semantics and power-loss durability have not been qualified; do not claim universal atomic replacement across operating systems.

## 3. Remaining release gates

### Cloud Store and key preservation — integrated and CI-qualified

The selected changes from PR #226 and stacked PR #224 are present in PR #232. Source file blobs were compared directly; the integration branch contains the full implementation plus superset validation workflow and upgrade/restore assertions. No duplicate cherry-pick is required. The source PRs are superseded, not merged, and their branch history is retained.

Included: bounded durable SSE queue reservations before Gateway execution, transactional completion/cancellation and atomic drain; owner-scoped Store indexes; SQLite collision guards; MySQL prefix/table limits; additive schema-v4 migration; CLI-only exact-key export to a private 0600 file outside the web root; and upgrade/restore key identity tests.

Key export preserves the currently effective vault/signing keys. It does not rotate either key. Actual key rotation remains out of scope and requires a separate migration/trust-rollover procedure.

### P0 — external/manual acceptance

- [Issue #92](https://github.com/darcreid163/okean-ai-admin/issues/92): observe real ChatGPT access-token expiry and confirm automatic renewal or a clear, safe reconnect path. No token values in evidence.
- Two independent account owners: prove negative cross-tenant isolation, selected-site enforcement, revocation and replay denial.
- Clean external ChatGPT/MCP client: consent, PKCE, site selection, tool catalog and revoke/reconnect.
- Disposable production-like staging: one full Read → Sandbox → Approval → Apply → Verify → Receipt/Proof → Rollback → Verify cycle; clean install, upgrade, paired backup/restore and post-restore identity check.
- Final artifact freeze: exact source commit, Native-only ZIP, separate Cloud package, checksums/provenance, dependency/license notices, secret scan and install test.
- Legal/privacy/terms, retention, support and vulnerability-reporting process for the actual release geography and product model.

No live site was accessed for this review. The last recorded live Cloud plan observation is dated 16 November and must not be treated as current.

## 4. Commercial scope — decide what is being released

The approved quota catalogue is five daily UTC tiers: Free 50, Daily 500, Pro 2,000, PRO+ 5,000, PRO++ 10,000 actions. Daily price remains unset; monthly prices remain as separately approved. Existing legacy account terms must not be silently migrated or repriced. Cloud Gateway Ledger remains the charging authority; successful Batch objects are counted individually. Automatic money debits and auto-renewal remain disabled.

- [Issue #203](https://github.com/darcreid163/okean-ai-admin/issues/203): YooKassa checkout and paid-order-to-license fulfillment are not implemented/qualified. This blocks a self-service paid launch. A closed/free beta can proceed only with explicit manual license provisioning and no claim of automatic payment fulfillment.
- [Issues #168/#169](https://github.com/darcreid163/okean-ai-admin/issues/168): WooCommerce shipping, tax and full store-settings surfaces remain incomplete. They are post-beta scope unless the product is marketed as a complete WooCommerce administration suite.
- [Issue #200](https://github.com/darcreid163/okean-ai-admin/issues/200): first-party Application Password provisioning/label is not yet a Native feature; current Native uses credentials created by the external connection flow. Defer unless the release explicitly promises first-party credential creation.
- Arbitrary Ability execution, broad Skills expansion, Divi/SeedProd and speculative builder adapters stay out of scope.

## 5. Repository housekeeping decisions

- PR #207, #213, #222, #223 and #225 are source branches whose selected work is now integrated into PR #232. Keep their history; close them as **superseded, not merged** only after this exact-head integration remains green and their final diffs are confirmed represented.
- PR #226 and stacked PR #224 are closed as superseded, not merged; their selected changes are integrated in PR #232, and their branches/history are retained.
- PR #197 (OpenCode configuration cleanup) is unrelated to release qualification; defer it.
- Issues #20's original 2.0.74 audit scope is closed as completed. Issues #36 and #38 are retained as historical QA/source-access records with current status notes. #92, #203, #218, #220 and #212 remain open for their outstanding acceptance dependencies. Issues #214/#216 are closed as completed by the integrated Cloud Store work.
- The repository has many historical branches (the first API page alone contains 100). Do not mass-delete by age. Delete only branches whose PR is merged/closed, whose commits are reachable from the retained integration/main history, and whose unique work has been reviewed. Preserve unmerged historical evidence.
- Dated audit/readiness documents are historical snapshots, not live status. Use this file and #40 for current release state.

## 6. Safe release sequence

1. Complete external OAuth expiry, two-owner tenant isolation and staging lifecycle/restore acceptance.
2. Freeze and inspect exact Native-only and Cloud artifacts; confirm checksums, provenance, licenses and secret scans.
3. Obtain owner approval for the selected release type: closed/free beta or self-service paid release.
4. Only after explicit approval: merge, tag, publish, split public Native/private Cloud repositories, and separately schedule deployment with backup and rollback.

Until those gates are complete, this project is a **green-CI release candidate**, not an approved production release.

## 7. Platform qualification follow-up — issue #233

The current same-directory staging + rename implementation has only been qualified by the Linux-based CI environment. It deliberately fails closed when replacement rename is unsupported and does not use a delete-old-file or copy/unlink fallback. Do not describe this as universally atomic across platforms.

Before claiming Windows support or power-loss durability, run a dedicated disposable platform matrix:
- Windows PHP 8.2/8.3/8.4: replace an existing regular file, preserve the original on every injected pre-commit failure, verify mode/ownership expectations where supported, and reject symlink/reparse-point escapes.
- Linux and Windows: simulate short writes, failed flush, rename failure, target replacement races, stale snapshots, and process interruption at each staging boundary.
- After every rejected/ambiguous Apply, verify the original target hash and the operation ledger state; never clean up an artifact whose ownership is uncertain.
- Record filesystem, PHP build, OS version and exact source SHA with the result. Until the matrix passes, keep Windows replacement semantics and power-loss durability explicitly unsupported/unqualified.

Issue #233 is the canonical owner of this follow-up. This work is a release qualification requirement only if Windows is included in the supported deployment promise; otherwise retain the documented fail-closed limitation and defer broader platform guarantees.
