# WP AI Admin — единый актуальный статус
Дата сверки: 2026-10-13. Источники: приватные GitHub main, открытые PR #33/#34/#35/#37, issues #20/#32/#36/#38, история проектных обсуждений.

> Этот документ различает **main**, **PR candidate**, **установленный production** и **локальный Work artifact**. Результат одного из уровней нельзя автоматически переносить на другой.

## 1. Канонические источники
- Private source: https://github.com/darcreid163/okean-ai-admin
- MAIN: Native **2.1.14**, Cloud Core **0.3.6** (README/AGENTS.md; не обновлено в ходе этого аудита).
- Integration: `codex/private-release-candidate-reconciliation`, draft PR #35: Native **2.1.15**, Cloud Core **0.3.7**.
- QA38: draft PR #37 поверх интеграции, Native 2.1.15 build `2.1.15-qa38-20261013`, source SHA `617ae304fb95ed0832c32a9d5f250fe2d8001c99`, ZIP SHA-256 `9e16f889d2b78f0f2b7156243c4f11fb6242e980a7c83c5aa25861e492274fb5`.
- Separate Work-produced **Cloud Core 0.3.8** cabinet is NOT in audited private repository as a verified source. Treat screenshots and file paths from another Work session as pointers, not verified build provenance; issue #38.
- Previously reported live environment `wpaiadmin.ru`: Native 2.1.15 pre-QA38, Core 0.3.7; **re-query live connection before deployment**. `okean.market` is the other connected WordPress site.
- Canonical central endpoint: https://wpaiadmin.ru/mcp . Native/local endpoint `/wp-json/okean-ai/mcp` is diagnostic/compatibility, not the primary user-facing gateway.

## 2. Tested versus unfinished
- PR #35 reports syntax, WordPress install/upgrade/reactivation and database/OAuth/MCP tests; explicit SKIPs: Chromium navigation, full runtime matrix, external ChatGPT/two-owner acceptance. Do not claim GitHub Actions green.
- PR #37 reports 159 PHP files linted with PHP 8.2/8.3/8.4; zero-ID regression 16/16; matrix **112 PASS / 0 FAIL / 4 SKIP**; self-test 77/77, fuzz 220/220 on clean install, deterministic ZIP builds, fixture and operation-ID hardening. **Not installed or live-accepted as QA38** per PR.
- Issue #36 records pre-QA38 live failures (10 PASS / 1 FAIL / 2 SKIP and 95 PASS / 13 FAIL / 8 SKIP) that need mapped retest evidence before closure.
- PR #37 also reports desktop/tablet/mobile browser QA on earlier production build and a missing H1 WARN on an old test draft: this is not a QA38 live sign-off.
- Cloud 0.3.8 cabinet from Work (account overview, connected sites/disconnect, history, plan, ChatGPT connection) has no independently retrieved source archive or runtime QA in this audit. **DO NOT MERGE OR DEPLOY BY VERSION NUMBER ALONE.**

## 3. Non-negotiable architecture
- WordPress native plugin on every connected website; Cloud Core central gateway/control plane on wpaiadmin.ru.
- Separate administrator site-pairing consent and AI-client OAuth site grant (PKCE, explicit selection, tenant isolation); up to 50 sites per account across plans in current design.
- Lifecycle: READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK, with stale-data, conflict, human approval and audit. `direct_live_writes=false`.
- No arbitrary modifications to business content in automated QA. QA mode uses separate draft/private pages, hidden Woo products, scoped fixture ownership/allowlists; no unapproved global WoodMart header writes.
- Never expose tokens, client secrets, verifier, credentials, exact callback URLs or server filesystem paths. Maintain compatibility contract and identity persistence across upgrades.
- Commercial tariffs by successful actions/shared quota, not tool feature access: preliminary Free 100, Pro 500, Power 2000, Agency 5000, Scale 10000 monthly calls; exact billing policy TBD. Batch provisional rule 1 action per five successfully processed objects; failures should not consume quota; overridable centrally. Paid checkout/activation NOT complete.
- Public app target: OpenAI Apps SDK/App Directory review, without requiring ChatGPT Business; custom MCP for QA. Separate future public MCP developer catalog/gateway is a different product and must not be mixed into Native release blockers.

## 4. Release order (stop gates)
1. Freeze and inventory HEAD SHAs of main, PR #35 and PR #37; save source/ZIP SHA and dependency versions. Record issue #38 artifact gap.
2. Import the **actual** Core 0.3.8 source from Work or source commit, compare with Core 0.3.7, check DB migrations, OAuth, tenant isolation, disconnect, and account UX. Do not fabricate missing ZIP.
3. Resolve PR dependency chain: QA38 (#37) based on qualified integration (#35); review no lost Native code, tests, and exact source-to-ZIP identity.
4. Run PHP lint (8.2–8.4), unit/integration tests, WordPress 7.0.6/7.1.3, MySQL/MariaDB, WP fresh install/upgrade/reactivation, Woo/Elementor/WoodMart adapters, security fuzz, deterministic packaging. If GitHub Actions constrained use isolated runner with saved logs and checksums.
5. Scoped live wpaiadmin.ru QA38 upgrade: full backup and verified restore, consented automation, test fixtures only, READ→SANDBOX→APPROVAL→APPLY→VERIFY→RECEIPT→ROLLBACK→verify, header only in isolated safe context. Check regression #36; follow QA disabled on completion.
6. Two distinct real-owner accounts, two sites, OAuth revoke/regrant and ChatGPT connector discovery/tool execution; test fail-closed quotas and grants.
7. Resolve legacy #20 security requirements by mapping each condition to *current version* regression; close only with evidence. Resolve #32 publication checklist. Complete privacy/terms, payment/billing disclosures and support.
8. After all gates pass, merge reviewed PRs into private main, build from exact main commit, publish immutable checksums and change log, only then deploy production.

## 5. GitHub hygiene
- Current open PRs observed 2026-10-13: #33 public release gates (draft); #34 safe public CI export; #35 integration; #37 QA38. Do not close/merge solely for tidiness.
- Open issues: #20 historical hardening, #32 public release gates, #36 live regression failures, #38 missing Cloud 0.3.8 provenance.
- Do not force-push main or destroy release tags, branches, audit logs, historical test evidence, or private code. Archive or delete stale branches **only** after HEAD-to-main comparison and positive evidence all unique work is merged or intentionally abandoned.
- Public CI repository must contain allowlisted test harness only, no private source/history/secrets.
- Single operational chat: **WP AI Admin — Release Control**. Other chats are reference archives; ChatGPT chat deletion is a user-managed action, not a safe automatic cleanup step. Preserve decision summaries here first.

## 6. Decision log / currently blocked
- CONFIRMED: commercial baseline main 2.1.14/0.3.6; candidate integration 2.1.15/0.3.7; QA38 documented tests and SKIPs.
- NOT YET VERIFIED: Core 0.3.8 source, merged main >= 2.1.15, QA38 production installation, GitHub Actions green, two-owner live final acceptance.
- NEXT ACTION: source acquisition and qualification of Core 0.3.8 plus verification of open release gates. No deletion/merge/deployment was part of this documentation-only audit.
