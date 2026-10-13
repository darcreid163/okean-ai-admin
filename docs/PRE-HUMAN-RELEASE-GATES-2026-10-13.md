# WP AI Admin — live pre-human qualification (2026-10-13)

## Installed and verified
- wpaiadmin.ru: Native 2.1.15 build `2.1.15-qa38-20261013`; Cloud Core 0.3.8; WP 7.1.3 / PHP 8.4.24.
- Native self-test 79/79, fuzz 220/220, regression matrix 112 PASS / 0 FAIL / 4 SKIP, baseline restored; reversible regression 16/16 PASS, baseline restored. Another independent historical QA38 run 17/17 PASS.
- Managed fixtures: Elementor draft 162, Gutenberg 31, Woo hidden 165/166. Static Page QA #162 PASS (H1=1; no missing alt, link, ID or controls warnings). Browser viewport check still pending.
- Cloud tool catalog 116; historical coverage report clean 103. Not equivalent to end-to-end all tool coverage.
- Free quota 57/100 used, reserved 0, remaining 43; unchanged across read-only tools. No failed-call billing result verified.
- Dry-run-only ledger cleanup: 83 eligible completed, two **protected prior pending operations** #39 (content post 2) and #40 (Elementor page 75). Do not apply or delete without separate review.
- Woodmart 8.6.2 / MCP 1.0.1 adapter: sandbox-only base settings; Header Builder not qualified.
- Native ZIP SHA256 `9e16f889d2b78f0f2b7156243c4f11fb6242e980a7c83c5aa25861e492274fb5`; Cloud ZIP SHA256 `c927b191d03f8f4f0c89491197fd47e3866c0c290bd048e422dbd01dab2c6d28`. ZIP CRC valid, 83 PHP files lint on PHP 8.4.24: 83/83 PASS.
- GitHub Actions for PR #43 branch: three workflow runs **failure**; job step lists empty, logs BlobNotFound. **Do not mark CI green or infer actual test failure.**

## Evening mandatory P0 acceptance — two independent real owners

1. Back up both databases/plugins and record installed build IDs. Two isolated owners A/B, separate browser profiles and disposable sites.
2. Owner A registers Site A, B registers Site B. Listings, tool calls, receipts, operations and quota must be tenant-scoped; wrong site_id denied **by server**.
3. Fresh OAuth/PKCE S256 for each in external ChatGPT; exact redirect URI, state, single-use code; denial and replay tests.
4. Grant read to Site A only: authorized read succeeds, Site B/read extra sites and write scope fail; adding another site must not expand old grant.
5. Pairing consent and AI consent independent. Cancellation, revoke, refresh rotation and previous refresh-token reuse denied; site disconnection invalidates outstanding grants, does not touch other owner.
6. Billing: record quota before/after. Successful new call exactly once; identical-key retry no extra billing; differing-payload same key denied; failure/authorization-denied/verify/receipt/rollback free; reserved reconciles to zero.
7. On scoped draft only: sandbox -> approval -> apply -> verify -> receipt/proof -> rollback -> snapshot equality. Explicit revision conflict and concurrency checks.
8. Test upgrade persistence (site/installation IDs, OAuth and pending operation evidence) and autonomy reset on new build.
9. Four SKIPs: browser visual, media URL import, production menu location and Woodmart header. Close or explicitly exclude from public scope.
10. Release condition: **zero tenant isolation breaches**, zero unauthorized writes, no lost evidence, exact Git-to-ZIP provenance, reproducible regression.

The full operator checklist is also stored in ChatGPT Library: `/WP AI Admin/QA evidence/WP_AI_Admin_Pre_Human_Release_Gates_2026-10-13.md`.

## Decision
NOT public release ready. Do not merge PR #43 or deploy further unqualified changes until above P0 gates close.
