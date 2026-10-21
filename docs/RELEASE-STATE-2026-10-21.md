# WP AI Admin — consolidated 2026-10-21 audit

| Repository | Visibility | Audited main | Role |
|---|---|---|---|
| `darcreid163/okean-ai-admin` | public | Native 2.1.14 / bundled Cloud 0.3.6 + pilot 0.1.0 | Public integration/CI candidate 2.1.17 on separate branch |
| `darcreid163/wp-ai-admin` | public | Native 2.1.16 | Eventual Native-only public release (PR #21 recovers 2.1.17) |
| `darcreid163/wp-ai-admin-cloud-core` | private | older baseline | Private 0.3.11 recovery PR #12 |
| `darcreid163/universal-mcp-platform` | private | separate platform | Not a WP AI Admin commercial release dependency |

Owned repository list does not currently include historic `wp-ai-admin-release-ci`; do not treat it as active canonical source without locating it.

**Candidate source:** Native 2.1.17, build `2.1.17-usage-batch-v1-20261019`, DB 24. Private Cloud Core 0.3.11, Gateway DB schema 4. Read-only live MCP tool catalogs previously advertised 116 tools for each registered site; installed-file checksums and private Cloud CI still unverified.

**Older monorepo PRs:** #35 (2.1.15/0.3.7), #37 (QA38), #41 (0.3.8), #43 (QA38/0.3.8) — historical candidates. #44 is obsolete parallel usage-meter proposal; do not merge while private 0.3.11 QuotaPolicy/Store is canonical. #45 is isolated experimental Elementor schema/save work requiring authorization proof. Preserve these drafts and investigate unique commits before explicitly superseding.

**Current release repos:** Native PR #17 audit, #20 Woo/Gutenberg QA, #21 recovery; private Cloud #8 audit, #9 duplicate Billing closed, #12 current source recovery. Universal MCP PR #1 belongs to separate product. No blanket auto-merges across repos.

**Tariff contract:** Free 100 monthly; Pro 500 daily; Business 2000 daily; BusinessPro 5000 daily. Central atomic Ledger, one action per successful call/changed batch object, never bill failed actions, rolls back/retries not double billed. Payments require explicit checkout approval, no automatic debit.

**Security stop-gates:** no private source/secrets in public; PHP/WordPress test matrix on exact HEAD; isolated Cloud Core SQLite/MySQL OAuth/migration/tenancy/ledger tests; signed Receipt/Proof and risky Elementor lifecycle test; staging upgrade+rollback; Beget runtime byte comparison; approval before main/tag/production.

**Public exposure finding:** 53 legacy `wp-ai-admin-cloud/` and `wp-ai-cloud/` files were committed on public old monorepo; deleting from a new working tree does not retract their historical exposure. Audit and rotate any compromised tokens found in repository history; never assume a current clean tree means history is private.

This document supersedes older active-status tables but not historical audit evidence.
