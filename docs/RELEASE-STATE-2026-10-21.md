# WP AI Admin — single active release state

## Verified repository audit

- `okean-ai-admin` PUBLIC: original `main` contained Native **2.1.14**, Cloud **0.3.6** and `wp-ai-cloud` pilot **0.1.0**. Unified PR #47 upgrades a single Native runtime to **2.1.17**, a single Cloud runtime from recovered **0.3.11** to an explicitly marked **0.3.12-dev** Gateway candidate, and keeps old duplicates out of working HEAD.
- `wp-ai-admin` PUBLIC: `main` Native **2.1.16**, PR #21 verified recovery Native 2.1.17.
- `wp-ai-admin-cloud-core` PRIVATE: current source of restored 0.3.11 in PR #12, historical `main` older. Duplicate Billing alternative #9 is closed, not canonical.
- `universal-mcp-platform` PRIVATE: independent project, do not merge.
- Historic `wp-ai-admin-release-ci` not returned in owned-repo inventory.

**Source fingerprints:** both public Native runtime 83/83 SHA-identical to recovery candidate; Cloud runtime starts from the exact recovered 0.3.11 manifest; 18 unchanged files remain SHA-256 identical, while only Gateway and plugin bootstrap are deliberately modified under 0.3.12-dev. The original 20-file manifest remains immutable. This is a new public snapshot; installed runtime hash comparisons remain separate.

## Work disposition

Old monorepo PRs #35 (2.1.15/0.3.7), #37 (QA38), #41 (Cloud 0.3.8), #43 (QA38/Cloud 0.3.8) are historical candidates, preserve unique QA work. #44 is superseded proposal for billing, do not introduce second Ledger. #45 is isolated Elementor stage 1, don't enable unverified full-document writer. Public Native #17/#20 provide audit and Woo/Gutenberg fixtures; #21 is restoration. Private Cloud #8 is audit, #9 closed alternate policy, #12 recovery.

## Contract

Free 100/month; Pro 500/day; Business 2000/day; BusinessPro 5000/day. Successful read/Sandbox costs one; successful batch change to N objects costs N; duplicate retry does not double-charge; rollback/failed calls excluded. Server Ledger only, no telemetry, no auto-debit. Central Batch/paid upgrades are disabled until contract signed and staged.

## Critical gates

1. Unified PR #47 exact SHA GitHub CI Native/Cloud PHP/WordPress, SQLite/MySQL. First both suites passed at SHA 3bf4b815; subsequent docs-only changes must be verified again.
2. OAuth, tenancy, migrations, signed Proof/Receipt, inconclusive write reconciliation and retry.
3. Elementor/Gutenberg/Woo/Media/Menu staging e2e tests with rollback and Site ID preservation.
4. Beget installed-file parity, account backups and production acceptance.
5. Split final approved versions into public Native and private Cloud release repos without copying Cloud code into the public Native package.
6. Main consolidation is authorized by the owner's request for one development repo; runtime deployment, visibility changes, payment activation and final split still require separate approval.

**Risk recorded:** because owner selected temporary PUBLIC all-source development, Cloud 0.3.11 source becomes publicly inspectable and this disclosure cannot be undone by changing repository visibility later. No real secrets/data intentionally committed.
