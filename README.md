# WP AI Admin — public integration candidate

**Native:** 2.1.17 (build `2.1.17-usage-batch-v1-20261019`, DB schema 24). **Private Cloud Core:** 0.3.11 (Gateway schema 4). This public integration branch is NOT an authorized production release.

## Canonical boundaries

- This repository `darcreid163/okean-ai-admin` is the *public* integration/CI workspace for validated Native sources and synthetic tests. Do not add private Cloud runtime, secrets or production customer data.
- `darcreid163/wp-ai-admin` is the public Native release destination; recovery PR #21 has the archived Native 2.1.17 source and independent CI.
- `darcreid163/wp-ai-admin-cloud-core` remains the *private* source of truth for Cloud Core 0.3.11 (recovery PR #12). OAuth, billing, ledger and server implementation stay there.
- `darcreid163/universal-mcp-platform` is independent future work, not a release dependency.

Old bundled `wp-ai-admin-cloud/` (0.3.6) and `wp-ai-cloud/` (0.1.0) and committed ZIPs are removed **from this candidate tree**, not from public Git history. Their historic exposure cannot be retracted merely by later making this repo private.

## Commercial policy (approved contract; do not infer live activation)

| Plan | Quota |
|---|---|
| Free | 100/month |
| Pro | 500/day |
| Business | 2000/day |
| BusinessPro | 5000/day |

Successful reads and Sandbox calls cost one; successful batch child object costs one; idempotent retries and rollback do not double-charge. The central Cloud Ledger is the only billing authority. No automatic payments. Do not enable central Batch or migrate customer quotas until private Cloud CI and staging pass.

## Safety / release

READ → PLAN → SANDBOX → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK. No unapproved direct live writes. Full-document Elementor writes remain gated. PHP 8.2–8.4, WordPress, WooCommerce/Gutenberg, migration, OAuth, tenant isolation, signed evidence, quota atomicity, staging upgrade/rollback and Beget file hashes must pass before publishing or upgrading a site.

[Current release state](docs/RELEASE-STATE-2026-10-21.md) · [Repository boundaries](docs/REPOSITORY-BOUNDARIES.md) · [Native recovery gates](docs/RELEASE-MAIN-GATE-NATIVE-2026-10-21.md)

Historic documents reflect historic states and are superseded where inconsistent with this status.
