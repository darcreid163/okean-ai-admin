# WP AI Admin temporary monorepo agent rules

- This repository is PUBLIC and temporarily contains a reviewed copy of Native 2.1.17 and Cloud Core 0.3.13-dev **source** (recovered 0.3.11 baseline). This is an explicit owner-driven CI arrangement, not a security boundary. Never publish real access tokens, OAuth secret keys, server key material, payment credentials, customer information, private WordPress backups or database exports.
- GitHub PRs must keep `permissions: contents: read` by default, use no privileged `pull_request_target` for untrusted code, and use synthetic database fixtures.
- Runtime separation: `okean-ai-admin.php` + `includes/` = Native; `wp-ai-admin-cloud/` = exactly one Cloud 0.3.13-dev candidate derived from recovered 0.3.11. Old pilot `wp-ai-cloud/` and old ZIPs must not reappear in the current tree.
- Native release archive must contain only Native files. Cloud production distribution must be exported separately and ultimately maintained in PRIVATE `wp-ai-admin-cloud-core`.
- Never loosen Approval Engine, mutation conflict guards, Verify, signed Receipt/Proof, Rollback or OAuth site/owner isolation solely to make CI green. No production data mutation in tests.
- Gateway Ledger alone computes quotas; per-successful-object Batch, atomic reservation/idempotency and uncertain outcomes are P0.
- One issue/branch per change, exact-HEAD CI logs before merge, evidence-backed review. No force-push/branch deletion for unfinished PRs. Production deployment, account/tariff migration, release tags and repo visibility changes require separate explicit authorization.
- Historic public disclosure cannot be reversed by making repo private; inspect exposures before release.

## Shared open-beta mission

- Before any task, read `docs/OPEN-BETA-RELEASE-MISSION.md`. It is the canonical shared product/release brief for all coding, review, QA and release agents.
- The mission covers the Open Beta acceptance scope for WordPress core, WooCommerce, Elementor, classic/block editor, classic themes and Woodmart; server-authoritative tariffs/quota ledger; plugin dashboard reconciliation; code audit; GitHub Actions; disposable virtual test environments; and periodic installable beta artifacts.
- Keep the mission document and this file aligned. Update the mission when the owner changes product scope or billing policy; do not silently reinterpret approved decisions.
- The release mission does not override this file's security, repository separation, branch/PR, data-integrity or explicit-owner-approval requirements.
