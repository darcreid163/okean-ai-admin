=== WP AI Admin ===
Contributors: andriyanovng
Tags: ai, admin, mcp, gutenberg, woocommerce, elementor, sandbox, approval, rollback
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.1.17
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal AI Admin for WordPress: sandbox, diff, approval, verification and rollback for every AI-driven change.

== Description ==

WP AI Admin connects your WordPress site to AI agents (ChatGPT, custom MCP clients) through a secure
READ → PLAN → SANDBOX → DIFF → APPROVAL → APPLY → VERIFY → RECEIPT/PROOF → ROLLBACK lifecycle.

Every mutation — post content, WooCommerce products, Gutenberg blocks, Elementor pages, menus, media,
options — goes through an approval gate before touching the database. Verification confirms the change
landed correctly; if it did not, one-click rollback restores the exact pre-change state using the
saved `before_data` snapshot. All operations are sealed with a cryptographic receipt.

Key capabilities:

* **MCP server** — exposes WordPress as an MCP tool host; compatible with the Model Context Protocol
  (2025-06-18). Connect from ChatGPT or any MCP client.
* **Sandbox & diff** — every write first produces a plan with a before/after diff; no side effect
  without human approval.
* **Approval engine** — every apply, rollback and reject requires a signed approval record; cannot
  be bypassed.
* **Verify & rollback** — post-apply verification compares runtime state to the approved snapshot;
  automatic or manual rollback restores `before_data`.
* **Receipt/proof** — each settled request produces a sealed, non-repudiable receipt in the ledger.
* **Quota authority** — when paired with WP AI Admin Cloud, the server ledger is the only charging
  authority; the dashboard shows the verified server projection or `unknown`, never a fabricated
  local number.
* **WooCommerce** — product create/update lifecycle (price, stock, categories, tags, images,
  attributes, SEO) with atomic creation claims and concurrency guards.
* **Gutenberg preservation** — `attrs_remove` / `inner_content_index` API; byte-exact rollback
  baseline; no silent block-structure corruption.
* **Elementor** — draft page creation via the Elementor data model.
* **Media** — upload, alt/title update, gallery management.
* **Menus** — create, add/move/remove items.
* **Options** — allowlisted option writes with protected-core and control-plane guards.
* **Abilities API** — extensible read/write ability system for third-party integrations.

== Installation ==

1. Upload the `okean-ai-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **WP AI Admin** in the wp-admin menu.
4. Pair your site with WP AI Admin Cloud at https://wpaiadmin.ru/wpai-cloud/connect, or use
   a direct MCP OAuth client.

**Requirements:**
- PHP 8.2 or higher (8.3 recommended)
- WordPress 6.4 or higher
- WooCommerce 8.0 or higher (only required if using WooCommerce tools)
- Sodium extension (bundled with PHP 7.2+)

== Frequently Asked Questions ==

= Does every AI action need manual approval? =

Yes, by design. Every write command goes through a PLAN → APPROVAL → APPLY lifecycle. The AI can
read and plan freely; it cannot apply changes to your database without a human approval step.

= What happens if an AI change breaks something? =

Click Rollback in the WP AI Admin dashboard. The plugin saved the exact pre-change state as
`before_data` before applying; rollback restores it atomically and verifies the result.

= Can I use this without WP AI Admin Cloud? =

Yes. You can connect any MCP-compatible client directly via OAuth. Cloud is optional and adds a
central quota ledger, multi-site management, and a signed usage dashboard.

= Does the plugin store AI responses or tool outputs? =

No. Receipts contain only state/units/fingerprints. Tool output is never stored by the plugin
or the Cloud service.

== Screenshots ==

1. Dashboard with server usage projection and approval queue.
2. Diff view: before/after for a WooCommerce product price change.
3. Rollback confirmation screen.

== Changelog ==

= 2.1.17 =
* Security: pin usage signing key at pairing; response-body key no longer trusted (issue #135).
* Security: bind every usage projection to stored account owner (issue #135).
* Security: purge superseded pairing credentials on re-pair (issue #135).
* Feature: signed server usage projection in dashboard; shows `unknown` when absent or stale (issue #135).
* Feature: explicit `isError` result contract on every MCP tools/call reply (issue #136).
* Feature: fail-closed Cloud billing — missing `isError`, non-boolean value or empty content held as uncertain, never auto-charged (issue #136).
* Feature: batch receipts and single-call receipts now carry the authoritative usage envelope (issue #136).
* Fix: Gutenberg `attrs_remove` / `inner_content_index` API; byte-exact rollback baseline (issue #137).
* Fix: align server tariff policy with approved Native table: free 100/day, daily 500/day, pro 2000/day, business 5000/day (issue #134).

= 2.1.14 - 2.1.16 =
* Earlier release hardening, consent callback fix, creation-intent identity, abilities API, Gutenberg preservation, Cloud Core 0.3.11 recovery, and Open Beta audit preparation. See docs/ directory for details.

== Upgrade Notice ==

= 2.1.17 =
Security update: re-pairing now purges old credentials. If you have multiple paired sites, each site
must be re-paired once after upgrade to benefit from key pinning. Existing operations, receipts and
rollback data are preserved.
