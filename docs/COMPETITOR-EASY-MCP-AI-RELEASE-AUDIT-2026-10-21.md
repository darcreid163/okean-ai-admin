# Easy MCP AI — competitive source audit and release-only adoption (2026-10-21)

Source: official WordPress plugin SVN https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/ (observed changing revisions 3757314 / 3758237), especially:
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/tools/class-tool-registry.php
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/cli-router/
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/approvals/
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/resources/
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/skills/
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/tools/woocommerce/
- https://plugins.svn.wordpress.org/easy-mcp-ai/trunk/includes/tools/media/
- https://github.com/Codeinwp/easy-mcp-ai/blob/main/easy-mcp-ai.php

License declared GPL-2.0-or-later. Source listing/registry and documentation inspected. **Not a complete verified line-by-line review of every plugin file or runtime behavior**: further download, pinned release hash and tests required before claiming that. Avoid transplanting unknown code or vendor files. Ideas are independently reimplemented; copied GPL code requires license/source/copyright compliance.

## Verified competitor architecture
- Large tool registry with lazy discovery and duplicate-name rejection; categories include WordPress posts/pages/CPT, Gutenberg, themes/appearance/widgets/menus, WooCommerce, SEO, OAuth/approvals, analytics, ACF, media, skills, CLI and resources.
- CLI router split into catalog, argument parsing, synopsis validation, capability grants, redaction, self-protection, batch, handlers.
- Dedicated approval persistence/gate interfaces; skills trust/sync modules; rich read-only MCP resources.
- WooCommerce registry includes variations, orders, customers, reports, coupons, shipping and taxes; runtime safety of individual write paths still requires independent review.

## Release-first adoption matrix

| Target | Competitor lesson | WP AI Admin action | Release decision |
|---|---|---|---|
| Tool naming collision | `Tool_Registry::register()` rejects duplicate names | Add static catalog duplicate/canonical alias regression; no dynamic overwrite | Before release, P0 |
| OAuth scope + allowed tools | Capability-filtered tool exposure | Test catalog and execution on per-site OAuth grant, read/write scopes, revocation | Before release, P0 |
| Stable retry fingerprint | Release notes note reordered argument keys | Add deterministic nested associative JSON canonicalization/idempotency regression, including batch | Before release, P0 |
| Force draft | Creation policies constrain new posts | Draft-only safe product/page creation via typed Sandbox, never silently publish | Before release, P0 |
| Read-only MCP resources | Dedicated resource registry | Minimal site info, capabilities, recent draft, media and menu via existing ACL reads; do not expose private details | Before release only if isolated and CI green, P1 |
| Media upload | Existing media tools | Implement strict typed upload approval, SSRF defense, size/MIME allowlist, verify and own-attachment rollback | Before release, P0 if staging green |
| WooCommerce draft | Many targeted Woo handlers | Product create draft, variants, menus and controlled updates via existing lifecycle | Before release, P0 if staging green |
| Skills | Server-managed skills and trust | Existing Cloud skills stay read-only; staged tenant-scoped `save_skill` later | After release |
| SEO, analytics, ACF, integrations | Wide tool categories | Capability-gated adapters with version checks; no wildcard REST/write | After release |
| Batch/fleet | CLI batch model | Keep bounded Cloud Batch v2; do not enable unrestricted fleet jobs until queue/ledger acceptance | After release |

## Release blocking evidence
1. Cloud quota policy: Free 100/month, Pro 500/day, Business 2000/day, BusinessPro 5000/day; only successful calls/objects charged, no telemetry charges or auto debits.
2. Concurrency: atomic reservation under simultaneous owner requests on MySQL + SQLite, no oversubscription, settle on success/failure/unknown.
3. Native and Cloud OAuth and tenant isolation, token revoke, grant-selected site scopes and unsafe tools denial.
4. Batch idempotency, nested reordered arguments, failed/uncertain/partial success receipts and rollback non-double-charge.
5. Staging Native DB23→24, Cloud schema3→4; Elementor/Gutenberg/Woo/media/menu apply+verify+rollback; archive parity and separate Native/Cloud exports.
6. Never claim successful CI = proven live WordPress acceptance. Never install on production based on this document.

## Explicitly deferred
Full analytics/SEO providers, generic third-party ability execution, Woo refunds/payment changes, theme switching, fleet jobs, arbitrary WP-CLI shell, database replacement, Skills writing, external URL scraping of private pages. Protect production from broad privileged mutations.
