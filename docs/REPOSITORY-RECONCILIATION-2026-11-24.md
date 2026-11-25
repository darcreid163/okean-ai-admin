# Repository reconciliation — 2026-11-24

## Update — 25 November 2026

- PR #251 (strict OAuth no-refresh policy) was squash-merged into the lifecycle branch on 2026-11-25 as `a7e1ffc9feb1f6bf3e3ae39233e395035b912075`. Its exact-head Native and Cloud workflows passed. The policy now rejects `offline_access`, issues no refresh tokens, and rejects `refresh_token` grants; users re-authorize after access-token expiry.
- PR #232's integration branch was synchronized with the five canonical repository-status files from main (#246), resolving the previous mergeability conflict. The branch now reports mergeable; its source-code head remains the Native 2.1.18 integration line and still needs a final exact-head check after the docs synchronization.
- PR #235 now contains the lifecycle work, OAuth merge and updated OAuth contract documents. It must be reconciled with the updated #232 base branch and revalidated before merge.
- PR #249's docs-only validation failed on main's old Native media-creation admission path: the latest WordPress/Imagick smoke observed a single winning operation but the expected five duplicate denials were not all returned. The #232 Native 2.1.18 integration branch contains a strict first-writer SQL reservation for media creation and its latest WordPress/Imagick smoke passed. Treat the #249 failure as evidence that main is stale, not proof that the fix is missing from #232. Re-run after integration; do not weaken the concurrency assertion.

## Executive status

**Canonical development repository:** `darcreid163/okean-ai-admin` (PRIVATE).

**No single release-ready integrated commit exists yet.** The default `main` branch is not the same source as the installed site and does not yet contain the open Native 2.1.18, lifecycle, Robokassa and one-time licence-delivery work. Do not describe any current branch as a production release until the dependency stack is integrated and both CI suites pass on the exact final SHA.

This document is the current repository/status authority. Older README, migration and audit statements dated before 2026-11-24 are historical unless explicitly reaffirmed here.

## Repository roles

| Repository | Visibility / observed state | Canonical role | Policy |
|---|---|---|---|
| `darcreid163/okean-ai-admin` | Private; main has Native 2.1.17 / DB 24 and Cloud Core 0.3.13-dev | **Only active development source** for Native + Cloud integration | All development PRs target this repository until release qualification |
| `darcreid163/wp-ai-admin` | Public; README says Native 2.1.16 | Historical Native-only publication snapshot | Do not use as development source or copy changes from it without an explicit diff/reconciliation |
| `darcreid163/wp-ai-admin-cloud-core` | Private; README describes Cloud Core 0.3.6 | Historical Cloud-only snapshot | Do not use as development source; export a reviewed Cloud release from the canonical monorepo after qualification |
| `darcreid163/universal-mcp-platform` | Private; Sprint 01 scaffold | Separate future product | Keep separate; no source sync or release dependency with WP AI Admin |
| `darcreid163/WP-AI-ADMIN-TEST` | Private; Robokassa test-kit artifacts | Disposable/test evidence only | Never treat as production source; no credentials or production data |

The Native and Cloud split repositories are not synchronized with the current monorepo. They are retained as historical/publication destinations; their old code must not overwrite newer monorepo work. No repository, branch or history is deleted as part of this reconciliation.

## Runtime facts

### Installed site — read-only diagnostics, 2026-11-24

- WP AI Admin Native: **2.1.18**
- WP AI Admin Cloud Core: **0.3.13-dev**
- WordPress: 7.1.5
- PHP: 8.5.13
- WooCommerce: 11.3.1
- Elementor: 4.4.1
- Site diagnostics: database, filesystem, REST, WooCommerce and Elementor reported OK.
- Installed file hashes were not compared with repository artifacts. Runtime diagnostics do not prove source parity or absence of vulnerabilities.

### Canonical monorepo branches

- `main` currently contains Native **2.1.17** (DB schema 24) and Cloud Core **0.3.13-dev**.
- PR **#232** proposes Native **2.1.18** integration (source head `6083d6d0907d4aefa3c8c89812e0a875b671562d`, followed by docs-only synchronization commits); its PHP 8.2/8.3/8.4, WordPress smoke, package install, Cloud upgrade/contracts and Gitleaks checks passed on the original source head. The branch is now mergeable against main after syncing repository-authority docs; rerun exact-head CI before merge.
- PR **#235** adds lifecycle approval propagation on top of #232. Its branch now also contains the merged strict OAuth change from PR #251 and updated OAuth documentation. PR #251 exact-head Native/Cloud CI passed; PR #235 must be rechecked against the updated #232 base before merge.
- PRs **#238 → #239 → #240 → #241 → #242 → #243 → #244 → #245** form a dependent Robokassa/licence-delivery stack. Do not merge them out of order or directly into main without first integrating the Native/lifecycle line and re-running exact-head CI.
- PRs #243, #244 and #245 had successful observed CI checks on their respective heads. This does not qualify the combined Native + Cloud result.
- PR #249's docs-only run against main failed the latest-Imagick WordPress smoke at the concurrent media-creation fixture. The main branch still uses `add_option()` for media-claim admission; the #232 integration branch uses strict first-writer SQL admission and passed its latest-Imagick smoke. Preserve the six-process single-winner regression; confirm it again on the integrated release SHA.
- PR **#237** (Daily 800 tariff scheme) is not an approved integration candidate: its observed Cloud MySQL `billing-proof` failed because a parallel quota-limit race charged two concurrent calls where exactly one was expected; the WordPress upgrade smoke also failed with “Database table set changed”. Do not merge it as-is.
- The latest PR #245 head is not the Native 2.1.18 line: its Native bootstrap remains 2.1.17. It therefore cannot be treated as the complete site-matching release candidate.

## Decisions and blockers

### 1. One tariff authority

The open PR #245 branch contains `wp-ai-admin-cloud/config/plans.json` version `tariffs-v5-20261123` with daily quotas Free 50, Daily 500, Pro 2,000, PRO+ 5,000 and PRO++ 10,000. It also contains product-price data (Daily 899 RUB/month; Pro 1,499; PRO+ 3,999; PRO++ 8,999) and legacy scheme data.

PR #237 proposes a separate Daily 800 line and currently fails CI. Historical documents contain other quota/price values. Before any merge or live tariff migration, publish one owner-approved contract covering plan slugs, displayed names, quota per UTC day, RUB prices, billing period, site limits, product IDs/SKUs and migration policy. Cloud Core must remain the sole quota/licence authority. Never infer approval from a branch or WooCommerce product alone.

### 2. OAuth policy — decision implemented, live client gate remains

The owner approved strict no-refresh OAuth on 2026-11-24. PR #251 is merged into the lifecycle branch: Native and Cloud Core reject `offline_access`, issue no refresh tokens, and reject every `refresh_token` grant. Authorization Code + PKCE is the only supported grant. Users must re-authorize after the 3600-second access token expires. Exact-head Native and Cloud CI passed on PR #251; real external ChatGPT expiry/re-authorization and two-account acceptance remain owner-run release gates. Historical refresh-token wording is superseded by `docs/CHATGPT-CONNECTION-CONTRACT-ADDENDUM-2026-11-18.md` §3.

### 3. PHP file writes

The current Native file writer accepts PHP files if a regex-based dangerous-function filter and PHP token parse pass. This is not a reliable sandbox for arbitrary PHP execution. The release gate should reject PHP and other server-executable file extensions in AI file-write operations, or replace this with a narrowly defined non-executable allowlist and tests. Existing path containment, symlink, lock, atomic-write and hash-conflict protections should be preserved.

### 4. Security CI

The `secret-history-scan.yml` workflow with Gitleaks exists on the PR #232 integration branch but is absent from the observed `main` workflow directory. The successful Gitleaks run on #232 is useful evidence, not proof that the default branch is protected. Promote the workflow to the canonical main line after reviewing permissions, fork behavior, full-ref coverage and redacted output. Add PHP SAST/dependency analysis separately; do not claim DAST has run unless it has.

## Required integration order

1. Keep `main` unchanged while reconciling the source; do not delete or force-update unfinished branches.
2. Review and qualify PR #232 (Native 2.1.18 + Cloud source baseline) and PR #235 (lifecycle propagation) as one Native/lifecycle line.
3. Recheck the PHP file-write policy on the integrated source. OAuth strict no-refresh policy is implemented; retain the live external-client expiry/re-authorization gate.
4. Rebase or reconstruct the Robokassa/licence chain #238–#245 on the qualified Native/lifecycle line; run the full Robokassa HPOS end-to-end matrix. Keep the main-branch media-creation failure visible until the integrated #232 code is on main and passes the same smoke.
5. Resolve the tariff contract once, retire the conflicting #237 proposal or replace it with the approved policy, and test SQLite + MySQL migration/ledger invariants.
6. Run the complete Native + Cloud CI, secret-history scan, security tests, artifact build/install tests and disposable WordPress/WooCommerce/HPOS end-to-end matrix on one exact integration SHA.
7. Compare built artifact hashes with installed site files on staging. Run real Robokassa test-mode checkout and verified Result URL callback; verify one-time licence reveal, email retry, refund/revocation and replay handling.
8. Only after acceptance, export Native-only code to the public Native repository and Cloud-only code to the private Cloud repository. Keep the monorepo as the development source until both exports are verified.

## Guardrails

- No production deployment, payment mutation, tariff migration, licence issuance/revocation or customer-data operation from this reconciliation.
- No branch deletion, PR closure, repository deletion, visibility change or force-push.
- No merge based only on an earlier green run; checks must match the exact final head SHA.
- Never copy secrets, payment credentials, customer data or production database snapshots into GitHub.
- A private visibility setting does not retract any content previously published from a public repository.
