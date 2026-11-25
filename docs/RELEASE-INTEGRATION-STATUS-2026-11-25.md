# Release integration status — 25 November 2026

> Current status snapshot for the canonical WP AI Admin source repository. Historical notes are retained for audit, not as current instructions.

## Source-of-truth boundaries

- `darcreid163/okean-ai-admin` is the canonical Native + Cloud monorepo.
- Current `main` baseline: `32fa28ca2edb3e66dbdc2c24fde09fe36e49237f`.
- Current integration candidate: PR [#253](https://github.com/darcreid163/okean-ai-admin/pull/253), Native 2.1.18 + Cloud Core 0.3.13-dev.
- The public `wp-ai-admin` repository is a historical Native publication snapshot; it is not the development source.
- The private `wp-ai-admin-cloud-core` repository is a historical Cloud snapshot; it is not the development source.
- `WP-AI-ADMIN-TEST` is synthetic test-artifact storage only. `universal-mcp-platform`, `okean-market-flutter` and `BLASTORIA` are independent projects.
- Do not copy historical snapshots over the canonical monorepo or bulk-delete historical branches.

## Approved tariff contract

| Plan | Actions per UTC day | Monthly RUB | Annual RUB |
|---|---:|---:|---:|
| Free | 50 | 0 | — |
| Daily | 500 | 899 | 9,999 |
| Pro | 2,000 | 1,499 | 16,999 |
| PRO+ | 5,000 | 3,999 | 46,999 |
| PRO++ | 10,000 | 8,999 | 105,999 |

All tools remain available on every plan. Free is one site; PRO+ supports up to five sites; PRO++ supports up to 50 sites. Public prices and quotas must match Cloud policy, WooCommerce offers, Native display and licence activation.

The older Daily 800 RUB proposal in PR #237 is superseded. Issue #236 was closed as superseded; its history is retained. Current `wp-ai-admin-cloud/config/plans.json` on PR #253 matches the later owner-approved catalogue.

## Current consolidated integration

- PR: [#253](https://github.com/darcreid163/okean-ai-admin/pull/253).
- Base: `main` at `32fa28ca2edb3e66dbdc2c24fde09fe36e49237f`.
- Branch: `codex/robokassa-paid-entitlements-reconciled`.
- Exact HEAD: `cbbe8f79cb0a4a814272bc3664991ece89126605`.
- PR is OPEN, Draft and unmerged. GitHub reports mergeable; mergeable does not mean release-approved.
- Compare snapshot: 34 commits ahead, 0 behind; 38 changed files; 2,268 additions / 249 deletions.
- Latest commit updates release-status documentation and Robokassa issue #203 only; no Native/Cloud runtime source changed after tested code HEAD `8463e7258845824e4c3471c7220e46f7fe5419c8`.
- No history rewrite or force-push was performed.

Included:
- Robokassa payment settlement, idempotent payment ledger and Cloud entitlement issuance.
- Strict product/plan/SKU/price/currency/registered-customer validation.
- Classic ResultURL signature preflight and immutable Woo order amount comparison; browser SuccessURL/FailURL redirects do not grant a licence.
- Bounded retries for transient settlement failures; permanent policy failures are surfaced for operator review.
- Encrypted order-scoped one-time key reveal and separate encrypted email delivery to the registered account address.
- Full/partial refunds, cumulative/out-of-order refund handling, stale-order protection, read-only reconciliation and narrowly scoped operator-approved repair.
- WooCommerce HPOS compatibility.
- Daily UTC quota cutover, retired monthly-scheme migration and preservation of historical balances.
- Dedicated Robokassa lifecycle runner in Cloud SQLite and MySQL CI.
- Native/Cloud OAuth rejection of `offline_access` and refresh-token grants.
- WordPress.org-style `README.txt` and automated metadata/readme checks.
- Deterministic Native ZIP builder, source-bound provenance and exact-artifact installation checks.

## Exact latest-head automated qualification — PASS

All three workflows completed successfully on exact HEAD `cbbe8f79cb0a4a814272bc3664991ece89126605`:

- [Native validation #45588124972](https://github.com/darcreid163/okean-ai-admin/actions/runs/45588124972): **SUCCESS** — PHP 8.2/8.3/8.4; WordPress.org metadata/readme checks; pinned WordPress 7.0.6 + Elementor 4.3.3 + WooCommerce 11.1.2; latest compatibility; WooCommerce 11.3.0 HPOS; deterministic Native artifact; provenance/checksum verification; installation and boot of exact ZIP on WordPress 7.0.6 and latest.
- [Cloud Core validation #45588124838](https://github.com/darcreid163/okean-ai-admin/actions/runs/45588124838): **SUCCESS** — PHP 8.2/8.3/8.4 contracts, browser contracts, WordPress 7.0.6/latest upgrade checks and Cloud artifact.
- [Secret history scan #45588124858](https://github.com/darcreid163/okean-ai-admin/actions/runs/45588124858): **SUCCESS**.

Candidate artifacts built from this exact HEAD (GitHub Actions artifacts, not signed/public releases; expire 23 February 2027):
- Native `wp-ai-admin-2.1.18`, artifact ID `15010435702`, SHA-256 `16221931f2f94b0d2e84fe372cc53a99f356e8cf24f9c19743d9ef68489c4f4a`.
- Cloud `wp-ai-admin-cloud-0.3.13-dev-candidate`, artifact ID `15008848511`, SHA-256 `70cdffc0b6b2d52a554be054a90703e38661d490f2cc59e9905dc6844a51e6a9`.

The release job is skipped by design on pull requests. The Robokassa lifecycle runner uses WordPress/WooCommerce test doubles and disposable SQLite/MySQL stores. It is not an actual Robokassa gateway callback, checkout-browser or email-provider E2E.

## Remaining release gates

1. **Robokassa disposable-shop E2E:** local WooCommerce + HPOS + Robokassa Test Mode. Verify checkout, ResultURL callback, signature/amount rejection, duplicate/delayed notifications, order creation, key reveal/replay denial, registered email, Native activation, mail retry, full/partial refunds, approved repair and concurrency. Automated runner coverage does not replace this real gateway/browser/email-provider test.
2. **Two-owner security acceptance:** negative cross-owner/site access, forged site/tenant IDs, foreign grant/token, replay, revoke and quota non-charge.
3. **Real ChatGPT OAuth lifecycle:** access-token expiry, supported reconnect/renewal without `offline_access`, scopes, consent and revoke.
4. **Staging operation lifecycle:** backup, Read → Sandbox → Diff → Approval → Apply → Verify → Receipt/Proof → Rollback → Verify; then restore and verify identity, grants and quota.
5. **WordPress.org submission:** verify the actual contributor username, publish actual Cloud Terms and Privacy URLs, run the official readme validator, prepare the application and complete manual review.
6. **Final legal/product review:** IP ownership, third-party licenses, privacy/retention/subprocessors, refund policy, support/security contact, compatibility claims and final release freeze.
7. **Windows qualification:** file replacement and power-loss guarantees remain unqualified. Preserve fail-closed behavior and do not advertise Windows atomic-write/durability guarantees unless Windows is in the supported scope and its dedicated qualification passes.

## Safety and release disposition

- Public payments remain disabled; Cloud plans must continue reporting `payments_enabled=false` until all applicable acceptance gates pass.
- Use only disposable local/staging systems and Robokassa Test Mode. Never put merchant credentials, customer PII, licence keys or production tokens in chat, GitHub or CI logs.
- No merge, tag, public release, production deployment, real payment, account/tariff migration or customer-data mutation is authorized by this candidate.
- Do not close the payment workstream until actual Robokassa test-shop E2E evidence is recorded. Issue #203 tracks this gate and names Robokassa.
- Historical branches and independent repositories remain untouched unless individually reconciled.
