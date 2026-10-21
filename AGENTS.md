# WP AI Admin temporary monorepo agent rules

- This repository is PUBLIC and temporarily contains a reviewed copy of Native 2.1.17 and Cloud Core 0.3.11 **source**. This is an explicit owner-driven CI arrangement, not a security boundary. Never publish real access tokens, OAuth secret keys, server key material, payment credentials, customer information, private WordPress backups or database exports.
- GitHub PRs must keep `permissions: contents: read` by default, use no privileged `pull_request_target` for untrusted code, and use synthetic database fixtures.
- Runtime separation: `okean-ai-admin.php` + `includes/` = Native; `wp-ai-admin-cloud/` = exactly one Cloud 0.3.11 source. Old pilot `wp-ai-cloud/` and old ZIPs must not reappear in the current tree.
- Native release archive must contain only Native files. Cloud production distribution must be exported separately and ultimately maintained in PRIVATE `wp-ai-admin-cloud-core`.
- Never loosen Approval Engine, mutation conflict guards, Verify, signed Receipt/Proof, Rollback or OAuth site/owner isolation solely to make CI green. No production data mutation in tests.
- Gateway Ledger alone computes quotas; per-successful-object Batch, atomic reservation/idempotency and uncertain outcomes are P0.
- One issue/branch per change, exact-HEAD CI logs before merge, evidence-backed review. No force-push/branch deletion for unfinished PRs. Production deployment, account/tariff migration, release tags and repo visibility changes require separate explicit authorization.
- Historic public disclosure cannot be reversed by making repo private; inspect exposures before release.
