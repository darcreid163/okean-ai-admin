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
| Integration branch | `release/native-2.1.18-integration-20261118` | `432b1ece676f4520a2e0d2a7fdf55176c8d44115` |
| PR #232 | Draft, open, base `main` | Not merged |

Exact-head CI for integration SHA `432b1ece676f4520a2e0d2a7fdf55176c8d44115`:

- Native workflow [44253496274](https://github.com/darcreid163/okean-ai-admin/actions/runs/44253496274): PASS — PHP 8.2/8.3/8.4, all three WordPress smoke profiles, Native artifact build and package installation on WordPress 7.0.6/latest. Release job is skipped on PRs by design.
- Cloud workflow [44253496283](https://github.com/darcreid163/okean-ai-admin/actions/runs/44253496283): PASS — PHP 8.2/8.3/8.4, SQLite/MySQL contracts, browser contracts, WordPress 7.0.6/latest upgrade/restore and Cloud artifact.
- The file lifecycle suite verifies 3 MB atomic Apply/Verify/Rollback and interrupted Apply recovery in three cases: committed write, no write yet, and ambiguous external change.

CI proves the exact source and disposable test matrix only. It does not prove the installed production files, live Cloud configuration, external ChatGPT expiry behavior, or owner acceptance.

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

## 3. Work still outside PR #232

### P0 — integrate and qualify Cloud Store

- [PR #226](https://github.com/darcreid163/okean-ai-admin/pull/226): durable atomic SSE queue, owner-scoped indexes, SQLite index collision guard and MySQL table-prefix boundary.
- [PR #224](https://github.com/darcreid163/okean-ai-admin/pull/224): CLI-only Cloud key preservation/export utility, stacked on #226.

The Cloud Store queue race can lose replies under concurrent append/drain. This is a release blocker for a public Cloud service unless the transport is replaced or the risk is explicitly removed by a verified design. Integrate #226 with the current Cloud candidate, resolve the shared Cloud upgrade/restore tests, and run exact-head Native + Cloud CI. Then integrate or separately qualify #224 key recovery before any AUTH_KEY/AUTH_SALT rotation or Cloud restore procedure that depends on those keys.

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
- PR #226 and stacked PR #224 remain active separate Cloud work; do not close them.
- PR #197 (OpenCode configuration cleanup) is unrelated to release qualification; defer it.
- Issues #20's original 2.0.74 audit scope is closed as completed. Issues #36 and #38 are retained as historical QA/source-access records with current status notes. #92, #203, #214, #216, #218, #220 and #212 remain open for their outstanding acceptance or integration dependencies.
- The repository has many historical branches (the first API page alone contains 100). Do not mass-delete by age. Delete only branches whose PR is merged/closed, whose commits are reachable from the retained integration/main history, and whose unique work has been reviewed. Preserve unmerged historical evidence.
- Dated audit/readiness documents are historical snapshots, not live status. Use this file and #40 for current release state.

## 6. Safe release sequence

1. Integrate Cloud Store #226 and key preservation #224 into a reviewed release candidate.
2. Run exact-head Native + Cloud CI and resolve every failed or skipped required job.
3. Complete external OAuth expiry, two-owner tenant isolation and staging lifecycle/restore acceptance.
4. Freeze and inspect exact Native-only and Cloud artifacts; confirm checksums, provenance, licenses and secret scans.
5. Obtain owner approval for the selected release type: closed/free beta or self-service paid release.
6. Only after explicit approval: merge, tag, publish, split public Native/private Cloud repositories, and separately schedule deployment with backup and rollback.

Until those gates are complete, this project is a **green-CI release candidate**, not an approved production release.
